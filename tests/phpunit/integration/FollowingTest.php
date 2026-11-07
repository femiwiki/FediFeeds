<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MediaWiki\Extension\FediFeeds\Following;
use MediaWiki\Extension\FediFeeds\FollowJob;
use MediaWiki\Http\GuzzleHttpRequest;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Following
 * @covers \MediaWiki\Extension\FediFeeds\FollowJob
 * @group Database
 */
class FollowingTest extends MediaWikiIntegrationTestCase {

	private const REMOTE = 'https://bridge.example.net/bridge';

	/** @var array Requests the remote server got, as Guzzle's history keeps them */
	private array $history = [];

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://wiki.example.org',
			'FediFeedsActorBase' => 'https://fedi.example.org/actors/',
		] );
	}

	/**
	 * Answers requests in turn with the responses given.
	 */
	private function respond( Response ...$responses ): void {
		$stack = HandlerStack::create( new MockHandler( $responses ) );
		$stack->push( Middleware::history( $this->history ) );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			static fn ( $url, $options, $caller ) => new GuzzleHttpRequest(
				$url, [ 'handler' => $stack, 'connectTimeout' => 5 ] + $options, $caller )
		);
		$this->setService( 'HttpRequestFactory', $factory );
	}

	private function getHandle(): string {
		$this->editPage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		return 'category_' . $this->getServiceContainer()->getPageStore()->getPageByText( 'Category:Posts' )->getId();
	}

	private static function actorResponse(): Response {
		return new Response( 200, [ 'Content-Type' => 'application/activity+json' ], json_encode( [
			'id' => self::REMOTE,
			'type' => 'Service',
			'inbox' => self::REMOTE . '/inbox',
		] ) );
	}

	public function testFollowsAnAccountFoundByWebFinger() {
		$handle = $this->getHandle();
		$this->respond(
			new Response( 200, [ 'Content-Type' => 'application/jrd+json' ], json_encode( [
				'subject' => 'acct:bridge@bridge.example.net',
				'links' => [
					[ 'rel' => 'http://webfinger.net/rel/profile-page', 'href' => 'https://bridge.example.net/' ],
					[ 'rel' => 'self', 'type' => 'application/activity+json', 'href' => self::REMOTE ],
				],
			] ) ),
			self::actorResponse(),
			new Response( 202 )
		);
		$this->assertSame( self::REMOTE, $this->getFollowing()->send( $handle, '@bridge@bridge.example.net' ) );

		$this->assertCount( 3, $this->history );
		$this->assertSame(
			'https://bridge.example.net/.well-known/webfinger?resource=acct%3Abridge%40bridge.example.net',
			(string)$this->history[0]['request']->getUri()
		);
		$post = $this->history[2]['request'];
		$this->assertSame( 'POST', $post->getMethod() );
		$this->assertSame( self::REMOTE . '/inbox', (string)$post->getUri() );
		$actor = "https://fedi.example.org/actors/$handle";
		$this->assertSame( [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => "$actor#follows/" . sha1( self::REMOTE ),
			'type' => 'Follow',
			'actor' => $actor,
			'object' => self::REMOTE,
		], json_decode( (string)$post->getBody(), true ) );
		$this->assertStringContainsString( "keyId=\"$actor#main-key\"", $post->getHeaderLine( 'Signature' ) );
		$this->assertTrue( $this->getFollowing()->has( $handle, 'bridge@bridge.example.net' ),
			'the follow is recorded without the leading @' );
	}

	private function getFollowing(): Following {
		return $this->getServiceContainer()->getService( 'FediFeeds.Following' );
	}

	public function testJobSendsAFollowOnce() {
		$handle = $this->getHandle();
		$this->respond( self::actorResponse(), new Response( 202 ) );
		$job = new FollowJob( [ 'feed' => $handle, 'account' => self::REMOTE ] );
		$this->assertTrue( $job->run() );
		$this->assertCount( 2, $this->history );
		$this->assertTrue( $this->getFollowing()->has( $handle, self::REMOTE ) );

		// Recorded, it fetches and sends nothing
		$this->assertTrue( ( new FollowJob( [ 'feed' => $handle, 'account' => self::REMOTE ] ) )->run() );
		$this->assertCount( 2, $this->history );
	}

	public function testJobRetriesARefusedFollow() {
		$handle = $this->getHandle();
		$this->respond( self::actorResponse(), new Response( 401 ) );
		$this->assertFalse( ( new FollowJob( [ 'feed' => $handle, 'account' => self::REMOTE ] ) )->run() );
		$this->assertFalse( $this->getFollowing()->has( $handle, self::REMOTE ) );
	}
}
