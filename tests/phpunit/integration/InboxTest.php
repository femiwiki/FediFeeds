<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Rest\FollowersHandler;
use MediaWiki\Extension\FediFeeds\Rest\InboxHandler;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Rest\InboxHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\FollowersHandler
 * @covers \MediaWiki\Extension\FediFeeds\Followers
 * @group Database
 */
class InboxTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const REMOTE = 'https://remote.example/users/a';
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

	private function newCategory(): Feed {
		$this->editPage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$this->runDeferredUpdates();
		return new Feed( 'category', $this->getServiceContainer()->getPageStore()->getPageByText( 'Category:Posts' ) );
	}

	private function post( array $activity, ?string $handle ) {
		$services = $this->getServiceContainer();
		$path = $handle === null ? 'inbox' : "$handle/inbox";
		return $this->executeHandler(
			new InboxHandler( $services->getService( 'FediFeeds.Notes' ), $services->getPageStore(),
				$this->newRemoteKeys(), $this->getFollowers(), $services->getJobQueueGroup() ),
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
}
