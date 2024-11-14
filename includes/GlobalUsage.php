<?php

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Context\IContextSource;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;

class GlobalUsage {
	/** @var string */
	private $interwiki;

	/**
	 * @var IDatabase
	 */
	private $dbw;

	/**
	 * @var IDatabase
	 */
	private $dbr;

	/**
	 * Construct a GlobalUsage instance for a certain wiki.
	 *
	 * @param string $interwiki Interwiki prefix of the wiki
	 * @param IDatabase $dbw Database object for write (primary)
	 * @param IDatabase $dbr Database object for read (replica)
	 */
	public function __construct( $interwiki, IDatabase $dbw, IDatabase $dbr ) {
		$this->interwiki = $interwiki;
		$this->dbw = $dbw;
		$this->dbr = $dbr;
	}

	/**
	 * Sets the images used by a certain page
	 *
	 * @param Title $title Title of the page
	 * @param string[] $images Array of db keys of images used
	 * @param int $pageIdFlags
	 * @param int|null $ticket
	 */
	public function insertLinks(
		Title $title, array $images, $pageIdFlags = IDBAccessObject::READ_LATEST, $ticket = null
	) {
		global $wgUpdateRowsPerQuery;

		$insert = [];
		foreach ( $images as $name ) {
			$insert[] = [
				'gil_wiki' => $this->interwiki,
				'gil_page' => $title->getArticleID( $pageIdFlags ),
				'gil_page_namespace_id' => $title->getNamespace(),
				'gil_page_namespace' => $title->getNsText(),
				'gil_page_title' => $title->getDBkey(),
				'gil_to' => $name
			];
		}

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$ticket = $ticket ?: $lbFactory->getEmptyTransactionTicket( __METHOD__ );
		$insertBatches = array_chunk( $insert, $wgUpdateRowsPerQuery );
		foreach ( $insertBatches as $insertBatch ) {
			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'globalimagelinks' )
				->ignore()
				->rows( $insertBatch )
				->caller( __METHOD__ )
				->execute();
			if ( count( $insertBatches ) > 1 ) {
				$lbFactory->commitAndWaitForReplication( __METHOD__, $ticket );
			}
		}
	}

	/**
	 * Get all global images from a certain page
	 * @param int $id
	 * @return string[]
	 */
	public function getLinksFromPage( $id ) {
		return $this->dbr->newSelectQueryBuilder()
			->select( 'gil_to' )
			->from( 'globalimagelinks' )
			->where( [
				'gil_wiki' => $this->interwiki,
				'gil_page' => $id,
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	/**
	 * Deletes all entries from a certain page to certain files
	 *
	 * @param int $id Page id of the page
	 * @param string[]|null $to File name(s)
	 * @param int|null $ticket
	 */
	public function deleteLinksFromPage( $id, ?array $to = null, $ticket = null ) {
		global $wgUpdateRowsPerQuery;

		$where = [
			'gil_wiki' => $this->interwiki,
			'gil_page' => $id
		];
		if ( $to ) {
			$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
			$ticket = $ticket ?: $lbFactory->getEmptyTransactionTicket( __METHOD__ );
			foreach ( array_chunk( $to, $wgUpdateRowsPerQuery ) as $toBatch ) {
				$where['gil_to'] = $toBatch;
				$this->dbw->newDeleteQueryBuilder()
					->deleteFrom( 'globalimagelinks' )
					->where( $where )
					->caller( __METHOD__ )
					->execute();
				$lbFactory->commitAndWaitForReplication( __METHOD__, $ticket );
			}
		} else {
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'globalimagelinks' )
				->where( $where )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * Deletes all entries to a certain image
	 *
	 * @param Title $title Title of the file
	 */
	public function deleteLinksToFile( $title ) {
		$this->dbw->newDeleteQueryBuilder()
			->deleteFrom( 'globalimagelinks' )
			->where( [
				'gil_wiki' => $this->interwiki,
				'gil_to' => $title->getDBkey()
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Copy local links to global table
	 *
	 * @param Title $title Title of the file to copy entries from.
	 * @param IDatabase $localDbr Database object for reading the local links from
	 */
	public function copyLocalImagelinks( Title $title, IDatabase $localDbr ) {
		$res = $localDbr->newSelectQueryBuilder()
			->select( [ 'il_to', 'page_id', 'page_namespace', 'page_title' ] )
			->from( 'imagelinks' )
			->join( 'page', null, 'il_from = page_id' )
			->where( [ 'il_to' => $title->getDBkey() ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return;
		}

		$insert = [];
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		foreach ( $res as $row ) {
			$insert[] = [
				'gil_wiki' => $this->interwiki,
				'gil_page' => $row->page_id,
				'gil_page_namespace_id' => $row->page_namespace,
				'gil_page_namespace' => $contLang->getNsText( $row->page_namespace ),
				'gil_page_title' => $row->page_title,
				'gil_to' => $row->il_to,
			];
		}

		$fname = __METHOD__;
		DeferredUpdates::addCallableUpdate( function () use ( $insert, $fname ) {
			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'globalimagelinks' )
				->ignore()
				->rows( $insert )
				->caller( $fname )
				->execute();
		} );
	}

	/**
	 * Changes the page title
	 *
	 * @param int $id Page id of the page
	 * @param Title $title New title of the page
	 */
	public function moveTo( $id, $title ) {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'globalimagelinks' )
			->set( [
				'gil_page_namespace_id' => $title->getNamespace(),
				'gil_page_namespace' => $title->getNsText(),
				'gil_page_title' => $title->getDBkey()
			] )
			->where( [
				'gil_wiki' => $this->interwiki,
				'gil_page' => $id
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Utility function to redirect special pages that are only on the shared repo
	 *
	 * Putting here as this can be useful in multiple special page classes.
	 * This redirects the current page to the same page on the shared repo
	 * wiki, making sure to use the english name of the special page, in case the
	 * current wiki uses something other than english for its content language.
	 *
	 * @param IContextSource $context $this->getContext() from the special page.
	 */
	public static function redirectSpecialPageToSharedRepo( IContextSource $context ) {
		global $wgGlobalUsageSharedRepoWiki;
		// Make sure to get the "canonical" page name, and not a translation.
		$titleText = $context->getTitle()->getDBkey();
		$services = MediaWikiServices::getInstance();
		[ $canonicalName, $subpage ] = $services->getSpecialPageFactory()->resolveAlias( $titleText );
		$canonicalName = $services->getNamespaceInfo()->getCanonicalName( NS_SPECIAL ) . ':' . $canonicalName;
		if ( $subpage !== null ) {
			$canonicalName .= '/' . $subpage;
		}

		$url = WikiMap::getForeignURL( $wgGlobalUsageSharedRepoWiki, $canonicalName );
		if ( $url !== false ) {
			// We have a url
			$args = $context->getRequest()->getQueryValues();
			unset( $args['title'] );
			$url = wfAppendQuery( $url, $args );

			$context->getOutput()->redirect( $url );
		} else {
			// WikiMap can't find the url for the shared repo.
			// Just pretend we don't exist in this case.
			$context->getOutput()->setStatusCode( 404 );
			$context->getOutput()->showErrorPage( 'nosuchspecialpage', 'nospecialpagetext' );
		}
	}

	/**
	 * Are we currently on the shared repo? (Utility function)
	 *
	 * @note This assumes the user has a single shared repo. If the user has
	 *   multiple/nested foreign repos, then its unclear what it means to
	 *   be on the "shared repo". See discussion on bug 23136.
	 * @return bool
	 */
	public static function onSharedRepo() {
		global $wgGlobalUsageSharedRepoWiki, $wgGlobalUsageDatabase;
		if ( !$wgGlobalUsageSharedRepoWiki ) {
			// backwards compatability with settings from before $wgGlobalUsageSharedRepoWiki
			// was introduced.
			return $wgGlobalUsageDatabase === WikiMap::getCurrentWikiId() || !$wgGlobalUsageDatabase;
		} else {
			return $wgGlobalUsageSharedRepoWiki === WikiMap::getCurrentWikiId();
		}
	}

	/**
	 * Query info for getting wanted files using global image links
	 *
	 * Adding a utility method here, as this same query is used in
	 * two different special page classes.
	 *
	 * @param string|bool $wiki
	 * @return array Query info array, as a QueryPage would expect.
	 */
	public static function getWantedFilesQueryInfo( $wiki = false ) {
		$qi = [
			'tables' => [
				'globalimagelinks',
				'page',
				'redirect',
				'img1' => 'image',
				'img2' => 'image',
			],
			'fields' => [
				'namespace' => NS_FILE,
				'title' => 'gil_to',
				'value' => 'COUNT(*)'
			],
			'conds' => [
				'img1.img_name' => null,
				// We also need to exclude file redirects
				'img2.img_name' => null,
			 ],
			'options' => [ 'GROUP BY' => 'gil_to' ],
			'join_conds' => [
				'img1' => [ 'LEFT JOIN',
					'gil_to = img1.img_name'
				],
				'page' => [ 'LEFT JOIN', [
					'gil_to = page_title',
					'page_namespace' => NS_FILE,
				] ],
				'redirect' => [ 'LEFT JOIN', [
					'page_id = rd_from',
					'rd_namespace' => NS_FILE,
					'rd_interwiki' => ''
				] ],
				'img2' => [ 'LEFT JOIN',
					'rd_title = img2.img_name'
				]
			]
		];
		if ( $wiki !== false ) {
			// Limit to just one wiki.
			$qi['conds']['gil_wiki'] = $wiki;
		}

		return $qi;
	}

	/**
	 * @param int $index DB_PRIMARY/DB_REPLICA
	 * @param array $groups
	 * @return IDatabase
	 */
	public static function getGlobalDB( $index, $groups = [] ) {
		global $wgGlobalUsageDatabase;

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wgGlobalUsageDatabase );

		return $lb->getConnection( $index, [], $wgGlobalUsageDatabase );
	}
}
