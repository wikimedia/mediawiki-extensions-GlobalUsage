<?php

namespace MediaWiki\Extension\GlobalUsage;

use HTMLCacheUpdateJob;
use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use Title;

/**
 * Class to insert HTMLCacheUpdate jobs on local wikis to purge all pages that use
 * a given shared file. Note that the global and local image link tables are assumed
 * to be in sync, so the later can be used for the local jobs.
 */
class GlobalUsageCachePurgeJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'globalUsageCachePurge', $title, $params );
		$this->removeDuplicates = true; // expensive
	}

	public function run() {
		$title = $this->getTitle();
		if ( !$title->inNamespace( NS_FILE ) ) {
			return true; // umm, OK
		}

		$rootParams = Job::newRootJobParams( // "overall" purge job info
			"GlobalUsage:htmlCacheUpdate:imagelinks:{$title->getPrefixedText()}" );

		$filesForPurge = [ $title->getDbKey() ]; // title to purge backlinks to
		// All File pages that redirect this one may have backlinks that need purging.
		// These backlinks are probably broken now (missing files or double redirects).
		$services = MediaWikiServices::getInstance();
		$backlinkCache = $services
			->getBacklinkCacheFactory()
			->getBacklinkCache( $title );
		foreach ( $backlinkCache->getLinkPages( 'redirect' ) as $redirPageIdentity ) {
			if ( $redirPageIdentity->getNamespace() == NS_FILE ) {
				$filesForPurge[] = $redirPageIdentity->getDbKey();
			}
		}
		// Remove any duplicates in case titles link to themselves
		$filesForPurge = array_values( array_unique( $filesForPurge ) );

		// Find all wikis that use any of these files in any of their pages...
		$dbr = GlobalUsage::getGlobalDB( DB_REPLICA );
		$res = $dbr->select(
			'globalimagelinks',
			[ 'gil_wiki', 'gil_to' ],
			[ 'gil_to' => $filesForPurge, 'gil_wiki != ' . $dbr->addQuotes( WikiMap::getCurrentWikiId() ) ],
			__METHOD__,
			[ 'DISTINCT' ]
		);

		// Build up a list of HTMLCacheUpdateJob jobs to put on each affected wiki to clear
		// the caches for all pages that link to these file pages. These jobs will use the
		// local imagelinks table, which should have the same links that the global one has.
		$jobsByWiki = [];
		foreach ( $res as $row ) {
			$jobsByWiki[$row->gil_wiki][] = new HTMLCacheUpdateJob(
				Title::makeTitle( NS_FILE, $row->gil_to ),
				[ 'table' => 'imagelinks' ] + $rootParams
			);
		}

		// Batch insert the jobs by wiki to save a few round trips
		$jobQueueGroupFactory = $services->getJobQueueGroupFactory();
		foreach ( $jobsByWiki as $wiki => $jobs ) {
			$jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $jobs );
		}

		return true;
	}
}
