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
	 * @param $linksUpdater LinksUpdate
	 * @param int|null $ticket
	 * @return bool
	 */
	public static function onLinksUpdateComplete( LinksUpdate $linksUpdater, $ticket = null ) {
		$title = $linksUpdater->getTitle();

		// Create a list of locally existing images (DB keys)
		$images = array_keys( $linksUpdater->getImages() );

		$localFiles = array();
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
	 * @param $ot Title
	 * @param $nt Title
	 * @param $user User
	 * @param $pageid int
	 * @param $redirid
	 * @return bool
	 */
	public static function onTitleMoveComplete( $ot, $nt, $user, $pageid, $redirid ) {
		$gu = self::getGlobalUsage();
		$gu->moveTo( $pageid, $nt );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$jobs = array();
			if ( $ot->inNamespace( NS_FILE ) ) {
				$jobs[] = new GlobalUsageCachePurgeJob( $ot, array() );
			}
			if ( $nt->inNamespace( NS_FILE ) ) {
				$jobs[] = new GlobalUsageCachePurgeJob( $nt, array() );
			}
			// Push the jobs after DB commit but cancel on rollback
			wfGetDB( DB_MASTER )->onTransactionIdle( function() use ( $jobs ) {
				JobQueueGroup::singleton()->lazyPush( $jobs );
			} );
		}

		return true;
	}

	/**
	 * Hook to ArticleDeleteComplete
	 * Deletes entries from usage table.
	 * @param $article Article
	 * @param $user User
	 * @param $reason string
	 * @param $id int
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
	 * @param $file File
	 * @param $oldimage
	 * @param $article Article
	 * @param $user User
	 * @param $reason string
	 * @return bool
	 */
	public static function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		if ( !$oldimage ) {
			$gu = self::getGlobalUsage();
			$gu->copyLocalImagelinks( $file->getTitle() );

			if ( self::fileUpdatesCreatePurgeJobs() ) {
				$job = new GlobalUsageCachePurgeJob( $file->getTitle(), array() );
				JobQueueGroup::singleton()->push( $job );
			}
		}

		return true;
	}

	/**
	 * Hook to FileUndeleteComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param $title Title
	 * @param $versions
	 * @param $user User
	 * @param $reason string
	 * @return bool
	 */
	public static function onFileUndeleteComplete( $title, $versions, $user, $reason ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksToFile( $title );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $title, array() );
			JobQueueGroup::singleton()->push( $job );
		}

		return true;
	}

	/**
	 * Hook to UploadComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param $upload File
	 * @return bool
	 */
	public static function onUploadComplete( $upload ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksToFile( $upload->getTitle() );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $upload->getTitle(), array() );
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
	 * @param $tables array
	 * @return bool
	 */
	public static function onParserTestTables( &$tables ) {
		$tables[] = 'globalimagelinks';
		return true;
	}

	/**
	 * Hook to apply schema changes
	 *
	 * @param $updater DatabaseUpdater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = dirname( __DIR__ ) . '/patches';

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'globalimagelinks',
				"$dir/GlobalUsage.sql", true ) );
			$updater->addExtensionUpdate( array( 'addIndex', 'globalimagelinks',
				'globalimagelinks_wiki_nsid_title', "$dir/patch-globalimagelinks_wiki_nsid_title.sql", true ) );
		} elseif ( $updater->getDB()->getType() == 'postgresql' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'globalimagelinks',
				"$dir/GlobalUsage.pg.sql", true ) );
			$updater->addExtensionUpdate( array( 'addIndex', 'globalimagelinks',
				'globalimagelinks_wiki_nsid_title', "$dir/patch-globalimagelinks_wiki_nsid_title.pg.sql", true ) );
		}
		return true;
	}

	public static function onwgQueryPages( &$queryPages ) {
		$queryPages[] = array( 'MostGloballyLinkedFilesPage', 'MostGloballyLinkedFiles' );
		$queryPages[] = array( 'SpecialGloballyWantedFiles', 'GloballyWantedFiles' );
		return true;
	}
}
