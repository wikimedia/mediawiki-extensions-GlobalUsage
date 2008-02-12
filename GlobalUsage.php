<?php

# Alert the user that this is not a valid entry point to MediaWiki if they try to access the extension file directly.
if (!defined('MEDIAWIKI')) {
        echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/GlobalUsage/GlobalUsage.php" );
EOT;
        exit( 1 );
}

$dir = dirname(__FILE__) . '/';

$wgExtensionCredits['specialpage'][] = array(
	'name' => 'Global Usage',
	'author' => 'Bryan Tong Minh',
	'description' => 'Special page to view global image usage',
	'url' => 'http://www.mediawiki.org/wiki/Extension:GlobalUsage',
	'version' => '1.0',
);

$wgAutoloadClasses['GlobalUsage'] = $dir . 'GlobalUsage_body.php';
//$wgExtensionMessageFiles['GlobalUsage'] = $dir . 'GlobalUsage.i18n.php';
$wgSpecialPages['GlobalUsage'] = 'GlobalUsage';
$wgHooks['LinksUpdate'][] = 'GlobalUsage::updateLinks';
$wgHooks['ArticleDeleteComplete'][] = 'GlobalUsage::articleDelete';
$wgHooks['UploadComplete'][] = 'GlobalUsage::imageUploaded';
// This wiki does not have a globalimagelinks table
$wgGuHasTable = false;

