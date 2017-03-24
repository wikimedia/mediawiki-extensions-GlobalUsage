<?php
/**
 * Class to insert HTMLCacheUpdate jobs on local wikis to purge all pages that use
 * a given shared file. Note that the global and local image link tables are assumed
 * to be in sync, so the later can be used for the local jobs.
 */
class GlobalUsageCachePurgeJob extends Job {
	function __construct( $title, $params, $id = 0 ) {
		parent::__construct( 'globalUsageCachePurge', $title, $params, $id );
		$this->removeDuplicates = true; // expensive
	}

	function run() {
		$title = $this->getTitle();
		if ( !$title->inNamespace( NS_FILE ) ) {
			return true; // umm, OK
		}

		$rootParams = Job::newRootJobParams( // "overall" purge job info
			"GlobalUsage:htmlCacheUpdate:imagelinks:{$title->getPrefixedText()}" );

		$filesForPurge = array( $title->getDbKey() ); // title to purge backlinks to
		// All File pages that redirect this one may have backlinks that need purging.
		// These backlinks are probably broken now (missing files or double redirects).
		foreach ( $title->getBacklinkCache()->getLinks( 'redirect' ) as $redirTitle ) {
			if ( $redirTitle->getNamespace() == NS_FILE ) {
				$filesForPurge[] = $redirTitle->getDbKey();
			}
		}
		// Remove any duplicates in case titles link to themselves
		$filesForPurge = array_values( array_unique( $filesForPurge ) );

		// Find all wikis that use any of these files in any of their pages...
		$dbr = GlobalUsage::getGlobalDB( DB_REPLICA );
		$res = $dbr->select(
			'globalimagelinks',
			array( 'gil_wiki', 'gil_to' ),
			array( 'gil_to' => $filesForPurge, 'gil_wiki != ' . $dbr->addQuotes( wfWikiID() ) ),
			__METHOD__,
			array( 'DISTINCT' )
		);

		// Build up a list of HTMLCacheUpdateJob jobs to put on each affected wiki to clear
		// the caches for all pages that link to these file pages. These jobs will use the
		// local imagelinks table, which should have the same links that the global one has.
		$jobsByWiki = array();
		foreach ( $res as $row ) {
			$jobsByWiki[$row->gil_wiki][] = new HTMLCacheUpdateJob(
				Title::makeTitle( NS_FILE, $row->gil_to ),
				array( 'table' => 'imagelinks' ) + $rootParams
			);
		}

		// Batch insert the jobs by wiki to save a few round trips
		foreach ( $jobsByWiki as $wiki => $jobs ) {
			JobQueueGroup::singleton( $wiki )->push( $jobs );
		}

		return true;
	}
}
