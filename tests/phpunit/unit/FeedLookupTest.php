<?php

namespace MediaWiki\Extension\FediFeeds\Tests;

use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\FediFeeds\FeedLookup
 */
class FeedLookupTest extends MediaWikiUnitTestCase {

	public static function provideParseHandle(): iterable {
		yield 'category' => [ 'category_4521', [ 'category', 4521, null ] ];
		yield 'section' => [ 'section_88', [ 'section', 88, null ] ];
		yield 'language' => [ 'category_en_4521', [ 'category', 4521, 'en' ] ];
		yield 'language with a subtag' => [ 'category_zh-hans_4521', [ 'category', 4521, 'zh-hans' ] ];
		yield 'language of a kind without them' => [ 'section_en_88', null ];
		yield 'reserved kind' => [ 'category_added_4521', null ];
		yield 'another reserved kind' => [ 'category_file_4521', null ];
		yield 'language too short' => [ 'category_e_4521', null ];
		yield 'language in upper case' => [ 'category_EN_4521', null ];
		yield 'language with an empty subtag' => [ 'category_zh-_4521', null ];
		yield 'no language' => [ 'category__4521', null ];
		yield 'unknown kind' => [ 'user_1', null ];
		yield 'unknown kind with a language' => [ 'user_en_1', null ];
		yield 'no id' => [ 'category', null ];
		yield 'language without an id' => [ 'category_en', null ];
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
