<?php

class GlobalUsage {
	private $interwiki;
	private $db;

	/**
	 * Construct a GlobalUsage instance for a certain wiki.
	 * 
	 * @param $interwiki string Interwiki prefix of the wiki
	 * @param $db mixed Database object
	 */
	public function __construct( $interwiki, $db ) {
		$this->interwiki = $interwiki;
		$this->db = $db;
	}
	
	/**
	 * Sets the images used by a certain page
	 * 
	 * @param $title Title Title of the page
	 * @param $images array Array of db keys of images used
	 */
	public function setUsage( $title, $images ) {
		$insert = array();
		foreach ( $images as $name ) {
			$insert[] = array(
				'gil_wiki' => $this->interwiki,
				'gil_page' => $title->getArticleID( GAID_FOR_UPDATE ),
				'gil_page_namespace' => $title->getNsText(),
				'gil_page_title' => $title->getText(),
				'gil_to' => $name
			);
		}
		$this->db->insert( 'globalimagelinks', $insert, __METHOD__ );
	}
	/**
	 * Deletes all entries from a certain page
	 * 
	 * @param $id int Page id of the page
	 */
	public function deleteFrom( $id ) {
		$this->db->delete(
				'globalimagelinks',
				array(
					'gil_wiki' => $this->interwiki,
					'gil_page' => $id
				),
				__METHOD__
		);
	}
	/**
	 * Deletes all entries to a certain image
	 * 
	 * @param $title Title Title of the file
	 */
	public function deleteTo( $title ) {
		$this->db->delete(
				'globalimagelinks',
				array(
					'gil_wiki' => $this->interwiki,
					'gil_to' => $title->getDBkey()
				),
				__METHOD__
		);		
	}
	
	/**
	 * Copy local links to global table
	 * 
	 * @param $title Title Title of the file to copy entries from.
	 */
	public function copyFromLocal( $title ) {
		global $wgContLang;
		
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 
				array( 'imagelinks', 'page' ), 
				array( 'il_to', 'page_id', 'page_namespace', 'page_title' ),
				array( 'il_from = page_id', 'il_to' => $title->getDBkey() ),
				__METHOD__
		);
		$insert = array();
		foreach ( $res as $row ) {
			$insert[] = array(
				'gil_wiki' => $this->interwiki,
				'gil_page' => $row->page_id,
				'gil_page_namespace' => $wgContLang->getNsText( $row->page_namespace ),
				'gil_page_title' => $row->page_title,
				'gil_to' => $row->il_to,
			);
		}
		$this->db->insert( 'globalimagelinks', $insert, __METHOD__ );
	}
	
	/**
	 * Changes the page title
	 * 
	 * @param $id int Page id of the page
	 * @param $title Title New title of the page
	 */
	public function moveTo( $id, $title ) {
		$this->db->update(
				'globalimagelinks',
				array( 
					'gil_page_namespace' => $title->getNsText(),
					'gil_page_title' => $title->getText()
				),
				array(
					'gil_wiki' => $this->interwiki,
					'gil_page' => $id
				),
				__METHOD__
		);
	}
	
	
	


}
