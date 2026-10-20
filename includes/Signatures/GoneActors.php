<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

/**
 * Tells whether a remote actor is gone, for a Delete whose signature cannot be checked because
 * the actor's key went with it
 */
class GoneActors {

	/**
	 * Whether the actor's document answers 404 or 410 to a signed GET. Anything else, a failure
	 * to connect included, is not taken to mean gone.
	 */
	public static function isGone( SignedFetch $signedFetch, string $actor ): bool {
		$url = explode( '#', $actor, 2 )[0];
		if ( parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) {
			return false;
		}
		$request = $signedFetch->newRequest( $url, __METHOD__ );
		$request->execute();
		return in_array( $request->getStatus(), [ 404, 410 ], true );
	}
}
