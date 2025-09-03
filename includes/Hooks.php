<?php
/**
 * GlobalUsage hooks for updating globalimagelinks table.
 *
 * UI hooks in SpecialGlobalUsage.
 */

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Content\Content;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\FileRepo\FileRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\FileUndeleteCompleteHook;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\WikiFilePage;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\Hook\WgQueryPagesHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use UploadBase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

class Hooks implements
	LinksUpdateCompleteHook,
	ArticleDeleteCompleteHook,
	FileDeleteCompleteHook,
	FileUndeleteCompleteHook,
	UploadCompleteHook,
	PageMoveCompleteHook,
	WgQueryPagesHook
{
	public function __construct(
		private readonly RepoGroup $repoGroup,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/**
	 * Hook to LinksUpdateComplete
	 * Deletes old links from usage table and insert new ones.
	 * @param LinksUpdate $linksUpdater
	 * @param int|null $ticket
	 */
	public function onLinksUpdateComplete( $linksUpdater, $ticket ) {
		$title = $linksUpdater->getTitle();

		// Create a list of locally existing images (DB keys)
		$images = array_keys( $linksUpdater->getParserOutput()->getImages() );

		$localFiles = [];
		$repo = $this->repoGroup->getLocalRepo();
		$imagesInfo = $repo->findFiles( $images, FileRepo::NAME_AND_TIME_ONLY );
		foreach ( $imagesInfo as $dbKey => $info ) {
			'@phan-var array $info';
			$localFiles[] = $dbKey;
			if ( $dbKey !== $info['title'] ) {
				// redirect
				$localFiles[] = $info['title'];
			}
		}
		$localFiles = array_values( array_unique( $localFiles ) );

		$missingFiles = array_diff( $images, $localFiles );

		$gu = $this->getGlobalUsage();
		$articleId = $title->getArticleID( IDBAccessObject::READ_NORMAL );
		$existing = $gu->getLinksFromPage( $articleId );

		// Calculate changes
		$added = array_diff( $missingFiles, $existing );
		$removed = array_diff( $existing, $missingFiles );

		// Add new usages and delete removed
		$gu->insertLinks( $title, $added, IDBAccessObject::READ_LATEST, $ticket );
		if ( $removed ) {
			$gu->deleteLinksFromPage( $articleId, $removed, $ticket );
		}
	}

	/**
	 * Hook to PageMoveComplete
	 * Sets the page title in usage table to the new name.
	 * For shared file moves, purges all pages in the wiki farm that use the files.
	 * @param LinkTarget $ot
	 * @param LinkTarget $nt
	 * @param UserIdentity $user
	 * @param int $pageid
	 * @param int $redirid
	 * @param string $reason
	 * @param RevisionRecord $revisionRecord
	 */
	public function onPageMoveComplete(
		$ot,
		$nt,
		$user,
		$pageid,
		$redirid,
		$reason,
		$revisionRecord
	) {
		$ot = Title::newFromLinkTarget( $ot );
		$nt = Title::newFromLinkTarget( $nt );

		$gu = $this->getGlobalUsage();
		$gu->moveTo( $pageid, $nt );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$jobs = [];
			if ( $ot->inNamespace( NS_FILE ) ) {
				$jobs[] = new GlobalUsageCachePurgeJob( $ot, [] );
			}
			if ( $nt->inNamespace( NS_FILE ) ) {
				$jobs[] = new GlobalUsageCachePurgeJob( $nt, [] );
			}
			// Push the jobs after DB commit but cancel on rollback
			$this->connectionProvider->getPrimaryDatabase()
				->onTransactionCommitOrIdle( function () use ( $jobs ) {
					$this->jobQueueGroup->lazyPush( $jobs );
				}, __METHOD__ );
		}
	}

	/**
	 * Hook to ArticleDeleteComplete
	 * Deletes entries from usage table.
	 * @param WikiPage $article
	 * @param User $user
	 * @param string $reason
	 * @param int $id
	 * @param Content|null $content
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public function onArticleDeleteComplete( $article, $user, $reason, $id,
		$content, $logEntry, $archivedRevisionCount
	) {
		$gu = $this->getGlobalUsage();
		// @FIXME: avoid making DB replication lag
		$gu->deleteLinksFromPage( $id );
	}

	/**
	 * Hook to FileDeleteComplete
	 * Copies the local link table to the global.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param LocalFile $file
	 * @param string|null $oldimage
	 * @param WikiFilePage|null $article
	 * @param User $user
	 * @param string $reason
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		if ( !$oldimage ) {
			if ( !GlobalUsage::onSharedRepo() ) {
				$gu = $this->getGlobalUsage();
				$gu->copyLocalImagelinks(
					$file->getTitle(),
					$this->connectionProvider->getPrimaryDatabase()
				);
			}

			if ( self::fileUpdatesCreatePurgeJobs() ) {
				$job = new GlobalUsageCachePurgeJob( $file->getTitle(), [] );
				$this->jobQueueGroup->push( $job );
			}
		}
	}

	/**
	 * Hook to FileUndeleteComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param Title $title
	 * @param array $versions
	 * @param User $user
	 * @param string $reason
	 */
	public function onFileUndeleteComplete( $title, $versions, $user, $reason ) {
		$gu = $this->getGlobalUsage();
		$gu->deleteLinksToFile( $title );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $title, [] );
			$this->jobQueueGroup->push( $job );
		}
	}

	/**
	 * Hook to UploadComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param UploadBase $upload
	 */
	public function onUploadComplete( $upload ) {
		$gu = $this->getGlobalUsage();
		$gu->deleteLinksToFile( $upload->getTitle() );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $upload->getTitle(), [] );
			$this->jobQueueGroup->push( $job );
		}
	}

	/**
	 * Check if file updates on this wiki should cause backlink page purge jobs
	 *
	 * @return bool
	 */
	private static function fileUpdatesCreatePurgeJobs() {
		global $wgGlobalUsageSharedRepoWiki, $wgGlobalUsagePurgeBacklinks;

		return ( $wgGlobalUsagePurgeBacklinks && WikiMap::getCurrentWikiId() === $wgGlobalUsageSharedRepoWiki );
	}

	/**
	 * Initializes a GlobalUsage object for the current wiki.
	 *
	 * @return GlobalUsage
	 */
	private function getGlobalUsage() {
		return new GlobalUsage(
			WikiMap::getCurrentWikiId(),
			$this->connectionProvider->getPrimaryDatabase( 'virtual-globalusage' ),
			$this->connectionProvider->getReplicaDatabase( 'virtual-globalusage' )
		);
	}

	/** @inheritDoc */
	public function onWgQueryPages( &$queryPages ) {
		$queryPages[] = [ 'SpecialMostGloballyLinkedFiles', 'MostGloballyLinkedFiles' ];
		$queryPages[] = [ 'SpecialGloballyWantedFiles', 'GloballyWantedFiles' ];
		if ( GlobalUsage::onSharedRepo() ) {
			$queryPages[] = [ 'SpecialGloballyUnusedFiles', 'GloballyUnusedFiles' ];
		}
	}
}
