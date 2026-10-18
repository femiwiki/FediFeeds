<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Page\ExistingPageRecord;

/**
 * A feed that exists, as named by a handle
 */
class Feed {

	public function __construct(
		public readonly string $kind,
		public readonly ExistingPageRecord $page,
	) {
	}

	public function getHandle(): string {
		return $this->kind . '_' . $this->page->getId();
	}
}
