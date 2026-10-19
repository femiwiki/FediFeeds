<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\RemoteActors;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\RemoteActors
 * @group Database
 */
class RemoteActorsTest extends MediaWikiIntegrationTestCase {

	private const ACTOR = 'https://remote.example/users/a';

	public static function provideParse() {
		$base = [ 'id' => self::ACTOR, 'type' => 'Person', 'preferredUsername' => 'a' ];
		yield 'discoverable left out is false' => [ $base, [
			'fa_handle' => 'a@remote.example', 'fa_name' => '', 'fa_url' => self::ACTOR, 'fa_discoverable' => 0,
		] ];
		yield 'a Link url, a name with markup' => [ $base + [
			'name' => "<p>A &amp; <b>B</b>\n</p>",
			'url' => [
				[ 'type' => 'Link', 'href' => 'javascript:x' ],
				[ 'type' => 'Link', 'href' => 'https://remote.example/@a' ],
			],
			'discoverable' => true,
		], [
			'fa_handle' => 'a@remote.example', 'fa_name' => 'A & B', 'fa_url' => 'https://remote.example/@a',
			'fa_discoverable' => 1,
		] ];
		yield 'discoverable must be true itself' => [ $base + [ 'discoverable' => 'true' ], [
			'fa_handle' => 'a@remote.example', 'fa_name' => '', 'fa_url' => self::ACTOR, 'fa_discoverable' => 0,
		] ];
		yield 'no preferredUsername' => [ [ 'id' => self::ACTOR, 'type' => 'Service' ], [
			'fa_handle' => '', 'fa_name' => '', 'fa_url' => self::ACTOR, 'fa_discoverable' => 0,
		] ];
		yield 'another actor' => [ [ 'id' => 'https://other.example/users/b' ] + $base, null ];
		yield 'not an actor' => [ [ 'type' => 'Note' ] + $base, null ];
		yield 'not a document' => [ null, null ];
	}

	/**
	 * @dataProvider provideParse
	 */
	public function testParse( mixed $document, ?array $expected ) {
		$this->assertSame( $expected, RemoteActors::parse( self::ACTOR, $document ) );
	}

	public function testStaleness() {
		$actors = $this->getServiceContainer()->getService( 'FediFeeds.RemoteActors' );
		$this->assertTrue( $actors->isStale( self::ACTOR ) );
		$actors->update( self::ACTOR, [ 'id' => self::ACTOR, 'type' => 'Person' ] );
		$this->assertTrue( $actors->has( self::ACTOR ) );
		$this->assertFalse( $actors->isStale( self::ACTOR ) );
		$this->assertTrue( $actors->isStale( self::ACTOR, time() + RemoteActors::MAX_AGE + 60 ) );
		$actors->remove( self::ACTOR );
		$this->assertFalse( $actors->has( self::ACTOR ) );
	}
}
