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
		$title = Title::newFromText( $target, NS_FILE );
		
		$this->setHeaders();
		
		$this->showForm( $title );
		
		if ( is_null( $title ) )
		{
			$wgOut->setPageTitle( wfMsg( 'globalusage' ) );
			return;			
		}
		
		$wgOut->setPageTitle( wfMsg( 'globalusage-for', $title->getPrefixedText() ) );
		
		$this->showResult( $title );
	}
	
	/**
	 * Shows the search form
	 */
	private function showForm( $title ) {
		global $wgScript, $wgOut;
				
		$html = Xml::openElement( 'form', array( 'action' => $wgScript ) ) . "\n";
		$html .= Xml::hidden( 'title', $this->getTitle()->getPrefixedText() ) . "\n";
		$formContent = "\t" . Xml::input( 'target', 40, is_null( $title ) ? '' : $title->getPrefixedText() ) 
			. "\n\t" . Xml::element( 'input', array( 
					'type' => 'submit', 
					'value' => wfMsg( 'globalusage-ok' )
					) ); 
		$html .= Xml::fieldSet( wfMsg( 'globalusage-text' ), $formContent ) . "\n</form>";
		
		$wgOut->addHtml( $html );
	}
	
	/**
	 * Creates as queryer and executes it based on $wgRequest
	 */
	private function showResult( $target ) {
		global $wgRequest;
		
		$query = new GlobalUsageQuery( $target );
		
		// Extract params from $wgRequest
		$query->setContinue( $wgRequest->getInt( 'offset', 0 ) );
		$query->setLimit( $wgRequest->getInt( 'limit', 50 ) );
		
		// Perform query
		$query->execute();
		
		// Show result
		global $wgOut;
		
		$navbar = wfViewPrevNext( $query->getOffset(), $query->getLimit(), $this->getTitle(), 
				'target=' . $target->getPrefixedText(), !$query->hasMore() );
		$targetName = $target->getText();
		
		$wgOut->addHtml( $navbar );
		
		foreach ( $query->getResult() as $wiki => $result ) {
			$wgOut->addHtml( 
					'<h2>' . wfMsgExt( 
						'globalusage-on-wiki', 'parseinline',
						$targetName, $wiki )  
					. "</h2><ul>\n" );
			foreach ( $result as $item )
				$wgOut->addHtml( "\t<li>" . $this->formatItem( $item ) . "</li>\n" );
			$wgOut->addHtml( "</ul>\n" );
		}
		
		$wgOut->addHtml( $navbar );
	}
	/**
	 * Helper to format a specific item
	 * TODO: Make links
	 */
	private function formatItem( $item ) {
		if ( !$item['namespace'] )
			return htmlspecialchars( $item['title'] );
		else
			return htmlspecialchars( "{$item['namespace']}:{$item['title']}" );
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
	 * Executes the query
	 */
	public function execute() {
		$res = $this->db->select( 'globalimagelinks',
				array( 
					'gil_wiki', 
					'gil_page', 
					'gil_page_namespace', 
					'gil_page_title' 
				),
				array(
					'gil_to' => $this->target->getDBkey()
				),
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
				'title' => $row->gil_page_title 
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
