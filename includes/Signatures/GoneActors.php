<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

use MediaWiki\Http\HttpRequestFactory;

/**
 * Tells whether a remote actor is gone, for a Delete whose signature cannot be checked because
 * the actor's key went with it
 */
class GoneActors {

	/**
	 * Whether the actor's document answers 404 or 410. Anything else, a failure to connect
	 * included, is not taken to mean gone.
	 */
	public static function isGone( HttpRequestFactory $httpRequestFactory, string $actor ): bool {
		$url = explode( '#', $actor, 2 )[0];
		if ( parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) {
			return false;
		}
		$request = $httpRequestFactory->create( $url, [ 'method' => 'GET', 'timeout' => 5 ], __METHOD__ );
		$request->setHeader( 'Accept', 'application/activity+json, application/ld+json' );
		$request->execute();
		return in_array( $request->getStatus(), [ 404, 410 ], true );
	}
}
