<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use Femiwiki\HttpSignatures\Cavage;
use Femiwiki\HttpSignatures\Signature;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Http\GuzzleHttpRequest;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\DeliveryJob
 * @group Database
 */
class DeliveryJobTest extends MediaWikiIntegrationTestCase {

	private const INBOX = 'https://remote.example.org/inbox';
	private const KEY_ID = 'https://fedi.example.org/actors/category_1#main-key';

	/**
	 * Runs a delivery through MediaWiki's real Guzzle request, so what the receiver gets is
	 * what MediaWiki puts on the wire.
	 *
	 * @param int $status The inbox's answer
	 * @return array Whether the job succeeded, and the request sent
	 */
	private function deliver( int $status ): array {
		$history = [];
		$stack = HandlerStack::create( new MockHandler( [ new Response( $status ) ] ) );
		$stack->push( Middleware::history( $history ) );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			static fn ( $url, $options, $caller ) => new GuzzleHttpRequest(
				$url, [ 'handler' => $stack, 'connectTimeout' => 5 ] + $options, $caller )
		);
		$this->setService( 'HttpRequestFactory', $factory );

		$job = new DeliveryJob( [
			'inbox' => self::INBOX,
			'keyId' => self::KEY_ID,
			'activity' => [ 'type' => 'Accept', 'actor' => 'https://fedi.example.org/actors/category_1' ],
		] );
		$ok = $job->run();
		$this->assertCount( 1, $history );
		return [ $ok, $history[0]['request'] ];
	}

	public function testSignatureCoversTheHeadersSent() {
		[ $ok, $request ] = $this->deliver( 202 );
		$this->assertTrue( $ok );
		$this->assertSame( [ 'application/activity+json' ], $request->getHeader( 'Content-Type' ) );

		$body = (string)$request->getBody();
		$headers = [];
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[strtolower( $name )] = implode( ', ', $values );
		}
		$parsed = Signature::parse( $headers['signature'] );
		$this->assertNotNull( $parsed );
		$this->assertSame( self::KEY_ID, $parsed->keyId );
		$this->assertNull( $parsed->check( 'POST', $headers, $body, time() ) );
		$string = Cavage::signingString( 'POST', '/inbox', $headers, $parsed->headers );
		$publicKey = $this->getServiceContainer()->getService( 'FediFeeds.LocalKeyStore' )
			->getKey()->getPublicKeyPem();
		$this->assertTrue( Cavage::verify( $string, $parsed->signature, $publicKey ) );
	}

	public function testRefusedDeliveryIsRetried() {
		[ $ok ] = $this->deliver( 401 );
		$this->assertFalse( $ok );
	}
}
