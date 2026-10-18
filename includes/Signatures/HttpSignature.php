<?php

namespace MediaWiki\Extension\FediFeeds\Signatures;

/**
 * draft-cavage-http-signatures-12 as Mastodon uses it: rsa-sha256 over (request-target), host,
 * date and, on a POST, digest.
 */
class HttpSignature {

	public const ALGORITHM = 'rsa-sha256';
	/** Mastodon's CLOCK_SKEW_MARGIN and EXPIRATION_WINDOW_LIMIT, in seconds */
	public const MAX_AHEAD = 3600;
	public const MAX_BEHIND = 43200;

	public static function digest( string $body ): string {
		return 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );
	}

	/**
	 * @param string $method
	 * @param string $target The path and query the request was sent to
	 * @param array<string,string> $headers Keyed by lower-case name
	 * @param string[] $names The signed headers, in order
	 * @return string|null Null when a named header is missing
	 */
	public static function signingString( string $method, string $target, array $headers, array $names ): ?string {
		$lines = [];
		foreach ( $names as $name ) {
			if ( $name === '(request-target)' ) {
				$lines[] = '(request-target): ' . strtolower( $method ) . ' ' . $target;
			} elseif ( isset( $headers[$name] ) ) {
				$lines[] = "$name: " . $headers[$name];
			} else {
				return null;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * The Signature header for a request, signing (request-target) and every header given.
	 *
	 * @param string $method
	 * @param string $target
	 * @param array<string,string> $headers Keyed by lower-case name
	 * @param string $keyId
	 * @param string $privateKeyPem
	 * @return string
	 */
	public static function sign(
		string $method, string $target, array $headers, string $keyId, string $privateKeyPem
	): string {
		$names = array_merge( [ '(request-target)' ], array_keys( $headers ) );
		openssl_sign( (string)self::signingString( $method, $target, $headers, $names ), $signature,
			$privateKeyPem, OPENSSL_ALGO_SHA256 );
		return sprintf( 'keyId="%s",algorithm="%s",headers="%s",signature="%s"',
			$keyId, self::ALGORITHM, implode( ' ', $names ), base64_encode( $signature ) );
	}

	/**
	 * @param string $header The Signature header
	 * @return array{keyId: string, algorithm: ?string, headers: string[], signature: string}|null
	 */
	public static function parse( string $header ): ?array {
		preg_match_all( '/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER );
		$fields = [];
		foreach ( $matches as [ , $name, $value ] ) {
			$fields[$name] = $value;
		}
		$signature = base64_decode( $fields['signature'] ?? '', true );
		if ( !isset( $fields['keyId'] ) || $signature === false || $signature === '' ) {
			return null;
		}
		return [
			'keyId' => $fields['keyId'],
			'algorithm' => $fields['algorithm'] ?? null,
			'headers' => explode( ' ', strtolower( $fields['headers'] ?? 'date' ) ),
			'signature' => $signature,
		];
	}

	/**
	 * Why a signature cannot be good whatever the key, or null if it can be.
	 *
	 * @param array $parsed From parse()
	 * @param string $method
	 * @param array<string,string> $headers Keyed by lower-case name
	 * @param string $body
	 * @param int $now Unix time
	 * @return string|null
	 */
	public static function check( array $parsed, string $method, array $headers, string $body, int $now ): ?string {
		if ( !in_array( $parsed['algorithm'], [ null, self::ALGORITHM, 'hs2019' ], true ) ) {
			return 'algorithm';
		}
		$post = strtoupper( $method ) === 'POST';
		$required = $post ? [ '(request-target)', 'host', 'date', 'digest' ] : [ '(request-target)', 'host', 'date' ];
		if ( array_diff( $required, $parsed['headers'] ) ) {
			return 'headers';
		}
		$date = strtotime( $headers['date'] ?? '' );
		if ( $date === false || $date > $now + self::MAX_AHEAD || $date < $now - self::MAX_BEHIND ) {
			return 'date';
		}
		if ( $post && !in_array( self::digest( $body ), self::digests( $headers['digest'] ?? '' ), true ) ) {
			return 'digest';
		}
		return null;
	}

	/**
	 * @param string $header A Digest header, which may list several digests
	 * @return string[] Each with its algorithm name upper-cased, such as "SHA-256=..."
	 */
	private static function digests( string $header ): array {
		$out = [];
		foreach ( explode( ',', $header ) as $digest ) {
			$parts = explode( '=', trim( $digest ), 2 );
			if ( count( $parts ) === 2 ) {
				$out[] = strtoupper( $parts[0] ) . '=' . $parts[1];
			}
		}
		return $out;
	}

	public static function verify( string $signingString, string $signature, string $publicKeyPem ): bool {
		return openssl_verify( $signingString, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256 ) === 1;
	}
}
