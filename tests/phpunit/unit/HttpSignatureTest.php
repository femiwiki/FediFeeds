<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\Signatures\HttpSignature;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Signatures\HttpSignature
 */
class HttpSignatureTest extends MediaWikiUnitTestCase {

	private const NOW = 1792320000;

	private static function newKeyPair(): array {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $private );
		return [ $private, openssl_pkey_get_details( $key )['key'] ];
	}

	private static function newPost( string $body ): array {
		return [
			'host' => 'fedi.example.org',
			'date' => gmdate( 'D, d M Y H:i:s \G\M\T', self::NOW ),
			'digest' => HttpSignature::digest( $body ),
		];
	}

	public function testSignedRequestVerifies() {
		[ $private, $public ] = self::newKeyPair();
		$headers = self::newPost( '{}' );
		$headers['signature'] = HttpSignature::sign( 'POST', '/actors/inbox', $headers,
			'https://remote.example/users/a#main-key', $private );

		$parsed = HttpSignature::parse( $headers['signature'] );
		$this->assertSame( 'https://remote.example/users/a#main-key', $parsed['keyId'] );
		$this->assertSame( [ '(request-target)', 'host', 'date', 'digest' ], $parsed['headers'] );
		$this->assertNull( HttpSignature::check( $parsed, 'POST', $headers, '{}', self::NOW ) );
		$string = HttpSignature::signingString( 'POST', '/actors/inbox', $headers, $parsed['headers'] );
		$this->assertTrue( HttpSignature::verify( $string, $parsed['signature'], $public ) );

		$other = HttpSignature::signingString( 'POST', '/actors/category_1/inbox', $headers, $parsed['headers'] );
		$this->assertFalse( HttpSignature::verify( $other, $parsed['signature'], $public ) );
		$this->assertFalse( HttpSignature::verify( $string, $parsed['signature'], self::newKeyPair()[1] ) );
	}

	public static function provideCheck(): iterable {
		$signed = [ '(request-target)', 'host', 'date', 'digest' ];
		yield 'good' => [ 'rsa-sha256', $signed, 0, '{}', null ];
		yield 'hs2019' => [ 'hs2019', $signed, 0, '{}', null ];
		yield 'other algorithm' => [ 'hmac-sha256', $signed, 0, '{}', 'algorithm' ];
		yield 'digest not signed' => [ 'rsa-sha256', [ '(request-target)', 'host', 'date' ], 0, '{}', 'headers' ];
		yield 'an hour ahead and more' => [ 'rsa-sha256', $signed, -3601, '{}', 'date' ];
		yield 'twelve hours behind and more' => [ 'rsa-sha256', $signed, 43201, '{}', 'date' ];
		yield 'just inside' => [ 'rsa-sha256', $signed, 43200, '{}', null ];
		yield 'body changed' => [ 'rsa-sha256', $signed, 0, '{"a":1}', 'digest' ];
	}

	/**
	 * @dataProvider provideCheck
	 */
	public function testCheck( string $algorithm, array $names, int $age, string $body, ?string $expected ) {
		$parsed = [ 'algorithm' => $algorithm, 'headers' => $names ];
		$this->assertSame( $expected,
			HttpSignature::check( $parsed, 'POST', self::newPost( '{}' ), $body, self::NOW + $age ) );
	}

	public function testGetNeedsNoDigest() {
		$parsed = [ 'algorithm' => null, 'headers' => [ '(request-target)', 'host', 'date' ] ];
		$headers = [ 'host' => 'fedi.example.org', 'date' => gmdate( 'D, d M Y H:i:s \G\M\T', self::NOW ) ];
		$this->assertNull( HttpSignature::check( $parsed, 'GET', $headers, '', self::NOW ) );
	}

	public function testParseRefusesWhatIsNotASignature() {
		$this->assertNull( HttpSignature::parse( '' ) );
		$this->assertNull( HttpSignature::parse( 'keyId="a"' ) );
		$this->assertNull( HttpSignature::parse( 'keyId="a",signature="not base64!"' ) );
		$this->assertSame( [ 'date' ], HttpSignature::parse( 'keyId="a",signature="YQ=="' )['headers'] );
	}

	public function testSigningStringNeedsEveryNamedHeader() {
		$this->assertNull( HttpSignature::signingString( 'GET', '/', [ 'host' => 'a' ], [ 'host', 'date' ] ) );
	}
}
