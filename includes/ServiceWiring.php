<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
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
];
