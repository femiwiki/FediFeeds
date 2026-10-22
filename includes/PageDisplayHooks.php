<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\PageStore;

/**
 * Links a page's note from its head, so a search for the page's URL finds the note
 */
class PageDisplayHooks implements BeforePageDisplayHook {

	public function __construct(
		private readonly Notes $notes,
		private readonly PageStore $pageStore,
		private readonly Translations $translations,
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$out->isArticle() || !$title || !$title->exists() ) {
			return;
		}
		$page = $this->pageStore->getPageById( $title->getArticleID() );
		// A translation page is an item of its language's feeds, and of no others
		$language = $page ? $this->translations->getLanguage( $page ) : null;
		$categories = [];
		foreach ( $out->getCategories() as $name ) {
			$name = $language === null ? $name : $this->translations->getSourceCategory( $name, $language );
			$category = $this->pageStore->getExistingPageByText( $name, NS_CATEGORY );
			if ( $category ) {
				$categories[] = $category;
			}
		}
		$feed = $page ? $this->notes->getPublishingFeed( $categories, $page->getId(), $language ) : null;
		if ( $feed && $page ) {
			$out->addLink( [
				'rel' => 'alternate',
				'type' => Actors::CONTENT_TYPE,
				'href' => $this->notes->getNoteId( $feed, new Item( $page ) ),
			] );
		}
	}
}
