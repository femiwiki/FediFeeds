<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\Hooks;
use MediaWiki\Extension\FediFeeds\Rest\ActorHandler;
use MediaWiki\Extension\FediFeeds\Rest\WebFingerHandler;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Media\MediaHandler;
use MediaWiki\Media\MediaTransformError;
use MediaWiki\Media\ThumbnailImage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Rest\ActorHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\BrowserRedirect
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
			'Sitename' => 'Example Wiki',
		] );
	}

	private function getLocalKeyStore(): LocalKeyStore {
		return $this->getServiceContainer()->getService( 'FediFeeds.LocalKeyStore' );
	}

	private function newActorHandler(): ActorHandler {
		$services = $this->getServiceContainer();
		return new ActorHandler( $services->getMainConfig(), $services->getService( 'FediFeeds.FeedLookup' ),
			$services->getTitleFormatter(), $services->getPageProps(), $this->getLocalKeyStore(),
			$services->getRepoGroup(), $services->getLanguageNameUtils() );
	}

	private function newWebFingerHandler(): WebFingerHandler {
		$services = $this->getServiceContainer();
		return new WebFingerHandler( $services->getMainConfig(), $services->getService( 'FediFeeds.FeedLookup' ),
			$services->getTitleFormatter(), $services->getPageProps(), $services->getRepoGroup(),
			$services->getLanguageNameUtils() );
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
		$this->assertSame( 'Accept', $response->getHeaderLine( 'Vary' ) );
		return json_decode( $response->getBody(), true );
	}

	public static function provideBrowserAccept(): iterable {
		yield 'Firefox' => [ 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ];
		yield 'Chrome' => [ 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,'
			. 'image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7' ];
		yield 'ActivityPub refused' => [ 'text/html, application/activity+json;q=0' ];
	}

	/**
	 * @dataProvider provideBrowserAccept
	 */
	public function testBrowserIsSentToThePage( string $accept ) {
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		$cases = [
			"category_$id" => $this->getActor( "category_$id" )['url'],
			'wiki' => 'https://wiki.example.org/',
		];
		$this->assertStringStartsWith( 'https://wiki.example.org/', $cases["category_$id"] );
		foreach ( $cases as $handle => $url ) {
			$response = $this->executeHandler( $this->newActorHandler(), new RequestData( [
				'pathParams' => [ 'handle' => $handle ],
				'headers' => [ 'Accept' => $accept ],
			] ) );
			$this->assertSame( 303, $response->getStatusCode(), $handle );
			$this->assertSame( $url, $response->getHeaderLine( 'Location' ), $handle );
			$this->assertSame( 'Accept', $response->getHeaderLine( 'Vary' ), $handle );
		}
	}

	public static function provideFetcherAccept(): iterable {
		yield 'none' => [ null ];
		yield 'any' => [ '*/*' ];
		yield 'ActivityPub' => [ 'application/activity+json' ];
		yield 'JSON-LD with a profile' => [ 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"' ];
		yield 'both' => [ 'application/activity+json, application/ld+json' ];
		yield 'with q-values and HTML' => [ 'application/activity+json;q=0.9, text/html;q=1' ];
		yield 'upper case' => [ 'Application/Activity+JSON, text/html' ];
		yield 'plain JSON' => [ 'application/json' ];
	}

	/**
	 * @dataProvider provideFetcherAccept
	 */
	public function testFetcherGetsJson( ?string $accept ) {
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		foreach ( [ "category_$id", 'wiki' ] as $handle ) {
			$response = $this->executeHandler( $this->newActorHandler(), new RequestData( [
				'pathParams' => [ 'handle' => $handle ],
				'headers' => $accept === null ? [] : [ 'Accept' => $accept ],
			] ) );
			$this->assertSame( 200, $response->getStatusCode(), $handle );
			$this->assertSame( 'application/activity+json', $response->getHeaderLine( 'Content-Type' ) );
			$this->assertSame( 'Accept', $response->getHeaderLine( 'Vary' ) );
			$this->assertSame( "https://fedi.example.org/actors/$handle",
				json_decode( $response->getBody(), true )['id'] );
		}
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

	public static function provideIconArgument(): iterable {
		yield 'with the namespace' => [ '{{FEDIFEEDSNAME:Blog|icon=File:Feed icon.png}}', 'fedifeeds-icon' ];
		yield 'without the namespace, before the kind' => [
			'{{FEDIFEEDSNAME:Blog| icon = Feed icon.png |section}}', 'fedifeeds-icon-section' ];
		yield 'an alias of the namespace, after the kind' => [
			'{{FEDIFEEDSNAME:Blog|section|section-unit=3|icon=Image:Feed_icon.png}}', 'fedifeeds-icon-section' ];
		yield 'another namespace' => [ '{{FEDIFEEDSNAME:Blog|icon=Category:Feed icon.png}}', null ];
		yield 'an invalid title' => [ '{{FEDIFEEDSNAME:Blog|icon=Feed{icon}.png}}', null ];
		yield 'empty' => [ '{{FEDIFEEDSNAME:Blog|icon=}}', null ];
		yield 'no name' => [ '{{FEDIFEEDSNAME:|icon=Feed icon.png}}', null ];
		yield 'an unknown kind' => [ '{{FEDIFEEDSNAME:Blog|page|icon=Feed icon.png}}', null ];
	}

	/**
	 * @dataProvider provideIconArgument
	 */
	public function testIconArgument( string $text, ?string $property ) {
		$output = $this->getServiceContainer()->getParserFactory()->create()->parse( $text,
			Title::makeTitle( NS_CATEGORY, 'Blog' ), ParserOptions::newFromAnon() );
		foreach ( [ Hooks::ICON_PROPERTY, Hooks::ICON_PROPERTY . '-section' ] as $name ) {
			$this->assertSame( $name === $property ? 'Feed_icon.png' : null, $output->getPageProperty( $name ) );
		}
		// Registered so that the file's usage lists the page
		$files = array_map( static fn ( $link ) => $link['link']->getDBkey(),
			$output->getLinkList( ParserOutputLinkTypes::MEDIA ) );
		$this->assertSame( $property === null ? [] : [ 'Feed_icon.png' ], $files );
	}

	/**
	 * Replaces the files with a file named Feed_icon.png, if it is given, whose 400px thumbnail is
	 * at the URL.
	 */
	private function setFile( string $extension, string $mime, string|false|null $thumbUrl ): void {
		$file = null;
		if ( $thumbUrl !== null ) {
			$handler = $this->createMock( MediaHandler::class );
			$handler->method( 'getThumbType' )
				->with( $extension, $mime, [ 'width' => 400 ] )
				->willReturn( [ 'png', 'image/png' ] );
			$thumb = $thumbUrl === false
				? new MediaTransformError( 'thumbnail_error', 400, 400 )
				: $this->createMock( ThumbnailImage::class );
			if ( $thumbUrl !== false ) {
				$thumb->method( 'getUrl' )->willReturn( $thumbUrl );
			}
			$file = $this->createMock( File::class );
			$file->method( 'exists' )->willReturn( true );
			$file->method( 'canRender' )->willReturn( true );
			$file->method( 'getExtension' )->willReturn( $extension );
			$file->method( 'getMimeType' )->willReturn( $mime );
			$file->method( 'getHandler' )->willReturn( $handler );
			$file->method( 'transform' )->with( [ 'width' => 400 ] )->willReturn( $thumb );
		}
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )
			->willReturnCallback( static fn ( $name ) => $name === 'Feed_icon.png' ? ( $file ?? false ) : false );
		$this->setService( 'RepoGroup', $repoGroup );
	}

	public static function provideFeedIcon(): iterable {
		yield 'a raster file' => [ 'png', 'image/png', '/images/thumb/1/1a/Feed_icon.png/400px-Feed_icon.png',
			'https://wiki.example.org/images/thumb/1/1a/Feed_icon.png/400px-Feed_icon.png' ];
		yield 'an SVG file, rendered to PNG' => [ 'svg', 'image/svg+xml',
			'//upload.example.org/thumb/1/1a/Feed_icon.svg/400px-Feed_icon.svg.png',
			'https://upload.example.org/thumb/1/1a/Feed_icon.svg/400px-Feed_icon.svg.png' ];
	}

	/**
	 * @dataProvider provideFeedIcon
	 */
	public function testFeedIcon( string $extension, string $mime, string $thumbUrl, string $expected ) {
		$this->overrideConfigValues( [ 'AppleTouchIcon' => '/favicon-180.png' ] );
		$this->setFile( $extension, $mime, $thumbUrl );
		$id = $this->savePage( 'Category:Iconed', '{{FEDIFEEDSNAME:Blog|icon=Feed icon.png}}' );
		$icon = [ 'type' => 'Image', 'mediaType' => 'image/png', 'url' => $expected ];
		$this->assertSame( $icon, $this->getActor( "category_$id" )['icon'] );
		$this->assertSame( $icon, $this->getActor( "section_$id" )['icon'] );
	}

	public function testFeedIconForOneKindWins() {
		$this->setFile( 'png', 'image/png', '/thumb.png' );
		$id = $this->savePage( 'Category:Iconed twice',
			'{{FEDIFEEDSNAME:Blog|section|icon=Feed icon.png}}{{FEDIFEEDSNAME:Blog|icon=Other.png}}' );
		$this->assertSame( 'https://wiki.example.org/thumb.png', $this->getActor( "section_$id" )['icon']['url'] );
		$this->assertArrayNotHasKey( 'icon', $this->getActor( "category_$id" ) );
	}

	public static function provideFeedIconFallback(): iterable {
		yield 'no argument' => [ '{{FEDIFEEDSNAME:Blog}}', '/thumb.png' ];
		yield 'a missing file' => [ '{{FEDIFEEDSNAME:Blog|icon=Feed icon.png}}', null ];
		yield 'a file that cannot be thumbnailed' => [ '{{FEDIFEEDSNAME:Blog|icon=Feed icon.png}}', false ];
	}

	/**
	 * @dataProvider provideFeedIconFallback
	 */
	public function testFeedIconFallback( string $text, string|false|null $thumbUrl ) {
		$this->overrideConfigValues( [ 'AppleTouchIcon' => '/favicon-180.png' ] );
		$this->setFile( 'png', 'image/png', $thumbUrl );
		$id = $this->savePage( 'Category:Fallback', $text );
		$this->assertSame(
			[ 'type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://wiki.example.org/favicon-180.png' ],
			$this->getActor( "category_$id" )['icon']
		);
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

	public static function provideLanguageActor(): iterable {
		yield 'English' => [ 'en', 'Blog (English)', 'Translations into English of new pages in Category:Blog.' ];
		yield 'Korean' => [ 'ko', 'Blog (한국어)', 'Category:Blog에 새로 만들어지는 문서의 한국어 번역' ];
		yield 'a code with a script' => [ 'zh-hans', 'Blog (中文（简体）)',
			'Translations into 中文（简体） of new pages in Category:Blog.' ];
	}

	/**
	 * @dataProvider provideLanguageActor
	 */
	public function testLanguageActor( string $language, string $name, string $summary ) {
		$id = $this->savePage( 'Category:Blog', '{{FEDIFEEDSNAME:Blog}}' );
		$handle = "category_{$language}_$id";
		$actor = $this->getActor( $handle );
		$this->assertSame( "https://fedi.example.org/actors/$handle", $actor['id'] );
		$this->assertSame( 'Service', $actor['type'] );
		$this->assertSame( $handle, $actor['preferredUsername'] );
		$this->assertSame( $name, $actor['name'] );
		$this->assertSame( $summary, $actor['summary'] );
		$this->assertSame( $this->getActor( "category_$id" )['url'], $actor['url'] );
		$this->assertSame( "https://fedi.example.org/actors/$handle/inbox", $actor['inbox'] );
		$this->assertSame( "https://fedi.example.org/actors/$handle/outbox", $actor['outbox'] );
		$this->assertSame( "https://fedi.example.org/actors/$handle/followers", $actor['followers'] );
		$this->assertSame( "https://fedi.example.org/actors/$handle#main-key", $actor['publicKey']['id'] );
		// Mastodon takes a username of this shape only
		$this->assertMatchesRegularExpression( '/^[a-z0-9_]+([.-]+[a-z0-9_]+)*$/D', $handle );
	}

	public static function provideLanguageWebFinger(): iterable {
		yield 'wiki host' => [ 'acct:category_%s_%d@wiki.example.org' ];
		yield 'actor host' => [ 'acct:category_%s_%d@fedi.example.org' ];
		yield 'actor id' => [ 'https://fedi.example.org/actors/category_%s_%d' ];
	}

	/**
	 * @dataProvider provideLanguageWebFinger
	 */
	public function testLanguageWebFinger( string $resource ) {
		$id = $this->getExistingTestPage( 'Category:Blog' )->getId();
		foreach ( [ 'en', 'zh-hans' ] as $language ) {
			$response = $this->executeHandler( $this->newWebFingerHandler(),
				new RequestData( [ 'queryParams' => [ 'resource' => sprintf( $resource, $language, $id ) ] ] ) );
			$this->assertSame( 200, $response->getStatusCode() );
			$data = json_decode( $response->getBody(), true );
			$this->assertSame( "acct:category_{$language}_$id@wiki.example.org", $data['subject'] );
			$this->assertSame( "https://fedi.example.org/actors/category_{$language}_$id", $data['links'][0]['href'] );
		}
	}

	public static function provideMissingLanguageFeed(): iterable {
		yield 'unknown language' => [ 'Category:Blog', 'category_xx-yy_%d' ];
		yield 'language in upper case' => [ 'Category:Blog', 'category_EN_%d' ];
		yield 'section feed of a language' => [ 'Blog', 'section_en_%d' ];
		yield 'not a category' => [ 'Blog', 'category_en_%d' ];
	}

	/**
	 * @dataProvider provideMissingLanguageFeed
	 */
	public function testMissingLanguageFeed( string $title, string $handle ) {
		$handle = sprintf( $handle, $this->getExistingTestPage( $title )->getId() );
		$this->assertSame( 404, $this->executeHandlerAndGetHttpException( $this->newActorHandler(),
			new RequestData( [ 'pathParams' => [ 'handle' => $handle ] ] ) )->getCode() );
		$this->assertSame( 404, $this->executeHandlerAndGetHttpException( $this->newWebFingerHandler(),
			new RequestData( [ 'queryParams' => [ 'resource' => "acct:$handle@wiki.example.org" ] ] ) )->getCode() );
	}

	public function testInstanceActor() {
		$actor = $this->getActor( 'wiki' );
		$this->assertSame( [
			'https://www.w3.org/ns/activitystreams',
			'https://w3id.org/security/v1',
		], $actor['@context'] );
		$this->assertSame( 'https://fedi.example.org/actors/wiki', $actor['id'] );
		$this->assertSame( 'Application', $actor['type'] );
		$this->assertSame( 'wiki', $actor['preferredUsername'] );
		$this->assertSame( 'Example Wiki', $actor['name'] );
		$this->assertSame( 'https://fedi.example.org/actors/inbox', $actor['inbox'] );
		$this->assertSame( 'https://fedi.example.org/actors/inbox', $actor['endpoints']['sharedInbox'] );
		$this->assertTrue( $actor['manuallyApprovesFollowers'] );
		$this->assertArrayNotHasKey( 'outbox', $actor );
		$this->assertArrayNotHasKey( 'followers', $actor );
		$this->assertSame( [
			'id' => 'https://fedi.example.org/actors/wiki#main-key',
			'owner' => 'https://fedi.example.org/actors/wiki',
			'publicKeyPem' => $this->getLocalKeyStore()->getKey()->getPublicKeyPem(),
		], $actor['publicKey'] );
	}

	public function testInstanceKeyIdIsTheOneFetchesAreSignedWith() {
		$services = $this->getServiceContainer();
		$actors = new Actors( $services->getMainConfig(), $services->getTitleFormatter(),
			RequestContext::getMain(), $services->getPageProps(), $services->getRepoGroup(),
			$services->getLanguageNameUtils() );
		$this->assertSame( $this->getActor( 'wiki' )['publicKey']['id'], $actors->getInstanceKeyId() );
		$this->assertNull( $actors->getHandleFromActorId( 'https://fedi.example.org/actors/wiki' ),
			'the instance actor is not a feed' );
	}

	public static function provideInstanceWebFinger(): iterable {
		yield 'wiki host' => [ 'acct:wiki@wiki.example.org' ];
		yield 'actor host' => [ 'acct:wiki@fedi.example.org' ];
		yield 'actor id' => [ 'https://fedi.example.org/actors/wiki' ];
	}

	/**
	 * @dataProvider provideInstanceWebFinger
	 */
	public function testInstanceWebFinger( string $resource ) {
		$response = $this->executeHandler( $this->newWebFingerHandler(),
			new RequestData( [ 'queryParams' => [ 'resource' => $resource ] ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/jrd+json', $response->getHeaderLine( 'Content-Type' ) );
		$data = json_decode( $response->getBody(), true );
		$this->assertSame( 'acct:wiki@wiki.example.org', $data['subject'] );
		$this->assertSame( [ 'https://fedi.example.org/actors/wiki', 'https://wiki.example.org/' ],
			$data['aliases'] );
		$this->assertSame( [
			[
				'rel' => 'self',
				'type' => 'application/activity+json',
				'href' => 'https://fedi.example.org/actors/wiki',
			],
			[
				'rel' => 'http://webfinger.net/rel/profile-page',
				'type' => 'text/html',
				'href' => 'https://wiki.example.org/',
			],
		], $data['links'] );
	}

	public static function provideWebFingerMiss(): iterable {
		yield 'other host' => [ 'acct:category_%d@example.com' ];
		yield 'no host' => [ 'acct:category_%d' ];
		yield 'other URL' => [ 'https://example.com/actors/category_%d' ];
		yield 'instance actor on another host' => [ 'acct:wiki@example.com' ];
		yield 'instance actor elsewhere' => [ 'https://example.com/actors/wiki' ];
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
