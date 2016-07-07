<?php

class GlobalUsage {
	private $interwiki;

	/**
	 * @var DatabaseBase
	 */
	private $db;

	/**
	 * Construct a GlobalUsage instance for a certain wiki.
	 *
	 * @param $interwiki string Interwiki prefix of the wiki
	 * @param $db mixed Database object
	 */
	public function __construct( $interwiki, $db ) {
		$this->interwiki = $interwiki;
		$this->db = $db;
	}

	/**
	 * Sets the images used by a certain page
	 *
	 * @param $title Title Title of the page
	 * @param $images array Array of db keys of images used
	 * @param $pageIdFlags int
	 */
	public function insertLinks( $title, $images, $pageIdFlags = Title::GAID_FOR_UPDATE ) {
		$insert = array();
		foreach ( $images as $name ) {
			$insert[] = array(
				'gil_wiki' => $this->interwiki,
				'gil_page' => $title->getArticleID( $pageIdFlags ),
				'gil_page_namespace_id' => $title->getNamespace(),
				'gil_page_namespace' => $title->getNsText(),
				'gil_page_title' => $title->getDBkey(),
				'gil_to' => $name
			);
		}
		$this->db->insert( 'globalimagelinks', $insert, __METHOD__, array( 'IGNORE' ) );
	}

	/**
	 * Get all global images from a certain page
	 * @param $id int
	 * @return array
	 */
	public function getLinksFromPage( $id ) {
		$res = $this->db->select(
			'globalimagelinks',
			'gil_to',
			array(
				'gil_wiki' => $this->interwiki,
				'gil_page' => $id,
			),
			__METHOD__
		);

		$images = array();
		foreach ( $res as $row )
			$images[] = $row->gil_to;
		return $images;
	}

	/**
	 * Deletes all entries from a certain page to certain files
	 *
	 * @param $id int Page id of the page
	 * @param $to mixed File name(s)
	 */
	public function deleteLinksFromPage( $id, $to = null ) {
		$where = array(
			'gil_wiki' => $this->interwiki,
			'gil_page' => $id
		);
		if ( $to ) {
			$where['gil_to'] = $to;
		}
		$this->db->delete( 'globalimagelinks', $where, __METHOD__ );
	}

	/**
	 * Deletes all entries to a certain image
	 *
	 * @param $title Title Title of the file
	 */
	public function deleteLinksToFile( $title ) {
		$this->db->delete(
			'globalimagelinks',
			array(
				'gil_wiki' => $this->interwiki,
				'gil_to' => $title->getDBkey()
			),
			__METHOD__
		);
	}

	/**
	 * Copy local links to global table
	 *
	 * @param $title Title Title of the file to copy entries from.
	 */
	public function copyLocalImagelinks( Title $title ) {
		global $wgContLang;

		$res = $this->db->select(
			array( 'imagelinks', 'page' ),
			array( 'il_to', 'page_id', 'page_namespace', 'page_title' ),
			array( 'il_from = page_id', 'il_to' => $title->getDBkey() ),
			__METHOD__
		);

		$insert = array();
		foreach ( $res as $row ) {
			$insert[] = array(
				'gil_wiki' => $this->interwiki,
				'gil_page' => $row->page_id,
				'gil_page_namespace_id' => $row->page_namespace,
				'gil_page_namespace' => $wgContLang->getNsText( $row->page_namespace ),
				'gil_page_title' => $row->page_title,
				'gil_to' => $row->il_to,
			);
		}

		$fname = __METHOD__;
		DeferredUpdates::addCallableUpdate( function () use ( $insert, $fname ) {
			$this->db->insert( 'globalimagelinks', $insert, $fname, array( 'IGNORE' ) );
		} );
	}

	/**
	 * Changes the page title
	 *
	 * @param $id int Page id of the page
	 * @param $title Title New title of the page
	 */
	public function moveTo( $id, $title ) {
		$this->db->update(
			'globalimagelinks',
			array(
				'gil_page_namespace_id' => $title->getNamespace(),
				'gil_page_namespace' => $title->getNsText(),
				'gil_page_title' => $title->getDBkey()
			),
			array(
				'gil_wiki' => $this->interwiki,
				'gil_page' => $id
			),
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
	 * @return boolean
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
	 * @return Array Query info array, as a QueryPage would expect.
	 */
	public static function getWantedFilesQueryInfo( $wiki = false ) {
		$qi = array(
			'tables' => array(
				'globalimagelinks',
				'page',
				'redirect',
				'img1' => 'image',
				'img2' => 'image',
			),
			'fields' => array(
				'namespace' => NS_FILE,
				'title' => 'gil_to',
				'value' => 'COUNT(*)'
			),
			'conds' => array(
				'img1.img_name' => null,
				// We also need to exclude file redirects
				'img2.img_name' => null,
			 ),
			'options' => array( 'GROUP BY' => 'gil_to' ),
			'join_conds' => array(
				'img1' => array( 'LEFT JOIN',
					'gil_to = img_name'
				),
				'page' => array( 'LEFT JOIN', array(
					'gil_to = page_title',
					'page_namespace' => NS_FILE,
				) ),
				'redirect' => array( 'LEFT JOIN', array(
					'page_id = rd_from',
					'rd_namespace' => NS_FILE,
					'rd_interwiki' => ''
				) ),
				'img2' => array( 'LEFT JOIN',
					'rd_title = img2.img_name'
				)
			)
		);
		if ( $wiki !== false ) {
			// Limit to just one wiki.
			$qi['conds']['gil_wiki'] = $wiki;
		}

		return $qi;
	}
}
