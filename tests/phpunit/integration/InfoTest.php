<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\InfoHooks;
use MediaWiki\Extension\FediFeeds\Items;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Extension\FediFeeds\RemoteActors;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\InfoHooks
 * @covers \MediaWiki\Extension\FediFeeds\Items
 * @covers \MediaWiki\Extension\FediFeeds\Reactions
 * @covers \MediaWiki\Extension\FediFeeds\RemoteActors
 * @group Database
 */
class InfoTest extends MediaWikiIntegrationTestCase {

	private const REMOTE = 'https://remote.example/users/';

	protected function setUp(): void {
		parent::setUp();
		$this->setUserLang( 'qqx' );
		$this->overrideConfigValue( 'FediFeedsInteractions', true );
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
	 * The rows the hook adds to the basic information of the page, as label key and value HTML.
	 *
	 * @return array<string,string>
	 */
	private function getRows( string $title, string $lang = 'qqx' ): array {
		$context = new RequestContext();
		$context->setTitle( Title::newFromText( $title ) );
		$context->setLanguage( $lang );
		$pageInfo = [ 'header-basic' => [] ];
		$services = $this->getServiceContainer();
		( new InfoHooks( $this->getItems(), $this->getReactions(), $this->getRemoteActors(),
			$services->getLinkRenderer() ) )->onInfoAction( $context, $pageInfo );
		$rows = [];
		foreach ( $pageInfo['header-basic'] as [ $label, $value ] ) {
			$rows[$label->getKey()] = $value;
		}
		return $rows;
	}

	/**
	 * The totals of the rows, without what follows them.
	 *
	 * @return array<string,string>
	 */
	private function getTotals( string $title ): array {
		return array_map( static fn ( $value ) => explode( '(colon-separator)', $value )[0],
			$this->getRows( $title ) );
	}

	private function getRemoteActors(): RemoteActors {
		return $this->getServiceContainer()->getService( 'FediFeeds.RemoteActors' );
	}

	/**
	 * Caches an actor as its document would say.
	 */
	private function cacheActor( string $name, bool $discoverable, string $displayName = '' ): string {
		$actor = self::REMOTE . $name;
		$this->getRemoteActors()->update( $actor, [
			'id' => $actor,
			'type' => 'Person',
			'preferredUsername' => $name,
			'name' => $displayName,
			'url' => "https://remote.example/@$name",
			'discoverable' => $discoverable,
		] );
		return $actor;
	}

	private static function totals( int $announces, int $likes ): array {
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
		$this->assertSame( [], $this->getTotals( 'Post' ) );
		$this->assertSame( [], $this->getRows( 'No such page' ) );
	}

	public function testNoRowsWhileInteractionsAreOff() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$this->getReactions()->add( $category, $pageId, '', Reactions::LIKE, $this->cacheActor( 'a', true, 'A' ), '' );
		$this->assertSame( self::totals( 0, 1 ), $this->getTotals( 'Post' ) );
		$this->overrideConfigValue( 'FediFeedsInteractions', false );
		$this->assertSame( [], $this->getRows( 'Post' ) );
	}

	public function testZerosForAnItemWithNoReactions() {
		$this->newCategoryItem();
		$this->assertSame( self::totals( 0, 0 ), $this->getTotals( 'Post' ) );
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
		$this->assertSame( self::totals( 2, 3 ), $this->getTotals( 'Post' ) );
		// The category's own page holds no item
		$this->assertSame( [], $this->getRows( 'Category:Posts' ) );
	}

	public function testRowsAfterThePageLeavesTheCategory() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$this->getReactions()->add( $category, $pageId, '', Reactions::ANNOUNCE, self::REMOTE . 'a', '' );
		$this->editPage( 'Post', 'Text' );
		$this->runDeferredUpdates();
		$this->assertSame( self::totals( 1, 0 ), $this->getTotals( 'Post' ) );
	}

	public function testOnlyDiscoverableAccountsAreNamed() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$reactions = $this->getReactions();
		$named = $this->cacheActor( 'named', true, 'Named <b>One</b>' );
		$handleOnly = $this->cacheActor( 'handle', true );
		$hidden = $this->cacheActor( 'hidden', false, 'Hidden' );
		foreach ( [ $named, $handleOnly, $hidden, self::REMOTE . 'uncached' ] as $actor ) {
			$reactions->add( $category, $pageId, '', Reactions::LIKE, $actor, '' );
		}
		$likes = $this->getRows( 'Post' )['fedifeeds-info-likes'];
		$this->assertStringStartsWith( '(fedifeeds-info-times: 4)(colon-separator)', $likes );
		$this->assertStringContainsString(
			'<a href="https://remote.example/@named" class="external" rel="nofollow noreferrer noopener" '
				. 'title="@named@remote.example">Named One</a>', $likes );
		$this->assertStringContainsString( '>@handle@remote.example</a>', $likes );
		$this->assertStringNotContainsString( 'Hidden', $likes );
		$this->assertStringNotContainsString( 'uncached', $likes );
		$this->assertStringContainsString( '(fedifeeds-info-others: 2)', $likes );
		$this->assertStringContainsString( 'Special:FediFeedsReactions/Post', $likes );
		$this->assertStringContainsString( '(fedifeeds-info-all)', $likes );
		// Nobody to name
		$this->assertStringContainsString( '(fedifeeds-info-times: 0)',
			$this->getRows( 'Post' )['fedifeeds-info-announces'] );
	}

	public function testOthersInKorean() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$reactions = $this->getReactions();
		$reactions->add( $category, $pageId, '', Reactions::ANNOUNCE, $this->cacheActor( 'a', true, '가' ), '' );
		$reactions->add( $category, $pageId, '', Reactions::ANNOUNCE, $this->cacheActor( 'b', false ), '' );
		$reactions->add( $category, $pageId, '', Reactions::LIKE, $this->cacheActor( 'c', false ), '' );
		$rows = $this->getRows( 'Post', 'ko' );
		$this->assertMatchesRegularExpression( '#^2회: <a [^>]+>가</a> 외 1명 \(<a [^>]+>모두 보기</a>\)$#',
			$rows['fedifeeds-info-announces'] );
		$this->assertMatchesRegularExpression( '#^1회: 1명 \(<a [^>]+>모두 보기</a>\)$#',
			$rows['fedifeeds-info-likes'] );
	}

	public function testAtMostTenNamedMostRecentFirst() {
		[ $category, $pageId ] = $this->newCategoryItem();
		$dbw = $this->getDb();
		for ( $i = 1; $i <= 12; $i++ ) {
			$actor = $this->cacheActor( "user$i", true );
			$this->getReactions()->add( $category, $pageId, '', Reactions::LIKE, $actor, '' );
			$dbw->newUpdateQueryBuilder()
				->update( 'fedifeeds_reaction' )
				->set( [ 'fr_timestamp' => $dbw->timestamp( 1760000000 + $i ) ] )
				->where( [ 'fr_actor' => $actor ] )
				->caller( __METHOD__ )->execute();
		}
		$likes = $this->getRows( 'Post' )['fedifeeds-info-likes'];
		preg_match_all( '#>@(user\d+)@#', $likes, $m );
		$this->assertSame( [ 'user12', 'user11', 'user10', 'user9', 'user8', 'user7', 'user6', 'user5',
			'user4', 'user3' ], $m[1] );
		$this->assertStringContainsString( '(fedifeeds-info-others: 2)', $likes );
	}
}
