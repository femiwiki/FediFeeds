<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;

/**
 * GETs of remote ActivityPub documents, signed as the wiki's instance actor, since a server in
 * Mastodon's secure mode (AUTHORIZED_FETCH) refuses unsigned fetches of its actors. Signing
 * needs only the wiki's own key, so it never fetches anything; the remote may fetch the
 * instance actor to check the signature, which it serves unsigned.
 */
class SignedFetch {

	public const ACCEPT = 'application/activity+json, application/ld+json';

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param LocalKeyStore $localKeyStore
	 * @param string $keyId The instance actor's key id
	 */
	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly LocalKeyStore $localKeyStore,
		private readonly string $keyId,
	) {
	}

	/**
	 * A GET of the https URL, signed over (request-target), host and date, ready to execute.
	 *
	 * @param string $url Without a fragment
	 * @param string $caller
	 * @return MWHttpRequest
	 */
	public function newRequest( string $url, string $caller ): MWHttpRequest {
		$query = parse_url( $url, PHP_URL_QUERY );
		$target = ( parse_url( $url, PHP_URL_PATH ) ?: '/' ) . ( is_string( $query ) ? "?$query" : '' );
		$port = parse_url( $url, PHP_URL_PORT );
		$headers = [
			'host' => (string)parse_url( $url, PHP_URL_HOST ) . ( is_int( $port ) ? ":$port" : '' ),
			'date' => gmdate( 'D, d M Y H:i:s \G\M\T' ),
		];
		$headers['signature'] = $this->localKeyStore->getKey()->sign( 'GET', $target, $headers, $this->keyId );

		$request = $this->httpRequestFactory->create( $url, [ 'method' => 'GET', 'timeout' => 5 ], $caller );
		foreach ( $headers as $name => $value ) {
			$request->setHeader( $name, $value );
		}
		$request->setHeader( 'Accept', self::ACCEPT );
		return $request;
	}
}
