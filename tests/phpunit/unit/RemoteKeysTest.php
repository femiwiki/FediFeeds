<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\Signatures\HttpSignature;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKey;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Extension\FediFeeds\Signatures\SignedFetch;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys
 * @covers \MediaWiki\Extension\FediFeeds\Signatures\SignedFetch
 */
class RemoteKeysTest extends MediaWikiUnitTestCase {

	private const KEY_ID = 'https://remote.example/users/a#main-key';
	private const OWNER = 'https://remote.example/users/a';
	private const NOW = 1792320000;
	private const INSTANCE_KEY_ID = 'https://fedi.example.org/actors/wiki#main-key';

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
			$this->createNoOpMock( SignedFetch::class ),
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$documents,
			$fetches
		) extends RemoteKeys {
			public function __construct( $http, $cache, private array $documents, private int &$fetches ) {
				parent::__construct( $http, $cache );
			}

			public function fetchDocument( string $url ): mixed {
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

	public function testMissIsCached() {
		$keys = $this->newRemoteKeys( [ null, null ], $fetches );
		$this->assertNull( $keys->getKey( self::KEY_ID ) );
		$this->assertNull( $keys->getKey( self::KEY_ID ) );
		$this->assertSame( 1, $fetches, 'a key that could not be fetched is not asked for again' );
		$this->assertNull( $keys->getKey( self::KEY_ID, true ) );
		$this->assertSame( 2, $fetches, 'unless fresh' );
	}

	public function testFetchDocumentIsSignedAsTheInstanceActor() {
		[ $private, $public ] = self::newKeyPair();
		$headers = [];
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'setHeader' )->willReturnCallback( static function ( $name, $value ) use ( &$headers ) {
			$headers[strtolower( $name )] = $value;
		} );
		$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		$request->method( 'getContent' )->willReturn( json_encode( self::newActor( $public ) ) );
		$http = $this->createMock( HttpRequestFactory::class );
		$http->expects( $this->once() )->method( 'create' )
			->with( 'https://remote.example/users/a?page=1', $this->callback(
				static fn ( $options ) => $options['method'] === 'GET'
			) )
			->willReturn( $request );
		$localKeyStore = $this->createMock( LocalKeyStore::class );
		$localKeyStore->method( 'getKey' )->willReturn( new LocalKey( $private ) );
		$keys = new RemoteKeys( new SignedFetch( $http, $localKeyStore, self::INSTANCE_KEY_ID ),
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ) );

		$document = $keys->fetchDocument( 'https://remote.example/users/a?page=1#main-key' );
		$this->assertSame( self::OWNER, $document['id'] );
		$this->assertSame( 'application/activity+json, application/ld+json', $headers['accept'] );
		$this->assertSame( 'remote.example', $headers['host'] );
		$parsed = HttpSignature::parse( $headers['signature'] );
		$this->assertSame( self::INSTANCE_KEY_ID, $parsed['keyId'] );
		$this->assertSame( 'rsa-sha256', $parsed['algorithm'] );
		$this->assertSame( [ '(request-target)', 'host', 'date' ], $parsed['headers'] );
		$this->assertNull( HttpSignature::check( $parsed, 'GET', $headers, '', time() ) );
		$string = HttpSignature::signingString( 'GET', '/users/a?page=1', $headers, $parsed['headers'] );
		$this->assertStringStartsWith( "(request-target): get /users/a?page=1\nhost: remote.example\ndate: ",
			$string );
		$this->assertTrue( HttpSignature::verify( $string, $parsed['signature'], $public ) );
	}

	public function testFetchDocumentSkipsWhatIsNotHttps() {
		$keys = new RemoteKeys(
			new SignedFetch( $this->createNoOpMock( HttpRequestFactory::class ),
				$this->createNoOpMock( LocalKeyStore::class ), self::INSTANCE_KEY_ID ),
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] )
		);
		$this->assertNull( $keys->fetchDocument( 'http://remote.example/users/a' ) );
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
