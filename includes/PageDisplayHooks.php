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
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$out->isArticle() || !$title || !$title->exists() ) {
			return;
		}
		$categories = [];
		foreach ( $out->getCategories() as $name ) {
			$category = $this->pageStore->getExistingPageByText( $name, NS_CATEGORY );
			if ( $category ) {
				$categories[] = $category;
			}
		}
		$page = $this->pageStore->getPageById( $title->getArticleID() );
		$feed = $page ? $this->notes->getPublishingFeed( $categories, $page->getId() ) : null;
		if ( $feed && $page ) {
			$out->addLink( [
				'rel' => 'alternate',
				'type' => Actors::CONTENT_TYPE,
				'href' => $this->notes->getNoteId( $feed, new Item( $page ) ),
			] );
		}
	}
}
