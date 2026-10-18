<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$type = $updater->getDB()->getType();
		// A new install creates every table from the first file; a wiki that has the key
		// table already gets the follower table from its patch
		$updater->addExtensionTable( 'fedifeeds_key', "$dir/$type/tables-generated.sql" );
		$updater->addExtensionTable( 'fedifeeds_follower', "$dir/$type/patch-fedifeeds_follower.sql" );
	}
}
