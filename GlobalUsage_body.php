<?php

class GlobalUsage extends SpecialPage {
	private static $database = array();

	function __construct() {
		parent::__construct('GlobalUsage');
		wfLoadExtensionMessages( 'GlobalUsage' );
	}
	
	static function getDatabase( $dbFlags = DB_MASTER ) {
		global $wgguIsMaster, $wgguMasterDatabase;
		if ( isset( GlobalUsage::$database[$dbFlags] ) )
			return GlobalUsage::$database[$dbFlags];
		
		if ( $wgguIsMaster ) {
			GlobalUsage::$database[$dbFlags] = wfGetDB( $dbFlags );
		} else {
			if ( is_array( $wgguMasterDatabase ) )
				$repo = new ForeignDBRepo( $wgguMasterDatabase );
			else if ( is_int( $wgguMasterDatabase ) )
				$repo = RepoGroup::singleton()->getRepo( $wgguMasterDatabase );
			else 
				$repo = RepoGroup::singleton()->getRepoByName( $wgguMasterDatabase );
			
			if ( $dbFlags == DB_MASTER )
				GlobalUsage::$database[DB_MASTER] = $repo->getMasterDB();
			else
				GlobalUsage::$database[DB_SLAVE] = $repo->getSlaveDB();
		}
		return GlobalUsage::$database[$dbFlags];
	}
	static function getLocalInterwiki() {
		global $wgLocalInterwiki;
		return $wgLocalInterwiki;
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
	
	static function foreignFiles( $pageId, $pageNamespace, $pageTitle, $images ) {
		// Return all foreign files properly formatted
		$result = array( );
		
		foreach ($images as $item) {
			// Is this an insertion or deletion?
			$imageName = is_array($item) ? $item['il_to'] : $item;

            		$image = wfFindFile($imageName);
            		if (!$image)
				$isLocal = false;
			else if ( $image->getRepoName() == 'local' )
				$isLocal = true;
			else
				$isLocal = false;
			
			$result[] = array(
				"gil_wiki" => GlobalUsage::getLocalInterwiki(), 
				"gil_page" => $pageId,
				"gil_page_namespace" => $pageNamespace,
				"gil_page_title" => $pageTitle,
				"gil_to" => $imageName,
				"gil_is_local" => $isLocal);
		}
		return $result;
	}
	
	static function doIncrementalUpdate( $linksUpdater ) {
		$title = $linksUpdater->getTitle();
		$pageId = $title->getArticleID();
		
		$existing = $linksUpdater->getExistingImages();
		$deletions = array_keys($linksUpdater->getImageDeletions( array_keys($existing) ));
		$insertions = GlobalUsage::foreignFiles( $pageId, $title->getNsText(), $title->getDBkey(), 
			$linksUpdater->getImageInsertions( $existing ), true );

		$dbw = GlobalUsage::getDatabase();
		
		$where = array( "gil_wiki" => GlobalUsage::getLocalInterwiki(), "gil_page" => $pageId );
		if ( count( $deletions ) ) {
			$where[] = "gil_to IN (" . $dbw->makeList( $deletions ) . ')';
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
		$title = $linksUpdater->getTitle();
		$pageId = $title->getArticleID();
		$insertions = GlobalUsage::foreignFiles( $pageId, $title->getNsText(), $title->getDBkey(), 
			$linksUpdater->getImageInsertions(), true );
		
		$dbw = GlobalUsage::getDatabase();
		$dbw->immediateBegin();
		$dbw->delete( 'globalimagelinks', array( 
				'gil_wiki' => GlobalUsage::getLocalInterwiki(), 
				'gil_page' => $pageId
			), __METHOD__ );
		$dbw->insert( 'globalimagelinks', $insertions, __METHOD__, 'IGNORE' );
		$dbw->immediateCommit();
	}
	
	// Set gil_is_local for an image
	static function setLocalFlag( $imageName, $isLocal ) {
		$dbw = GlobalUsage::getDatabase();
		$dbw->immediateBegin();
		$dbw->update( 'globalimagelinks', array( 'gil_is_local' => $isLocal ), array(
				'gil_wiki' => GlobalUsage::getLocalInterwiki(),
				'gil_to' => $imageName),
			__METHOD__ );
		$dbw->immediateCommit();
	}
	
	// Set gil_is_local to false
	static function fileDeleted( &$file, &$oldimage, &$article, &$user, $reason ) {
		if ( !$oldimage ) 
			GlobalUsage::setLocalFlag( $article->getTitle()->getDBkey(), 0 );
		return true;
	}
	// Set gil_is_local to true
	static function fileUndeleted( &$title, $versions, &$user, $reason ) {
		GlobalUsage::setLocalFlag( $title->getDBkey(), 1 );
		return true;
	}
	static function imageUploaded( $uploadForm ) {
		$imageName = $uploadForm->mLocalFile->getTitle()->getDBkey();		
		GlobalUsage::setLocalFlag( $imageName, 1 );
		return true;		
	}	
	
		
	static function articleDeleted( &$article, &$user, $reason ) {
		$dbw = GlobalUsage::getDatabase();
		$dbw->immediateBegin();
		$dbw->delete( 'globalimagelinks', array(
				'gil_wiki' => GlobalUsage::getLocalInterwiki(),
				// Use GAID_FOR_UPDATE to make sure the old id is fetched from 
				// the link cache
				'gil_page' => $article->getTitle()->getArticleId(GAID_FOR_UPDATE)),
			__METHOD__ );
		$dbw->immediateCommit();
		
		return true;
	}
	
	static function articleMoved( &$movePageForm, &$from, &$to ) {
		$dbw = GlobalUsage::getDatabase();
		$dbw->immediateBegin();
		$dbw->update( 'globalimagelinks', array(
				'gil_page_namespace' => $to->getNsText(),
				'gil_page_title' => $to->getDBkey()
			), array(
				'gil_wiki' => GlobalUsage::getLocalInterwiki(),
				'gil_page' => $to->getArticleId()
			), __METHOD__ );
		$dbw->immediateCommit();
		
		return true;
	}

	public function execute( $par ) {	
		global $wgOut, $wgScript, $wgRequest;
		
		$this->setHeaders();
		
		$self = Title::makeTitle( NS_SPECIAL, 'GlobalUsage' );
		$target= Title::makeTitleSafe( NS_IMAGE, $wgRequest->getText( 'target', $par ) );
		
		$wgOut->addWikiText( wfMsg( 'globalusage-text' ) );
		
		$form = Xml::openElement( 'form', array( 
			'id' => 'mw-globalusage-form',
			'method' => 'get', 
			'action' => $wgScript ));
		$form .= Xml::hidden( 'title', $self->getPrefixedDbKey() );
		$form .= Xml::openElement( 'fieldset' );
		$form .= Xml::element( 'legend', array(), wfMsg( 'globalusage' ));
		$form .= Xml::inputLabel( wfMsg( 'filename' ), 'target', 
			'target', 50, $target->getDBkey() );
		$form .= Xml::submitButton( wfMsg( 'globalusage-ok' ) );
		$form .= Xml::closeElement( 'fieldset' );
		$form .= Xml::closeElement( 'form' );
		
		$wgOut->addHtml( $form );
		
		if ( !$target->getDBkey() ) return;
		
		$dbr = GlobalUsage::getDatabase( DB_SLAVE );
		$res = $dbr->select( 'globalimagelinks',
			array( 'gil_wiki', 'gil_page_namespace', 'gil_page_title' ),
			array( 'gil_to' => $target->getDBkey(), 'gil_is_local' => 0 ),
			__METHOD__ );
			
		// Quick dirty list output
		while ( $row = $dbr->fetchObject($res) )
			$wgOut->addWikiText(GlobalUsage::formatItem( $row ) );
		$dbr->freeResult($res);
	}
	
	public static function formatItem( $row ) {
		$out = '* [[';
		if ( GlobalUsage::getLocalInterwiki() != $row->gil_wiki )
			$out .= ':'.$row->gil_wiki;
		if ( $row->gil_page_namespace )
			$out .= ':'.str_replace('_', ' ', $row->gil_page_namespace);
		$out .= ':'.str_replace('_', ' ', $row->gil_page_title)."]]\n";
		return $out;
	}
}
