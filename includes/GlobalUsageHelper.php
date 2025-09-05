<?php

namespace MediaWiki\Extension\GlobalUsage;

class GlobalUsageHelper {

	/**
	 *
	 * @var array
	 */
	private static $wikis = [];

	public static function getWikis() {
		if ( count( self::$wikis ) !== 0 ) {
			return self::$wikis;
		}
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
		global $liquipedia_wikis;
		foreach ( $liquipedia_wikis as $wiki => $data ) {
			self::$wikis[ $data[ 'db_prefix' ] ] = $data[ 'game' ];
		}
		return self::$wikis;
	}

	public static function getWikiName( $wikiId ) {
		if ( count( self::$wikis ) === 0 ) {
			self::getWikis();
		}
		return array_key_exists( $wikiId, self::$wikis ) ? self::$wikis[ $wikiId ] : $wikiId;
	}
}
