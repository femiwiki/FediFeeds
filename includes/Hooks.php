<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\Title;

/**
 * Registers {{FEDIFEEDSNAME:}}, which sets the display name of a page's feeds
 */
class Hooks implements ParserFirstCallInitHook {

	/** The page property holding the display name for every kind, or with "-<kind>" for one */
	public const NAME_PROPERTY = 'fedifeeds-name';

	/**
	 * The page property holding the file name, without its namespace, of the icon for every
	 * kind, or with "-<kind>" for one
	 */
	public const ICON_PROPERTY = 'fedifeeds-icon';

	/** The page property holding the heading level of the items of the page's section feed */
	public const SECTION_UNIT_PROPERTY = 'fedifeeds-section-unit';

	/** The heading level of a section feed's items when the page sets none */
	public const DEFAULT_SECTION_UNIT = 2;

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		// Written {{FEDIFEEDSNAME:...}} like {{DISPLAYTITLE:...}}, without a hash
		$parser->setFunctionHook( 'fedifeedsname', [ self::class, 'renderName' ], Parser::SFH_NO_HASH );
	}

	/**
	 * Stores the name, the icon file, and the heading level whose sections are a section feed's
	 * items, as page properties and outputs nothing.
	 *
	 * @param Parser $parser
	 * @param string $name
	 * @param string ...$args In any order: a kind from FeedLookup::KINDS, or none for every
	 *  kind, icon=<file>, with or without the file namespace, and section-unit=<1 to 6>
	 * @return string
	 */
	public static function renderName( Parser $parser, string $name = '', string ...$args ): string {
		// Parsed first so wikitext such as '''bold''' or [[links]] leaves its text, not its markup
		$name = trim( Sanitizer::stripAllTags( $parser->killMarkers( $parser->recursiveTagParseFully( $name ) ) ) );
		$kind = '';
		$unit = null;
		$icon = null;
		foreach ( $args as $arg ) {
			$arg = trim( $arg );
			if ( preg_match( '/^section-unit\s*=\s*(.*)$/D', $arg, $m ) ) {
				$unit = trim( $m[1] );
			} elseif ( preg_match( '/^icon\s*=\s*(.*)$/D', $arg, $m ) ) {
				$icon = trim( $m[1] );
			} elseif ( $arg !== '' ) {
				$kind = $arg;
			}
		}
		if ( $name === '' || ( $kind !== '' && !in_array( $kind, FeedLookup::KINDS, true ) ) ) {
			return '';
		}
		$output = $parser->getOutput();
		$suffix = $kind === '' ? '' : '-' . $kind;
		$output->setUnsortedPageProperty( self::NAME_PROPERTY . $suffix, $name );
		$file = $icon === null ? null : Title::newFromText( $icon, NS_FILE );
		if ( $file && $file->inNamespace( NS_FILE ) && $file->getDBkey() !== '' ) {
			// Lists the page among the file's usage, and reparses it when the file changes
			$parser->fetchFileAndTitle( $file );
			$output->setUnsortedPageProperty( self::ICON_PROPERTY . $suffix, $file->getDBkey() );
		}
		if ( $unit !== null && preg_match( '/^[1-6]$/D', $unit ) ) {
			$output->setUnsortedPageProperty( self::SECTION_UNIT_PROPERTY, $unit );
		}
		return '';
	}
}
