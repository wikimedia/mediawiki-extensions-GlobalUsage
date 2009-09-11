<?php
/**
 * Crappy ui towards globalimagelinks
 */

class SpecialGlobalUsage extends SpecialPage {
	public function __construct() {
		parent::__construct( 'GlobalUsage', 'globalusage' );
		
		wfLoadExtensionMessages( 'globalusage' );
	} 
	
	public function execute( $par ) {
		global $wgOut, $wgRequest;
		
		$target = $par ? $par : $wgRequest->getVal( 'target' );
		$title = Title::newFromText( $target, NS_FILE );
		
		$this->setHeaders();
		
		if ( is_null( $title ) )
		{
			$wgOut->setPageTitle( wfMsg( 'globalusage' ) );
			return;			
		}
		
		$wgOut->setPageTitle( wfMsg( 'globalusage-for', $title->getPrefixedText() ) );
		
		$pager = new GlobalUsagePager( $title );
		
		$wgOut->addHTML(
			'<p>' . $pager->getNavigationBar() . '</p>' .
			'<ul>' . $pager->getBody() . '</ul>' .
			'<p>' . $pager->getNavigationBar() . '</p>' );
	}
}

/**
 * Pager for globalimagelinks.
 */
class GlobalUsagePager extends IndexPager {
	public function __construct( $title = null ) {
		// Initialize parent first
		parent::__construct();
		
		$this->title = $title;
		
		// Override the DB
		global $wgGlobalUsageDatabase;
		$this->mDb = wfGetDB( DB_SLAVE, array(), $wgGlobalUsageDatabase );
	}
	public function formatRow( $row ) {
		return '<li>'
				. htmlspecialchars( $row->gil_wiki ) . ':'
				. htmlspecialchars( $row->gil_page_namespace ) . ':'
				. htmlspecialchars( $row->gil_page_title ) 
				. "</li>\n";
	}
	public function getQueryInfo() {
		$info = array(
				'tables' => array( 'globalimagelinks' ),
				'fields' => array( 
						'gil_wiki', 
						'gil_page_namespace', 
						'gil_page_title', 
				),	
		);
		if ( !is_null( $this->title ) && $this->title->getNamespace() == NS_FILE ) {
			$info['conds'] = array( 'gil_to' => $this->title->getDBkey() );
		}
		return $info;
	}
	public function getIndexField() {
		// FIXME: This is non-unique! Needs a hack in IndexPager to sort on (wiki, page)
		return 'gil_wiki';
	}

	
	public function getNavigationBar() {
		global $wgLang;

		if ( isset( $this->mNavigationBar ) ) {
			return $this->mNavigationBar;
		}
		$fmtLimit = $wgLang->formatNum( $this->mLimit );
		$linkTexts = array(
			'prev' => wfMsgExt( 'whatlinkshere-prev', array( 'escape', 'parsemag' ), $fmtLimit ),
			'next' => wfMsgExt( 'whatlinkshere-next', array( 'escape', 'parsemag' ), $fmtLimit ),
			'first' => wfMsgHtml( 'page_first' ),
			'last' => wfMsgHtml( 'page_last' )
		);

		$pagingLinks = $this->getPagingLinks( $linkTexts );
		$limitLinks = $this->getLimitLinks();
		$limits = $wgLang->pipeList( $limitLinks );

		$this->mNavigationBar = "(" . $wgLang->pipeList( array( $pagingLinks['first'], $pagingLinks['last'] ) ) . ") " .
			wfMsgExt( 'viewprevnext', array( 'parsemag', 'escape', 'replaceafter' ), $pagingLinks['prev'], $pagingLinks['next'], $limits );
		return $this->mNavigationBar;
	}
}