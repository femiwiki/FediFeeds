<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Extension\FediFeeds\Specials\SpecialFediFeedsReactions;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\Specials\SpecialFediFeedsReactions
 * @covers \MediaWiki\Extension\FediFeeds\Specials\ReactionsPager
 * @group Database
 */
class SpecialFediFeedsReactionsTest extends SpecialPageTestBase {

	private const REMOTE = 'https://remote.example/users/';

	protected function newSpecialPage() {
		$services = $this->getServiceContainer();
		return new SpecialFediFeedsReactions( $services->getPageStore(), $services->getConnectionProvider(),
			$services->getService( 'FediFeeds.Reactions' ) );
	}

	/**
	 * A page that is an item of a category feed and has three section items, with reactions
	 * to each note.
	 */
	private function setUpReactions(): void {
		$services = $this->getServiceContainer();
		$this->editPage( 'Category:Posts', '{{FEDIFEEDSNAME:Posts}}' );
		$pageId = $this->editPage( 'Post', "{{FEDIFEEDSNAME:Post}}\nText [[Category:Posts]]" )
			->getNewRevision()->getPageId();
		$this->runDeferredUpdates();
		$pageStore = $services->getPageStore();
		$category = new Feed( 'category', $pageStore->getPageByText( 'Category:Posts' ) );
		$section = new Feed( 'section', $pageStore->getPageByText( 'Post' ) );
		$items = $services->getService( 'FediFeeds.Items' );
		$items->add( $section, $pageId, '20261019000000', 'A' );
		$items->add( $section, $pageId, '20261019000001', "B\n 2" );
		$items->add( $section, $pageId, '20261019000002', "Part\nSub" );

		$services->getService( 'FediFeeds.RemoteActors' )->update( self::REMOTE . 'alice', [
			'id' => self::REMOTE . 'alice', 'type' => 'Person', 'preferredUsername' => 'alice',
			'name' => 'Alice',
		] );
		$services->getService( 'FediFeeds.RemoteActors' )->update( self::REMOTE . 'bob', [
			'id' => self::REMOTE . 'bob', 'type' => 'Person', 'preferredUsername' => 'bob', 'name' => 'Bob',
		] );
		$reactions = $services->getService( 'FediFeeds.Reactions' );
		$reactions->add( $category, $pageId, '', Reactions::ANNOUNCE, self::REMOTE . 'alice', '' );
		$reactions->add( $section, $pageId, 'A', Reactions::LIKE, self::REMOTE . 'bob', '' );
		$reactions->add( $section, $pageId, "B\n 2", Reactions::LIKE, self::REMOTE . 'carol', '' );
		$reactions->add( $section, $pageId, "Part\nSub", Reactions::LIKE, self::REMOTE . 'alice', '' );
	}

	/**
	 * The group headings in order, and the rows under each as reaction and account.
	 */
	private static function getNoteGroups( string $html ): array {
		$groups = [];
		preg_match_all( '#<h3>(.*?)</h3>(.*?)</table>#s', $html, $tables, PREG_SET_ORDER );
		foreach ( $tables as [ , $heading, $table ] ) {
			preg_match_all( '#<tr><td>[^<]*</td><td>(.*?)</td><td>(.*?)</td></tr>#s', $table, $rows, PREG_SET_ORDER );
			$groups[$heading] = array_map( static fn ( $row ) => $row[1] . ' ' . strip_tags( $row[2] ), $rows );
		}
		return $groups;
	}

	public function testGroupsByNoteAndNamesCachedAccounts() {
		$this->setUpReactions();
		[ $html ] = $this->executeSpecialPage( 'Post', null, 'qqx' );
		$this->assertStringContainsString( '(fedifeeds-reactions-totals: 1, 3)', $html );
		$this->assertSame( [
			'Part(fedifeeds-reactions-path-separator)Sub' => [ '(fedifeeds-reactions-like) Alice' ],
			'(fedifeeds-reactions-section-nth: B, 2)' => [
				'(fedifeeds-reactions-like) (fedifeeds-reactions-uncached)',
			],
			'A' => [ '(fedifeeds-reactions-like) Bob' ],
			'(fedifeeds-reactions-category: Posts)' => [ '(fedifeeds-reactions-announce) Alice' ],
		], self::getNoteGroups( $html ) );
		$this->assertStringNotContainsString( 'carol', $html );
	}

	public function testPaging() {
		$this->setUpReactions();
		[ $html ] = $this->executeSpecialPage( 'Post', new FauxRequest( [ 'limit' => 3 ] ), 'qqx' );
		$this->assertSame(
			[ 'Part(fedifeeds-reactions-path-separator)Sub', '(fedifeeds-reactions-section-nth: B, 2)', 'A' ],
			array_keys( self::getNoteGroups( $html ) ) );
		$this->assertMatchesRegularExpression( '#offset=([^&"]+)#', $html );
		preg_match( '#[?&;]offset=([^&"]+)#', html_entity_decode( $html ), $m );
		[ $next ] = $this->executeSpecialPage( 'Post',
			new FauxRequest( [ 'limit' => 3, 'offset' => urldecode( $m[1] ) ] ), 'qqx' );
		$this->assertSame( [ '(fedifeeds-reactions-category: Posts)' ], array_keys( self::getNoteGroups( $next ) ) );
	}

	public function testNoReactionsAndNoPage() {
		$this->editPage( 'Quiet', 'Text' );
		[ $html ] = $this->executeSpecialPage( 'Quiet', null, 'qqx' );
		$this->assertStringContainsString( '(fedifeeds-reactions-none)', $html );
		[ $html ] = $this->executeSpecialPage( 'No such page', null, 'qqx' );
		$this->assertStringContainsString( '(fedifeeds-reactions-no-page: No such page)', $html );
		[ $html ] = $this->executeSpecialPage( '', null, 'qqx' );
		$this->assertStringContainsString( '(fedifeeds-reactions-legend)', $html );
	}
}
