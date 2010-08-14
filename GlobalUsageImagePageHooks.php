<?php

class GlobalUsageImagePageHooks {
	
	/**
	 * Show a global usage link on the image page
	 *
	 * @param object $imagePage The ImagePage
	 * @param string $html HTML to add to the image page as the link in image links section
	 * @return bool
	 */
	public static function onImagePageAfterImageLinks( $imagePage, &$html ) {
		
		$targetName = $imagePage->getFile()->getTitle()->getText();
		
		$html .= '<p id="mw-imagepage-section-globalusage">';
		$html .= wfMsgExt( 'globalusage-more', 'parse', $targetName );
		$html .= '</p>';
		
		return true;
	}
	
}
