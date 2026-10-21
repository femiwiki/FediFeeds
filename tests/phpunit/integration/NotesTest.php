<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Rest\NoteHandler;
use MediaWiki\Extension\FediFeeds\Rest\OutboxHandler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Notes
 * @covers \MediaWiki\Extension\FediFeeds\Rest\NoteHandler
 * @covers \MediaWiki\Extension\FediFeeds\Rest\BrowserRedirect
 * @covers \MediaWiki\Extension\FediFeeds\Rest\OutboxHandler
 * @group Database
 */
class NotesTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://wiki.example.org',
			'FediFeedsActorBase' => 'https://fedi.example.org/actors/',
			'FediFeedsInteractions' => true,
		] );
	}

	/** Saves the page and returns its id once its links and properties are stored. */
	private function savePage( string $title, string $text ): int {
		$this->editPage( $title, $text );
		$this->runDeferredUpdates();
		return $this->getServiceContainer()->getPageStore()->getPageByText( $title )->getId();
	}

	private function getNotes(): Notes {
		return $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
	}

	private function executeNote( string $handle, int|string $item, ?string $accept = null ) {
		return $this->executeHandler(
			new NoteHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
			new RequestData( [
				'pathParams' => [ 'handle' => $handle, 'item' => (string)$item ],
				'headers' => $accept === null ? [] : [ 'Accept' => $accept ],
			] )
		);
	}

	private function getOutbox( string $handle ): array {
		$response = $this->executeHandler(
			new OutboxHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
			new RequestData( [ 'pathParams' => [ 'handle' => $handle ] ] )
		);
		$this->assertSame( 200, $response->getStatusCode() );
		return json_decode( $response->getBody(), true );
	}

	public function testNote() {
		$cat = $this->savePage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$post = $this->savePage( 'First post', "Hello '''world'''.\n\nSecond paragraph.\n\n[[Category:Posts]]" );
		$response = $this->executeNote( "category_$cat", $post );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/activity+json', $response->getHeaderLine( 'Content-Type' ) );
		$note = json_decode( $response->getBody(), true );
		$actor = "https://fedi.example.org/actors/category_$cat";
		$this->assertSame( "$actor/notes/$post", $note['id'] );
		$this->assertSame( 'Note', $note['type'] );
		$this->assertSame( $actor, $note['attributedTo'] );
		$this->assertStringStartsWith( '<p>Hello world.</p><p><a href="https://wiki.example.org/', $note['content'] );
		$this->assertStringEndsWith( '">First post</a></p>', $note['content'] );
		$this->assertStringStartsWith( 'https://wiki.example.org/', $note['url'] );
		$this->assertStringEndsWith( 'utm_source=fediverse', $note['url'] );
		$this->assertStringContainsString( 'href="' . htmlspecialchars( $note['url'] ) . '"', $note['content'] );
		$this->assertSame( [ 'https://www.w3.org/ns/activitystreams#Public' ], $note['to'] );
		$this->assertNotNull( $note['published'] );
		$this->assertSame( 'Accept', $response->getHeaderLine( 'Vary' ) );
	}

	public function testBrowserIsSentToTheNotesPage() {
		$text = "{{FEDIFEEDSNAME:Releases|section}}\n\nAbout the releases.\n";
		$page = $this->savePage( 'Release notes', $text );
		$this->savePage( 'Release notes', "$text\n== 1.0 ==\nThe first release.\n" );
		$url = json_decode( $this->executeNote( "section_$page", 'MS4w' )->getBody(), true )['url'];
		$this->assertStringContainsString( '&oldid=', $url );
		$this->assertStringEndsWith( '#1.0', $url );

		$response = $this->executeNote( "section_$page", 'MS4w',
			'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' );
		$this->assertSame( 303, $response->getStatusCode() );
		$this->assertSame( $url, $response->getHeaderLine( 'Location' ) );
		$this->assertSame( 'Accept', $response->getHeaderLine( 'Vary' ) );

		$response = $this->executeNote( "section_$page", 'MS4w', 'application/activity+json, application/ld+json' );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/activity+json', $response->getHeaderLine( 'Content-Type' ) );
		$this->assertSame( 'Accept', $response->getHeaderLine( 'Vary' ) );
	}

	private static function assertQuotable( array $note, array $context ) {
		// The shape Mastodon reads: anyone may quote, approved automatically
		self::assertSame(
			[ 'canQuote' => [ 'automaticApproval' => [ 'https://www.w3.org/ns/activitystreams#Public' ] ] ],
			$note['interactionPolicy']
		);
		self::assertSame( 'https://www.w3.org/ns/activitystreams', $context[0] );
		self::assertSame( 'https://gotosocial.org/ns#', $context[1]['gts'] );
		foreach ( [ 'interactionPolicy', 'canQuote', 'automaticApproval' ] as $term ) {
			self::assertSame( [ '@id' => "gts:$term", '@type' => '@id' ], $context[1][$term] );
		}
	}

	public function testNoteMayBeQuoted() {
		$cat = $this->savePage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$post = $this->savePage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$note = json_decode( $this->executeNote( "category_$cat", $post )->getBody(), true );
		self::assertQuotable( $note, $note['@context'] );

		$outbox = $this->getOutbox( "category_$cat" );
		self::assertQuotable( $outbox['orderedItems'][0]['object'], $outbox['@context'] );
	}

	public function testNoteHasNoQuotePolicyWhileInteractionsAreOff() {
		$this->overrideConfigValue( 'FediFeedsInteractions', false );
		$cat = $this->savePage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$post = $this->savePage( 'First post', "Hello.\n\n[[Category:Posts]]" );
		$response = $this->executeNote( "category_$cat", $post );
		$this->assertSame( 200, $response->getStatusCode() );
		$note = json_decode( $response->getBody(), true );
		// No policy is what Mastodon reads as nobody but the author may quote
		$this->assertArrayNotHasKey( 'interactionPolicy', $note );
		$this->assertSame( "https://fedi.example.org/actors/category_$cat/notes/$post", $note['id'] );

		$outbox = $this->getOutbox( "category_$cat" );
		$this->assertSame( 1, $outbox['totalItems'] ?? count( $outbox['orderedItems'] ) );
		$this->assertArrayNotHasKey( 'interactionPolicy', $outbox['orderedItems'][0]['object'] );
	}

	public function testNoNoteFromAFeedWithoutADisplayName() {
		$cat = $this->savePage( 'Category:Maintenance', 'Pages to fix.' );
		$post = $this->savePage( 'Broken page', "Text.\n\n[[Category:Maintenance]]" );
		$ex = $this->executeHandlerAndGetHttpException(
			new NoteHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
			new RequestData( [ 'pathParams' => [ 'handle' => "category_$cat", 'item' => (string)$post ] ] )
		);
		$this->assertSame( 404, $ex->getCode() );
		$this->assertSame( [], $this->getOutbox( "category_$cat" )['orderedItems'] );
	}

	public function testNoNoteForAPageOutsideTheCategory() {
		$cat = $this->savePage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$other = $this->savePage( 'Unrelated page', 'Text.' );
		$ex = $this->executeHandlerAndGetHttpException(
			new NoteHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
			new RequestData( [ 'pathParams' => [ 'handle' => "category_$cat", 'item' => (string)$other ] ] )
		);
		$this->assertSame( 404, $ex->getCode() );
	}

	public function testOutbox() {
		$cat = $this->savePage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$first = $this->savePage( 'First post', "One.\n\n[[Category:Posts]]" );
		$second = $this->savePage( 'Second post', "Two.\n\n[[Category:Posts]]" );
		$outbox = $this->getOutbox( "category_$cat" );
		$actor = "https://fedi.example.org/actors/category_$cat";
		$this->assertSame( "$actor/outbox", $outbox['id'] );
		$this->assertSame( 'OrderedCollection', $outbox['type'] );
		$this->assertSame( 2, $outbox['totalItems'] );
		$this->assertSame( [ "$actor/notes/$second", "$actor/notes/$first" ],
			array_map( static fn ( $create ) => $create['object']['id'], $outbox['orderedItems'] ) );
		$this->assertSame( 'Create', $outbox['orderedItems'][0]['type'] );
		$this->assertSame( $actor, $outbox['orderedItems'][0]['actor'] );
	}

	public function testPublishingFeedIsTheFirstNamedCategory() {
		$this->savePage( 'Category:Unnamed', 'Text.' );
		$this->savePage( 'Category:Named', '{{FEDIFEEDSNAME:Named}}' );
		$pageStore = $this->getServiceContainer()->getPageStore();
		$feed = $this->getNotes()->getPublishingFeed( [
			$pageStore->getPageByText( 'Category:Unnamed' ),
			$pageStore->getPageByText( 'Category:Named' ),
		] );
		$this->assertSame( 'Named', $feed->page->getDBkey() );
		$unnamedOnly = [ $pageStore->getPageByText( 'Category:Unnamed' ) ];
		$this->assertNull( $this->getNotes()->getPublishingFeed( $unnamedOnly ) );
	}

	public function testSectionNote() {
		$text = "{{FEDIFEEDSNAME:Releases|section}}\n\nAbout the releases.\n";
		$page = $this->savePage( 'Release notes', $text );
		$this->savePage( 'Release notes', "$text\n== 2026 ==\n=== 1.0 ===\nThe '''first''' release.\n" );
		$this->savePage( 'Release notes', "$text\n== 2026 ==\n=== 1.0 ===\nThe '''first''' release.\n"
			. "=== 1.1 ===\n* A list\n\n== Café & co. ==\nDone.\n" );
		$actor = "https://fedi.example.org/actors/section_$page";

		$response = $this->executeNote( "section_$page", 'MjAyNg' );
		$this->assertSame( 200, $response->getStatusCode() );
		$note = json_decode( $response->getBody(), true );
		$this->assertSame( "$actor/notes/MjAyNg", $note['id'] );
		$this->assertStringStartsWith( '<p><strong>2026</strong></p><p><strong>1.0</strong></p>'
			. '<p>The first release.</p><p><strong>1.1</strong></p><ul><li>A list</li></ul><p><a href=',
			$note['content'] );
		$this->assertStringContainsString( '&oldid=', $note['url'] );
		$this->assertStringEndsWith( 'utm_source=fediverse#2026', $note['url'] );
		$this->assertNotNull( $note['published'] );

		$outbox = $this->getOutbox( "section_$page" );
		$this->assertSame( 2, $outbox['totalItems'] );
		$this->assertSame( [ "$actor/notes/Q2Fmw6kgJiBjby4", "$actor/notes/MjAyNg" ],
			array_map( static fn ( $create ) => $create['object']['id'], $outbox['orderedItems'] ) );

		// A section removed from the page has no note
		$this->savePage( 'Release notes', "$text\n== 2026 ==\nNothing yet.\n" );
		$ex = $this->executeHandlerAndGetHttpException(
			new NoteHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
			new RequestData( [ 'pathParams' => [ 'handle' => "section_$page", 'item' => 'Q2Fmw6kgJiBjby4' ] ] )
		);
		$this->assertSame( 404, $ex->getCode() );
		$this->assertCount( 1, $this->getOutbox( "section_$page" )['orderedItems'] );
	}

	public function testNoSectionNoteForAnUnrecordedOrMisspelledItem() {
		$text = "{{FEDIFEEDSNAME:Releases}}\n\n== 1.0 ==\nThe first release.\n";
		$page = $this->savePage( 'Release notes', $text );
		$this->savePage( 'Release notes', "$text\n== 1.1 ==\nThe second release.\n" );
		$this->assertSame( 200, $this->executeNote( "section_$page", 'MS4x' )->getStatusCode() );
		// 1.0 was there when the page was created; the others are not the one spelling of 1.1
		foreach ( [ 'MS4w', 'MS4x=', 'MS4x.', (string)$page ] as $item ) {
			$ex = $this->executeHandlerAndGetHttpException(
				new NoteHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
				new RequestData( [ 'pathParams' => [ 'handle' => "section_$page", 'item' => $item ] ] )
			);
			$this->assertSame( 404, $ex->getCode(), $item );
		}
	}
}
