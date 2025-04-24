<?php

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\Jobs\HTMLCacheUpdateJob;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/**
 * Class to insert HTMLCacheUpdate jobs on local wikis to purge all pages that use
 * a given shared file. Note that the global and local image link tables are assumed
 * to be in sync, so the later can be used for the local jobs.
 */
class GlobalUsageCachePurgeJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'globalUsageCachePurge', $title, $params );
		// expensive job
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run() {
		$title = $this->getTitle();
		if ( !$title->inNamespace( NS_FILE ) ) {
			// umm, OK
			return true;
		}

		// "overall" purge job info
		$rootParams = Job::newRootJobParams(
			"GlobalUsage:htmlCacheUpdate:imagelinks:{$title->getPrefixedText()}" );

		// title to purge backlinks to
		$filesForPurge = [ $title->getDbKey() ];
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
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'gil_wiki', 'gil_to' ] )
			->distinct()
			->from( 'globalimagelinks' )
			->where( [
				'gil_to' => $filesForPurge,
				$dbr->expr( 'gil_wiki', '!=', WikiMap::getCurrentWikiId() ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

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
