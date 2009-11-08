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
		$query->setContinue( $wgRequest->getInt( 'offset', 0 ) );
		$query->setLimit( $wgRequest->getInt( 'limit', 50 ) );
		$query->filterLocal( $this->filterLocal );
		
		// Perform query
		$query->execute();
		
		// Show result
		global $wgOut;
		
		$navbar = wfViewPrevNext( $query->getOffset(), $query->getLimit(), $this->getTitle(), 
				'target=' . $this->target->getPrefixedText(), !$query->hasMore() );
		$targetName = $this->target->getText();
		
		$wgOut->addHtml( $navbar );
		
		$wgOut->addHtml( '<div id="mw-globalusage-result">' );
		foreach ( $query->getResult() as $wiki => $result ) {
			$wgOut->addHtml( 
					'<h2>' . wfMsgExt( 
						'globalusage-on-wiki', 'parseinline',
						$targetName, $wiki )  
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
		
		$wiki = WikiMap::getWiki( $item['wiki'] );
		
		return WikiMap::makeForeignLink( $item['wiki'], 
				str_replace( '_', ' ', $page ) );
	}
	
	public static function onImagePageAfterImageLinks( $imagePage, &$html ) {
		$title = $imagePage->getFile()->getTitle();
		$targetName = $title->getText();
		
		$query = new GlobalUsageQuery( $title );
		$query->filterLocal();
		$query->execute();
		
		$guHtml = '';
		foreach ( $query->getResult() as $wiki => $result ) {
			$guHtml .= '<li>' . wfMsgExt( 
					'globalusage-on-wiki', 'parseinline',
					$targetName, $wiki ) . "\n<ul>";
			foreach ( $result as $item )
				$guHtml .= "\t<li>" . self::formatItem( $item ) . "</li>\n";
			$guHtml .= "</ul></li>\n";
		}

		if ( $guHtml ) {
			$html .= Html::rawElement( 'h2', array( 'class' => 'mw-globalusage-list' ), wfMsgHtml( 'globalusage' ) ) . "\n" .
				wfMsgExt( 'globalusage-of-file', 'parse' ) .
				Html::rawElement( 'ul', array(), $guHtml ) . "\n";
			if ( $query->hasMore() )
				$html .= wfMsgExt( 'globalusage-more', 'parse', $targetName );
		}

		
		return true;
	}
}



/**
 * A helper class to query the globalimagelinks table
 * 
 * Should maybe simply resort to offset/limit query rather 
 */
class GlobalUsageQuery {
	private $limit = 50;
	private $offset = 0;
	private $hasMore = false;
	private $filterLocal = false;
	private $result;
	
	
	public function __construct( $target ) {
		global $wgGlobalUsageDatabase;
		$this->db = wfGetDB( DB_SLAVE, array(), $wgGlobalUsageDatabase );
		$this->target = $target;

	}
	
	/**
	 * Set the offset parameter
	 * 
	 * @param $offset int offset
	 */
	public function setContinue( $offset ) {
		$this->offset = $offset;
	}
	/**
	 * Return the offset set by the user
	 * 
	 * @return int offset
	 */
	public function getOffset() {
		return $this->offset;
	}
	/**
	 * Set the maximum amount of items to return. Capped at 500.
	 * 
	 * @param $limit int The limit
	 */
	public function setLimit( $limit ) {
		$this->limit = min( $limit, 500 );
	}
	public function getLimit() {
		return $this->limit;
	}
	
	/**
	 * Set whether to filter out the local usage
	 */
	public function filterLocal( $value = true ) {
		$this->filterLocal = $value;
	}
	
	
	/**
	 * Executes the query
	 */
	public function execute() {
		$where = array( 'gil_to' => $this->target->getDBkey() );
		if ( $this->filterLocal )
			$where[] = 'gil_wiki != ' . $this->db->addQuotes( wfWikiId() );
		
		$res = $this->db->select( 'globalimagelinks',
				array( 
					'gil_wiki', 
					'gil_page', 
					'gil_page_namespace', 
					'gil_page_title' 
				),
				$where,
				__METHOD__,
				array( 
					'ORDER BY' => 'gil_wiki, gil_page',
					'LIMIT' => $this->limit + 1,
					'OFFSET' => $this->offset,
				)
		);
		
		$count = 0;
		$this->hasMore = false;
		$this->result = array();
		foreach ( $res as $row ) {
			$count++;
			if ( $count > $this->limit ) {
				$this->hasMore = true;
				break;
			}
			if ( !isset( $this->result[$row->gil_wiki] ) )
				$this->result[$row->gil_wiki] = array();
			$this->result[$row->gil_wiki][] = array( 
				'namespace' => $row->gil_page_namespace, 
				'title' => $row->gil_page_title,
				'wiki' => $row->gil_wiki,
			);	
		}
	}
	public function getResult() {
		return $this->result;
	}
	
	/**
	 * Returns whether there are more results
	 * 
	 * @return bool
	 */
	public function hasMore() {
		return $this->hasMore;
	}
}
