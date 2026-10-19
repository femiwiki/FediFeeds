<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\InfoHooks;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\InfoHooks
 * @covers \MediaWiki\Extension\FediFeeds\Items
 * @covers \MediaWiki\Extension\FediFeeds\Reactions
 * @group Database
 */
class InfoTest extends MediaWikiIntegrationTestCase {

	private const REMOTE = 'https://remote.example/users/';

	protected function setUp(): void {
		parent::setUp();
		$this->setUserLang( 'qqx' );
	}

	private function getItems(): Items {
		return $this->getServiceContainer()->getService( 'FediFeeds.Items' );
	}

	private function getReactions(): Reactions {
		return $this->getServiceContainer()->getService( 'FediFeeds.Reactions' );
	}

	private function getFeed( string $kind, string $title ): Feed {
		return new Feed( $kind, $this->getServiceContainer()->getPageStore()->getPageByText( $title ) );
	}

	/**
	 * The rows the hook adds to the basic information of the page, as label key and value text.
	 *
	 * @return array<string,string>
	 */
	private function getRows( string $title ): array {
		$context = new RequestContext();
		$context->setTitle( Title::newFromText( $title ) );
		$context->setLanguage( 'qqx' );
		$pageInfo = [ 'header-basic' => [] ];
		( new InfoHooks( $this->getItems(), $this->getReactions() ) )->onInfoAction( $context, $pageInfo );
		$rows = [];
		foreach ( $pageInfo['header-basic'] as [ $label, $value ] ) {
			$rows[$label->getKey()] = $value->text();
		}
		return $rows;
	}

	private static function rows( int $announces, int $likes ): array {
		return [
			'fedifeeds-info-announces' => "(fedifeeds-info-times: $announces)",
			'fedifeeds-info-likes' => "(fedifeeds-info-times: $likes)",
		];
	}

	/**
	 * A page in Category:Posts, recorded as an item of its feed when it is saved.
	 */
	private function newCategoryItem(): array {
		$this->editPage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$page = $this->editPage( 'Post', 'Text [[Category:Posts]]' )->getNewRevision()->getPageId();
		$this->runDeferredUpdates();
		$feed = $this->getFeed( 'category', 'Category:Posts' );
		$this->assertTrue( $this->getItems()->has( $feed, $page ) );
		return [ $feed, $page ];
	}

	public function testNoRowsWithoutItems() {
		// A category without a display name publishes nothing, so records no item
		$this->editPage( 'Category:Posts', 'Text' );
		$this->editPage( 'Post', 'Text [[Category:Posts]]' );
		$this->runDeferredUpdates();
		$this->assertSame( [], $this->getRows( 'Post' ) );
		$this->assertSame( [], $this->getRows( 'No such page' ) );
	}

	public function testZerosForAnItemWithNoReactions() {
		$this->newCategoryItem();
		$this->assertSame( self::rows( 0, 0 ), $this->getRows( 'Post' ) );
	}

	public function testTotalsAcrossTheNotesOfThePage() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$this->editPage( 'Post', "{{FEDIFEEDSNAME:Post}}\n== A ==\nText\n== B ==\nText\n[[Category:Posts]]" );
		$section = $this->getFeed( 'section', 'Post' );
		$this->getItems()->add( $section, $pageId, '20261019000000', "A\n1" );
		$this->getItems()->add( $section, $pageId, '20261019000000', "B\n1" );
		$reactions = $this->getReactions();
		$reactions->add( $category, $pageId, '', Reactions::LIKE, self::REMOTE . 'a', '' );
		$reactions->add( $category, $pageId, '', Reactions::LIKE, self::REMOTE . 'b', '' );
		$reactions->add( $category, $pageId, '', Reactions::ANNOUNCE, self::REMOTE . 'a', '' );
		$reactions->add( $section, $pageId, "A\n1", Reactions::LIKE, self::REMOTE . 'a', '' );
		$reactions->add( $section, $pageId, "B\n1", Reactions::ANNOUNCE, self::REMOTE . 'c', '' );
		$this->assertSame( self::rows( 2, 3 ), $this->getRows( 'Post' ) );
		// The category's own page holds no item
		$this->assertSame( [], $this->getRows( 'Category:Posts' ) );
	}

	public function testRowsAfterThePageLeavesTheCategory() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$this->getReactions()->add( $category, $pageId, '', Reactions::ANNOUNCE, self::REMOTE . 'a', '' );
		$this->editPage( 'Post', 'Text' );
		$this->runDeferredUpdates();
		$this->assertSame( self::rows( 1, 0 ), $this->getRows( 'Post' ) );
	}
}
