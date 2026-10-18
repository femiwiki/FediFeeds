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

	private function executeNote( string $handle, int $item ) {
		return $this->executeHandler(
			new NoteHandler( $this->getNotes(), $this->getServiceContainer()->getPageStore() ),
			new RequestData( [ 'pathParams' => [ 'handle' => $handle, 'item' => (string)$item ] ] )
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
		$this->assertSame( [ 'https://www.w3.org/ns/activitystreams#Public' ], $note['to'] );
		$this->assertNotNull( $note['published'] );
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
}
