<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\Signatures\HttpSignature;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys
 */
class RemoteKeysTest extends MediaWikiUnitTestCase {

	private const KEY_ID = 'https://remote.example/users/a#main-key';
	private const OWNER = 'https://remote.example/users/a';
	private const NOW = 1792320000;

	private static function newKeyPair(): array {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $private );
		return [ $private, openssl_pkey_get_details( $key )['key'] ];
	}

	private static function newActor( string $publicKeyPem ): array {
		return [
			'id' => self::OWNER,
			'type' => 'Person',
			'publicKey' => [ 'id' => self::KEY_ID, 'owner' => self::OWNER, 'publicKeyPem' => $publicKeyPem ],
		];
	}

	/**
	 * @param array[] $documents What each fetch answers, in turn
	 * @param int &$fetches
	 */
	private function newRemoteKeys( array $documents, ?int &$fetches = 0 ): RemoteKeys {
		$fetches = 0;
		return new class (
			$this->createNoOpMock( HttpRequestFactory::class ),
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$documents,
			$fetches
		) extends RemoteKeys {
			public function __construct( $http, $cache, private array $documents, private int &$fetches ) {
				parent::__construct( $http, $cache );
			}

			protected function fetch( string $keyId ): mixed {
				return $this->documents[$this->fetches++] ?? null;
			}
		};
	}

	private static function newSignedPost( string $privateKeyPem, string $body ): array {
		$headers = [
			'host' => 'fedi.example.org',
			'date' => gmdate( 'D, d M Y H:i:s \G\M\T', self::NOW ),
			'digest' => HttpSignature::digest( $body ),
		];
		$headers['signature'] = HttpSignature::sign( 'POST', '/actors/inbox', $headers, self::KEY_ID, $privateKeyPem );
		return $headers;
	}

	public function testVerifyReturnsTheOwner() {
		[ $private, $public ] = self::newKeyPair();
		$keys = $this->newRemoteKeys( [ self::newActor( $public ) ], $fetches );
		$headers = self::newSignedPost( $private, '{}' );
		$this->assertSame( self::OWNER, $keys->verify( 'POST', '/actors/inbox', $headers, '{}', self::NOW ) );
		$this->assertSame( self::OWNER, $keys->verify( 'POST', '/actors/inbox', $headers, '{}', self::NOW ) );
		$this->assertSame( 1, $fetches, 'the key is cached' );
	}

	public function testVerifyRetriesWithAFreshKey() {
		[ $private, $public ] = self::newKeyPair();
		$stale = self::newKeyPair()[1];
		$keys = $this->newRemoteKeys( [ self::newActor( $stale ), self::newActor( $public ) ], $fetches );
		$headers = self::newSignedPost( $private, '{}' );
		$this->assertSame( self::OWNER, $keys->verify( 'POST', '/actors/inbox', $headers, '{}', self::NOW ) );
		$this->assertSame( 2, $fetches );
	}

	public function testVerifyRefuses() {
		[ $private, $public ] = self::newKeyPair();
		$keys = $this->newRemoteKeys( [ self::newActor( $public ), self::newActor( $public ) ] );
		$headers = self::newSignedPost( $private, '{}' );
		$this->assertNull( $keys->verify( 'POST', '/actors/category_1/inbox', $headers, '{}', self::NOW ),
			'another target' );
		$this->assertNull( $keys->verify( 'POST', '/actors/inbox', $headers, '{"a":1}', self::NOW ), 'another body' );
		$this->assertNull( $keys->verify( 'POST', '/actors/inbox', [], '{}', self::NOW ), 'no signature' );
	}

	public static function provideFindKey(): iterable {
		$key = [ 'id' => self::KEY_ID, 'owner' => self::OWNER, 'publicKeyPem' => 'PEM' ];
		$found = [ 'pem' => 'PEM', 'owner' => self::OWNER ];
		yield 'actor' => [ [ 'publicKey' => $key ], $found ];
		yield 'key document' => [ $key, $found ];
		yield 'list of keys' => [ [ 'publicKey' => [ [ 'id' => 'other' ] + $key, $key ] ], $found ];
		yield 'other id' => [ [ 'publicKey' => [ 'id' => 'other' ] + $key ], null ];
		yield 'no owner' => [ [ 'publicKey' => [ 'id' => self::KEY_ID, 'publicKeyPem' => 'PEM' ] ], null ];
		yield 'not JSON' => [ null, null ];
	}

	/**
	 * @dataProvider provideFindKey
	 */
	public function testFindKey( mixed $document, ?array $expected ) {
		$this->assertSame( $expected, RemoteKeys::findKey( $document, self::KEY_ID ) );
	}
}
