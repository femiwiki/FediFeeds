<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Page\PageLookup;

/**
 * Finds the feed a handle names
 */
class FeedLookup {

	public const KINDS = [ 'category', 'section' ];

	public function __construct(
		private readonly PageLookup $pageLookup,
	) {
	}

	/**
	 * Splits a handle into its kind and id, without checking that the target exists.
	 *
	 * @param string $handle Such as "category_4521"
	 * @return array{0: string, 1: int}|null
	 */
	public static function parseHandle( string $handle ): ?array {
		if ( !preg_match( '/^([a-z]+(?:_[a-z]+)*)_([1-9][0-9]*)$/D', $handle, $m ) ) {
			return null;
		}
		if ( !in_array( $m[1], self::KINDS, true ) ) {
			return null;
		}
		return [ $m[1], (int)$m[2] ];
	}

	public function getFeed( string $handle ): ?Feed {
		$parsed = self::parseHandle( $handle );
		if ( !$parsed ) {
			return null;
		}
		[ $kind, $id ] = $parsed;
		$page = $this->pageLookup->getPageById( $id );
		if ( !$page || !$page->exists() ) {
			return null;
		}
		if ( $kind === 'category' && $page->getNamespace() !== NS_CATEGORY ) {
			return null;
		}
		return new Feed( $kind, $page );
	}
}
