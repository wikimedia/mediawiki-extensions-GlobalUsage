<?php

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * A helper class to query the globalimagelinks table
 *
 */
class GlobalUsageQuery {
	/** @var int */
	private $limit = 50;
	/** @var array */
	private $offset;
	/** @var bool */
	private $hasMore = false;
	/** @var bool */
	private $filterLocal = false;
	/** @var array[][][] */
	private $result;
	/** @var bool|null */
	private $reversed = false;

	/** @var int[] namespace ID(s) desired */
	private $filterNamespaces;

	/** @var string[] sites desired */
	private $filterSites;

	/**
	 * @var Title|array
	 */
	private $target;

	/** @var stdClass|null */
	private $lastRow;

	/**
	 * @var IDatabase
	 */
	private $db;

	/**
	 * @param mixed $target Title or array of db keys of target(s).
	 * If a title, can be a category or a file
	 */
	public function __construct( $target ) {
		$this->db = GlobalUsage::getGlobalDB( DB_REPLICA );
		if ( $target instanceof Title ) {
			$this->target = $target;
		} elseif ( is_array( $target ) ) {
			// List of files to query
			$this->target = $target;
		} else {
			$this->target = Title::makeTitleSafe( NS_FILE, $target );
		}
		$this->offset = [];
	}

