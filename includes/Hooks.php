<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\Sanitizer;

/**
 * Registers {{FEDIFEEDSNAME:}}, which sets the display name of a page's feeds
 */
class Hooks implements ParserFirstCallInitHook {

	/** The page property holding the display name for every kind, or with "-<kind>" for one */
	public const NAME_PROPERTY = 'fedifeeds-name';

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		// Written {{FEDIFEEDSNAME:...}} like {{DISPLAYTITLE:...}}, without a hash
		$parser->setFunctionHook( 'fedifeedsname', [ self::class, 'renderName' ], Parser::SFH_NO_HASH );
	}

	/**
	 * Stores the name as a page property and outputs nothing.
	 *
	 * @param Parser $parser
	 * @param string $name
	 * @param string $kind A kind from FeedLookup::KINDS, or empty for every kind
	 * @return string
	 */
	public static function renderName( Parser $parser, string $name = '', string $kind = '' ): string {
		$name = trim( Sanitizer::stripAllTags( $parser->killMarkers( $name ) ) );
		$kind = trim( $kind );
		if ( $name === '' || ( $kind !== '' && !in_array( $kind, FeedLookup::KINDS, true ) ) ) {
			return '';
		}
		$property = $kind === '' ? self::NAME_PROPERTY : self::NAME_PROPERTY . '-' . $kind;
		$parser->getOutput()->setUnsortedPageProperty( $property, $name );
		return '';
	}
}
