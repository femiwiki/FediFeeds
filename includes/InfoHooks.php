<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Actions\Hook\InfoActionHook;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Shows on action=info how many Announces, Likes and quotes the notes of a page's items got, and the
 * accounts that gave them most recently, with links that send a reader to their own server to
 * give an Announce or a Like
 */
class InfoHooks implements InfoActionHook {

	/** How many accounts are named after each total */
	public const NAMED = 10;

	/** The id of the page's newest note, for the links to a reader's own server */
	private ?string $noteId = null;

	public function __construct(
		private readonly Items $items,
		private readonly Reactions $reactions,
		private readonly RemoteActors $remoteActors,
		private readonly LinkRenderer $linkRenderer,
		private readonly Notes $notes,
		private readonly PageStore $pageStore,
	) {
	}

	/** @inheritDoc */
	public function onInfoAction( $context, &$pageInfo ) {
		$title = $context->getTitle();
		$pageId = $title?->getArticleID() ?? 0;
		if ( !$title || !$pageId || !$context->getConfig()->get( 'FediFeedsInteractions' ) ) {
			return;
		}
		$counts = $this->reactions->countForPage( $pageId );
		$page = $this->pageStore->getPageById( $pageId );
		$this->noteId = $page ? $this->notes->getNewestNoteId( $page ) : null;
		if ( $this->noteId !== null ) {
			$context->getOutput()->addModules( 'ext.fediFeeds.interact' );
		}
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
		$labels = [
			Reactions::ANNOUNCE => 'fedifeeds-info-announces',
			Reactions::LIKE => 'fedifeeds-info-likes',
			Reactions::QUOTE => 'fedifeeds-info-quotes',
		];
		foreach ( $labels as $type => $label ) {
			$pageInfo['header-basic'][] = [
				$context->msg( $label ),
				$this->getValue( $context, $title, $type, $counts[$type], $actorCounts[$type] ),
			];
		}
	}

	/**
	 * The total, then the cached accounts that gave the reaction last, how many others
	 * did, and a link to the full list, as HTML. A link to give the reaction from the reader's
	 * own server follows.
	 */
	private function getValue(
		IContextSource $context, Title $title, string $type, int $total, int $actors
	): string {
		return $this->getReactions( $context, $title, $type, $total, $actors )
			. $this->getInteractLink( $context, $type );
	}

	/**
	 * A link that the ext.fediFeeds.interact module makes ask for the reader's server, or
	 * nothing if the page has no note to send or the reaction is not one a reader gives.
	 */
	private function getInteractLink( IContextSource $context, string $type ): string {
		if ( $this->noteId === null || !in_array( $type, [ Reactions::ANNOUNCE, Reactions::LIKE ], true ) ) {
			return '';
		}
		return $context->msg( 'pipe-separator' )->escaped() . Html::element( 'a', [
			'href' => '#',
			'role' => 'button',
			'class' => 'ext-fedifeeds-interact',
			'data-note' => $this->noteId,
		// fedifeeds-info-interact-announce, fedifeeds-info-interact-like
		], $context->msg( "fedifeeds-info-interact-$type" )->text() );
	}

	private function getReactions(
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
