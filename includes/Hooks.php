<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\Title;

/**
 * Registers {{FEDIFEEDSNAME:}}, which sets the display name of a page's feeds, and
 * {{FEDIFEEDSTAGS:}}, which sets the hashtags of the page's notes
 */
class Hooks implements ParserFirstCallInitHook {

	/**
	 * The page property holding the display name for every kind, or with "-<kind>" for one, and
	 * with "@<language>" after either for the feeds of that language
	 */
	public const NAME_PROPERTY = 'fedifeeds-name';

	/**
	 * The page property holding the file name, without its namespace, of the icon for every
	 * kind, or with "-<kind>" for one
	 */
	public const ICON_PROPERTY = 'fedifeeds-icon';

	/** The page property holding the heading level of the items of the page's section feed */
	public const SECTION_UNIT_PROPERTY = 'fedifeeds-section-unit';

	/** The page property holding the hashtags of the page's notes, without "#", one per line */
	public const TAGS_PROPERTY = 'fedifeeds-tags';

	/** The heading level of a section feed's items when the page sets none */
	public const DEFAULT_SECTION_UNIT = 2;

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		// Written {{FEDIFEEDSNAME:...}} like {{DISPLAYTITLE:...}}, without a hash
		$parser->setFunctionHook( 'fedifeedsname', [ self::class, 'renderName' ], Parser::SFH_NO_HASH );
		$parser->setFunctionHook( 'fedifeedstags', [ self::class, 'renderTags' ], Parser::SFH_NO_HASH );
	}

	/**
	 * Adds the hashtags to those of the page's notes, kept as a page property, and outputs
	 * nothing. A tag that Mastodon would not read as one, such as one with a space, is left out,
	 * and so is one that differs from an earlier one only in case.
	 *
	 * @param Parser $parser
	 * @param string ...$tags With or without "#"
	 * @return string
	 */
	public static function renderTags( Parser $parser, string ...$tags ): string {
		$output = $parser->getOutput();
		$kept = self::getTags( $output );
		foreach ( $tags as $tag ) {
			$tag = ltrim( trim( Sanitizer::stripAllTags( $parser->killMarkers(
				$parser->recursiveTagParseFully( $tag ) ) ) ), '#' );
			// Letters, digits and underscores, with at least one letter, as Mastodon's hashtags are
			if ( preg_match( '/^[\p{L}\p{M}\p{N}_]*\p{L}[\p{L}\p{M}\p{N}_]*$/uD', $tag )
				&& !in_array( mb_strtolower( $tag ), array_map( 'mb_strtolower', $kept ), true )
			) {
				$kept[] = $tag;
			}
		}
		if ( $kept ) {
			$output->setUnsortedPageProperty( self::TAGS_PROPERTY, implode( "\n", $kept ) );
		}
		return '';
	}

	/**
	 * The hashtags a rendering set with {{FEDIFEEDSTAGS:}}, without "#"
	 *
	 * @return string[]
	 */
	public static function getTags( ParserOutput $output ): array {
		$tags = (string)$output->getPageProperty( self::TAGS_PROPERTY );
		return $tags === '' ? [] : explode( "\n", $tags );
	}

	/**
	 * Stores the name, the icon file, and the heading level whose sections are a section feed's
	 * items, as page properties and outputs nothing.
	 *
	 * @param Parser $parser
	 * @param string $name
	 * @param string ...$args In any order: a kind from FeedLookup::KINDS, or none for every
	 *  kind, icon=<file>, with or without the file namespace, section-unit=<1 to 6>, and
	 *  lang=<language code>, which makes the name that of the kind's feeds in the language only
	 * @return string
	 */
	public static function renderName( Parser $parser, string $name = '', string ...$args ): string {
		// Parsed first so wikitext such as '''bold''' or [[links]] leaves its text, not its markup
		$name = trim( Sanitizer::stripAllTags( $parser->killMarkers( $parser->recursiveTagParseFully( $name ) ) ) );
		$kind = '';
		$unit = null;
		$icon = null;
		$language = null;
		foreach ( $args as $arg ) {
			$arg = trim( $arg );
			if ( preg_match( '/^section-unit\s*=\s*(.*)$/D', $arg, $m ) ) {
				$unit = trim( $m[1] );
			} elseif ( preg_match( '/^icon\s*=\s*(.*)$/D', $arg, $m ) ) {
				$icon = trim( $m[1] );
			} elseif ( preg_match( '/^lang\s*=\s*(.*)$/D', $arg, $m ) ) {
				$language = strtolower( trim( $m[1] ) );
			} elseif ( $arg !== '' ) {
				$kind = $arg;
			}
		}
		if ( $name === '' || ( $kind !== '' && !in_array( $kind, FeedLookup::KINDS, true ) ) ) {
			return '';
		}
		$output = $parser->getOutput();
		$suffix = $kind === '' ? '' : '-' . $kind;
		if ( $language !== null ) {
			// A language's name only; the icon and the unit stay those of every language
			if ( MediaWikiServices::getInstance()->getLanguageNameUtils()->isValidCode( $language ) ) {
				$output->setUnsortedPageProperty( self::NAME_PROPERTY . $suffix . '@' . $language, $name );
			}
			return '';
		}
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
