<?php
/**
 * Maintenance script to populate the globalimagelinks table. Needs to be run
 * on all wikis.
 */

// @codeCoverageIgnoreStart
$path = dirname( dirname( dirname( __DIR__ ) ) );

if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
	$path = getenv( 'MW_INSTALL_PATH' );
}

require_once $path . '/maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Extension\GlobalUsage\GlobalUsage;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDBAccessObject;

class RefreshGlobalimagelinks extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'start-page', 'page_id of the page to start with' );
		$this->addOption( 'start-image', 'il_to of the image to start with' );
		$this->addOption( 'pages', 'CSV of (existing,nonexisting)', true, true );
		$this->setBatchSize( 500 );

		$this->requireExtension( 'Global Usage' );
	}

	public function execute() {
		$pages = explode( ',', $this->getOption( 'pages' ) );

		$connProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbr = $connProvider->getReplicaDatabase();
		$gdbw = $connProvider->getPrimaryDatabase( 'virtual-globalusage' );
		$gdbr = $connProvider->getReplicaDatabase( 'virtual-globalusage' );
		$gu = new GlobalUsage( WikiMap::getCurrentWikiId(), $gdbw, $gdbr );

		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$ticket = $lbFactory->getEmptyTransactionTicket( __METHOD__ );

		// Clean up links for existing pages...
		if ( in_array( 'existing', $pages ) ) {
			$lastPageId = intval( $this->getOption( 'start-page', 0 ) );
			$lastIlTo = $this->getOption( 'start-image' );

			do {
				$this->output( "Querying links after (page_id, il_to) = ($lastPageId, $lastIlTo)\n" );

				# Query all pages and any imagelinks associated with that
				$res = $dbr->newSelectQueryBuilder()
					->select( [
						'page_id', 'page_namespace', 'page_title',
						'il_to', 'img_name'
					] )
					->from( 'page' )
					// LEFT JOIN imagelinks since we need to delete usage
					// from all images, even if they don't have images anymore
					->leftJoin( 'imagelinks', null, 'page_id = il_from' )
					// Check to see if images exist locally
					->leftJoin( 'image', null, 'il_to = img_name' )
					->where( $dbr->buildComparison( '>', [
						'page_id' => $lastPageId,
						'il_to' => $lastIlTo,
					] ) )
					->orderBy( $dbr->implicitOrderby() ? 'page_id' : 'page_id, il_to' )
					->limit( $this->mBatchSize )
					->caller( __METHOD__ )
					->fetchResultSet();

				# Build up a tree per pages
				$pages = [];
				$lastRow = null;
				foreach ( $res as $row ) {
					if ( !isset( $pages[$row->page_id] ) ) {
						$pages[$row->page_id] = [];
					}
					# Add the imagelinks entry to the pages array if the image
					# does not exist locally
					if ( $row->il_to !== null && $row->img_name === null ) {
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
						# IDBAccessObject::READ_LATEST
						$gu->insertLinks( $title, $images, IDBAccessObject::READ_NORMAL );
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
			} while ( $lastRow !== null );
		}

		// Clean up broken links from pages that no longer exist...
		if ( in_array( 'nonexisting', $pages ) ) {
			$lastPageId = 0;
			while ( 1 ) {
				$this->output( "Querying for broken links after (page_id) = ($lastPageId)\n" );

				$res = $gdbw->newSelectQueryBuilder()
					->select( 'gil_page' )
					->from( 'globalimagelinks' )
					->where( [
						'gil_wiki' => WikiMap::getCurrentWikiId(),
						$gdbw->expr( 'gil_page', '>', $lastPageId ),
					] )
					->orderBy( 'gil_page' )
					->limit( $this->mBatchSize )
					->caller( __METHOD__ )
					->fetchResultSet();

				if ( !$res->numRows() ) {
					break;
				}

				$pageIds = [];
				foreach ( $res as $row ) {
					$pageIds[$row->gil_page] = false;
					$lastPageId = (int)$row->gil_page;
				}

				$lres = $dbr->newSelectQueryBuilder()
					->select( 'page_id' )
					->from( 'page' )
					->where( [ 'page_id' => array_keys( $pageIds ) ] )
					->caller( __METHOD__ )
					->fetchResultSet();

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
			}
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = RefreshGlobalimagelinks::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
