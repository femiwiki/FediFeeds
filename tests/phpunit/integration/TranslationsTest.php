<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\PageDisplayHooks;
use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePageSettings;
use MediaWiki\Extension\Translate\Services;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\EmptyBagOStuff;

/**
 * Translation pages of Extension:Translate, which the tests mark and translate as Translate does
 *
 * @covers \MediaWiki\Extension\FediFeeds\DeliveryHooks
 * @covers \MediaWiki\Extension\FediFeeds\Translations
 * @covers \MediaWiki\Extension\FediFeeds\Notes
 * @covers \MediaWiki\Extension\FediFeeds\PageDisplayHooks
 * @group Database
 */
class TranslationsTest extends MediaWikiIntegrationTestCase {

	/** The jobs by which Translate renders translation pages, in the order they spawn each other */
	private const TRANSLATE_JOBS = [
		'UpdateTranslatablePageJob',
		'RenderTranslationPageJob',
		'NonPrioritizedRenderTranslationPageJob',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Translate' );
		$this->overrideConfigValues( [
			'CanonicalServer' => 'https://wiki.example.org',
			'FediFeedsActorBase' => 'https://fedi.example.org/actors/',
			'LanguageCode' => 'ko',
			'EnablePageTranslation' => true,
			'TranslateMessageIndex' => 'hash',
			'TranslateTranslationServices' => [],
		] );
		$this->setGroupPermissions( 'sysop', 'translate', true );
		// Translate keeps the list of translatable pages in a local cache for a while, which
		// would outlive a test's pages and miss a page marked after it was filled
		$this->setService( 'LocalServerObjectCache', new EmptyBagOStuff() );
		MessageGroups::singleton()->clearProcessCache();
	}

	private function getPage( string $title ): ?ExistingPageRecord {
		return $this->getServiceContainer()->getPageStore()->getExistingPageByText( $title );
	}

	private function savePage( string $title, string $text ): ExistingPageRecord {
		$this->editPage( $title, $text, '', NS_MAIN, $this->getTestSysop()->getUser() );
		$this->runDeferredUpdates();
		return $this->getPage( $title );
	}

	private function getItems(): Items {
		return $this->getServiceContainer()->getService( 'FediFeeds.Items' );
	}

	private function follow( Feed $feed, string $inbox ): void {
		$this->getServiceContainer()->getService( 'FediFeeds.Followers' )
			->add( $feed, "$inbox#actor", $inbox );
	}

