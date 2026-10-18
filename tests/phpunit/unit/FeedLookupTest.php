<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\FeedLookup
 */
class FeedLookupTest extends MediaWikiUnitTestCase {

	public static function provideParseHandle(): iterable {
		yield 'category' => [ 'category_4521', [ 'category', 4521 ] ];
		yield 'section' => [ 'section_88', [ 'section', 88 ] ];
		yield 'reserved kind' => [ 'category_added_4521', null ];
		yield 'unknown kind' => [ 'user_1', null ];
		yield 'no id' => [ 'category', null ];
		yield 'zero' => [ 'category_0', null ];
		yield 'leading zero' => [ 'category_04521', null ];
		yield 'upper case' => [ 'Category_4521', null ];
		yield 'trailing newline' => [ "category_4521\n", null ];
		yield 'dot' => [ 'category.4521', null ];
	}

	/**
	 * @dataProvider provideParseHandle
	 */
	public function testParseHandle( string $handle, ?array $expected ) {
		$this->assertSame( $expected, FeedLookup::parseHandle( $handle ) );
	}
}
