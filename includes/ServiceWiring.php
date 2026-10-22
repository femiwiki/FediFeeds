<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Quotes;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Extension\FediFeeds\RemoteActors;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Extension\FediFeeds\Signatures\SignedFetch;
use MediaWiki\Extension\FediFeeds\Translations;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

/** @phpcs-require-sorted-array */
return [
	'FediFeeds.FeedLookup' => static function ( MediaWikiServices $services ): FeedLookup {
		return new FeedLookup( $services->getPageStore(), $services->getLanguageNameUtils() );
	},
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
				RequestContext::getMain(), $services->getPageProps(), $services->getRepoGroup(),
				$services->getLanguageNameUtils() ),
			$services->getService( 'FediFeeds.Items' ),
			$services->getService( 'FediFeeds.FeedLookup' ),
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
		return new RemoteKeys( $services->getService( 'FediFeeds.SignedFetch' ), $services->getMainWANObjectCache() );
	},
	'FediFeeds.SignedFetch' => static function ( MediaWikiServices $services ): SignedFetch {
		$actors = new Actors( $services->getMainConfig(), $services->getTitleFormatter(),
			RequestContext::getMain(), $services->getPageProps(), $services->getRepoGroup(),
			$services->getLanguageNameUtils() );
		return new SignedFetch( $services->getHttpRequestFactory(), $services->getService( 'FediFeeds.LocalKeyStore' ),
			$actors->getInstanceKeyId() );
	},
	'FediFeeds.Translations' => static function ( MediaWikiServices $services ): Translations {
		return new Translations( ExtensionRegistry::getInstance()->isLoaded( 'Translate' ) );
	},
];
