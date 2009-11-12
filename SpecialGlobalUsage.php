<?php
/**
 * Crappy ui towards globalimagelinks
 */

class SpecialGlobalUsage extends SpecialPage {
	public function __construct() {
		parent::__construct( 'GlobalUsage', 'globalusage' );

		wfLoadExtensionMessages( 'globalusage' );
	} 

	/**
	 * Entry point
	 */
	public function execute( $par ) {
		global $wgOut, $wgRequest;

		$target = $par ? $par : $wgRequest->getVal( 'target' );
		$this->target = Title::newFromText( $target, NS_FILE );

		$this->filterLocal = $wgRequest->getCheck( 'filterlocal' );

		$this->setHeaders();

		$this->showForm();

		if ( is_null( $this->target ) )
		{
			$wgOut->setPageTitle( wfMsg( 'globalusage' ) );
			return;
		}

		$wgOut->setPageTitle( wfMsg( 'globalusage-for', $this->target->getPrefixedText() ) );

		$this->showResult();
	}
	
	/**
	 * Shows the search form
	 */
	private function showForm() {
		global $wgScript, $wgOut;

		$html = Xml::openElement( 'form', array( 'action' => $wgScript ) ) . "\n";
		$html .= Xml::hidden( 'title', $this->getTitle()->getPrefixedText() ) . "\n";
		$formContent = "\t" . Xml::input( 'target', 40, is_null( $this->target ) ? ''
					: $this->target->getPrefixedText() )
			. "\n\t" . Xml::element( 'input', array(
					'type' => 'submit',
					'value' => wfMsg( 'globalusage-ok' )
					) )
			. "\n\t<p>" . Xml::checkLabel( wfMsg( 'globalusage-filterlocal' ),
					'filterlocal', 'mw-filterlocal', $this->filterLocal ) . '</p>';
		if ( !is_null( $this->target ) && wfFindFile( $this->target ) ) {
			global $wgUser, $wgContLang;
			$skin = $wgUser->getSkin();

			$html .= $skin->makeImageLinkObj( $this->target,
					$this->target->getPrefixedText(),
					/* $alt */ '', /* $align */ $wgContLang->alignEnd(),
					/* $handlerParams */ array(), /* $framed */ false,
					/* $thumb */ true );
		}
		$html .= Xml::fieldSet( wfMsg( 'globalusage-text' ), $formContent ) . "\n</form>";

		$wgOut->addHtml( $html );
	}

	/**
	 * Creates as queryer and executes it based on $wgRequest
	 */
	private function showResult() {
		global $wgRequest;

		$query = new GlobalUsageQuery( $this->target );

		// Extract params from $wgRequest
		$query->setOffset( $wgRequest->getText( 'offset' ) );
		$query->setLimit( $wgRequest->getInt( 'limit', 50 ) );
		$query->filterLocal( $this->filterLocal );

		// Perform query
		$query->execute();

		// Show result
		global $wgOut;

		// Don't show form element if there is no data
		if ( $query->count() == 0 ) {
			$wgOut->addWikiMsg( 'globalusage-no-results', $this->target->getPrefixedText() );
			return;
		}

		$offset = $query->getOffsetString();
		$navbar = $this->getNavBar( $query );
		$targetName = $this->target->getText();

		$wgOut->addHtml( $navbar );

		$wgOut->addHtml( '<div id="mw-globalusage-result">' );
		foreach ( $query->getSingleImageResult() as $wiki => $result ) {
			$wgOut->addHtml(
					'<h2>' . wfMsgExt(
						'globalusage-on-wiki', 'parseinline',
						$targetName, WikiMap::getWikiName( $wiki ) )
					. "</h2><ul>\n" );
			foreach ( $result as $item )
				$wgOut->addHtml( "\t<li>" . self::formatItem( $item ) . "</li>\n" );
			$wgOut->addHtml( "</ul>\n" );
		}
		$wgOut->addHtml( '</div>' );

		$wgOut->addHtml( $navbar );
	}
	/**
	 * Helper to format a specific item
	 * TODO: Make links
	 */
	private static function formatItem( $item ) {
		if ( !$item['namespace'] )
			$page = $item['title'];
		else
			$page = "{$item['namespace']}:{$item['title']}";

		return WikiMap::makeForeignLink( $item['wiki'], $page,
				str_replace( '_', ' ', $page ) );
	}

