<?php
use MediaWiki\MediaWikiServices;

class GlobalUsage {
	/** @var string */
	private $interwiki;

	/**
	 * @var IDatabase
	 */
	private $db;

	/**
	 * Construct a GlobalUsage instance for a certain wiki.
	 *
	 * @param string $interwiki Interwiki prefix of the wiki
	 * @param IDatabase $db Database object
	 */
	public function __construct( $interwiki, IDatabase $db ) {
		$this->interwiki = $interwiki;
		$this->db = $db;
	}

	/**
	 * Sets the images used by a certain page
	 *
	 * @param Title $title Title of the page
	 * @param array $images Array of db keys of images used
	 * @param int $pageIdFlags
	 * @param int|null $ticket
	 */
	public function insertLinks(
		Title $title, array $images, $pageIdFlags = Title::GAID_FOR_UPDATE, $ticket = null
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
		foreach ( array_chunk( $insert, $wgUpdateRowsPerQuery ) as $insertBatch ) {
			$this->db->insert( 'globalimagelinks', $insertBatch, __METHOD__, [ 'IGNORE' ] );
			$lbFactory->commitAndWaitForReplication( __METHOD__, $ticket );
		}
	}

	/**
	 * Get all global images from a certain page
	 * @param int $id
	 * @return array
	 */
	public function getLinksFromPage( $id ) {
		$res = $this->db->select(
			'globalimagelinks',
			'gil_to',
			[
				'gil_wiki' => $this->interwiki,
				'gil_page' => $id,
			],
			__METHOD__
		);

		$images = [];
		foreach ( $res as $row ) {
			$images[] = $row->gil_to;
		}

		return $images;
	}

	/**
	 * Deletes all entries from a certain page to certain files
	 *
	 * @param int $id Page id of the page
	 * @param mixed $to File name(s)
	 * @param int|null $ticket
	 */
	public function deleteLinksFromPage( $id, array $to = null, $ticket = null ) {
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
				$this->db->delete( 'globalimagelinks', $where, __METHOD__ );
				$lbFactory->commitAndWaitForReplication( __METHOD__, $ticket );
			}
		} else {
			$this->db->delete( 'globalimagelinks', $where, __METHOD__ );
		}
	}

	/**
	 * Deletes all entries to a certain image
	 *
	 * @param Title $title Title of the file
	 */
	public function deleteLinksToFile( $title ) {
		$this->db->delete(
			'globalimagelinks',
			[
				'gil_wiki' => $this->interwiki,
				'gil_to' => $title->getDBkey()
			],
			__METHOD__
		);
	}

	/**
	 * Copy local links to global table
	 *
	 * @param Title $title Title of the file to copy entries from.
	 */
	public function copyLocalImagelinks( Title $title ) {
		global $wgContLang;

		$res = $this->db->select(
			[ 'imagelinks', 'page' ],
			[ 'il_to', 'page_id', 'page_namespace', 'page_title' ],
			[ 'il_from = page_id', 'il_to' => $title->getDBkey() ],
			__METHOD__
		);

		$insert = [];
		foreach ( $res as $row ) {
			$insert[] = [
				'gil_wiki' => $this->interwiki,
				'gil_page' => $row->page_id,
				'gil_page_namespace_id' => $row->page_namespace,
				'gil_page_namespace' => $wgContLang->getNsText( $row->page_namespace ),
				'gil_page_title' => $row->page_title,
				'gil_to' => $row->il_to,
			];
		}

		$fname = __METHOD__;
		DeferredUpdates::addCallableUpdate( function () use ( $insert, $fname ) {
			$this->db->insert( 'globalimagelinks', $insert, $fname, [ 'IGNORE' ] );
		} );
	}

	/**
	 * Changes the page title
	 *
	 * @param int $id Page id of the page
	 * @param Title $title New title of the page
	 */
	public function moveTo( $id, $title ) {
		$this->db->update(
			'globalimagelinks',
			[
				'gil_page_namespace_id' => $title->getNamespace(),
				'gil_page_namespace' => $title->getNsText(),
				'gil_page_title' => $title->getDBkey()
			],
			[
				'gil_wiki' => $this->interwiki,
				'gil_page' => $id
			],
			__METHOD__
		);
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
		list( $canonicalName, $subpage ) = SpecialPageFactory::resolveAlias( $titleText );
		$canonicalName = MWNamespace::getCanonicalName( NS_SPECIAL ) . ':' . $canonicalName;
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
			return $wgGlobalUsageDatabase === wfWikiID() || !$wgGlobalUsageDatabase;
		} else {
			return $wgGlobalUsageSharedRepoWiki === wfWikiID();
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
					'gil_to = img_name'
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
	 * @param int $index DB_MASTER/DB_REPLICA
	 * @param array $groups
	 * @return IDatabase
	 */
	public static function getGlobalDB( $index, $groups = [] ) {
		global $wgGlobalUsageDatabase;

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wgGlobalUsageDatabase );

		return $lb->getConnectionRef( $index, [], $wgGlobalUsageDatabase );
	}
}
