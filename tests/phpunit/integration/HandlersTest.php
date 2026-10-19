<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\Rest\ActorHandler;
use MediaWiki\Extension\FediFeeds\Rest\WebFingerHandler;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Rest\ActorHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\WebFingerHandler
 * @covers \MediaWiki\Extension\FediFeeds\Actors
 * @covers \MediaWiki\Extension\FediFeeds\FeedLookup
 * @covers \MediaWiki\Extension\FediFeeds\Hooks
 * @covers \MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore
 * @group Database
 */
class HandlersTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://wiki.example.org',
			'FediFeedsActorBase' => 'https://fedi.example.org/actors/',
			'FediFeedsIcon' => null,
			'AppleTouchIcon' => false,
			'Logos' => false,
		] );
	}

	private function getLocalKeyStore(): LocalKeyStore {
		return $this->getServiceContainer()->getService( 'FediFeeds.LocalKeyStore' );
	}

	private function newActorHandler(): ActorHandler {
		$services = $this->getServiceContainer();
		return new ActorHandler( $services->getMainConfig(), $services->getPageStore(),
			$services->getTitleFormatter(), $services->getPageProps(), $this->getLocalKeyStore() );
	}

	private function newWebFingerHandler(): WebFingerHandler {
		$services = $this->getServiceContainer();
		return new WebFingerHandler( $services->getMainConfig(), $services->getPageStore(),
			$services->getTitleFormatter(), $services->getPageProps() );
	}

	/** Saves the page and returns its id once its page properties are stored. */
	private function savePage( string $title, string $text ): int {
		$this->editPage( $title, $text );
		$this->runDeferredUpdates();
		return $this->getServiceContainer()->getPageStore()->getPageByText( $title )->getId();
	}

	private function getActor( string $handle ): array {
		$response = $this->executeHandler( $this->newActorHandler(),
			new RequestData( [ 'pathParams' => [ 'handle' => $handle ] ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/activity+json', $response->getHeaderLine( 'Content-Type' ) );
		return json_decode( $response->getBody(), true );
	}

	public function testCategoryActor() {
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$actor = $this->getActor( "category_$id" );
		$this->assertSame( "https://fedi.example.org/actors/category_$id", $actor['id'] );
		$this->assertSame( 'Service', $actor['type'] );
		$this->assertSame( "category_$id", $actor['preferredUsername'] );
		$this->assertSame( 'Category:Blog', $actor['name'] );
		$this->assertSame( "https://fedi.example.org/actors/category_$id/inbox", $actor['inbox'] );
		$this->assertSame( 'https://fedi.example.org/actors/inbox', $actor['endpoints']['sharedInbox'] );
		$this->assertSame( "https://fedi.example.org/actors/category_$id#main-key", $actor['publicKey']['id'] );
		$this->assertSame( $this->getLocalKeyStore()->getKey()->getPublicKeyPem(),
			$actor['publicKey']['publicKeyPem'] );
		$this->assertStringStartsWith( '-----BEGIN PUBLIC KEY-----', $actor['publicKey']['publicKeyPem'] );
		$this->assertStringStartsWith( 'https://wiki.example.org/', $actor['url'] );
	}

	public function testDisplayNameForEveryKind() {
		$id = $this->savePage( 'Category:Named', '{{FEDIFEEDSNAME:Dev blog}}' );
		$actor = $this->getActor( "category_$id" );
		$this->assertSame( 'Dev blog', $actor['name'] );
		$this->assertSame( 'New pages in Category:Named.', $actor['summary'] );
		$this->assertSame( 'Dev blog', $this->getActor( "section_$id" )['name'] );
	}

	public function testDisplayNameForOneKindWins() {
		$id = $this->savePage( 'Category:Named twice',
			'{{FEDIFEEDSNAME:Dev blog|section}}{{FEDIFEEDSNAME:Dev blog posts}}' );
		$this->assertSame( 'Dev blog posts', $this->getActor( "category_$id" )['name'] );
		$this->assertSame( 'Dev blog', $this->getActor( "section_$id" )['name'] );
	}

	public static function provideIgnoredDisplayName(): iterable {
		yield 'empty' => [ '{{FEDIFEEDSNAME:}}' ];
		yield 'only markup' => [ '{{FEDIFEEDSNAME:<b></b>}}' ];
		yield 'unknown kind' => [ '{{FEDIFEEDSNAME:Dev blog|page}}' ];
	}

	/**
	 * @dataProvider provideIgnoredDisplayName
	 */
	public function testIgnoredDisplayName( string $text ) {
		$id = $this->savePage( 'Category:Unnamed', $text );
		$this->assertSame( 'Category:Unnamed', $this->getActor( "category_$id" )['name'] );
	}

	public function testDisplayNameDropsMarkupAndLastWins() {
		$id = $this->savePage( 'Category:Renamed', "{{FEDIFEEDSNAME:Old}}\n{{FEDIFEEDSNAME:'''Dev''' blog}}" );
		$this->assertSame( 'Dev blog', $this->getActor( "category_$id" )['name'] );
	}

	public function testKeyIsGeneratedOnceAndKept() {
		$first = $this->getLocalKeyStore()->getKey()->getPublicKeyPem();
		$this->assertNotNull( $first );
		$this->assertSame( $first, $this->getLocalKeyStore()->getKey()->getPublicKeyPem() );
		$id = $this->getExistingTestPage( 'Talk:Updates' )->getId();
		$this->assertSame( $first, $this->getActor( "section_$id" )['publicKey']['publicKeyPem'] );
	}

	public static function provideIcon() {
		yield 'config wins' => [
			[
				'FediFeedsIcon' => '/images/feed.jpg',
				'AppleTouchIcon' => '/favicon-180.png',
				'Logos' => [ '1x' => '/logo.png' ],
			],
			[ 'type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://wiki.example.org/images/feed.jpg' ],
		];
		yield 'Apple touch icon' => [
			[ 'AppleTouchIcon' => '/favicon-180.png', 'Logos' => [ '1x' => '/logo.png' ] ],
			[ 'type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://wiki.example.org/favicon-180.png' ],
		];
		yield 'SVG skipped' => [
			[
				'FediFeedsIcon' => '/images/feed.svg',
				'AppleTouchIcon' => '/touch.SVG',
				'Logos' => [ '1x' => '/logo.svg', 'icon' => '//cdn.example.org/icon.webp?1' ],
			],
			[ 'type' => 'Image', 'mediaType' => 'image/webp', 'url' => 'https://cdn.example.org/icon.webp?1' ],
		];
		yield 'logos' => [
			[ 'Logos' => [ '1x' => '/logo.png', 'icon' => '/icon.gif' ] ],
			[ 'type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://wiki.example.org/logo.png' ],
		];
		yield 'unknown media type' => [
			[ 'FediFeedsIcon' => 'https://img.example.org/avatar' ],
			[ 'type' => 'Image', 'url' => 'https://img.example.org/avatar' ],
		];
	}

	/**
	 * @dataProvider provideIcon
	 */
	public function testIcon( array $config, array $expected ) {
		$this->overrideConfigValues( $config );
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$this->assertSame( $expected, $this->getActor( "category_$id" )['icon'] );
	}

	public function testNoIcon() {
		$this->overrideConfigValues( [ 'Logos' => [ 'icon' => '/icon.svg' ] ] );
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$this->assertArrayNotHasKey( 'icon', $this->getActor( "category_$id" ) );
	}

	public function testDefaultActorBase() {
		$this->overrideConfigValues( [ 'FediFeedsActorBase' => null, 'RestPath' => '/rest.php' ] );
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$this->assertSame( "https://wiki.example.org/rest.php/fedifeeds/actors/category_$id",
			$this->getActor( "category_$id" )['id'] );
	}

	public static function provideMissingFeed(): iterable {
		yield 'not a handle' => [ 'blog' ];
		yield 'no such page' => [ 'section_999999999' ];
	}

	/**
	 * @dataProvider provideMissingFeed
	 */
	public function testMissingFeed( string $handle ) {
		$ex = $this->executeHandlerAndGetHttpException( $this->newActorHandler(),
			new RequestData( [ 'pathParams' => [ 'handle' => $handle ] ] ) );
		$this->assertSame( 404, $ex->getCode() );
	}

	public function testCategoryKindNeedsACategory() {
		$id = $this->getExistingTestPage( 'Blog' )->getId();
		$ex = $this->executeHandlerAndGetHttpException( $this->newActorHandler(),
			new RequestData( [ 'pathParams' => [ 'handle' => "category_$id" ] ] ) );
		$this->assertInstanceOf( LocalizedHttpException::class, $ex );
		$this->assertSame( 404, $ex->getCode() );
	}

	public static function provideWebFingerResource(): iterable {
		yield 'wiki host' => [ 'acct:category_%d@wiki.example.org' ];
		yield 'actor host' => [ 'acct:category_%d@fedi.example.org' ];
		yield 'actor id' => [ 'https://fedi.example.org/actors/category_%d' ];
	}

	/**
	 * @dataProvider provideWebFingerResource
	 */
	public function testWebFinger( string $resource ) {
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$response = $this->executeHandler( $this->newWebFingerHandler(),
			new RequestData( [ 'queryParams' => [ 'resource' => sprintf( $resource, $id ) ] ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/jrd+json', $response->getHeaderLine( 'Content-Type' ) );
		$data = json_decode( $response->getBody(), true );
		$this->assertSame( "acct:category_$id@wiki.example.org", $data['subject'] );
		$this->assertSame( [
			'rel' => 'self',
			'type' => 'application/activity+json',
			'href' => "https://fedi.example.org/actors/category_$id",
		], $data['links'][0] );
	}

	public static function provideWebFingerMiss(): iterable {
		yield 'other host' => [ 'acct:category_%d@example.com' ];
		yield 'no host' => [ 'acct:category_%d' ];
		yield 'other URL' => [ 'https://example.com/actors/category_%d' ];
	}

	/**
	 * @dataProvider provideWebFingerMiss
	 */
	public function testWebFingerMiss( string $resource ) {
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$ex = $this->executeHandlerAndGetHttpException( $this->newWebFingerHandler(),
			new RequestData( [ 'queryParams' => [ 'resource' => sprintf( $resource, $id ) ] ] ) );
		$this->assertSame( 404, $ex->getCode() );
	}
}
