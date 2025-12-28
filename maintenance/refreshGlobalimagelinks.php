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

use MediaWiki\Deferred\LinksUpdate\ImageLinksTable;
use MediaWiki\Extension\GlobalUsage\GlobalUsage;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDBAccessObject;

class RefreshGlobalimagelinks extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'start-page', 'page_id of the page to start with' );
		$this->addOption( 'start-image', 'il_target_id of the image to start with' );
		$this->addOption( 'pages', 'CSV of (existing,nonexisting)', true, true );
		$this->setBatchSize( 500 );

		$this->requireExtension( 'Global Usage' );
	}

	public function execute() {
		$pages = explode( ',', $this->getOption( 'pages' ) );

		$connProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbr = $connProvider->getReplicaDatabase();
		$imagelinksDbr = $connProvider->getReplicaDatabase( ImageLinksTable::VIRTUAL_DOMAIN );
		$gdbw = $connProvider->getPrimaryDatabase( 'virtual-globalusage' );
		$gdbr = $connProvider->getReplicaDatabase( 'virtual-globalusage' );
		$gu = new GlobalUsage( WikiMap::getCurrentWikiId(), $gdbw, $gdbr );

		$ticket = $connProvider->getEmptyTransactionTicket( __METHOD__ );

		// Clean up links for existing pages...
		if ( in_array( 'existing', $pages ) ) {
			$lastPageId = intval( $this->getOption( 'start-page', 0 ) );
			$lastIlTargetId = intval( $this->getOption( 'start-image', 0 ) );

			do {
				$this->output( "Querying links after (page_id, il_target_id) = ($lastPageId, $lastIlTargetId)\n" );

				// Query all pages and any imagelinks associated with that
				$res = $imagelinksDbr->newSelectQueryBuilder()
					->select( [ 'page_id', 'page_namespace', 'page_title', 'lt_title', 'lt_id' ] )
					->from( 'page' )
					// LEFT JOIN imagelinks since we need to delete usage
					// from all images, even if they don't have images anymore
					->leftJoin( 'imagelinks', null, 'page_id = il_from' )
					->leftJoin( 'linktarget', null, [ 'lt_id = il_target_id', 'lt_namespace' => NS_FILE ] )
					->where( $imagelinksDbr->buildComparison( '>', [
						'page_id' => $lastPageId,
						'il_target_id' => $lastIlTargetId,
					] ) )
					->orderBy( $imagelinksDbr->implicitOrderby() ? 'page_id' : 'page_id, il_target_id' )
					->limit( $this->mBatchSize )
					->caller( __METHOD__ )
					->fetchResultSet();

				// Collect per-page metadata and lt_title values for a separate local image existence check
				$imagesByPage = [];
				$pageMeta = [];
				$ltTitlesSet = [];
				$lastRow = null;
				foreach ( $res as $row ) {
					$pageId = (int)$row->page_id;
					if ( !isset( $imagesByPage[$pageId] ) ) {
						$imagesByPage[$pageId] = [];
						$pageMeta[$pageId] = [
							'ns' => (int)$row->page_namespace,
							'title' => $row->page_title,
						];
					}
					if ( $row->lt_title !== null ) {
						$ltTitlesSet[$row->lt_title] = true;
						$imagesByPage[$pageId][$row->lt_title] = true;
					}
					$lastRow = $row;
				}

				// Query the local image table separately to find which images exist
				$existingImages = [];
				if ( $ltTitlesSet ) {
					$imgRes = $dbr->newSelectQueryBuilder()
						->select( 'img_name' )
						->from( 'image' )
						->where( [ 'img_name' => array_keys( $ltTitlesSet ) ] )
						->caller( __METHOD__ )
						->fetchResultSet();
					foreach ( $imgRes as $imgRow ) {
						$existingImages[$imgRow->img_name] = true;
					}
				}

				// Build list of images per page, keeping only those that do not exist locally
				$pages = [];
				foreach ( $imagesByPage as $pageId => $imgSet ) {
					foreach ( $imgSet as $imgName => $_ ) {
						if ( !isset( $existingImages[$imgName] ) ) {
							$pages[$pageId][] = $imgName;
						}
					}
				}

				// Insert the imagelinks data to the global table
				foreach ( $pages as $pageId => $rows ) {
					// Delete all original links if this page is not a continuation
					// of last iteration.
					if ( $pageId != $lastPageId ) {
						$gu->deleteLinksFromPage( $pageId );
					}
					if ( $rows ) {
						$title = Title::makeTitle( $pageMeta[$pageId]['ns'], $pageMeta[$pageId]['title'] );
						$images = $rows;
						// Since we have a pretty accurate page_id, don't specify
						// IDBAccessObject::READ_LATEST
						$gu->insertLinks( $title, $images, IDBAccessObject::READ_NORMAL );
					}
				}

				if ( $lastRow ) {
					// We've processed some rows in this iteration, so save
					// continuation variables
					$lastPageId = $lastRow->page_id;
					$lastIlTargetId = $lastRow->lt_id;

					// Be nice to the database
					$connProvider->commitAndWaitForReplication( __METHOD__, $ticket );
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
					$connProvider->commitAndWaitForReplication( __METHOD__, $ticket );
				}
			}
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = RefreshGlobalimagelinks::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