	/** Runs Translate's jobs, which save the translation pages, and none of ours. */
	private function runTranslateJobs(): void {
		$this->runDeferredUpdates();
		$runner = $this->getServiceContainer()->getJobRunner();
		foreach ( [ 1, 2 ] as $round ) {
			foreach ( self::TRANSLATE_JOBS as $type ) {
				$runner->run( [ 'type' => $type ] );
				$this->runDeferredUpdates();
			}
		}
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

	/** Marks the page for translation, as Special:PageTranslation does, and renders its pages. */
	private function markForTranslation( ExistingPageRecord $page ): void {
		$marker = Services::getInstance()->getTranslatablePageMarker();
		$operation = $marker->getMarkOperation( $page, null, false );
		$marker->markForTranslation(
			$operation,
			new TranslatablePageSettings( [], false, '', [], false, true, false ),
			RequestContext::getMain(),
			$this->getTestSysop()->getUser()
		);
		// As a later request would, which does not keep the list of translatable pages
		$this->getServiceContainer()->getMainWANObjectCache()->clearProcessCache();
		$this->runTranslateJobs();
	}

	private function translate( string $unit, string $text ): void {
		$this->savePage( "Translations:$unit", $text );
		$this->runTranslateJobs();
	}

	/**
	 * A category feed that publishes, followed for its pages and for their English
	 * translations, and a page in it marked for translation, with two units.
	 *
	 * @return array{0: Feed, 1: ExistingPageRecord}
	 */
	private function setUpTranslatablePost(): array {
		$category = $this->savePage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$feed = new Feed( 'category', $category );
		$this->follow( $feed, 'https://ko.example/inbox' );
		$this->follow( $feed->withLanguage( 'en' ), 'https://en.example/inbox' );
		$post = $this->savePage( 'Post', "<languages />\n<translate>\n첫 문단입니다.\n\n둘째 문단입니다.\n"
			. "</translate>\n\n[[Category:Posts]]" );
		$this->markForTranslation( $post );
		return [ $feed, $post ];
	}

	public function testTranslationIsAnItemOfItsLanguageOnceComplete() {
		[ $feed, $post ] = $this->setUpTranslatablePost();
		$english = $feed->withLanguage( 'en' );
		$this->assertTrue( $this->getItems()->has( $feed, $post->getId() ), 'the page itself is an item' );
		$this->assertCount( 1, $this->getDeliveries() );

		$this->translate( 'Post/1/en', 'This is the first paragraph.' );
		$translation = $this->getPage( 'Post/en' );
		$this->assertNotNull( $translation, 'Translate made the translation page' );
		$this->assertFalse( $this->getItems()->has( $feed, $translation->getId() ),
			'a translation page is never an item of the feed of the pages' );
		$this->assertFalse( $this->getItems()->has( $english, $translation->getId() ),
			'an incomplete translation is not an item' );
		$this->assertCount( 1, $this->getDeliveries() );

		$this->translate( 'Post/2/en', 'This is the second paragraph.' );
		$this->assertFalse( $this->getItems()->has( $feed, $translation->getId() ) );
		$this->assertTrue( $this->getItems()->has( $english, $translation->getId() ),
			'a complete translation is an item of its language' );
		$deliveries = $this->getDeliveries();
		$this->assertCount( 2, $deliveries );
		$actor = 'https://fedi.example.org/actors/category_en_' . $feed->page->getId();
		$this->assertSame( 'https://en.example/inbox', $deliveries[1]['inbox'] );
		$this->assertSame( "$actor#main-key", $deliveries[1]['keyId'] );
		$note = $deliveries[1]['activity']['object'];
		$this->assertSame( "$actor/notes/{$translation->getId()}", $note['id'] );
		$this->assertSame( [ 'en' ], array_keys( $note['contentMap'] ) );
		$this->assertStringContainsString( 'This is the first paragraph.', $note['content'] );
		$this->assertSame( [ 'ko' ], array_keys( $deliveries[0]['activity']['object']['contentMap'] ),
			'the page itself is in the content language' );

		// Falling behind and catching up again sends nothing more
		$this->translate( 'Post/2/en', 'This is the second paragraph, reworded.' );
		$this->savePage( 'Post', "<languages />\n<translate>\n<!--T:1-->\n첫 문단입니다.\n\n<!--T:2-->\n"
			. "둘째 문단입니다.\n\n셋째 문단입니다.\n</translate>\n\n[[Category:Posts]]" );
		$this->markForTranslation( $this->getPage( 'Post' ) );
		$this->translate( 'Post/3/en', 'This is the third paragraph.' );
		$this->assertCount( 2, $this->getDeliveries() );
		$this->assertSame( 1, $this->getItems()->count( $english ) );
	}

	public function testTranslatedCategoryStandsForItsPage() {
		$category = $this->savePage( 'Category:Posts', "{{FEDIFEEDSNAME:Posts}}\n<translate>\n글 모음입니다.\n</translate>" );
		$this->markForTranslation( $category );
		$english = ( new Feed( 'category', $this->getPage( 'Category:Posts' ) ) )->withLanguage( 'en' );
		// The source page is in Category:Posts and its translation in Category:Posts/en
		$this->savePage( 'Post', "<translate>\n첫 문단입니다.\n</translate>\n\n[[Category:Posts{{#translation:}}]]" );
		$this->markForTranslation( $this->getPage( 'Post' ) );
		$this->translate( 'Post/1/en', 'This is the first paragraph.' );
		$this->assertTrue( $this->getItems()->has( $english, $this->getPage( 'Post/en' )->getId() ) );
	}

	public function testOutboxOfTheLanguage() {
		[ $feed, ] = $this->setUpTranslatablePost();
		$this->translate( 'Post/1/en', 'This is the first paragraph.' );
		$this->translate( 'Post/2/en', 'This is the second paragraph.' );
		$this->overrideConfigValue( 'ULSLanguageDetection', false );
		$notes = $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
		$english = $notes->getOutbox( $feed->withLanguage( 'en' ) );
		$this->assertSame( 1, $english['totalItems'] );
		$this->assertSame( 'https://wiki.example.org/index.php?title=Post/en&uselang=en&utm_source=fediverse',
			$english['orderedItems'][0]['object']['url'],
			'the link names the language when the wiki does not pick one from the browser' );
		$this->assertSame( 1, $notes->getOutbox( $feed )['totalItems'], 'only the page itself' );
		$this->assertSame( 0, $notes->getOutbox( $feed->withLanguage( 'ja' ) )['totalItems'] );

		$this->overrideConfigValue( 'ULSLanguageDetection', true );
		$notes = $this->getServiceContainer()->getService( 'FediFeeds.Notes' );
		$this->assertSame( 'https://wiki.example.org/index.php?title=Post/en&utm_source=fediverse',
			$notes->getOutbox( $feed->withLanguage( 'en' ) )['orderedItems'][0]['object']['url'],
			'the browser\'s language is left to pick the interface' );
	}

	/** The note ids that the page's HTML head links */
	private function getLinkedNotes( ExistingPageRecord $page, array $categories ): array {
		$context = new RequestContext();
		$context->setTitle( Title::newFromPageIdentity( $page ) );
		$out = $context->getOutput();
		$out->setArticleFlag( true );
		$out->addCategoryLinks( $categories );
		$services = $this->getServiceContainer();
		( new PageDisplayHooks( $services->getService( 'FediFeeds.Notes' ), $services->getPageStore(),
			$services->getService( 'FediFeeds.Translations' ) ) )->onBeforePageDisplay( $out, $context->getSkin() );
		$notes = [];
		foreach ( $out->getLinkTags() as $link ) {
			if ( ( $link['type'] ?? '' ) === 'application/activity+json' ) {
				$notes[] = $link['href'];
			}
		}
		return $notes;
	}

	public function testTranslationLinksItsNote() {
		[ $feed, $post ] = $this->setUpTranslatablePost();
		$this->translate( 'Post/1/en', 'This is the first paragraph.' );
		$translation = $this->getPage( 'Post/en' );
		$this->assertSame( [], $this->getLinkedNotes( $translation, [ 'Posts' => 'Posts' ] ),
			'an incomplete translation has no note' );
		$this->translate( 'Post/2/en', 'This is the second paragraph.' );
		$actor = 'https://fedi.example.org/actors/category_';
		$this->assertSame( [ "{$actor}en_{$feed->page->getId()}/notes/{$translation->getId()}" ],
			$this->getLinkedNotes( $translation, [ 'Posts' => 'Posts' ] ) );
		$this->assertSame( [ "$actor{$feed->page->getId()}/notes/{$post->getId()}" ],
			$this->getLinkedNotes( $post, [ 'Posts' => 'Posts' ] ) );
	}

	public function testSourceLanguageSubpageIsNoItem() {
		[ $feed, ] = $this->setUpTranslatablePost();
		// Translate renders the subpage in the source language when the page is marked
		$source = $this->getPage( 'Post/ko' );
		$this->assertNotNull( $source, 'Translate made the subpage in the source language' );
		$this->assertFalse( $this->getItems()->has( $feed, $source->getId() ) );
		$this->assertFalse( $this->getItems()->has( $feed->withLanguage( 'ko' ), $source->getId() ) );
		$this->assertCount( 1, $this->getDeliveries() );
	}
}
