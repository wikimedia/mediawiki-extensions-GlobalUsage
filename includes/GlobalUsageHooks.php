<?php
/**
 * GlobalUsage hooks for updating globalimagelinks table.
 *
 * UI hooks in SpecialGlobalUsage.
 */

class GlobalUsageHooks {
	/**
	 * Hook to LinksUpdateComplete
	 * Deletes old links from usage table and insert new ones.
	 * @param LinksUpdate $linksUpdater
	 * @param int|null $ticket
	 * @return bool
	 */
	public static function onLinksUpdateComplete( LinksUpdate $linksUpdater, $ticket = null ) {
		$title = $linksUpdater->getTitle();

		// Create a list of locally existing images (DB keys)
		$images = array_keys( $linksUpdater->getImages() );

		$localFiles = [];
		$repo = RepoGroup::singleton()->getLocalRepo();
		$imagesInfo = $repo->findFiles( $images, FileRepo::NAME_AND_TIME_ONLY );
		foreach ( $imagesInfo as $dbKey => $info ) {
			$localFiles[] = $dbKey;
			if ( $dbKey !== $info['title'] ) { // redirect
				$localFiles[] = $info['title'];
			}
		}
		$localFiles = array_values( array_unique( $localFiles ) );

		$missingFiles = array_diff( $images, $localFiles );

		$gu = self::getGlobalUsage();
		$articleId = $title->getArticleID( Title::GAID_FOR_UPDATE );
		$existing = $gu->getLinksFromPage( $articleId );

		// Calculate changes
		$added = array_diff( $missingFiles, $existing );
		$removed = array_diff( $existing, $missingFiles );

		// Add new usages and delete removed
		$gu->insertLinks( $title, $added, Title::GAID_FOR_UPDATE, $ticket );
		if ( $removed ) {
			$gu->deleteLinksFromPage( $articleId, $removed, $ticket );
		}

		return true;
	}

	/**
	 * Hook to TitleMoveComplete
	 * Sets the page title in usage table to the new name.
	 * For shared file moves, purges all pages in the wiki farm that use the files.
	 * @param Title $ot
	 * @param Title $nt
	 * @param User $user
	 * @param int $pageid
	 * @param int $redirid
	 * @return bool
	 */
	public static function onTitleMoveComplete( $ot, $nt, $user, $pageid, $redirid ) {
		$gu = self::getGlobalUsage();
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
			wfGetDB( DB_MASTER )->onTransactionIdle( function () use ( $jobs ) {
				JobQueueGroup::singleton()->lazyPush( $jobs );
			} );
		}

		return true;
	}

	/**
	 * Hook to ArticleDeleteComplete
	 * Deletes entries from usage table.
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 * @param int $id
	 * @return bool
	 */
	public static function onArticleDeleteComplete( $article, $user, $reason, $id ) {
		$gu = self::getGlobalUsage();
		// @FIXME: avoid making DB replication lag
		$gu->deleteLinksFromPage( $id );

		return true;
	}

	/**
	 * Hook to FileDeleteComplete
	 * Copies the local link table to the global.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param File $file
	 * @param File $oldimage
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public static function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		if ( !$oldimage ) {
			$gu = self::getGlobalUsage();
			$gu->copyLocalImagelinks( $file->getTitle() );

			if ( self::fileUpdatesCreatePurgeJobs() ) {
				$job = new GlobalUsageCachePurgeJob( $file->getTitle(), [] );
				JobQueueGroup::singleton()->push( $job );
			}
		}

		return true;
	}

	/**
	 * Hook to FileUndeleteComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param Title $title
	 * @param array $versions
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public static function onFileUndeleteComplete( $title, $versions, $user, $reason ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksToFile( $title );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $title, [] );
			JobQueueGroup::singleton()->push( $job );
		}

		return true;
	}

	/**
	 * Hook to UploadComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param File $upload
	 * @return bool
	 */
	public static function onUploadComplete( $upload ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksToFile( $upload->getTitle() );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $upload->getTitle(), [] );
			JobQueueGroup::singleton()->push( $job );
		}

		return true;
	}

	/**
	 *
	 * Check if file updates on this wiki should cause backlink page purge jobs
	 *
	 * @return bool
	 */
	private static function fileUpdatesCreatePurgeJobs() {
		global $wgGlobalUsageSharedRepoWiki, $wgGlobalUsagePurgeBacklinks;

		return ( $wgGlobalUsagePurgeBacklinks && wfWikiId() === $wgGlobalUsageSharedRepoWiki );
	}

	/**
	 * Initializes a GlobalUsage object for the current wiki.
	 *
	 * @return GlobalUsage
	 */
	private static function getGlobalUsage() {
		return new GlobalUsage( wfWikiID(), GlobalUsage::getGlobalDB( DB_MASTER ) );
	}

	/**
	 * Hook to make sure globalimagelinks table gets duplicated for parsertests
	 * @param array &$tables
	 * @return bool
	 */
	public static function onParserTestTables( &$tables ) {
		$tables[] = 'globalimagelinks';
		return true;
	}

	/**
	 * Hook to apply schema changes
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = dirname( __DIR__ ) . '/patches';

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			$updater->addExtensionUpdate( [ 'addTable', 'globalimagelinks',
				"$dir/GlobalUsage.sql", true ] );
			$updater->addExtensionUpdate( [ 'addIndex', 'globalimagelinks',
				'globalimagelinks_wiki_nsid_title',
				"$dir/patch-globalimagelinks_wiki_nsid_title.sql", true ] );
		} elseif ( $updater->getDB()->getType() == 'postgresql' ) {
			$updater->addExtensionUpdate( [ 'addTable', 'globalimagelinks',
				"$dir/GlobalUsage.pg.sql", true ] );
			$updater->addExtensionUpdate( [ 'addIndex', 'globalimagelinks',
				'globalimagelinks_wiki_nsid_title',
				"$dir/patch-globalimagelinks_wiki_nsid_title.pg.sql", true ] );
		}
		return true;
	}

	public static function onwgQueryPages( &$queryPages ) {
		$queryPages[] = [ 'SpecialMostGloballyLinkedFiles', 'MostGloballyLinkedFiles' ];
		$queryPages[] = [ 'SpecialGloballyWantedFiles', 'GloballyWantedFiles' ];
		return true;
	}
}
