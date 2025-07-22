<?php
/**
 * GlobalUsage schema hooks for updating globalimagelinks table.
 */

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * Hook to apply schema changes
	 *
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';

		$type = $updater->getDB()->getType();
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-globalusage',
			'addTable',
			'globalimagelinks',
			"$dir/$type/tables-generated.sql",
			true
		] );

		if ( $type === 'mysql' || $type === 'sqlite' ) {
			// 1.35
			$updater->addExtensionUpdateOnVirtualDomain( [
				'virtual-globalusage',
				'dropExtensionIndex',
				'globalimagelinks',
				'globalimagelinks_to_wiki_page',
				"$dir/patch-globalimagelinks-pk.sql",
				true
			] );
		}
	}

}
