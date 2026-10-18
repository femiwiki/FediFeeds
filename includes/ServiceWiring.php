<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'FediFeeds.LocalKeyStore' => static function ( MediaWikiServices $services ): LocalKeyStore {
		return new LocalKeyStore( $services->getConnectionProvider() );
	},
	'FediFeeds.Notes' => static function ( MediaWikiServices $services ): Notes {
		return new Notes(
			new Actors( $services->getMainConfig(), $services->getTitleFormatter(),
				RequestContext::getMain(), $services->getPageProps() ),
			$services->getConnectionProvider(),
			$services->getPageStore(),
			$services->getPageProps(),
			$services->getRevisionLookup(),
			$services->getParserOutputAccess(),
			$services->getTitleFormatter(),
		);
	},
	'FediFeeds.RemoteKeys' => static function ( MediaWikiServices $services ): RemoteKeys {
		return new RemoteKeys( $services->getHttpRequestFactory(), $services->getMainWANObjectCache() );
	},
];
