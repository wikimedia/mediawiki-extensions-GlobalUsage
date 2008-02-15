<?php

class GlobalUsage extends SpecialPage {

	function GlobalUsage() {
		SpecialPage::SpecialPage('GlobalUsage');
		//wfLoadExtensionMessages('GlobalUsage');
	}
	
	static function updateLinks( $linksUpdater ) {
		global $wgUseDumbLinkUpdate;

		if ( $wgUseDumbLinkUpdate ) {
			GlobalUsage::doDumbUpdate( $linksUpdater );
		} else {
			GlobalUsage::doIncrementalUpdate( $linksUpdater );
		}	
		return true;
	}
	
	static function foreignFiles( $pageId, $pageName, $images, $isInsertion ) {
		// Return all foreign files properly formatted
		global $wgLocalInterwiki;
		$result = array( );
		
		foreach ($images as $item) {
			// Is this an insertion or deletion?
			$imageName = is_array($item) ? $item['il_to'] : $item;
			$image = wfFindFile($imageName);
			if (!$image) continue;
			$repo = $image->getRepoName();
			
			if ( $repo != 'local' ) {
				if ( !isset($result[$repo]) )
					$result[$repo] = array( );
				
				$result[$repo][] = $isInsertion ? array(
					"gil_wiki" => $wgLocalInterwiki, 
					"gil_page" => $pageId,
					"gil_pagename" => $pageName,
					"gil_to" => $imageName)
					: $imageName;
			}
		}
		return $result;
	}
	
	static function doIncrementalUpdate( $linksUpdater ) {
		$title = $linksUpdater->getTitle();
		$pageId = $title->getArticleID();
		$existing = $linksUpdater->getExistingImages();
		$deletions = GlobalUsage::foreignFiles( $pageId, $title->getPrefixedText(), 
			$linksUpdater->getImageDeletions( array_keys($existing)), false );
		$insertions = GlobalUsage::foreignFiles( $pageId, $title->getPrefixedText(), 
			$linksUpdater->getImageInsertions($existing), true );
		
		// Don't open repo you don't need and don't open them twice
		$repositories = array_keys($deletions) + array_keys($insertions);
		$updatedRepositories = array ( );
		foreach ($repositories as $repo) {
			if ( in_array( $repo, $updatedRepositories) ) continue;
			
			GlobalUsage::incrementalUpdateForeignRepository( $pageId, $repo,
				isset($deletions[$repo]) ? $deletions[$repo] : array(),
				isset($insertions[$repo]) ? $insertions[$repo] : array()
			);
			
			$updatedRepositories[] =  $repo;
		}
	}
	
	static function incrementalUpdateForeignRepository( $pageId, $repoName, $deletions, $insertions ) {
		global $wgLocalInterwiki;
		
		$repo = RepoGroup::singleton()->getRepoByName($repoName);
		$dbw = $repo->getMasterDB();
		
		$where = array( "gil_wiki" => $wgLocalInterwiki, "gil_page" => $pageId );
		if ( count( $deletions ) ) {
			$where[] = "gil_to IN (" . $dbw->makeList( array_keys( $deletions ) ) . ')';
		} else {
			$where = false;
		}
		
		$dbw->immediateBegin();
		if ( $where ) 
			$dbw->delete( 'globalimagelinks', $where, __METHOD__ );
		if ( count( $insertions ) ) 
			$dbw->insert( 'globalimagelinks', $insertions, __METHOD__, 'IGNORE' );
		$dbw->immediateCommit();
	}
	
	static function doDumbUpdate( $linksUpdater ) {
		$title = $linksUpdater->getTitle(); # TODO: Implement
		$pageId = $title->getArticleID();
		$insertions = GlobalUsage::foreignFiles( $pageId, $title->getPrefixedText(), 
			$linksUpdater->getImageInsertions(), true );
		
		foreach ($insertions as $repo => $insertion)
			GlobalUsage::dumbUpdateForeignRepository( $pageId, $repo, $insertion );

	}
	static function dumbUpdateForeignRepository( $pageId, $repoName, $insertions ) {
		global $wgLocalInterwiki;
		
		$repo = RepoGroup::singleton()->getRepoByName($repoName);
		$dbw = $repo->getMasterDB();
		
		$dbw->immediateBegin();
		$dbw->delete( 'globalimagelinks', array( 
				'gil_wiki' => $wgLocalInterwiki, 
				'gil_page' => $pageId
			), __METHOD__ );
		$dbw->insert( 'globalimagelinks', $insertions, __METHOD__, 'IGNORE' );
		$dbw->immediateCommit();
	}
	
