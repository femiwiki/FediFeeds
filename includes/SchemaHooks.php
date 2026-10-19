<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$type = $updater->getDB()->getType();
		// A new install creates every table from the first file; an older wiki gets each later
		// table from its patch
		$updater->addExtensionTable( 'fedifeeds_key', "$dir/$type/tables-generated.sql" );
		$updater->addExtensionTable( 'fedifeeds_follower', "$dir/$type/patch-fedifeeds_follower.sql" );
		$updater->addExtensionTable( 'fedifeeds_item', "$dir/$type/patch-fedifeeds_item.sql" );
		// A page holds several items once sections are items
		$updater->addExtensionField( 'fedifeeds_item', 'fi_key', "$dir/$type/patch-fedifeeds_item-fi_key.sql" );
		$updater->addExtensionTable( 'fedifeeds_quote', "$dir/$type/patch-fedifeeds_quote.sql" );
		$updater->addExtensionTable( 'fedifeeds_reaction', "$dir/$type/patch-fedifeeds_reaction.sql" );
	}
}
