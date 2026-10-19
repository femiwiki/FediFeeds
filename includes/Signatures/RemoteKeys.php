<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Fetches and caches remote actors' public keys, and verifies requests signed with them
 */
class RemoteKeys {

	private const ACCEPT = 'application/activity+json, application/ld+json';
	/** How long a key that could not be fetched is not asked for again */
	private const MISS_TTL = 300;

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly WANObjectCache $cache,
	) {
	}

	/**
	 * The actor id that signed the request, or null if the signature is not good.
	 *
	 * @param string $method
	 * @param string $target The path and query the sender signed
	 * @param array<string,string> $headers Keyed by lower-case name
	 * @param string $body
	 * @param int|null $now Unix time, for tests
	 * @return string|null
	 */
	public function verify(
		string $method, string $target, array $headers, string $body, ?int $now = null
	): ?string {
		$parsed = HttpSignature::parse( $headers['signature'] ?? '' );
		if ( $parsed === null || HttpSignature::check( $parsed, $method, $headers, $body, $now ?? time() ) !== null ) {
			return null;
		}
		$string = HttpSignature::signingString( $method, $target, $headers, $parsed['headers'] );
		if ( $string === null ) {
			return null;
		}
		foreach ( [ false, true ] as $fresh ) {
			$key = $this->getKey( $parsed['keyId'], $fresh );
			if ( $key && HttpSignature::verify( $string, $parsed['signature'], $key['pem'] ) ) {
				return $key['owner'];
			}
		}
		return null;
	}

	/**
	 * @param string $keyId
	 * @param bool $fresh Skip the cache, for a remote that changed its key
	 * @return array{pem: string, owner: string}|null
	 */
	public function getKey( string $keyId, bool $fresh = false ): ?array {
		$cacheKey = $this->makeCacheKey( $keyId );
		if ( $fresh ) {
			$this->cache->delete( $cacheKey );
		}
		$key = $this->cache->getWithSetCallback( $cacheKey, WANObjectCache::TTL_DAY,
			function ( $old, &$ttl ) use ( $keyId ) {
				$key = self::findKey( $this->fetchDocument( $keyId ), $keyId );
				if ( !$key ) {
					$ttl = self::MISS_TTL;
				}
				return $key ?? false;
			}
		);
		return is_array( $key ) ? $key : null;
	}

	/**
	 * Drops a cached key, for an actor that was deleted.
	 */
	public function forget( string $keyId ): void {
		$this->cache->delete( $this->makeCacheKey( $keyId ) );
	}

	private function makeCacheKey( string $keyId ): string {
		return $this->cache->makeGlobalKey( 'fedifeeds-remote-key', sha1( $keyId ) );
	}

	/**
	 * The ActivityPub document at an https URL, without its fragment: an actor, or a key.
	 */
	public function fetchDocument( string $url ): mixed {
		$url = explode( '#', $url, 2 )[0];
		if ( parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) {
			return null;
		}
		$request = $this->httpRequestFactory->create( $url, [ 'method' => 'GET', 'timeout' => 5 ], __METHOD__ );
		$request->setHeader( 'Accept', self::ACCEPT );
		if ( !$request->execute()->isOK() ) {
			return null;
		}
		return json_decode( $request->getContent(), true );
	}

	/**
	 * The key with that id in an actor document or a key document.
	 *
	 * @param mixed $document
	 * @param string $keyId
	 * @return array{pem: string, owner: string}|null
	 */
	public static function findKey( mixed $document, string $keyId ): ?array {
		if ( !is_array( $document ) ) {
			return null;
		}
		$keys = $document['publicKey'] ?? $document;
		$keys = is_array( $keys ) && array_is_list( $keys ) ? $keys : [ $keys ];
		foreach ( $keys as $key ) {
			if ( is_array( $key ) && ( $key['id'] ?? null ) === $keyId
				&& is_string( $key['publicKeyPem'] ?? null ) && is_string( $key['owner'] ?? null )
			) {
				return [ 'pem' => $key['publicKeyPem'], 'owner' => $key['owner'] ];
			}
		}
		return null;
	}
}