	static function fileDelete( $file, $oldimage, $article, $user, $reason ) {
		global $wgLocalInterwiki, $wgGuHasTable;
		
		if ($oldimage) return true;
	
		$title = $article->getTitle();
		$image = wfFindFile($title->getDBkey());
		if (!$image) return true;
		
		// If the article is an image, check whether the imagelinks will poing to the foreign repository
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( array( 'imagelinks', 'page' ),
			array( 'page_id', 'page_namespace', 'page_title' ),
			array( 'il_to' => $title()->getDBkey(), 'page_id = il_from' ),
			__METHOD__);
					
		$imageLinks = array();
		while ( $row = $dbr->fetchObject ) {
			$imageLinks[] = array(
				"gil_wiki" => $wgLocalInterwiki, 
				"gil_page" => $row['page_id'],
				"gil_pagename" => Title::makeTitle($row['page_namespace'], $row['page_title'])
					->getPrefixedText(),
				"gil_to" => $title->getDBkey());
		}
		$dbr->freeResult($res);
			
		$dbw = $image->repo->getMasterDB();
		$dbw->immediateBegin();
		$dbw->insert( 'globalimagelinks', $imageLinks, __METHOD__, 'IGNORE' );
		$dbw->immediateCommit();
		
		// If this is the shared repository and this is an image, should all links in globalimagelinks be deleted?
		if ($wgGuHasTable)
		{
			$dbw = wfGetDb( DB_MASTER );
			$dbw->delete( 'globalimagelinks', array('gil_to' => $title->getDBkey()), __METHOD__ );
		}
	}
		
	static function articleDelete( &$article, &$user, $reason ) {
		global $wgLocalInterwiki;

		// Remove all links that pointed to this article
		// Probably hits performance quite drastically...
		foreach ( RepoGroup::singleton()->foreignRepos as $repo ) {
			$dbw = $repo->getMasterDB();
			$dbw->immediateBegin();
			$dbw->delete( 'globalimagelinks', array( 
					'gil_wiki' => $wgLocalInterwiki, 
					'gil_page' => $article->getId()
				), __METHOD__ );
			$dbw->immediateCommit();
		}
		
	}
	static function imageUploaded( $uploadForm ) {
		// Delete the links in the globalimagelinks table
		global $wgLocalInterwiki;
		
		$imageName = $uploadForm->mLocalFile->getTitle()->getDBkey();
		
		// In order to not load the shared repository too much, first check whether there are image links to this image
		// Hmmm... Race conditions?
		$dbr = wfGetDb( DB_SLAVE );
		$res = $dbr->select( 'imagelinks', array('1'), array( 'il_to' => $imageName), array('limit' => 1) );
		if ( $dbr->fetchObject($res) ) {
			foreach ( RepoGroup::singleton()->foreignRepos as $repo ) {
				$dbw = $repo->getMasterDB();
				$dbw->immediateBegin();
				$dbw->delete( 'globalimagelinks', array( 
						'gil_wiki' => $wgLocalInterwiki, 
						'gil_to' => $imageName
					), __METHOD__ );
				$dbw->immediateCommit();
			}
		}
		$dbr->freeResult($res);
		return true;		
	}
	
	function execute() {	
		global $wgOut, $wgRequest;
		
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'globalimagelinks',
			array( 'gil_wiki', 'gil_pagename' ),
			// Needs normalizing
			array( 'gil_to' => $wgRequest->getText('image') ),
			__METHOD__ );
			
		// Quick dirty list output
		while ( $row = $dbr->fetchObject($res) )
			$wgOut->addWikiText("* [[:".$row->gil_wiki.":".$row->gil_pagename."]] \n");
		$dbr->freeResult($res);
	}
}

/* TODO: 
* Have idea reviewed by the filerepo guy (Tim Starling?)
* How does the interwiki stuff work?
*/