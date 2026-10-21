<?php

namespace MediaWiki\Extension\FediFeeds;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageProps;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Builds the notes and the outbox of a feed
 */
class Notes {

	public const LIMIT = 20;

	private const BLOCK_TAGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'dl', 'pre', 'blockquote' ];

	/** How many characters of a section's text a note carries */
	public const CONTENT_LENGTH = 500;
	private const PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';
	private const FEP_044F = 'https://w3id.org/fep/044f#';

	/** The JSON-LD context of notes and of quoting them, with the terms of FEP-044f */
	public const CONTEXT = [
		'https://www.w3.org/ns/activitystreams',
		[
			'gts' => 'https://gotosocial.org/ns#',
			'interactionPolicy' => [ '@id' => 'gts:interactionPolicy', '@type' => '@id' ],
			'canQuote' => [ '@id' => 'gts:canQuote', '@type' => '@id' ],
			'automaticApproval' => [ '@id' => 'gts:automaticApproval', '@type' => '@id' ],
			'interactingObject' => [ '@id' => 'gts:interactingObject', '@type' => '@id' ],
			'interactionTarget' => [ '@id' => 'gts:interactionTarget', '@type' => '@id' ],
			'QuoteRequest' => self::FEP_044F . 'QuoteRequest',
			'QuoteAuthorization' => self::FEP_044F . 'QuoteAuthorization',
		],
	];

	/**
	 * The current rendering of the page read last, kept because an outbox reads one page's
	 * sections once per item
	 *
	 * @var array{rev: int, full: bool, paragraph: string, sections: array<string,array>}|null
	 */
	private ?array $rendering = null;

	public function __construct(
		public readonly Actors $actors,
		private readonly Items $items,
		private readonly PageStore $pageStore,
		private readonly PageProps $pageProps,
		private readonly RevisionLookup $revisionLookup,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly TitleFormatter $titleFormatter,
		/** Whether Likes, Announces and quotes of notes are taken ($wgFediFeedsInteractions) */
		public readonly bool $interactions = false,
	) {
	}

	/**
	 * Whether the feed publishes notes, which it does once its page sets a display name for
	 * every kind or for the feed's kind.
	 */
	public function publishes( Feed $feed ): bool {
		if ( $feed->kind === 'category' ) {
			return $this->getPublishingFeed( [ $feed->page ] ) !== null;
		}
		return $feed->kind === 'section' && ( $this->pageProps->getProperties( $feed->page,
			[ Hooks::NAME_PROPERTY, Hooks::NAME_PROPERTY . '-section' ] )[$feed->page->getId()] ?? [] );
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
	 * The item the id names, if the feed publishes, recorded the item and the item exists: its
	 * page, and for a section item also the section in the page's current revision.
	 *
	 * @param Feed $feed
	 * @param string $itemId As in a note id, from getItemId()
	 */
	public function getItem( Feed $feed, string $itemId ): ?Item {
		$recorded = $this->publishes( $feed ) ? $this->getRecordedItem( $feed, $itemId ) : null;
		if ( $recorded === null ) {
			return null;
		}
		$page = $this->pageStore->getPageById( $recorded['page'] );
		return $page ? $this->getExistingItem( new Item( $page, $recorded['key'] ) ) : null;
	}

	/**
	 * The page id and key of the item the id names, if the feed recorded it, whether or not the
	 * item still exists or the feed still publishes.
	 *
	 * @param Feed $feed
	 * @param string $itemId As in a note id, from getItemId()
	 * @return array{page: int, key: string}|null
	 */
	public function getRecordedItem( Feed $feed, string $itemId ): ?array {
		if ( $feed->kind === 'section' ) {
			$key = self::decodeKey( $itemId );
			$pageId = $feed->page->getId();
		} else {
			$key = '';
			$pageId = preg_match( '/^[1-9][0-9]*$/D', $itemId ) ? (int)$itemId : null;
		}
		if ( $key === null || $pageId === null || !$this->items->has( $feed, $pageId, $key ) ) {
			return null;
		}
		return [ 'page' => $pageId, 'key' => $key ];
	}

	/**
	 * The newest items, newest first.
	 *
	 * @return Item[]
	 */
	public function getRecentItems( Feed $feed ): array {
		if ( !$this->publishes( $feed ) ) {
			return [];
		}
		$items = [];
		foreach ( $this->items->getRecent( $feed, self::LIMIT ) as [ 'page' => $pageId, 'key' => $key ] ) {
			$page = $this->pageStore->getPageById( $pageId );
			$item = $page ? $this->getExistingItem( new Item( $page, $key ) ) : null;
			if ( $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * The last part of the item's note id: the page id, or for a section item its key in
	 * unpadded base64url, which is safe in a URL path segment whatever the headings hold.
	 */
	public function getItemId( Feed $feed, Item $item ): string {
		return $feed->kind === 'section'
			? self::encodeKey( $item->key )
			: (string)$item->page->getId();
	}

	private static function encodeKey( string $key ): string {
		return rtrim( strtr( base64_encode( $key ), '+/', '-_' ), '=' );
	}

	public function getNoteId( Feed $feed, Item $item ): string {
		return $this->actors->getActorId( $feed->getHandle() ) . '/notes/' . $this->getItemId( $feed, $item );
	}

	/**
	 * The feed and item of a note id of this wiki, if getItem() has the item.
	 *
	 * @return array{0: Feed, 1: Item}|null
	 */
	public function findNote( string $noteId ): ?array {
		[ $feed, $itemId ] = $this->parseNoteId( $noteId ) ?? [ null, '' ];
		$item = $feed ? $this->getItem( $feed, $itemId ) : null;
		return $feed && $item ? [ $feed, $item ] : null;
	}

	/**
	 * The feed and the recorded item that a note id of this wiki names, as getRecordedItem().
	 *
	 * @return array{feed: Feed, page: int, key: string}|null
	 */
	public function resolveNoteId( string $noteId ): ?array {
		[ $feed, $itemId ] = $this->parseNoteId( $noteId ) ?? [ null, '' ];
		$recorded = $feed ? $this->getRecordedItem( $feed, $itemId ) : null;
		return $feed && $recorded ? [ 'feed' => $feed ] + $recorded : null;
	}

	/**
	 * The existing feed of a note id of this wiki, and the id's last part, from getItemId().
	 *
	 * @return array{0: Feed, 1: string}|null
	 */
	private function parseNoteId( string $noteId ): ?array {
		if ( !preg_match( '#^(.+)/notes/([^/]+)$#D', $noteId, $m ) ) {
			return null;
		}
		$handle = $this->actors->getHandleFromActorId( $m[1] );
		$feed = $handle === null ? null : ( new FeedLookup( $this->pageStore ) )->getFeed( $handle );
		return $feed ? [ $feed, $m[2] ] : null;
	}

	public function getNote( Feed $feed, Item $item ): array {
		$actorId = $this->actors->getActorId( $feed->getHandle() );
		$title = Title::newFromPageIdentity( $item->page );
		$linkText = $this->titleFormatter->getPrefixedText( $item->page );
		if ( $feed->kind === 'section' ) {
			$record = $this->items->get( $feed, $item->page->getId(), $item->key );
			// The revision the section appeared in, so the link shows what followers were told
			$rev = $record['rev'] ?? 0;
			$query = $rev ? [ 'oldid' => $rev ] : [];
			$url = $title->getFullURL( $query + [ 'utm_source' => 'fediverse' ], false, PROTO_CANONICAL )
				. ( ( $record['anchor'] ?? '' ) !== '' ? '#' . $record['anchor'] : '' );
			$content = $this->getSectionContent( $item, $url, $linkText );
			$published = $record['timestamp'] ?? null;
			// The revision is a later one once an edit changed the section
			$changed = $rev ? ( $this->revisionLookup->getTimestampFromId( $rev ) ?: null ) : null;
			$updated = $changed !== null && $changed !== $published ? $changed : null;
		} else {
			$url = $title->getFullURL( [ 'utm_source' => 'fediverse' ], false, PROTO_CANONICAL );
			$paragraph = $this->getRendering( $item->page, false )['paragraph'];
			$content = ( $paragraph === '' ? '' : '<p>' . htmlspecialchars( $paragraph ) . '</p>' )
				. '<p><a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $linkText ) . '</a></p>';
			$published = $this->revisionLookup->getFirstRevision( $item->page )?->getTimestamp();
			$updated = null;
		}
		$note = [
			'id' => $this->getNoteId( $feed, $item ),
			'type' => 'Note',
			'attributedTo' => $actorId,
			'content' => $content,
			'url' => $url,
			'published' => $published !== null ? wfTimestamp( TS::ISO_8601, $published ) : null,
		];
		if ( $updated !== null ) {
			$note['updated'] = wfTimestamp( TS::ISO_8601, $updated );
		}
		$note += [
			'to' => [ self::PUBLIC ],
			'cc' => [ "$actorId/followers" ],
		];
		// Anyone may quote a note, and gets a QuoteAuthorization without waiting (FEP-044f).
		// While interactions are off there is no policy, which Mastodon reads as nobody may.
		if ( $this->interactions ) {
			$note['interactionPolicy'] = [
				'canQuote' => [ 'automaticApproval' => [ self::PUBLIC ] ],
			];
		}
		return $note;
	}

	public function getQuoteAuthorizationId( Feed $feed, int $id ): string {
		return $this->actors->getActorId( $feed->getHandle() ) . "/quote_authorizations/$id";
	}

	/**
	 * The proof that a post may quote the item's note, which the quoting server and others
	 * fetch to check the quote.
	 *
	 * @param Feed $feed
	 * @param Item $item
	 * @param int $id Of the authorization
	 * @param string $post Id of the quoting post
	 * @return array
	 */
	public function getQuoteAuthorization( Feed $feed, Item $item, int $id, string $post ): array {
		return [
			'@context' => self::CONTEXT,
			'id' => $this->getQuoteAuthorizationId( $feed, $id ),
			'type' => 'QuoteAuthorization',
			'attributedTo' => $this->actors->getActorId( $feed->getHandle() ),
			'interactingObject' => $post,
			'interactionTarget' => $this->getNoteId( $feed, $item ),
		];
	}

	/**
	 * The section's text, then the link.
	 */
	private function getSectionContent( Item $item, string $url, string $linkText ): string {
		$section = $this->getRendering( $item->page, true )['sections'][$item->key] ?? null;
		return self::getSectionText( $section )
			. '<p><a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $linkText ) . '</a></p>';
	}

	/**
	 * The path in bold, then the section's paragraphs and lists with its subheadings as bold
	 * lines, cut with an ellipsis after CONTENT_LENGTH characters of text: what a note says
	 * before its link.
	 *
	 * @param array{path: string[], blocks: array}|null $section From getSections()
	 */
	public static function getSectionText( ?array $section ): string {
		$html = '<p><strong>' . htmlspecialchars( implode( ' ', $section['path'] ?? [] ) ) . '</strong></p>';
		$budget = self::CONTENT_LENGTH;
		foreach ( $section['blocks'] ?? [] as $block ) {
			$parts = '';
			foreach ( $block['items'] as [ $itemHtml, $itemText ] ) {
				$length = mb_strlen( $itemText );
				if ( $length > $budget ) {
					$itemHtml = htmlspecialchars( rtrim( mb_substr( $itemText, 0, $budget ) ) ) . '…';
				}
				$parts .= $block['tag'] === 'p' ? "<p>$itemHtml</p>" : "<li>$itemHtml</li>";
				$budget -= $length;
				if ( $budget < 0 ) {
					break;
				}
			}
			$html .= $block['tag'] === 'p' ? $parts : "<{$block['tag']}>$parts</{$block['tag']}>";
			if ( $budget < 0 ) {
				break;
			}
		}
		return $html;
	}

	public function getCreate( Feed $feed, Item $item ): array {
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

	/**
	 * The Update that tells followers the item's note changed, as of the revision that
	 * changed it.
	 */
	public function getUpdate( Feed $feed, Item $item, int $revId ): array {
		$note = $this->getNote( $feed, $item );
		return [
			'id' => $note['id'] . "/updates/$revId",
			'type' => 'Update',
			'actor' => $note['attributedTo'],
			'published' => $note['updated'] ?? $note['published'],
			'to' => $note['to'],
			'cc' => $note['cc'],
			'object' => $note,
		];
	}

	public function getOutbox( Feed $feed ): array {
		$items = $this->getRecentItems( $feed );
		return [
			'@context' => self::CONTEXT,
			'id' => $this->actors->getActorId( $feed->getHandle() ) . '/outbox',
			'type' => 'OrderedCollection',
			'totalItems' => $items ? $this->items->count( $feed ) : 0,
			'orderedItems' => array_map( fn ( $item ) => $this->getCreate( $feed, $item ), $items ),
		];
	}

	/**
	 * The heading level whose sections are the items of the page's section feed.
	 */
	public function getSectionUnit( ExistingPageRecord $page ): int {
		$unit = $this->pageProps->getProperties( $page, Hooks::SECTION_UNIT_PROPERTY )[$page->getId()] ?? null;
		return is_string( $unit ) && preg_match( '/^[1-6]$/D', $unit ) ? (int)$unit : Hooks::DEFAULT_SECTION_UNIT;
	}

	/**
	 * The item while it exists: a page item while its page does, a section item while the
	 * page's current revision has its path, at least as many times as its ordinal.
	 */
	private function getExistingItem( Item $item ): ?Item {
		if ( $item->key === '' ) {
			return $item;
		}
		return isset( $this->getRendering( $item->page, true )['sections'][$item->key] ) ? $item : null;
	}

	private static function decodeKey( string $itemId ): ?string {
		if ( !preg_match( '/^[A-Za-z0-9_-]+$/D', $itemId ) ) {
			return null;
		}
		$key = base64_decode( strtr( $itemId, '-_', '+/' ), true );
		// Only the one spelling getItemId() gives names the item
		return $key !== false && $key !== '' && self::encodeKey( $key ) === $itemId ? $key : null;
	}

	/**
	 * The page's current rendering: its first paragraph, and with $sections the section
	 * feed's items it has, by key, each with its path and content blocks.
	 *
	 * A path that occurs n times holds the items with ordinals 1 to n. Ordinals count in the
	 * order the occurrences appeared, which is up the page when the page adds new sections
	 * above old ones. That is taken to be so when, of the feed's two newest items whose paths
	 * occur once, the newer is higher up.
	 *
	 * @param ExistingPageRecord $page
	 * @param bool $sections
	 * @return array{rev: int, full: bool, paragraph: string, sections: array<string,array>}
	 */
	private function getRendering( ExistingPageRecord $page, bool $sections ): array {
		$rev = $page->getLatest();
		$cached = $this->rendering;
		if ( $cached !== null && $cached['rev'] === $rev && ( $cached['full'] || !$sections ) ) {
			return $cached;
		}
		$rendering = [ 'rev' => $rev, 'full' => $sections, 'paragraph' => '', 'sections' => [] ];
		$status = $this->parserOutputAccess->getParserOutput( $page, ParserOptions::newFromAnon() );
		if ( !$status->isOK() ) {
			return $rendering;
		}
		$output = $status->getValue();
		$doc = self::loadDocument( $output );
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			$text = trim( preg_replace( '/\s+/u', ' ', $p->textContent ) );
			if ( $text !== '' ) {
				$rendering['paragraph'] = $text;
				break;
			}
		}
		if ( $sections ) {
			$rendering['sections'] = $this->getSectionItems( $page, $output, $doc );
		}
		$this->rendering = $rendering;
		return $rendering;
	}

	/**
	 * The section feed's items that a rendering of the page has, by key, as getRendering()
	 * finds them in the current one. Ordinals are counted by the items recorded so far.
	 *
	 * @param ExistingPageRecord $page
	 * @param ParserOutput $output A rendering of any revision of the page
	 * @return array<string,array{path: string[], blocks: array, link: string}>
	 */
	public function getSections( ExistingPageRecord $page, ParserOutput $output ): array {
		return $this->getSectionItems( $page, $output, self::loadDocument( $output ) );
	}

	private static function loadDocument( ParserOutput $output ): DOMDocument {
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $output->getContentHolderText() . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $doc;
	}

	/**
	 * @return array<string,array{path: string[], blocks: array, link: string}>
	 */
	private function getSectionItems( ExistingPageRecord $page, ParserOutput $output, DOMDocument $doc ): array {
		$unit = $this->getSectionUnit( $page );
		$headings = Sections::getHeadings( $output );
		$occurrences = Sections::getOccurrences( $headings, $unit );

		// Each occurrence's content: what follows its heading until a heading of its level or higher
		$levels = array_column( $headings, 'level', 'anchor' );
		$texts = array_column( $headings, 'text', 'anchor' );
		$occurrenceByAnchor = array_flip( array_column( $occurrences, 'anchor' ) );
		$blocks = [];
		$current = null;
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		foreach ( $body ? self::getBlockElements( $body ) : [] as $element ) {
			if ( preg_match( '/^h([1-6])$/D', $element->tagName ) ) {
				// The legacy parser marks the anchor until the output is transformed, Parsoid with an id
				$anchor = $element->getAttribute( 'data-mw-anchor' ) ?: $element->getAttribute( 'id' );
				$level = $levels[$anchor] ?? null;
				if ( $level === null ) {
					continue;
				}
				if ( $level <= $unit ) {
					$current = $occurrenceByAnchor[$anchor] ?? null;
				} elseif ( $current !== null ) {
					$text = $texts[$anchor];
					$blocks[$current][] = [ 'tag' => 'p', 'items' => [
						[ '<strong>' . htmlspecialchars( $text ) . '</strong>', $text ] ] ];
				}
				continue;
			}
			if ( $current === null ) {
				continue;
			}
			$tag = $element->tagName === 'ul' || $element->tagName === 'ol' ? $element->tagName : 'p';
			$parts = [];
			if ( $tag === 'p' ) {
				$parts[] = self::getInline( $element );
			} else {
				foreach ( $element->childNodes as $li ) {
					if ( $li instanceof DOMElement && $li->tagName === 'li' ) {
						$parts[] = self::getInline( $li );
					}
				}
			}
			$parts = array_values( array_filter( $parts, static fn ( $part ) => $part[1] !== '' ) );
			if ( $parts ) {
				$blocks[$current][] = [ 'tag' => $tag, 'items' => $parts ];
			}
		}

		// The occurrences of each path in the order they appeared
		$byBase = [];
		foreach ( $occurrences as $i => $occurrence ) {
			$byBase[$occurrence['base']][] = (int)$i;
		}
		if ( $this->addsAbove( $page, $byBase ) ) {
			$byBase = array_map( 'array_reverse', $byBase );
		}
		$sections = [];
		foreach ( $byBase as $base => $indexes ) {
			foreach ( array_values( $indexes ) as $n => $i ) {
				$sections[Sections::getKey( (string)$base, $n + 1 )] = [
					'path' => $occurrences[$i]['path'],
					'blocks' => $blocks[$i] ?? [],
					'link' => $occurrences[$i]['link'],
				];
			}
		}
		return $sections;
	}

	/**
	 * Whether the page adds new sections above old ones, going by the feed's two newest items
	 * whose paths occur once.
	 *
	 * @param ExistingPageRecord $page
	 * @param array<string,int[]> $byBase Occurrence indexes by path
	 */
	private function addsAbove( ExistingPageRecord $page, array $byBase ): bool {
		$positions = [];
		foreach ( $byBase as $base => $indexes ) {
			if ( count( $indexes ) === 1 ) {
				$positions[Sections::getKey( (string)$base, 1 )] = $indexes[0];
			}
		}
		$found = [];
		foreach ( $this->items->getRecent( new Feed( 'section', $page ), self::LIMIT ) as [ 'key' => $key ] ) {
			if ( isset( $positions[$key] ) ) {
				$found[] = $positions[$key];
				if ( count( $found ) === 2 ) {
					return $found[0] < $found[1];
				}
			}
		}
		return false;
	}

	/**
	 * The headings and blocks of text under a node in order, looking into sections and other
	 * wrappers but not into tables, figures and the like.
	 *
	 * @return DOMElement[]
	 */
	private static function getBlockElements( DOMNode $node ): array {
		$elements = [];
		foreach ( $node->childNodes as $child ) {
			if ( !$child instanceof DOMElement ) {
				continue;
			}
			$tag = $child->tagName;
			if ( in_array( $tag, self::BLOCK_TAGS, true ) ) {
				$elements[] = $child;
			} elseif ( $tag === 'section' || $tag === 'div' ) {
				$elements = array_merge( $elements, self::getBlockElements( $child ) );
			}
		}
		return $elements;
	}

	/**
	 * An element's text as HTML, keeping only links to absolute URLs, and as plain text.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function getInline( DOMNode $node ): array {
		$html = '';
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$html .= htmlspecialchars( $child->data );
				$text .= $child->data;
				continue;
			}
			if ( !$child instanceof DOMElement || in_array( $child->tagName, [ 'style', 'script' ], true ) ) {
				continue;
			}
			if ( in_array( $child->tagName, [ 'br', 'ul', 'ol', 'li', 'dd', 'dt' ], true ) ) {
				$html .= ' ';
				$text .= ' ';
			}
			[ $innerHtml, $innerText ] = self::getInline( $child );
			$href = $child->tagName === 'a' ? $child->getAttribute( 'href' ) : '';
			if ( preg_match( '#^(https?:)?//#i', $href ) ) {
				$href = str_starts_with( $href, '//' ) ? "https:$href" : $href;
				$html .= '<a href="' . htmlspecialchars( $href ) . '">' . $innerHtml . '</a>';
			} else {
				$html .= $innerHtml;
			}
			$text .= $innerText;
		}
		return [
			trim( preg_replace( '/\s+/u', ' ', $html ) ),
			trim( preg_replace( '/\s+/u', ' ', $text ) ),
		];
	}
}
