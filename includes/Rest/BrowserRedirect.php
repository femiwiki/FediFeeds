<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;

/**
 * Sends a browser that opens the id of an actor or a note to its wiki page, as Mastodon
 * answers its own ids with HTML or JSON by the Accept header. A remote server keeps the
 * url of an actor only on the host of its id, so its "open in browser" link is the id.
 */
class BrowserRedirect {

	private const ACTIVITY_TYPES = [ 'application/activity+json', 'application/ld+json' ];

	/**
	 * Whether the Accept header names HTML but neither ActivityPub type. No header, or only
	 * wildcards, means a fetcher, which is answered with JSON.
	 */
	public static function wantsHtml( string $accept ): bool {
		$html = false;
		foreach ( explode( ',', $accept ) as $range ) {
			$params = explode( ';', $range );
			$type = strtolower( trim( array_shift( $params ) ) );
			foreach ( $params as $param ) {
				if ( preg_match( '/^\s*q\s*=\s*0(\.0*)?\s*$/i', $param ) ) {
					// q=0 says the type is not acceptable
					continue 2;
				}
			}
			if ( in_array( $type, self::ACTIVITY_TYPES, true ) ) {
				return false;
			}
			$html = $html || $type === 'text/html';
		}
		return $html;
	}

	/**
	 * A 303 to the page for a browser, else the JSON, both varying by Accept.
	 *
	 * @param Handler $handler
	 * @param callable(): string $pageUrl
	 * @param callable(): Response $json
	 */
	public static function respond( Handler $handler, callable $pageUrl, callable $json ): Response {
		$response = self::wantsHtml( $handler->getRequest()->getHeaderLine( 'Accept' ) )
			? $handler->getResponseFactory()->createSeeOther( $pageUrl() )
			: $json();
		$response->addHeader( 'Vary', 'Accept' );
		return $response;
	}
}
