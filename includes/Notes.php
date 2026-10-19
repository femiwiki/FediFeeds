<?php

namespace MediaWiki\Extension\FediFeeds;

use DOMDocument;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageProps;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Builds the notes and the outbox of a feed. Only category feeds have items so far.
 */
class Notes {

	public const LIMIT = 20;
	private const PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	public function __construct(
		public readonly Actors $actors,
		private readonly Items $items,
		private readonly PageStore $pageStore,
		private readonly PageProps $pageProps,
		private readonly RevisionLookup $revisionLookup,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly TitleFormatter $titleFormatter,
	) {
	}

	/**
	 * Whether the feed publishes notes, which it does once its page sets a display name.
	 */
	public function publishes( Feed $feed ): bool {
		return $feed->kind === 'category' && $this->getPublishingFeed( [ $feed->page ] ) !== null;
	}

	/**
	 * The first of the category pages, in order, whose category feed publishes notes and, if
	 * an item is given, has it.
	 *
	 * @param ExistingPageRecord[] $categoryPages
	 * @param int|null $itemId Page id of the item
	 */
	public function getPublishingFeed( array $categoryPages, ?int $itemId = null ): ?Feed {
		if ( !$categoryPages ) {
			return null;
		}
		$props = $this->pageProps->getProperties( $categoryPages,
			[ Hooks::NAME_PROPERTY, Hooks::NAME_PROPERTY . '-category' ] );
		foreach ( $categoryPages as $page ) {
			$feed = new Feed( 'category', $page );
			if ( $page->getNamespace() === NS_CATEGORY && ( $props[$page->getId()] ?? [] )
				&& ( $itemId === null || $this->items->has( $feed, $itemId ) )
			) {
				return $feed;
			}
		}
		return null;
	}

	/**
	 * The item the id names, if the feed publishes, recorded the item and the page exists.
	 */
	public function getItem( Feed $feed, int $pageId ): ?ExistingPageRecord {
		if ( !$this->publishes( $feed ) || !$this->items->has( $feed, $pageId ) ) {
			return null;
		}
		return $this->pageStore->getPageById( $pageId );
	}

	/**
	 * The newest items, newest first.
	 *
	 * @return ExistingPageRecord[]
	 */
	public function getRecentItems( Feed $feed ): array {
		if ( !$this->publishes( $feed ) ) {
			return [];
		}
		$ids = $this->items->getRecent( $feed, self::LIMIT );
		return array_values( array_filter( array_map(
			fn ( $id ) => $this->pageStore->getPageById( $id ), $ids ) ) );
	}

	public function getNoteId( Feed $feed, ExistingPageRecord $item ): string {
		return $this->actors->getActorId( $feed->getHandle() ) . '/notes/' . $item->getId();
	}

	public function getNote( Feed $feed, ExistingPageRecord $item ): array {
		$actorId = $this->actors->getActorId( $feed->getHandle() );
		$url = Title::newFromPageIdentity( $item )
			->getFullURL( [ 'utm_source' => 'fediverse' ], false, PROTO_CANONICAL );
		$link = '<p><a href="' . htmlspecialchars( $url ) . '">'
			. htmlspecialchars( $this->titleFormatter->getPrefixedText( $item ) ) . '</a></p>';
		$paragraph = $this->getFirstParagraph( $item );
		$first = $this->revisionLookup->getFirstRevision( $item );
		return [
			'id' => $this->getNoteId( $feed, $item ),
			'type' => 'Note',
			'attributedTo' => $actorId,
			'content' => ( $paragraph === '' ? '' : '<p>' . htmlspecialchars( $paragraph ) . '</p>' ) . $link,
			'url' => $url,
			'published' => $first ? wfTimestamp( TS::ISO_8601, $first->getTimestamp() ) : null,
			'to' => [ self::PUBLIC ],
			'cc' => [ "$actorId/followers" ],
		];
	}

	public function getCreate( Feed $feed, ExistingPageRecord $item ): array {
		$note = $this->getNote( $feed, $item );
		return [
			'id' => $note['id'] . '/activity',
			'type' => 'Create',
			'actor' => $note['attributedTo'],
			'published' => $note['published'],
			'to' => $note['to'],
			'cc' => $note['cc'],
			'object' => $note,
		];
	}

	public function getOutbox( Feed $feed ): array {
		$items = $this->getRecentItems( $feed );
		return [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $this->actors->getActorId( $feed->getHandle() ) . '/outbox',
			'type' => 'OrderedCollection',
			'totalItems' => $items ? $this->items->count( $feed ) : 0,
			'orderedItems' => array_map( fn ( $item ) => $this->getCreate( $feed, $item ), $items ),
		];
	}

	/**
	 * The text of the first paragraph that has any, from the page's current rendering.
	 */
	private function getFirstParagraph( ExistingPageRecord $page ): string {
		$status = $this->parserOutputAccess->getParserOutput( $page, ParserOptions::newFromAnon() );
		if ( !$status->isOK() ) {
			return '';
		}
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $status->getValue()->getContentHolderText() . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			$text = trim( preg_replace( '/\s+/u', ' ', $p->textContent ) );
			if ( $text !== '' ) {
				return $text;
			}
		}
		return '';
	}
}
