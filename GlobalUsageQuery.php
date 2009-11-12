<?php
/**
 * A helper class to query the globalimagelinks table
 * 
 * Should maybe simply resort to offset/limit query rather 
 */
class GlobalUsageQuery {
	private $limit = 50;
	private $offset = 0;
	private $hasMore = false;
	private $filterLocal = false;
	private $result;
	private $continue;


	public function __construct( $target ) {
		global $wgGlobalUsageDatabase;
		$this->db = wfGetDB( DB_SLAVE, array(), $wgGlobalUsageDatabase );
		if ( $target instanceof Title )
			$this->target = $target->getDBKey();
		else
			$this->target = $target;
		$this->offset = array( '', '', '' );

	}

	/**
	 * Set the offset parameter
	 *
	 * @param $offset int offset
	 */
	public function setOffset( $offset ) {
		if ( !is_array( $offset ) )
			$offset = explode( '|', $offset );

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
	 * @return array offset
	 */
	public function getOffsetString() {
		return implode( '|', $this->offset );
	}
	/**
	 *
	 */
	public function getContinueString() {
		return "{$this->lastRow->gil_to}|{$this->lastRow->gil_wiki}|{$this->lastRow->gil_page}";
	}

	/**
	 * Set the maximum amount of items to return. Capped at 500.
	 *
	 * @param $limit int The limit
	 */
	public function setLimit( $limit ) {
		$this->limit = min( $limit, 500 );
	}
	public function getLimit() {
		return $this->limit;
	}

	/**
	 * Set whether to filter out the local usage
	 */
	public function filterLocal( $value = true ) {
		$this->filterLocal = $value;
	}


	/**
	 * Executes the query
	 */
	public function execute() {
		$where = array( 'gil_to' => $this->target );
		if ( $this->filterLocal )
			$where[] = 'gil_wiki != ' . $this->db->addQuotes( wfWikiId() );

		$qTo = $this->db->addQuotes( $this->offset[0] );
		$qWiki = $this->db->addQuotes( $this->offset[1] );
		$qPage = intval( $this->offset[2] );

		$where[] = "(gil_to > $qTo) OR " .
			"(gil_to = $qTo AND gil_wiki > $qWiki) OR " .
			"(gil_to = $qTo AND gil_wiki = $qWiki AND gil_page >= $qPage )";


		$res = $this->db->select( 'globalimagelinks',
				array(
					'gil_to',
					'gil_wiki',
					'gil_page',
					'gil_page_namespace',
					'gil_page_title' 
				),
				$where,
				__METHOD__,
				array( 
					'ORDER BY' => 'gil_to, gil_wiki, gil_page',
					'LIMIT' => $this->limit + 1,
				)
		);

		$count = 0;
		$this->hasMore = false;
		$this->result = array();
		foreach ( $res as $row ) {
			$count++;
			if ( $count > $this->limit ) {
				$this->hasMore = true;
				$this->lastRow = $row;
				break;
			}

			if ( !isset( $this->result[$row->gil_to] ) )
				$this->result[$row->gil_to] = array();
			if ( !isset( $this->result[$row->gil_to][$row->gil_wiki] ) )
				$this->result[$row->gil_to][$row->gil_wiki] = array();

			$this->result[$row->gil_to][$row->gil_wiki][] = array(
				'image'	=> $row->gil_to,
				'id' => $row->gil_page,
				'namespace' => $row->gil_page_namespace,
				'title' => $row->gil_page_title,
				'wiki' => $row->gil_wiki,
			);
		}
	}
	public function getResult() {
		return $this->result;
	}
	public function getSingleImageResult() {
		if ( $this->result )
			return current( $this->result );
		else
			return array();
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
