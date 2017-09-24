<?php
/**
 * Maintenance script to populate the globalimagelinks table. Needs to be run
 * on all wikis.
 */
$path = dirname( dirname( __DIR__ ) );

if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
	$path = getenv( 'MW_INSTALL_PATH' );
}

require_once $path . '/maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class RefreshGlobalImageLinks extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'start-page', 'page_id of the page to start with' );
		$this->addOption( 'start-image', 'il_to of the image to start with' );
		$this->addOption( 'pages', 'CSV of (existing,nonexisting)', true, true );
		$this->setBatchSize( 500 );
	}

	public function execute() {
		$pages = explode( ',', $this->getOption( 'pages' ) );

		$dbr = wfGetDB( DB_REPLICA );
		$gdbw = GlobalUsage::getGlobalDB( DB_MASTER );
		$gu = new GlobalUsage( wfWikiID(), $gdbw );

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$ticket = $lbFactory->getEmptyTransactionTicket( __METHOD__ );

		// Clean up links for existing pages...
		if ( in_array( 'existing', $pages ) ) {
			$lastPageId = intval( $this->getOption( 'start-page', 0 ) );
			$lastIlTo = $this->getOption( 'start-image' );

			do {
				$this->output( "Querying links after (page_id, il_to) = ($lastPageId, $lastIlTo)\n" );

				# Query all pages and any imagelinks associated with that
				$quotedLastIlTo = $dbr->addQuotes( $lastIlTo );
				$res = $dbr->select(
					[ 'page', 'imagelinks', 'image' ],
					[
						'page_id', 'page_namespace', 'page_title',
						'il_to', 'img_name'
					],
					"(page_id = $lastPageId AND il_to > {$quotedLastIlTo})" .
						" OR page_id > $lastPageId",
					__METHOD__,
					[
						'ORDER BY' => $dbr->implicitOrderby() ? 'page_id' : 'page_id, il_to',
						'LIMIT' => $this->mBatchSize,
					],
					[
						# LEFT JOIN imagelinks since we need to delete usage
						# from all images, even if they don't have images anymore
						'imagelinks' => [ 'LEFT JOIN', 'page_id = il_from' ],
						# Check to see if images exist locally
						'image' => [ 'LEFT JOIN', 'il_to = img_name' ]
					]
				);

				# Build up a tree per pages
				$pages = [];
				$lastRow = null;
				foreach ( $res as $row ) {
					if ( !isset( $pages[$row->page_id] ) ) {
						$pages[$row->page_id] = [];
					}
					# Add the imagelinks entry to the pages array if the image
					# does not exist locally
					if ( !is_null( $row->il_to ) && is_null( $row->img_name ) ) {
						$pages[$row->page_id][$row->il_to] = $row;
					}
					$lastRow = $row;
				}

				# Insert the imagelinks data to the global table
				foreach ( $pages as $pageId => $rows ) {
					# Delete all original links if this page is not a continuation
					# of last iteration.
					if ( $pageId != $lastPageId ) {
						$gu->deleteLinksFromPage( $pageId );
					}
					if ( $rows ) {
						$title = Title::newFromRow( reset( $rows ) );
						$images = array_keys( $rows );
						# Since we have a pretty accurate page_id, don't specify
						# Title::GAID_FOR_UPDATE
						$gu->insertLinks( $title, $images, /* $flags */ 0 );
					}
				}

				if ( $lastRow ) {
					# We've processed some rows in this iteration, so save
					# continuation variables
					$lastPageId = $lastRow->page_id;
					$lastIlTo = $lastRow->il_to;

					# Be nice to the database
					$lbFactory->commitAndWaitForReplication( __METHOD__, $ticket );
				}
			} while ( !is_null( $lastRow ) );
		}

		// Clean up broken links from pages that no longer exist...
		if ( in_array( 'nonexisting', $pages ) ) {
			$lastPageId = 0;
			while ( 1 ) {
				$this->output( "Querying for broken links after (page_id) = ($lastPageId)\n" );

				$res = $gdbw->select( 'globalimagelinks', 'gil_page',
					[ 'gil_wiki' => wfWikiID(), "gil_page > $lastPageId" ],
					__METHOD__,
					[ 'ORDER BY' => 'gil_page', 'LIMIT' => $this->mBatchSize ]
				);

				if ( !$res->numRows() ) {
					break;
				}

				$pageIds = [];
				foreach ( $res as $row ) {
					$pageIds[$row->gil_page] = false;
					$lastPageId = (int)$row->gil_page;
				}

				$lres = $dbr->select( 'page', 'page_id',
					[ 'page_id' => array_keys( $pageIds ) ], __METHOD__ );

				foreach ( $lres as $row ) {
					$pageIds[$row->page_id] = true;
				}

				$deleted = 0;
				foreach ( $pageIds as $pageId => $exists ) {
					if ( !$exists ) {
						$gu->deleteLinksFromPage( $pageId );
						++$deleted;
					}
				}

				if ( $deleted > 0 ) {
					$lbFactory->commitAndWaitForReplication( __METHOD__, $ticket );
				}
			};
		}
	}
}

$maintClass = 'RefreshGlobalImageLinks';
require_once DO_MAINTENANCE;
