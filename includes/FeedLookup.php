<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\Page\PageLookup;

/**
 * Finds the feed a handle names
 */
class FeedLookup {

	public const KINDS = [ 'category', 'section' ];

	/** The kinds that have a feed for each language their items are translated into */
	public const LANGUAGE_KINDS = [ 'category' ];

	/**
	 * The shape of a language code in a handle: a MediaWiki code, which starts with two or three
	 * letters, so that no word of a kind such as "added" or "file" is read as one
	 */
	private const LANGUAGE_PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]+)*$/D';

	public function __construct(
		private readonly PageLookup $pageLookup,
		private readonly LanguageNameUtils $languageNameUtils,
	) {
	}

	/**
	 * Splits a handle into its kind, id and language, without checking that the target or the
	 * language exists. A kind is matched before a language is looked for.
	 *
	 * @param string $handle Such as "category_4521", or "category_en_4521" for a language's feed
	 * @return array{0: string, 1: int, 2: ?string}|null
	 */
	public static function parseHandle( string $handle ): ?array {
		if ( !preg_match( '/^([a-z][a-z0-9_-]*)_([1-9][0-9]*)$/D', $handle, $m ) ) {
			return null;
		}
		[ , $prefix, $id ] = $m;
		if ( in_array( $prefix, self::KINDS, true ) ) {
			return [ $prefix, (int)$id, null ];
		}
		$pos = strrpos( $prefix, '_' );
		if ( $pos === false ) {
			return null;
		}
		$kind = substr( $prefix, 0, $pos );
		$language = substr( $prefix, $pos + 1 );
		if ( !in_array( $kind, self::LANGUAGE_KINDS, true ) || !preg_match( self::LANGUAGE_PATTERN, $language ) ) {
			return null;
		}
		return [ $kind, (int)$id, $language ];
	}

	public function getFeed( string $handle ): ?Feed {
		$parsed = self::parseHandle( $handle );
		if ( !$parsed ) {
			return null;
		}
		[ $kind, $id, $language ] = $parsed;
		if ( $language !== null && !$this->languageNameUtils->isKnownLanguageTag( $language ) ) {
			return null;
		}
		$page = $this->pageLookup->getPageById( $id );
		if ( !$page || !$page->exists() ) {
			return null;
		}
		if ( $kind === 'category' && $page->getNamespace() !== NS_CATEGORY ) {
			return null;
		}
		return new Feed( $kind, $page, $language );
	}
}
