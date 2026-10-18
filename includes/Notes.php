<?php

namespace MediaWiki\Extension\FediFeeds;

use DOMDocument;
use MediaWiki\Deferred\LinksUpdate\CategoryLinksTable;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageProps;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Builds the notes and the outbox of a feed. Only category feeds have items so far.
 */
class Notes {

	public const LIMIT = 20;
	private const PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	public function __construct(
		public readonly Actors $actors,
		private readonly IConnectionProvider $dbProvider,
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
	 * The first of the category pages, in order, whose category feed publishes notes.
	 *
	 * @param ExistingPageRecord[] $categoryPages
	 */
	public function getPublishingFeed( array $categoryPages ): ?Feed {
		if ( !$categoryPages ) {
			return null;
		}
		$props = $this->pageProps->getProperties( $categoryPages,
			[ Hooks::NAME_PROPERTY, Hooks::NAME_PROPERTY . '-category' ] );
		foreach ( $categoryPages as $page ) {
			if ( $page->getNamespace() === NS_CATEGORY && ( $props[$page->getId()] ?? [] ) ) {
				return new Feed( 'category', $page );
			}
		}
		return null;
	}

	/**
	 * The item the id names, if the feed publishes and the page is in its category now.
	 */
	public function getItem( Feed $feed, int $pageId ): ?ExistingPageRecord {
		if ( !$this->publishes( $feed ) ) {
			return null;
		}
		$found = $this->newMembersQuery( $feed )
			->andWhere( [ 'cl_from' => $pageId ] )
			->caller( __METHOD__ )->fetchField();
		return $found === false ? null : $this->pageStore->getPageById( $pageId );
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
		$ids = $this->newMembersQuery( $feed )
			->orderBy( 'page_id', SelectQueryBuilder::SORT_DESC )
			->limit( self::LIMIT )
			->caller( __METHOD__ )->fetchFieldValues();
		return array_values( array_filter( array_map(
			fn ( $id ) => $this->pageStore->getPageById( (int)$id ), $ids ) ) );
	}

	private function newMembersQuery( Feed $feed ): SelectQueryBuilder {
		return $this->dbProvider->getReplicaDatabase( CategoryLinksTable::VIRTUAL_DOMAIN )
			->newSelectQueryBuilder()
			->select( 'page_id' )
			->from( 'categorylinks' )
			->join( 'page', null, 'cl_from = page_id' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [
				'lt_namespace' => NS_CATEGORY,
				'lt_title' => $feed->page->getDBkey(),
				'cl_type' => 'page',
			] );
	}

	public function getNoteId( Feed $feed, ExistingPageRecord $item ): string {
		return $this->actors->getActorId( $feed->getHandle() ) . '/notes/' . $item->getId();
	}

	public function getNote( Feed $feed, ExistingPageRecord $item ): array {
		$actorId = $this->actors->getActorId( $feed->getHandle() );
		$url = Title::newFromPageIdentity( $item )->getFullURL( '', false, PROTO_CANONICAL );
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
			// Counted with the same query as the items, not from the category table's counts
			'totalItems' => $items
				? (int)$this->newMembersQuery( $feed )->clearFields()->select( 'COUNT(*)' )
					->caller( __METHOD__ )->fetchField()
				: 0,
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
