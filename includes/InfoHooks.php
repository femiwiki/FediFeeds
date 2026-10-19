<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Actions\Hook\InfoActionHook;

/**
 * Shows on action=info how many Announces and Likes the notes of a page's items got
 */
class InfoHooks implements InfoActionHook {

	public function __construct(
		private readonly Items $items,
		private readonly Reactions $reactions,
	) {
	}

	/** @inheritDoc */
	public function onInfoAction( $context, &$pageInfo ) {
		$pageId = $context->getTitle()?->getArticleID() ?? 0;
		if ( !$pageId ) {
			return;
		}
		$counts = $this->reactions->countForPage( $pageId );
		// A reaction is only recorded for an item, so counts mean the page has one. Otherwise
		// look in its own section feed and the category feeds it is in now.
		if ( !array_sum( $counts ) ) {
			$handles = [ "section_$pageId" ];
			foreach ( $context->getWikiPage()->getCategories() as $category ) {
				if ( $category->getArticleID() ) {
					$handles[] = 'category_' . $category->getArticleID();
				}
			}
			if ( !$this->items->hasPage( $pageId, $handles ) ) {
				return;
			}
		}
		$pageInfo['header-basic'][] = [
			$context->msg( 'fedifeeds-info-announces' ),
			$context->msg( 'fedifeeds-info-times' )->numParams( $counts[Reactions::ANNOUNCE] ),
		];
		$pageInfo['header-basic'][] = [
			$context->msg( 'fedifeeds-info-likes' ),
			$context->msg( 'fedifeeds-info-times' )->numParams( $counts[Reactions::LIKE] ),
		];
	}
}
