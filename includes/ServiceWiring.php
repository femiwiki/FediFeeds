<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Quotes;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Extension\FediFeeds\RemoteActors;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'FediFeeds.Followers' => static function ( MediaWikiServices $services ): Followers {
		return new Followers( $services->getConnectionProvider() );
	},
	'FediFeeds.Items' => static function ( MediaWikiServices $services ): Items {
		return new Items( $services->getConnectionProvider() );
	},
	'FediFeeds.LocalKeyStore' => static function ( MediaWikiServices $services ): LocalKeyStore {
		return new LocalKeyStore( $services->getConnectionProvider() );
	},
	'FediFeeds.Notes' => static function ( MediaWikiServices $services ): Notes {
		return new Notes(
			new Actors( $services->getMainConfig(), $services->getTitleFormatter(),
				RequestContext::getMain(), $services->getPageProps(), $services->getRepoGroup() ),
			$services->getService( 'FediFeeds.Items' ),
			$services->getPageStore(),
			$services->getPageProps(),
			$services->getRevisionLookup(),
			$services->getParserOutputAccess(),
			$services->getTitleFormatter(),
			(bool)$services->getMainConfig()->get( 'FediFeedsInteractions' ),
		);
	},
	'FediFeeds.Quotes' => static function ( MediaWikiServices $services ): Quotes {
		return new Quotes( $services->getConnectionProvider() );
	},
	'FediFeeds.Reactions' => static function ( MediaWikiServices $services ): Reactions {
		return new Reactions( $services->getConnectionProvider() );
	},
	'FediFeeds.RemoteActors' => static function ( MediaWikiServices $services ): RemoteActors {
		return new RemoteActors( $services->getConnectionProvider() );
	},
	'FediFeeds.RemoteKeys' => static function ( MediaWikiServices $services ): RemoteKeys {
		return new RemoteKeys( $services->getHttpRequestFactory(), $services->getMainWANObjectCache() );
	},
];
