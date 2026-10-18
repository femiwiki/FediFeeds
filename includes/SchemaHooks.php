<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable( 'fedifeeds_follower', "$dir/$type/tables-generated.sql" );
	}
}
