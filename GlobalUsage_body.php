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
		global $wgOut;

		$title = $linksUpdater->getTitle();
		$pageId = $title->getArticleID();
		$existing = $linksUpdater->getExistingImages();
		$deletions = GlobalUsage::foreignFiles( $pageId, $title->getPrefixedText(), 
			$linksUpdater->getImageDeletions( array_keys($existing)), false );
		$insertions = GlobalUsage::foreignFiles( $pageId, $title->getPrefixedText(), 
			$linksUpdater->getImageInsertions($existing), true );
		
		// Don't open repos you don't need and don't open them twice
		$repositories = array_keys($deletions) + array_keys($insertions);
		$updatedRepositories = array ( );
		foreach ($repositories as $repos) {
			if ( in_array( $repos, $updatedRepositories) ) continue;
			
			GlobalUsage::incrementalUpdateForeignRepository( $pageId, $repos,
				isset($deletions[$repos]) ? $deletions[$repos] : array(),
				isset($insertions[$repos]) ? $insertions[$repos] : array()
			);
			
			$updatedRepositories[] =  $repos;
		}
	}
	
	static function incrementalUpdateForeignRepository( $pageId, $reposName, $deletions, $insertions ) {
		global $wgLocalInterwiki;
		$fname = 'GlobalUsage::incrementalUpdateForeignRepository';
		
		$repos = RepoGroup::singleton()->getRepoByName($reposName);
		$dbw =& $repos->getMasterDB();
		
		$where = array( "gil_wiki" => $wgLocalInterwiki, "gil_page" => $pageId );
		if ( count( $deletions ) ) {
			$where[] = "gil_to IN (" . $dbw->makeList( array_keys( $deletions ) ) . ')';
		} else {
			$where = false;
		}
		
		$dbw->immediateBegin();
		if ( $where ) 
			$dbw->delete( 'globalimagelinks', $where, $fname );
		if ( count( $insertions ) ) 
			$dbw->insert( 'globalimagelinks', $insertions, $fname, 'IGNORE' );
		$dbw->immediateCommit();
	}
	
	static function dumbTableUpdate( $linksUpdater ) {
		global $wgLocalInterwiki;

		$fname = 'GlobalUsage::dumbTableUpdate';

		$title = $linksUpdater->getTitle(); # TODO: Implement
		$pageId = $title->getArticleID();

		$existing = $linksUpdater->getExistingImages();
		// $insertions = GlobalUsage::foreignFiles($linksUpdater->getImageInsertions( $existing ));
		
		// This is probably even wrong in the original LinksUpdate code. Needs fixing there first.
		/*$this->mDb->delete( 'globalimagelinks', array( 'gil_wiki' => $wgLocalInterwiki, 'gil_page' => $this->mId ), $fname );
		if ( count( $insertions ) ) {
			# The link array was constructed without FOR UPDATE, so there may be collisions
			# This may cause minor link table inconsistencies, which is better than
			# crippling the site with lock contention.
			$this->mDb->insert( $table, $insertions, $fname, array( 'IGNORE' ) );
		}*/
	}
	
	static function articleDeleted( &$article, &$user, $reason ) {
		global $wgLocalInterwiki;
		$fname = 'GlobalUsage::articleDeleted';
		
		$title = $article->getTitle();
		// If the article is an image, check whether the imagelinks will poing to the foreign repository
		if ( $title->getNamespace() == NS_IMAGE ) {
			$image = wfFindFile($title->getDBkey());
			if ($image) {
				$dbr =& wfGetDB( DB_SLAVE );
				$res = $dbr->select( array( 'imagelinks', 'page' ),
					array( 'page_id', 'page_namespace', 'page_title' ),
					array( 'il_to' => $title()->getDBkey(), 'page_id = il_from' ),
					$fname);
					
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
			
				$dbw =& $image->repo->getMasterDB();
				$dbw->immediateBegin();
				$dbw->insert( 'globalimagelinks', $imageLinks, $fname, 'IGNORE' );
				$dbw->immediateCommit();
			}
		}
		
		// Remove all links that pointed to this article
		foreach ( RepoGroup::singleton()->foreignRepos as $repo ) {
			$dbw =& $repo->getMasterDB();
			$dbw->immediateBegin();
			$dbw->delete( 'globalimagelinks', array( 
					'gil_wiki' => $wgLocalInterwiki, 
					'gil_page' => $article->getId()
				), $fname );
			$dbw->immediateCommit();
		}
		
		// If this is the shared repository and this is an image, should all links in globalimagelinks be deleted?
	}
	static function imageUploaded( $image ) {
		// Delete the links in the globalimagelinks table
	}
	
	function execute() {	
		global $wgOut, $wgRequest;
		$fname = 'GlobalUsage::execute';
		
		$dbr =& wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'globalimagelinks',
			array( 'gil_wiki', 'gil_pagename' ),
			// Needs normalizing
			array( 'gil_to' => $wgRequest->getText('image') ),
			$fname );
			
		// Quick dirty list output
		while ( $row = $dbr->fetchObject($res) )
			$wgOut->addWikiText("* [[:".$row->gil_wiki.":".$row->gil_pagename."]] \n");
		$dbr->freeResult($res);
		
	}

}

/* TODO: 
* Hook ImageUpload
* Hook ArticleDelete
* Make the code working
* Have idea reviewed by the filerepo guy (Tim Starling?)
* How does the interwiki stuff work?
*/