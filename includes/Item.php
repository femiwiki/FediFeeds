<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Page\ExistingPageRecord;

/**
 * An item of a feed: a page, or for a section feed one section of it
 */
class Item {

	/**
	 * @param ExistingPageRecord $page
	 * @param string $key The key of a section item, from Sections::getKey(), or empty when the page is
	 *  the item
	 */
	public function __construct(
		public readonly ExistingPageRecord $page,
		public readonly string $key = '',
	) {
	}
}
