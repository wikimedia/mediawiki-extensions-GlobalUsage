<?php
/**
 * GlobalUsage schema hooks for updating globalimagelinks table.
 */

namespace MediaWiki\Extension\GlobalUsage;

use DatabaseUpdater;
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
		$updater->addExtensionTable( 'globalimagelinks', "$dir/$type/tables-generated.sql" );

		if ( $type === 'mysql' || $type === 'sqlite' ) {
			// 1.35
			$updater->dropExtensionIndex(
				'globalimagelinks',
				'globalimagelinks_to_wiki_page',
				"$dir/patch-globalimagelinks-pk.sql"
			);
		}
	}

}
