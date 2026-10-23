<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Storage\EditResult;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\FediFeeds\DeliveryHooks
 * @covers \MediaWiki\Extension\FediFeeds\Item
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
		$this->assertNotNull( $notes->getItem( $feed, (string)$first ) );
		$this->assertNull( $notes->getItem( $feed, (string)$later ) );
	}

	private const RELEASES = "{{FEDIFEEDSNAME:Releases|section}}\n\n== 1.0 ==\nThe first release.\n";

	private const LOG_HEAD = "{{FEDIFEEDSNAME:Updates|section|section-unit=3}}\n";

	/** A section feed with one follower, its page created with the text, which makes no items */
	private function newSectionFeed( string $text = self::RELEASES, string $title = 'Release notes' ): Feed {
		$this->savePage( $title, $text );
		$feed = new Feed( 'section', $this->getServiceContainer()->getPageStore()->getPageByText( $title ) );
		$this->follow( $feed, 'https://remote.example/users/a', 'https://remote.example/inbox' );
		return $feed;
	}

	/**
	 * An update log: a section for each day, newest first, holding a section for each update,
	 * newest first
	 *
	 * @param array<string,string[]> $days Times by day
	 */
	private static function log( array $days ): string {
		$text = self::LOG_HEAD;
		foreach ( $days as $day => $times ) {
			$text .= "\n== $day ==\n";
			foreach ( $times as $time ) {
				$text .= "=== $time ===\n[https://example.org/pull/$time Pull request]\n* Updated $time\n";
			}
		}
		return $text;
	}

	/** The item id of a section item in a note id */
	private static function itemId( string $key ): string {
		return rtrim( strtr( base64_encode( $key ), '+/', '-_' ), '=' );
	}

	/** @return string[] The keys of the feed's items, newest first */
	private function getKeys( Feed $feed ): array {
		return array_column( $this->getItems()->getRecent( $feed, 100 ), 'key' );
	}

	public function testNewSectionIsRecordedAndDelivered() {
		$feed = $this->newSectionFeed();
		$page = $this->savePage( 'Release notes',
			self::RELEASES . "\n== 1.1 ==\nThe second release.\n\n=== Fixes ===\n* A fix\n" );
		// The default unit is level 2, and the subsection belongs to the item
		$this->assertSame( [ '1.1' ], $this->getKeys( $feed ) );

		$deliveries = $this->getDeliveries();
		$this->assertCount( 1, $deliveries );
		$actor = 'https://fedi.example.org/actors/section_' . $page;
		$this->assertSame( 'https://remote.example/inbox', $deliveries[0]['inbox'] );
		$this->assertSame( "$actor#main-key", $deliveries[0]['keyId'] );
		$this->assertSame( 'Create', $deliveries[0]['activity']['type'] );
		$note = $deliveries[0]['activity']['object'];
		$this->assertSame( "$actor/notes/MS4x", $note['id'] );
		$this->assertArrayNotHasKey( 'summary', $note );
		$this->assertSame( '<p><strong>1.1</strong></p><p>The second release.</p><p><strong>Fixes</strong></p>'
			. '<ul><li>A fix</li></ul><p><a href="' . htmlspecialchars( $note['url'] ) . '">Release notes</a></p>',
			$note['content'] );
	}

	public function testUpdateLog() {
		$feed = $this->newSectionFeed( self::log( [ '10월 18일' => [ '14:10' ] ] ), 'Update log' );
		// A new day with its first update is one item, the update
		$this->savePage( 'Update log', self::log( [ '10월 19일' => [ '09:00' ], '10월 18일' => [ '14:10' ] ] ) );
		$this->assertSame( [ "10월 19일\n09:00" ], $this->getKeys( $feed ) );
		// So is another update that day
		$this->savePage( 'Update log',
			self::log( [ '10월 19일' => [ '11:30', '09:00' ], '10월 18일' => [ '14:10' ] ] ) );
		$this->assertSame( [ "10월 19일\n11:30", "10월 19일\n09:00" ], $this->getKeys( $feed ) );
		// A time that an older day has too is new under a new day
		$this->savePage( 'Update log', self::log(
			[ '10월 20일' => [ '14:10' ], '10월 19일' => [ '11:30', '09:00' ], '10월 18일' => [ '14:10' ] ] ) );
		$this->assertSame( [ "10월 20일\n14:10", "10월 19일\n11:30", "10월 19일\n09:00" ], $this->getKeys( $feed ) );
		$this->assertCount( 3, $this->getDeliveries() );

		$note = $this->getDeliveries()[0]['activity']['object'];
		$this->assertStringStartsWith( '<p><strong>10월 19일 09:00</strong></p>'
			. '<p><a href="https://example.org/pull/09:00">Pull request</a></p><ul><li>Updated 09:00</li></ul>',
			$note['content'] );
	}

	public function testCategorySubsectionsAreContent() {
		$feed = $this->newSectionFeed( self::log( [ '10월 18일' => [ '14:10' ] ] ), 'Update log' );
		$text = self::log( [ '10월 19일' => [ '09:00' ], '10월 18일' => [ '14:10' ] ] );
		$text = str_replace( "* Updated 09:00\n", "* Updated 09:00\n==== 추가 ====\n* [[New page]]\n", $text );
		$this->savePage( 'Update log', $text );
		$this->assertSame( [ "10월 19일\n09:00" ], $this->getKeys( $feed ) );
		$content = $this->getDeliveries()[0]['activity']['object']['content'];
		$this->assertStringContainsString( '<ul><li>Updated 09:00</li></ul><p><strong>추가</strong></p>'
			. '<ul><li>New page</li></ul><p><a href=', $content );
	}

	public function testSamePathTwice() {
		$feed = $this->newSectionFeed( self::log( [ '10월 18일' => [ '14:10' ] ] ), 'Update log' );
		$this->savePage( 'Update log', self::log( [ '10월 19일' => [ '08:00' ], '10월 18일' => [ '14:10' ] ] ) );
		$this->savePage( 'Update log',
			self::log( [ '10월 19일' => [ '09:00', '08:00' ], '10월 18일' => [ '14:10' ] ] ) );
		$this->savePage( 'Update log',
			self::log( [ '10월 19일' => [ '14:10', '09:00', '08:00' ], '10월 18일' => [ '14:10' ] ] ) );
		// Two updates in the same minute make the same path twice; the second is numbered 2
		$text = self::log( [ '10월 19일' => [ '14:10', '14:10', '09:00', '08:00' ], '10월 18일' => [ '14:10' ] ] );
		$text = preg_replace( '/Updated 14:10/', 'Updated again', $text, 1 );
		$page = $this->savePage( 'Update log', $text );
		$this->assertSame( [ "10월 19일\n14:10\n 2", "10월 19일\n14:10" ], array_slice( $this->getKeys( $feed ), 0, 2 ) );
		$this->assertCount( 4, $this->getDeliveries() );

		/** @var Notes $notes */
		$notes = $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
		$feed = new Feed( 'section', $this->getServiceContainer()->getPageStore()->getPageById( $page ) );
		$second = $notes->getItem( $feed, self::itemId( "10월 19일\n14:10\n 2" ) );
		$this->assertNotNull( $second );
		// The page adds updates above old ones, so the second is the upper one
		$this->assertStringContainsString( 'Updated again', $notes->getNote( $feed, $second )['content'] );

		// With one of them gone, only the first resolves
		$page = $this->savePage( 'Update log',
			self::log( [ '10월 19일' => [ '14:10', '09:00', '08:00' ], '10월 18일' => [ '14:10' ] ] ) );
		$feed = new Feed( 'section', $this->getServiceContainer()->getPageStore()->getPageById( $page ) );
		$this->assertNull( $notes->getItem( $feed, self::itemId( "10월 19일\n14:10\n 2" ) ) );
		$this->assertNotNull( $notes->getItem( $feed, self::itemId( "10월 19일\n14:10" ) ) );
	}

	public function testPermalink() {
		$feed = $this->newSectionFeed();
		$this->savePage( 'Release notes', self::RELEASES . "\n== Café & co. ==\nThe second release.\n" );
		$rev = $this->getServiceContainer()->getPageStore()->getPageByText( 'Release notes' )->getLatest();
		$url = $this->getDeliveries()[0]['activity']['object']['url'];
		$this->assertSame( "https://wiki.example.org/index.php?title=Release_notes&oldid=$rev"
			. '&utm_source=fediverse#Café_&_co.', $url );
		// A later edit moves the section down, and the note still links where it appeared
		$this->savePage( 'Release notes', "{{FEDIFEEDSNAME:Releases|section}}\n\n== 1.1 ==\nNext.\n\n"
			. "== 1.0 ==\nThe first release.\n\n== Café & co. ==\nThe second release.\n" );
		/** @var Notes $notes */
		$notes = $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
		$feed = new Feed( 'section', $this->getServiceContainer()->getPageStore()->getPageByText( 'Release notes' ) );
		$item = $notes->getItem( $feed, self::itemId( 'Café & co.' ) );
		$this->assertSame( $url, $notes->getNote( $feed, $item )['url'] );
	}

	public function testLongContentIsCut() {
		$feed = $this->newSectionFeed();
		$long = str_repeat( 'word ', 150 );
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\n$long\n\nNot shown.\n" );
		$content = $this->getDeliveries()[0]['activity']['object']['content'];
		$this->assertStringNotContainsString( 'Not shown', $content );
		$this->assertMatchesRegularExpression( '#^<p><strong>1\.1</strong></p><p>(word ){99}word…</p>'
			. '<p><a href="[^"]+">Release notes</a></p>$#', $content );
	}

	public function testEditedSectionIsNotAnItem() {
		$feed = $this->newSectionFeed();
		$this->savePage( 'Release notes', self::RELEASES . "Fixed a typo.\n" );
		// A null edit
		$this->savePage( 'Release notes', self::RELEASES . "Fixed a typo.\n" );
		// Moving a section and rewording it keeps its heading
		$this->savePage( 'Release notes',
			"== 1.0 ==\nThe first release, reworded.\n\n{{FEDIFEEDSNAME:Releases|section}}" );
		$this->assertSame( 0, $this->getItems()->count( $feed ) );
		$this->assertSame( [], $this->getDeliveries() );
	}

	public function testChangedSectionIsUpdated() {
		$feed = $this->newSectionFeed();
		ConvertibleTimestamp::setFakeTime( '20261021090000' );
		$page = $this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\nThe second release.\n" );
		$created = $this->getDeliveries()[0]['activity']['object'];
		$this->assertArrayNotHasKey( 'updated', $created );

		// Rewording it later sends an Update of the same note, which now links to the revision
		ConvertibleTimestamp::setFakeTime( '20261021091500' );
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\nThe second release, fixed.\n" );
		$rev = $this->getServiceContainer()->getPageStore()->getPageById( $page )->getLatest();
		$deliveries = $this->getDeliveries();
		$this->assertCount( 2, $deliveries );
		$update = $deliveries[1]['activity'];
		$this->assertSame( 'Update', $update['type'] );
		$this->assertSame( $created['id'] . "/updates/$rev", $update['id'] );
		$note = $update['object'];
		$this->assertSame( $created['id'], $note['id'] );
		$this->assertSame( $created['published'], $note['published'] );
		$this->assertSame( '2026-10-21T09:15:00Z', $note['updated'] );
		$this->assertSame( $note['updated'], $update['published'] );
		$this->assertStringContainsString( 'The second release, fixed.', $note['content'] );
		$this->assertStringContainsString( "oldid=$rev&", $note['url'] );

		/** @var Notes $notes */
		$notes = $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
		$feed = new Feed( 'section', $this->getServiceContainer()->getPageStore()->getPageById( $page ) );
		$fetched = $notes->getNote( $feed, $notes->getItem( $feed, self::itemId( '1.1' ) ) );
		$this->assertSame( $note['updated'], $fetched['updated'] );
		$this->assertSame( $note['url'], $fetched['url'] );

		// A section that was never an item, and moving the item, send nothing
		$this->savePage( 'Release notes', "{{FEDIFEEDSNAME:Releases|section}}\n\n== 1.0 ==\nReworded.\n\n"
			. "== 1.1 ==\nThe second release, fixed.\n" );
		$this->savePage( 'Release notes', "{{FEDIFEEDSNAME:Releases|section}}\n\n"
			. "== 1.1 ==\nThe second release, fixed.\n\n== 1.0 ==\nReworded.\n" );
		$this->assertCount( 2, $this->getDeliveries() );
	}

	public function testBareLinkNumbersAreNoUpdate() {
		$feed = $this->newSectionFeed();
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\nSee [https://example.org/1.1].\n" );
		// Linking above renumbers the bare link from [1] to [2], which the note does not show
		$this->savePage( 'Release notes', "{{FEDIFEEDSNAME:Releases|section}}\n\n== 1.2 ==\n"
			. "See [https://example.org/1.2].\n\n== 1.0 ==\nThe first release.\n\n"
			. "== 1.1 ==\nSee [https://example.org/1.1].\n" );
		$deliveries = $this->getDeliveries();
		$this->assertSame( [ 'Create', 'Create' ], array_map(
			static fn ( $delivery ) => $delivery['activity']['type'], $deliveries ) );
		$this->assertStringContainsString(
			'See <a href="https://example.org/1.1">https://example.org/1.1</a>.',
			$deliveries[0]['activity']['object']['content'] );
		$this->assertSame( [ '1.2', '1.1' ], $this->getKeys( $feed ) );
	}

	public function testChangePastTheCutIsNoUpdate() {
		$this->newSectionFeed();
		$long = str_repeat( 'word ', 150 );
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\n$long\n\nNot shown.\n" );
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\n$long\n\nStill not shown.\n" );
		$this->assertCount( 1, $this->getDeliveries() );
	}

	public function testRevertOfAChangeIsUpdated() {
		$feed = $this->newSectionFeed();
		$services = $this->getServiceContainer();
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\nThe second release.\n" );
		$good = $services->getPageStore()->getPageByText( 'Release notes' )->getLatest();
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\nVandalism.\n" );
		$updater = $services->getWikiPageFactory()->newFromTitle( $feed->page )
			->newPageUpdater( $this->getTestSysop()->getAuthority() );
		$updater->setContent( 'main', new WikitextContent( self::RELEASES . "\n== 1.1 ==\nThe second release.\n" ) );
		$updater->markAsRevert( EditResult::REVERT_UNDO, $updater->grabParentRevision()->getId(), $good );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( '' ) );
		$this->runDeferredUpdates();
		$deliveries = $this->getDeliveries();
		$types = array_column( array_column( $deliveries, 'activity' ), 'type' );
		$this->assertSame( [ 'Create', 'Update', 'Update' ], $types );
		$this->assertStringContainsString( 'The second release.', $deliveries[2]['activity']['object']['content'] );
	}

	public function testRenamedSectionIsANewItem() {
		$feed = $this->newSectionFeed( self::log( [ '10월 19일' => [ '09:00' ] ] ), 'Update log' );
		$this->savePage( 'Update log', self::log( [ '10월 19일' => [ '09:05' ] ] ) );
		// Renaming the heading above it gives the item a new path too
		$this->savePage( 'Update log', self::log( [ '10월 19일 (월)' => [ '09:05' ] ] ) );
		$this->assertSame( [ "10월 19일 (월)\n09:05", "10월 19일\n09:05" ], $this->getKeys( $feed ) );
		$this->assertCount( 2, $this->getDeliveries() );
	}

	public function testBotEditCounts() {
		$feed = $this->newSectionFeed();
		$services = $this->getServiceContainer();
		$bot = $this->getTestUser( [ 'bot' ] )->getAuthority();
		$updater = $services->getWikiPageFactory()->newFromTitle( $feed->page )->newPageUpdater( $bot );
		$updater->setContent( 'main', new WikitextContent( self::RELEASES . "\n== 1.1 ==\nBy a bot.\n" ) );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( '' ), EDIT_FORCE_BOT );
		$this->runDeferredUpdates();
		$this->assertSame( [ '1.1' ], $this->getKeys( $feed ) );
		$this->assertCount( 1, $this->getDeliveries() );
	}

	public function testRevertIsNotAnItem() {
		$feed = $this->newSectionFeed();
		$services = $this->getServiceContainer();
		$first = $feed->page->getLatest();
		$this->savePage( 'Release notes', '{{FEDIFEEDSNAME:Releases|section}}' );
		$updater = $services->getWikiPageFactory()->newFromTitle( $feed->page )
			->newPageUpdater( $this->getTestSysop()->getAuthority() );
		$updater->setContent( 'main', new WikitextContent( self::RELEASES ) );
		$updater->markAsRevert( EditResult::REVERT_UNDO, $updater->grabParentRevision()->getId(), $first );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( '' ) );
		$this->runDeferredUpdates();
		$this->assertSame( 0, $this->getItems()->count( $feed ) );
	}

	public function testCreationRecordsNoSections() {
		$this->savePage( 'Release notes', self::RELEASES . "\n== 1.1 ==\nThe second release.\n" );
		$feed = new Feed( 'section', $this->getServiceContainer()->getPageStore()->getPageByText( 'Release notes' ) );
		$this->assertSame( 0, $this->getItems()->count( $feed ) );
	}

	public function testNothingForAPageThatDoesNotPublishSections() {
		// A display name for another kind does not publish sections
		$feed = $this->newSectionFeed( "{{FEDIFEEDSNAME:Releases|category}}\n\n== 1.0 ==\nThe first release.\n" );
		$this->savePage( 'Release notes', "== 1.0 ==\nThe first release.\n\n== 1.1 ==\nThe second release.\n" );
		$this->assertSame( 0, $this->getItems()->count( $feed ) );
		$this->assertSame( [], $this->getDeliveries() );
	}

	public function testArgumentsInAnyOrder() {
		$props = $this->getServiceContainer()->getPageProps();
		$pageStore = $this->getServiceContainer()->getPageStore();
		$names = [ 'fedifeeds-name', 'fedifeeds-name-section', 'fedifeeds-section-unit' ];
		foreach ( [
			'A' => [ '{{FEDIFEEDSNAME:Updates|section|section-unit=3}}', 'fedifeeds-name-section', '3' ],
			'B' => [ '{{FEDIFEEDSNAME:Updates| section-unit = 3 |section}}', 'fedifeeds-name-section', '3' ],
			'C' => [ '{{FEDIFEEDSNAME:Updates|section-unit=3}}', 'fedifeeds-name', '3' ],
			// A unit out of range is not set
			'D' => [ '{{FEDIFEEDSNAME:Updates|section-unit=7|section}}', 'fedifeeds-name-section', null ],
		] as $title => [ $text, $name, $unit ] ) {
			$this->savePage( $title, $text );
			$page = $pageStore->getPageByText( $title );
			$expected = [ $name => 'Updates' ] + ( $unit === null ? [] : [ 'fedifeeds-section-unit' => $unit ] );
			$this->assertSame( $expected, $props->getProperties( $page, $names )[$page->getId()], $text );
		}
	}

	public function testDisplayNameForEveryKindPublishesSections() {
		$feed = $this->newSectionFeed( "{{FEDIFEEDSNAME:Releases}}\n\n== 1.0 ==\nThe first release.\n" );
		$this->savePage( 'Release notes',
			"{{FEDIFEEDSNAME:Releases}}\n\n== 1.0 ==\nThe first release.\n\n== 1.1 ==\nThe second release.\n" );
		$this->assertSame( [ '1.1' ], $this->getKeys( $feed ) );
	}
}
