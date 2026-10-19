<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Actions\Hook\InfoActionHook;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Shows on action=info how many Announces and Likes the notes of a page's items got, and the
 * accounts that gave them most recently
 */
class InfoHooks implements InfoActionHook {

	/** How many accounts are named after each total */
	public const NAMED = 10;

	public function __construct(
		private readonly Items $items,
		private readonly Reactions $reactions,
		private readonly RemoteActors $remoteActors,
		private readonly LinkRenderer $linkRenderer,
	) {
	}

	/** @inheritDoc */
	public function onInfoAction( $context, &$pageInfo ) {
		$title = $context->getTitle();
		$pageId = $title?->getArticleID() ?? 0;
		if ( !$title || !$pageId ) {
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
		$actorCounts = array_sum( $counts ) ? $this->reactions->countActorsForPage( $pageId ) : $counts;
		foreach ( [ Reactions::ANNOUNCE => 'fedifeeds-info-announces', Reactions::LIKE => 'fedifeeds-info-likes' ]
			as $type => $label
		) {
			$pageInfo['header-basic'][] = [
				$context->msg( $label ),
				$this->getValue( $context, $title, $type, $counts[$type], $actorCounts[$type] ),
			];
		}
	}

	/**
	 * The total, then the cached accounts that gave the reaction last, how many others
	 * did, and a link to the full list, as HTML.
	 */
	private function getValue(
		IContextSource $context, Title $title, string $type, int $total, int $actors
	): string {
		$html = $context->msg( 'fedifeeds-info-times' )->numParams( $total )->escaped();
		if ( !$total ) {
			return $html;
		}
		$links = [];
		foreach ( $this->remoteActors->getRecent( $title->getArticleID(), $type, self::NAMED ) as $actor ) {
			$links[] = self::makeActorLink( $actor );
		}
		$others = max( 0, $actors - count( $links ) );
		if ( $links ) {
			$who = $context->getLanguage()->commaList( $links );
			if ( $others ) {
				$who .= $context->msg( 'word-separator' )->escaped()
					. $context->msg( 'fedifeeds-info-others' )->numParams( $others )->escaped();
			}
		} else {
			$who = $context->msg( 'fedifeeds-info-people' )->numParams( $others )->escaped();
		}
		$all = $this->linkRenderer->makeKnownLink(
			SpecialPage::getTitleFor( 'FediFeedsReactions', $title->getPrefixedDBkey() ),
			$context->msg( 'fedifeeds-info-all' )->text()
		);
		return $html . $context->msg( 'colon-separator' )->escaped() . $who
			. $context->msg( 'word-separator' )->escaped()
			. $context->msg( 'parentheses' )->rawParams( $all )->escaped();
	}

	/**
	 * A link to an account's profile, named by its display name, else its handle.
	 *
	 * @param array{actor: string, handle: string, name: string, url: string} $actor
	 */
	public static function makeActorLink( array $actor ): string {
		$handle = $actor['handle'] !== '' ? '@' . $actor['handle'] : $actor['actor'];
		return Html::element( 'a', [
			'href' => $actor['url'],
			'class' => 'external',
			'rel' => 'nofollow noreferrer noopener',
			'title' => $handle,
		], $actor['name'] !== '' ? $actor['name'] : $handle );
	}
}
