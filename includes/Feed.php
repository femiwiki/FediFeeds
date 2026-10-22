<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Page\ExistingPageRecord;

/**
 * A feed that exists, as named by a handle
 */
class Feed {

	/**
	 * @param string $kind
	 * @param ExistingPageRecord $page
	 * @param string|null $language The MediaWiki code of the language whose translations the feed
	 *  publishes, or null for the feed of the pages themselves
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ExistingPageRecord $page,
		public readonly ?string $language = null,
	) {
	}

	public function getHandle(): string {
		return self::makeHandle( $this->kind, $this->page->getId(), $this->language );
	}

	/**
	 * The handle of the feed of that kind, page id and language: <kind>_<id>, or
	 * <kind>_<language>_<id> for a language's feed.
	 */
	public static function makeHandle( string $kind, int $pageId, ?string $language = null ): string {
		return $kind . ( $language === null ? '' : "_$language" ) . '_' . $pageId;
	}

	/**
	 * The feed of the same page and kind for a language's translations, or with null for the
	 * pages themselves.
	 */
	public function withLanguage( ?string $language ): self {
		return new self( $this->kind, $this->page, $language );
	}
}
