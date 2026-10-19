<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\PurgeActorJob;
use MediaWiki\Extension\FediFeeds\Quotes;
use MediaWiki\Extension\FediFeeds\Rest\FeedInboxHandler;
use MediaWiki\Extension\FediFeeds\Rest\FollowersHandler;
use MediaWiki\Extension\FediFeeds\Rest\InboxHandler;
use MediaWiki\Extension\FediFeeds\Rest\QuoteAuthorizationHandler;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use StatusValue;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Rest\InboxHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\FeedInboxHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\FollowersHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\QuoteAuthorizationHandler
 * @covers \MediaWiki\Extension\FediFeeds\Followers
 * @covers \MediaWiki\Extension\FediFeeds\PurgeActorJob
 * @covers \MediaWiki\Extension\FediFeeds\Quotes
 * @covers \MediaWiki\Extension\FediFeeds\Signatures\GoneActors
 * @group Database
 */
class InboxTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const REMOTE = 'https://remote.example/users/a';
	private const POST = 'https://remote.example/users/a/statuses/1';
	private const REQUEST = 'https://remote.example/users/a/quote_requests/1';
	public const SHARED_INBOX = 'https://remote.example/inbox';

	/** @var array{target: ?string, signer: ?string} */
	private array $keys;

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://wiki.example.org',
			'FediFeedsActorBase' => 'https://fedi.example.org/actors/',
		] );
		$this->keys = [ 'target' => null, 'signer' => self::REMOTE ];
	}

	private function newRemoteKeys(): RemoteKeys {
		return new class (
			$this->createNoOpMock( HttpRequestFactory::class ),
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$this->keys
		) extends RemoteKeys {
			public function __construct( $http, $cache, private array &$keys ) {
				parent::__construct( $http, $cache );
			}

			public function verify(
				string $method, string $target, array $headers, string $body, ?int $now = null
			): ?string {
				if ( $this->keys['signer'] === 'throw' ) {
					throw new RuntimeException( 'verify() should not be called' );
				}
				$this->keys['target'] = $target;
				return $this->keys['signer'];
			}

			public function fetchDocument( string $url ): mixed {
				return [
					'id' => $url,
					'inbox' => "$url/inbox",
					'endpoints' => [ 'sharedInbox' => InboxTest::SHARED_INBOX ],
				];
			}
		};
	}

	private function getFollowers(): Followers {
		return $this->getServiceContainer()->getService( 'FediFeeds.Followers' );
	}

	private function getQuotes(): Quotes {
		return $this->getServiceContainer()->getService( 'FediFeeds.Quotes' );
	}

	private function newCategory(): Feed {
		$this->editPage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$this->runDeferredUpdates();
		return new Feed( 'category', $this->getServiceContainer()->getPageStore()->getPageByText( 'Category:Posts' ) );
	}

	private function post( array $activity, ?string $handle ) {
		$services = $this->getServiceContainer();
		$path = $handle === null ? 'inbox' : "$handle/inbox";
		$class = $handle === null ? InboxHandler::class : FeedInboxHandler::class;
		return $this->executeHandler(
			new $class( $services->getService( 'FediFeeds.Notes' ), $services->getPageStore(),
				$this->newRemoteKeys(), $this->getFollowers(), $this->getQuotes(), $services->getJobQueueGroup() ),
			new RequestData( [
				'method' => 'POST',
				'uri' => new Uri( "https://fedi.example.org/rest.php/fedifeeds/actors/$path" ),
				'pathParams' => $handle === null ? [] : [ 'handle' => $handle ],
				'headers' => [ 'Content-Type' => 'application/activity+json' ],
				'bodyContents' => json_encode( $activity ),
			] )
		);
	}

	private static function newFollow( Feed $feed ): array {
		return [
			'id' => self::REMOTE . '#follows/1',
			'type' => 'Follow',
			'actor' => self::REMOTE,
			'object' => 'https://fedi.example.org/actors/' . $feed->getHandle(),
		];
	}

	public function testFollowIsStoredAndAccepted() {
		$feed = $this->newCategory();
		$response = $this->post( self::newFollow( $feed ), $feed->getHandle() );
		$this->assertSame( 202, $response->getStatusCode() );
		$this->assertSame( '/actors/' . $feed->getHandle() . '/inbox', $this->keys['target'] );
		$this->assertSame( 1, $this->getFollowers()->count( $feed ) );
		$this->runDeferredUpdates();
		$this->assertSame( 1, $this->getServiceContainer()->getJobQueueGroup()->get( DeliveryJob::TYPE )->getSize() );
	}

	public function testSharedInboxTakesTheSameFollow() {
		$feed = $this->newCategory();
		$this->assertSame( 202, $this->post( self::newFollow( $feed ), null )->getStatusCode() );
		$this->assertSame( '/actors/inbox', $this->keys['target'] );
		$this->assertSame( 1, $this->getFollowers()->count( $feed ) );
	}

	public function testUndoRemovesTheFollow() {
		$feed = $this->newCategory();
		$this->post( self::newFollow( $feed ), $feed->getHandle() );
		$undo = [ 'type' => 'Undo', 'actor' => self::REMOTE, 'object' => self::newFollow( $feed ) ];
		$this->assertSame( 202, $this->post( $undo, $feed->getHandle() )->getStatusCode() );
		$this->assertSame( 0, $this->getFollowers()->count( $feed ) );
	}

	public function testBadSignature() {
		$feed = $this->newCategory();
		$this->keys['signer'] = null;
		$this->assertSame( 401, $this->post( self::newFollow( $feed ), $feed->getHandle() )->getStatusCode() );
		$this->keys['signer'] = 'https://other.example/users/b';
		$this->assertSame( 401, $this->post( self::newFollow( $feed ), $feed->getHandle() )->getStatusCode() );
		$this->assertSame( 0, $this->getFollowers()->count( $feed ) );
	}

	public function testDeleteFromANonFollowerIsDroppedUnverified() {
		$this->keys['signer'] = 'throw';
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::REMOTE ];
		$this->assertSame( 202, $this->post( $delete, null )->getStatusCode() );
	}

	/**
	 * Answers each fetch of an actor with the status, and counts the fetches.
	 */
	private function setRemoteStatus( int $status, ?int &$fetches = 0 ): void {
		$fetches = 0;
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		$request->method( 'getStatus' )->willReturn( $status );
		$http = $this->createMock( HttpRequestFactory::class );
		$http->method( 'create' )->willReturnCallback( static function ( $url ) use ( $request, &$fetches ) {
			$fetches++;
			return $request;
		} );
		$this->setService( 'HttpRequestFactory', $http );
	}

	private function newOtherCategory(): Feed {
		$this->editPage( 'Category:News', '{{FEDIFEEDSNAME:News}}' );
		$this->runDeferredUpdates();
		return new Feed( 'category', $this->getServiceContainer()->getPageStore()->getPageByText( 'Category:News' ) );
	}

	private function runPurges(): void {
		$this->runJobs( [ 'minJobs' => 0 ], [ 'type' => PurgeActorJob::TYPE ] );
	}

	public function testDeleteFromANonFollowerFetchesNothing() {
		$this->setRemoteStatus( 410, $fetches );
		$this->keys['signer'] = 'throw';
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::REMOTE ];
		$this->assertSame( 202, $this->post( $delete, null )->getStatusCode() );
		$this->runDeferredUpdates();
		$this->assertSame( 0, $this->getServiceContainer()->getJobQueueGroup()->get( PurgeActorJob::TYPE )
			->getSize() );
		$this->assertSame( 0, $fetches );
	}

	public function testSignedDeleteOfTheActorDropsItsFollows() {
		$this->setRemoteStatus( 200, $fetches );
		$feed = $this->newCategory();
		$other = $this->newOtherCategory();
		$this->getFollowers()->add( $feed, self::REMOTE, self::SHARED_INBOX );
		$this->getFollowers()->add( $other, self::REMOTE, self::SHARED_INBOX );
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::REMOTE ];
		$this->assertSame( 202, $this->post( $delete, null )->getStatusCode() );
		$this->runPurges();
		$this->assertSame( 0, $this->getFollowers()->count( $feed ) );
		$this->assertSame( 0, $this->getFollowers()->count( $other ) );
		$this->assertSame( 0, $fetches, 'a verified Delete needs no fetch' );
	}

	public function testUnverifiedDeleteOfAGoneActorDropsItsFollows() {
		$this->setRemoteStatus( 410, $fetches );
		$feed = $this->newCategory();
		$this->getFollowers()->add( $feed, self::REMOTE, self::SHARED_INBOX );
		$this->keys['signer'] = null;
		$tombstone = [ 'type' => 'Delete', 'actor' => self::REMOTE,
			'object' => [ 'type' => 'Tombstone', 'id' => self::REMOTE ] ];
		$this->assertSame( 202, $this->post( $tombstone, $feed->getHandle() )->getStatusCode() );
		$this->runPurges();
		$this->assertSame( 0, $this->getFollowers()->count( $feed ) );
		$this->assertSame( 1, $fetches );
	}

	public function testUnverifiedDeleteOfALiveActorKeepsItsFollows() {
		$this->setRemoteStatus( 200, $fetches );
		$feed = $this->newCategory();
		$this->getFollowers()->add( $feed, self::REMOTE, self::SHARED_INBOX );
		$this->keys['signer'] = null;
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::REMOTE ];
		$this->assertSame( 202, $this->post( $delete, null )->getStatusCode() );
		$this->runPurges();
		$this->assertSame( 1, $this->getFollowers()->count( $feed ) );
		$this->assertSame( 1, $fetches );
	}

	public function testUnverifiedDeleteOfAnotherObjectIsRefused() {
		$feed = $this->newCategory();
		$this->getFollowers()->add( $feed, self::REMOTE, self::SHARED_INBOX );
		$this->keys['signer'] = null;
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::REMOTE . '/statuses/1' ];
		$this->assertSame( 401, $this->post( $delete, null )->getStatusCode() );
		$this->assertSame( 1, $this->getFollowers()->count( $feed ) );
	}

	public function testPurgeJobDropsEveryFollowOfTheActor() {
		$feed = $this->newCategory();
		$other = $this->newOtherCategory();
		$followers = $this->getFollowers();
		$followers->add( $feed, self::REMOTE, self::SHARED_INBOX );
		$followers->add( $other, self::REMOTE, self::SHARED_INBOX );
		$followers->add( $feed, 'https://remote.example/users/b', self::SHARED_INBOX );
		$job = new PurgeActorJob( [ 'actor' => self::REMOTE, 'check' => false ] );
		$this->assertTrue( $job->run() );
		$this->assertSame( 1, $followers->count( $feed ) );
		$this->assertSame( 0, $followers->count( $other ) );
		$this->assertFalse( $followers->isFollower( self::REMOTE ) );
	}

	public function testOtherActivitiesAreDropped() {
		$feed = $this->newCategory();
		$like = [ 'type' => 'Like', 'actor' => self::REMOTE, 'object' => 'https://fedi.example.org/actors/x/notes/1' ];
		$this->assertSame( 202, $this->post( $like, $feed->getHandle() )->getStatusCode() );
		$this->assertSame( 0, $this->getFollowers()->count( $feed ) );
	}

	public function testFollowOfNoFeed() {
		$follow = [ 'type' => 'Follow', 'actor' => self::REMOTE, 'object' => 'https://fedi.example.org/actors/blog' ];
		$this->assertSame( 404, $this->post( $follow, null )->getStatusCode() );
	}

	public function testFollowersShowsOnlyTheCount() {
		$feed = $this->newCategory();
		$this->post( self::newFollow( $feed ), $feed->getHandle() );
		$services = $this->getServiceContainer();
		$response = $this->executeHandler(
			new FollowersHandler( $services->getService( 'FediFeeds.Notes' ), $services->getPageStore(),
				$this->getFollowers() ),
			new RequestData( [ 'pathParams' => [ 'handle' => $feed->getHandle() ] ] )
		);
		$this->assertSame( [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => 'https://fedi.example.org/actors/' . $feed->getHandle() . '/followers',
			'type' => 'OrderedCollection',
			'totalItems' => 1,
		], json_decode( $response->getBody(), true ) );
	}

	/** Saves a page in the feed's category and returns its note id. */
	private function newNote( Feed $feed ): string {
		$this->editPage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$this->runDeferredUpdates();
		$post = $this->getServiceContainer()->getPageStore()->getPageByText( 'First post' )->getId();
		return 'https://fedi.example.org/actors/' . $feed->getHandle() . "/notes/$post";
	}

	private static function newQuoteRequest( string $note ): array {
		return [
			'@context' => [ 'https://www.w3.org/ns/activitystreams',
				[ 'QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest' ] ],
			'id' => self::REQUEST,
			'type' => 'QuoteRequest',
			'actor' => self::REMOTE,
			'object' => $note,
			'instrument' => [
				'id' => self::POST,
				'type' => 'Note',
				'attributedTo' => self::REMOTE,
				'quote' => $note,
			],
		];
	}

	/** @return array[] The params of the queued deliveries */
	private function getDeliveries(): array {
		$this->runDeferredUpdates();
		$params = [];
		foreach ( $this->getServiceContainer()->getJobQueueGroup()->get( DeliveryJob::TYPE )->getAllQueuedJobs()
			as $job
		) {
			$params[] = $job->getParams();
		}
		return $params;
	}

	/** @return array|int The authorization at the URL, or the HTTP error's code */
	private function getQuoteAuthorization( string $url ) {
		$this->assertSame( 1, preg_match( '#^https://fedi\.example\.org/actors/(.+)/quote_authorizations/(\d+)$#',
			$url, $m ) );
		$services = $this->getServiceContainer();
		$handler = new QuoteAuthorizationHandler( $services->getService( 'FediFeeds.Notes' ),
			$services->getPageStore(), $this->getQuotes() );
		$request = new RequestData( [ 'pathParams' => [ 'handle' => $m[1], 'id' => $m[2] ] ] );
		try {
			$response = $this->executeHandler( $handler, $request );
		} catch ( LocalizedHttpException $ex ) {
			return $ex->getCode();
		}
		$this->assertSame( 'application/activity+json', $response->getHeaderLine( 'Content-Type' ) );
		return json_decode( $response->getBody(), true );
	}

	/** Quotes a note and returns the note and the URL of its authorization. */
	private function quote( Feed $feed ): array {
		$note = $this->newNote( $feed );
		$this->assertSame( 202, $this->post( self::newQuoteRequest( $note ), null )->getStatusCode() );
		$deliveries = $this->getDeliveries();
		$this->assertCount( 1, $deliveries );
		return [ $note, $deliveries[0]['activity']['result'] ];
	}

	public function testQuoteRequestIsAcceptedWithAnAuthorization() {
		$feed = $this->newCategory();
		[ $note, $url ] = $this->quote( $feed );
		$actor = 'https://fedi.example.org/actors/' . $feed->getHandle();

		$delivery = $this->getDeliveries()[0];
		$this->assertSame( self::SHARED_INBOX, $delivery['inbox'] );
		$this->assertSame( "$actor#main-key", $delivery['keyId'] );
		$accept = $delivery['activity'];
		$this->assertSame( 'https://www.w3.org/ns/activitystreams', $accept['@context'][0] );
		$this->assertSame( 'https://w3id.org/fep/044f#QuoteAuthorization',
			$accept['@context'][1]['QuoteAuthorization'] );
		$this->assertSame( 'Accept', $accept['type'] );
		$this->assertSame( $actor, $accept['actor'] );
		$this->assertSame( [
			'id' => self::REQUEST,
			'type' => 'QuoteRequest',
			'actor' => self::REMOTE,
			'object' => $note,
			'instrument' => self::POST,
		], $accept['object'] );
		$this->assertStringStartsWith( "$actor/quote_authorizations/", $accept['result'] );

		$authorization = $this->getQuoteAuthorization( $url );
		$this->assertSame( $accept['@context'], $authorization['@context'] );
		unset( $authorization['@context'] );
		$this->assertSame( [
			'id' => $url,
			'type' => 'QuoteAuthorization',
			'attributedTo' => $actor,
			'interactingObject' => self::POST,
			'interactionTarget' => $note,
		], $authorization );
	}

	public function testSectionNoteIsQuotedUntilTheSectionGoes() {
		$text = "{{FEDIFEEDSNAME:Releases|section}}\n";
		$this->editPage( 'Release notes', $text );
		$this->editPage( 'Release notes', "$text\n== 1.1 ==\nThe second release.\n" );
		$this->runDeferredUpdates();
		$page = $this->getServiceContainer()->getPageStore()->getPageByText( 'Release notes' )->getId();
		$note = "https://fedi.example.org/actors/section_$page/notes/MS4x";

		$this->assertSame( 202, $this->post( self::newQuoteRequest( $note ), null )->getStatusCode() );
		$url = $this->getDeliveries()[0]['activity']['result'];
		$this->assertSame( $note, $this->getQuoteAuthorization( $url )['interactionTarget'] );

		$this->editPage( 'Release notes', $text );
		$this->assertSame( 404, $this->getQuoteAuthorization( $url ) );
	}

	public function testFeedInboxTakesTheSameQuoteRequest() {
		$feed = $this->newCategory();
		$note = $this->newNote( $feed );
		$this->assertSame( 202, $this->post( self::newQuoteRequest( $note ), $feed->getHandle() )->getStatusCode() );
		$this->assertSame( '/actors/' . $feed->getHandle() . '/inbox', $this->keys['target'] );
		$this->assertCount( 1, $this->getDeliveries() );
		$this->assertTrue( $this->getQuotes()->isQuoter( self::REMOTE ) );
	}

	public function testRepeatedQuoteRequestKeepsTheAuthorization() {
		$feed = $this->newCategory();
		[ $note, $url ] = $this->quote( $feed );
		$again = [ 'id' => self::REQUEST . '-again' ] + self::newQuoteRequest( $note );
		$this->assertSame( 202, $this->post( $again, null )->getStatusCode() );
		$deliveries = $this->getDeliveries();
		$this->assertCount( 2, $deliveries );
		$this->assertSame( $url, $deliveries[1]['activity']['result'] );
	}

	public static function provideDroppedQuoteRequest(): iterable {
		yield 'a note that is not there' => [ static fn ( $note ) => [ 'object' => "{$note}0" ] ];
		yield 'another wiki\'s note' => [
			static fn ( $note ) => [ 'object' => str_replace( 'fedi.example.org', 'other.example', $note ) ],
		];
		yield 'a post on another server' => [
			static fn ( $note ) => [ 'instrument' => 'https://other.example/notes/1' ],
		];
		yield 'no post' => [ static fn ( $note ) => [ 'instrument' => null ] ];
		yield 'a post id too long to store' => [
			static fn ( $note ) => [ 'instrument' => self::POST . str_repeat( '1', 255 ) ],
		];
		yield 'a request id on another server' => [
			static fn ( $note ) => [ 'id' => 'https://other.example/quote_requests/1' ],
		];
	}

	/**
	 * @dataProvider provideDroppedQuoteRequest
	 */
	public function testDroppedQuoteRequest( callable $change ) {
		$feed = $this->newCategory();
		$note = $this->newNote( $feed );
		$request = $change( $note ) + self::newQuoteRequest( $note );
		$this->assertSame( 202, $this->post( $request, null )->getStatusCode() );
		$this->assertSame( [], $this->getDeliveries() );
		$this->assertFalse( $this->getQuotes()->isQuoter( self::REMOTE ) );
	}

	public function testQuoteRequestWithABadSignature() {
		$feed = $this->newCategory();
		$note = $this->newNote( $feed );
		$this->keys['signer'] = null;
		$this->assertSame( 401, $this->post( self::newQuoteRequest( $note ), null )->getStatusCode() );
		$this->keys['signer'] = 'https://other.example/users/b';
		$this->assertSame( 401, $this->post( self::newQuoteRequest( $note ), null )->getStatusCode() );
		$this->assertSame( [], $this->getDeliveries() );
		$this->assertFalse( $this->getQuotes()->isQuoter( self::REMOTE ) );
	}

	public static function provideWithdrawal(): iterable {
		yield 'Undo of the request' => [ static fn ( $note ) => [
			'type' => 'Undo',
			'actor' => self::REMOTE,
			'object' => self::newQuoteRequest( $note ),
		] ];
		yield 'Undo of the request by its id' => [ static fn () => [
			'type' => 'Undo',
			'actor' => self::REMOTE,
			'object' => self::REQUEST,
		] ];
		yield 'Delete of the post' => [ static fn () => [
			'type' => 'Delete',
			'actor' => self::REMOTE,
			'object' => [ 'id' => self::POST, 'type' => 'Tombstone' ],
		] ];
		yield 'Delete of the actor' => [ static fn () => [
			'type' => 'Delete',
			'actor' => self::REMOTE,
			'object' => self::REMOTE,
		] ];
	}

	/**
	 * @dataProvider provideWithdrawal
	 */
	public function testWithdrawalRemovesTheAuthorization( callable $withdrawal ) {
		$feed = $this->newCategory();
		[ $note, $url ] = $this->quote( $feed );
		$this->assertSame( 202, $this->post( $withdrawal( $note ), null )->getStatusCode() );
		// A Delete of the actor is purged by a job
		$this->runPurges();
		$this->assertSame( 404, $this->getQuoteAuthorization( $url ) );
		$this->assertFalse( $this->getQuotes()->isQuoter( self::REMOTE ) );
	}

	public function testWithdrawalByAnotherActorKeepsTheAuthorization() {
		$feed = $this->newCategory();
		[ , $url ] = $this->quote( $feed );
		$this->keys['signer'] = 'https://remote.example/users/b';
		$delete = [ 'type' => 'Delete', 'actor' => 'https://remote.example/users/b', 'object' => self::POST ];
		$undo = [ 'type' => 'Undo', 'actor' => 'https://remote.example/users/b', 'object' => self::REQUEST ];
		$this->assertSame( 202, $this->post( $undo, null )->getStatusCode() );
		$this->assertSame( 202, $this->post( $delete, null )->getStatusCode() );
		$this->assertSame( $url, $this->getQuoteAuthorization( $url )['id'] );
	}

	public function testDeleteFromAQuoterIsVerified() {
		$feed = $this->newCategory();
		[ , $url ] = $this->quote( $feed );
		$this->keys['signer'] = null;
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::POST ];
		$this->assertSame( 401, $this->post( $delete, null )->getStatusCode() );
		$this->assertSame( $url, $this->getQuoteAuthorization( $url )['id'] );
	}

	public function testUnverifiedDeleteOfAGoneQuoterRemovesTheAuthorization() {
		$feed = $this->newCategory();
		[ , $url ] = $this->quote( $feed );
		$this->setRemoteStatus( 404 );
		$this->keys['signer'] = null;
		$delete = [ 'type' => 'Delete', 'actor' => self::REMOTE, 'object' => self::REMOTE ];
		$this->assertSame( 202, $this->post( $delete, null )->getStatusCode() );
		$this->runPurges();
		$this->assertSame( 404, $this->getQuoteAuthorization( $url ) );
	}

	public function testNoAuthorizationOnceTheNoteIsGone() {
		$feed = $this->newCategory();
		[ , $url ] = $this->quote( $feed );
		$this->deletePage( $this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( $this->getServiceContainer()->getTitleFactory()->newFromText( 'First post' ) ) );
		$this->assertSame( 404, $this->getQuoteAuthorization( $url ) );
		$this->assertSame( 404, $this->getQuoteAuthorization( "{$url}0" ) );
	}
}