	public static function onImagePageAfterImageLinks( $imagePage, &$html ) {
		$title = $imagePage->getFile()->getTitle();
		$targetName = $title->getText();

		$query = new GlobalUsageQuery( $title );
		$query->filterLocal();
		$query->execute();

		$guHtml = '';
		foreach ( $query->getSingleImageResult() as $wiki => $result ) {
			$guHtml .= '<li>' . wfMsgExt( 
					'globalusage-on-wiki', 'parseinline',
					$targetName, WikiMap::getWikiName( $wiki ) ) . "\n<ul>";
			foreach ( $result as $item )
				$guHtml .= "\t<li>" . self::formatItem( $item ) . "</li>\n";
			$guHtml .= "</ul></li>\n";
		}

		if ( $guHtml ) {
			$html .= '<h2 id="globalusage">' . wfMsgHtml( 'globalusage' ) . "</h2>\n"
				. wfMsgExt( 'globalusage-of-file', 'parse' )
				. "<ul>\n" . $guHtml . "</ul>\n";
			if ( $query->hasMore() )
				$html .= wfMsgExt( 'globalusage-more', 'parse', $targetName );
		}


		return true;
	}

	public static function onImagePageShowTOC( $imagePage, &$toc ) {
		$toc[] = '<li><a href="#globalusage">' . wfMsgHtml( 'globalusage' ) . '</a></li>';
		return true;
	}

	protected function getNavBar( $query ) {
		global $wgLang;

		$target = $this->target->getPrefixedText();
		$limit = $query->getLimit();
		$fmtLimit = $wgLang->formatNum( $limit );
		$offset = $query->getOffsetString();
		if ( $offset == '||' )
			$offset = '';

		# Get prev/next link display text
		$prev =  wfMsgExt( 'prevn', array('parsemag','escape'), $fmtLimit );
		$next =  wfMsgExt( 'nextn', array('parsemag','escape'), $fmtLimit );
		# Get prev/next link title text
		$pTitle = wfMsgExt( 'prevn-title', array('parsemag','escape'), $fmtLimit );
		$nTitle = wfMsgExt( 'nextn-title', array('parsemag','escape'), $fmtLimit );

		# Fetch the title object
		$title = $this->getTitle();

		# Make 'previous' link
		if ( $offset ) {
			$q = array( 'limit' => $limit, 'offset' => $offset, 'target' => $target );
			$plink = '<a href="' . $title->escapeLocalUrl( $q ) . "\" title=\"{$pTitle}\" class=\"mw-prevlink\">{$prev}</a>";
		} else { 
			$plink = $prev;
		}

		# Make 'next' link
		if ( $query->hasMore() ) {
			$q = array( 'limit' => $limit, 'offset' => $query->getContinueString(), 'target' => $target );
			$nlink = '<a href="' . $title->escapeLocalUrl( $q ) . "\" title=\"{$nTitle}\" class=\"mw-nextlink\">{$next}</a>";
		} else {
			$nlink = $next;
		}

		# Make links to set number of items per page
		$numLinks = array();
		foreach ( array( 20, 50, 100, 250, 500 ) as $num ) {
			$fmtLimit = $wgLang->formatNum( $num );
			$q = array( 'offset' => $offset, 'limit' => $num, 'target' => $target );
			$lTitle = wfMsgExt( 'shown-title', array( 'parsemag', 'escape' ), $num );
			$numLinks[] = '<a href="' . $title->escapeLocalUrl( $q ) . "\" title=\"{$lTitle}\" class=\"mw-numlink\">{$fmtLimit}</a>";
		}
		$nums = $wgLang->pipeList( $numLinks );

		return wfMsgHtml( 'viewprevnext', $plink, $nlink, $nums );
	}
}

