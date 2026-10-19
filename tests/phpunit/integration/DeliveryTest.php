<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\DeliveryHooks
 * @covers \MediaWiki\Extension\FediFeeds\Items
 * @covers \MediaWiki\Extension\FediFeeds\Followers
 * @covers \MediaWiki\Extension\FediFeeds\Notes
 * @group Database
 */
class DeliveryTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://wiki.example.org',
			'FediFeedsActorBase' => 'https://fedi.example.org/actors/',
		] );
	}

	/** Saves the page and returns its id once its links are stored and its jobs pushed. */
	private function savePage( string $title, string $text ): int {
		$this->editPage( $title, $text );
		$this->runDeferredUpdates();
		return $this->getServiceContainer()->getPageStore()->getPageByText( $title )->getId();
	}

	private function newFeed( string $title, string $text ): Feed {
		$this->savePage( $title, $text );
		return new Feed( 'category', $this->getServiceContainer()->getPageStore()->getPageByText( $title ) );
	}

	private function follow( Feed $feed, string $actor, string $inbox ): void {
		$this->getServiceContainer()->getService( 'FediFeeds.Followers' )->add( $feed, $actor, $inbox );
	}

	private function getItems(): Items {
		return $this->getServiceContainer()->getService( 'FediFeeds.Items' );
	}

	/** @return array[] The params of the queued deliveries */
	private function getDeliveries(): array {
		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( DeliveryJob::TYPE );
		$params = [];
		foreach ( $queue->getAllQueuedJobs() as $job ) {
			$params[] = $job->getParams();
		}
		return $params;
	}

	public function testCreationIsRecordedAndDelivered() {
		$feed = $this->newFeed( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$this->follow( $feed, 'https://remote.example/users/a', 'https://remote.example/inbox' );
		$post = $this->savePage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$this->assertTrue( $this->getItems()->has( $feed, $post ) );

		$deliveries = $this->getDeliveries();
		$this->assertCount( 1, $deliveries );
		$actor = 'https://fedi.example.org/actors/' . $feed->getHandle();
		$this->assertSame( 'https://remote.example/inbox', $deliveries[0]['inbox'] );
		$this->assertSame( "$actor#main-key", $deliveries[0]['keyId'] );
		$this->assertSame( 'Create', $deliveries[0]['activity']['type'] );
		$this->assertSame( "$actor/notes/$post", $deliveries[0]['activity']['object']['id'] );

		// Saving it again, or a null edit, sends nothing more
		$this->savePage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$this->savePage( 'First post', "Hello again.\n\n[[Category:Posts]]" );
		$this->assertCount( 1, $this->getDeliveries() );
	}

	public function testCategoryFromATemplateCounts() {
		$feed = $this->newFeed( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$this->savePage( 'Template:Post', '[[Category:Posts]]' );
		$post = $this->savePage( 'Templated post', "Hello.\n\n{{Post}}" );
		$this->assertTrue( $this->getItems()->has( $feed, $post ) );
	}

	public function testPageAddedLaterIsNotAnItem() {
		$feed = $this->newFeed( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$this->follow( $feed, 'https://remote.example/users/a', 'https://remote.example/inbox' );
		$page = $this->savePage( 'Old page', 'Hello.' );
		$this->savePage( 'Old page', "Hello.\n\n[[Category:Posts]]" );
		$this->assertFalse( $this->getItems()->has( $feed, $page ) );
		$this->assertSame( [], $this->getDeliveries() );
	}

	public function testNothingForACategoryThatDoesNotPublish() {
		$feed = $this->newFeed( 'Category:Maintenance', 'Pages to fix.' );
		$this->follow( $feed, 'https://remote.example/users/a', 'https://remote.example/inbox' );
		$page = $this->savePage( 'Broken page', "Text.\n\n[[Category:Maintenance]]" );
		$this->assertFalse( $this->getItems()->has( $feed, $page ) );
		$this->assertSame( [], $this->getDeliveries() );
	}

	public function testRecordedWithoutFollowers() {
		$feed = $this->newFeed( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$post = $this->savePage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$this->assertTrue( $this->getItems()->has( $feed, $post ) );
		$this->assertSame( [], $this->getDeliveries() );
	}

	public function testOneDeliveryPerInbox() {
		$feed = $this->newFeed( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$this->follow( $feed, 'https://remote.example/users/a', 'https://remote.example/inbox' );
		$this->follow( $feed, 'https://remote.example/users/b', 'https://remote.example/inbox' );
		$this->follow( $feed, 'https://other.example/users/c', 'https://other.example/users/c/inbox' );
		$this->savePage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$inboxes = array_column( $this->getDeliveries(), 'inbox' );
		sort( $inboxes );
		$this->assertSame( [ 'https://other.example/users/c/inbox', 'https://remote.example/inbox' ], $inboxes );
	}

	public function testOutboxReadsTheRecordedItems() {
		$feed = $this->newFeed( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$first = $this->savePage( 'First post', "One.\n\n[[Category:Posts]]" );
		$later = $this->savePage( 'Added later', 'Two.' );
		$this->savePage( 'Added later', "Two.\n\n[[Category:Posts]]" );
		// An item that leaves the category stays a note while its page exists
		$this->savePage( 'First post', 'One.' );

		/** @var Notes $notes */
		$notes = $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
		$outbox = $notes->getOutbox( $feed );
		$this->assertSame( 1, $outbox['totalItems'] );
		$actor = 'https://fedi.example.org/actors/' . $feed->getHandle();
		$this->assertSame( [ "$actor/notes/$first" ],
			array_map( static fn ( $create ) => $create['object']['id'], $outbox['orderedItems'] ) );
		$this->assertNotNull( $notes->getItem( $feed, $first ) );
		$this->assertNull( $notes->getItem( $feed, $later ) );
	}
}