	/**
	 * Set the offset parameter
	 *
	 * @param string $offset offset
	 * @param bool|null $reversed True if this is the upper offset
	 * @return bool
	 */
	public function setOffset( $offset, $reversed = null ) {
		if ( $reversed !== null ) {
			$this->reversed = $reversed;
		}

		if ( !is_array( $offset ) ) {
			$offset = explode( '|', $offset );
		}

		if ( count( $offset ) == 3 ) {
			$this->offset = $offset;
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Return the offset set by the user
	 *
	 * @return string offset
	 */
	public function getOffsetString() {
		return implode( '|', $this->offset );
	}

	/**
	 * Is the result reversed
	 *
	 * @return bool
	 */
	public function isReversed() {
		return $this->reversed;
	}

	/**
	 * Returns the string used for continuation
	 *
	 * @return string
	 *
	 */
	public function getContinueString() {
		if ( $this->hasMore() ) {
			return "{$this->lastRow->gil_to}|{$this->lastRow->gil_wiki}|{$this->lastRow->gil_page}";
		} else {
			return '';
		}
	}

	/**
	 * Set the maximum amount of items to return. Capped at 500.
	 *
	 * @param int $limit The limit
	 */
	public function setLimit( $limit ) {
		$this->limit = min( $limit, 500 );
	}

	/**
	 * Returns the user set limit
	 * @return int
	 */
	public function getLimit() {
		return $this->limit;
	}

	/**
	 * Set whether to filter out the local usage
	 * @param bool $value
	 */
	public function filterLocal( $value = true ) {
		$this->filterLocal = $value;
	}

	/**
	 * Return results only for these namespaces.
	 * @param int[] $namespaces numeric namespace IDs
	 */
	public function filterNamespaces( $namespaces ) {
		$this->filterNamespaces = $namespaces;
	}

	/**
	 * Return results only for these sites.
	 * @param string[] $sites wiki site names
	 */
	public function filterSites( $sites ) {
		$this->filterSites = $sites;
	}

	/**
	 * Executes the query
	 */
	public function execute() {
		/* Construct the SQL query */
		$queryBuilder = $this->db->newSelectQueryBuilder()
			->select( [
				'gil_to',
				'gil_wiki',
				'gil_page',
				'gil_page_namespace_id',
				'gil_page_namespace',
				'gil_page_title'
			] )
			->from( 'globalimagelinks' )
			// Select an extra row to check whether we have more rows available
			->limit( $this->limit + 1 )
			->caller( __METHOD__ );

		// Add target image(s)
		if ( is_array( $this->target ) ) { // array of dbkey strings
			$namespace = NS_FILE;
			$queryIn = $this->target;
		} else { // a Title object
			$namespace = $this->target->getNamespace();
			$queryIn = $this->target->getDbKey();
		}
		switch ( $namespace ) {
			case NS_FILE:
				$queryBuilder->where( [ 'gil_to' => $queryIn ] );
				break;
			case NS_CATEGORY:
				$queryBuilder->join( 'categorylinks', null, 'page_id = cl_from' );
				$queryBuilder->join( 'page', null, 'page_title = gil_to' );
				$queryBuilder->where( [
					'cl_to' => $queryIn,
					'page_namespace' => NS_FILE,
				] );
				break;
			default:
				return;
		}

		if ( $this->filterLocal ) {
			// Don't show local file usage
			$queryBuilder->andWhere( $this->db->expr( 'gil_wiki', '!=', WikiMap::getCurrentWikiId() ) );
		}

		if ( $this->filterNamespaces ) {
			$queryBuilder->andWhere( [ 'gil_page_namespace_id' => $this->filterNamespaces ] );
		}

		if ( $this->filterSites ) {
			$queryBuilder->andWhere( [ 'gil_wiki' => $this->filterSites ] );
		}

		// Set the continuation condition
		if ( $this->offset ) {
			// Check which limit we got in order to determine which way to traverse rows
			if ( $this->reversed ) {
				// Reversed traversal; do not include offset row
				$op = '<';
				$queryBuilder->orderBy( [ 'gil_to', 'gil_wiki', 'gil_page' ], SelectQueryBuilder::SORT_DESC );
			} else {
				// Normal traversal; include offset row
				$op = '>=';
			}

			$queryBuilder->andWhere( $this->db->buildComparison( $op, [
				'gil_to' => $this->offset[0],
				'gil_wiki' => $this->offset[1],
				'gil_page' => intval( $this->offset[2] ),
			] ) );
		}

		$res = $queryBuilder->fetchResultSet();

		/* Process result */
		// Always return the result in the same order; regardless whether reversed was specified
		// reversed is really only used to determine from which direction the offset is
		$rows = [];
		$count = 0;
		$this->hasMore = false;
		foreach ( $res as $row ) {
			$count++;
			if ( $count > $this->limit ) {
				// We've reached the extra row that indicates that there are more rows
				$this->hasMore = true;
				$this->lastRow = $row;
				break;
			}
			$rows[] = $row;
		}
		if ( $this->reversed ) {
			$rows = array_reverse( $rows );
		}

		// Build the result array
		$this->result = [];
		foreach ( $rows as $row ) {
			if ( !isset( $this->result[$row->gil_to] ) ) {
				$this->result[$row->gil_to] = [];
			}
			if ( !isset( $this->result[$row->gil_to][$row->gil_wiki] ) ) {
				$this->result[$row->gil_to][$row->gil_wiki] = [];
			}

			$this->result[$row->gil_to][$row->gil_wiki][] = [
				'image' => $row->gil_to,
				'id' => $row->gil_page,
				'namespace_id' => $row->gil_page_namespace_id,
				'namespace' => $row->gil_page_namespace,
				'title' => $row->gil_page_title,
				'wiki' => $row->gil_wiki,
			];
		}
	}

	/**
	 * Returns the result set. The result is a 4 dimensional array
	 * (file, wiki, page), whose items are arrays with keys:
	 *   - image: File name
	 *   - id: Page id
	 *   - namespace: Page namespace text
	 *   - title: Unprefixed page title
	 *   - wiki: Wiki id
	 *
	 * @return array Result set
	 */
	public function getResult() {
		return $this->result;
	}

	/**
	 * Returns a 3 dimensional array with the result of the first file. Useful
	 * if only one image was queried.
	 *
	 * For further information see documentation of getResult()
	 *
	 * @return array Result set
	 */
	public function getSingleImageResult() {
		if ( $this->result ) {
			return current( $this->result );
		} else {
			return [];
		}
	}

	/**
	 * Returns whether there are more results
	 *
	 * @return bool
	 */
	public function hasMore() {
		return $this->hasMore;
	}

	/**
	 * Returns the result length
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->result );
	}
}
