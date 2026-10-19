<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

/**
 * Records the items a save makes and sends their Create to the feed's followers: a new page is
 * an item of each publishing category feed it is created in, and a new heading at the unit level
 * of an existing page is an item of the page's section feed if that publishes
 */
class DeliveryHooks implements PageSaveCompleteHook {

	public function __construct(
		private readonly Notes $notes,
		private readonly Items $items,
		private readonly Followers $followers,
		private readonly PageStore $pageStore,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly RevisionLookup $revisionLookup,
	) {
	}

	/**
	 * A save that creates the page makes category items, and a later save that adds headings
	 * makes section items. Null edits, reverts and reparses add nothing.
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$pageId = $wikiPage->getId();
		if ( $flags & EDIT_NEW ) {
			DeferredUpdates::addCallableUpdate( fn () => $this->recordCategoryItems( $pageId, $revisionRecord ) );
		} elseif ( !$editResult->isNullEdit() && !$editResult->isRevert() && $revisionRecord->getParentId() ) {
			// A revert brings back headings that were there before, which are not news
			DeferredUpdates::addCallableUpdate( fn () => $this->recordSectionItems( $pageId, $revisionRecord ) );
		}
	}

	private function recordCategoryItems( int $pageId, RevisionRecord $revision ): void {
		$page = $this->pageStore->getPageById( $pageId );
		if ( !$page ) {
			return;
		}
		// The categories of the new revision's rendering, so categories from templates count
		$status = $this->parserOutputAccess->getParserOutput( $page, ParserOptions::newFromAnon(), $revision );
		if ( !$status->isOK() ) {
			return;
		}
		foreach ( $status->getValue()->getCategoryNames() as $name ) {
			$category = $this->pageStore->getExistingPageByText( $name, NS_CATEGORY );
			if ( !$category ) {
				continue;
			}
			$feed = new Feed( 'category', $category );
			// Recorded whether or not anyone follows, so the outbox lists it
			if ( $this->notes->publishes( $feed )
				&& $this->items->add( $feed, $pageId, $revision->getTimestamp() ?? wfTimestampNow(), '',
					(int)$revision->getId() )
			) {
				$this->deliver( $feed, new Item( $page ) );
			}
		}
	}

	/**
	 * Records each heading of the new revision at the feed's unit level whose path the parent
	 * revision did not have, or had fewer times.
	 */
	private function recordSectionItems( int $pageId, RevisionRecord $revision ): void {
		$page = $this->pageStore->getPageById( $pageId );
		$parent = $this->revisionLookup->getRevisionById( (int)$revision->getParentId() );
		if ( !$page || !$parent ) {
			return;
		}
		$feed = new Feed( 'section', $page );
		// Checked before anything is parsed, so a page without a section feed costs one query
		if ( !$this->notes->publishes( $feed ) ) {
			return;
		}
		$options = ParserOptions::newFromAnon();
		$newStatus = $this->parserOutputAccess->getParserOutput( $page, $options, $revision );
		$parentStatus = $this->parserOutputAccess->getParserOutput( $page, $options, $parent );
		if ( !$newStatus->isOK() || !$parentStatus->isOK() ) {
			return;
		}
		$unit = $this->notes->getSectionUnit( $page );
		$new = Sections::getOccurrences( Sections::getHeadings( $newStatus->getValue() ), $unit );
		$old = Sections::getOccurrences( Sections::getHeadings( $parentStatus->getValue() ), $unit );
		$timestamp = $revision->getTimestamp() ?? wfTimestampNow();
		foreach ( Sections::findNew( $old, $new ) as $i => $ordinal ) {
			$key = Sections::getKey( $new[$i]['base'], $ordinal );
			if ( $this->items->add( $feed, $pageId, $timestamp, $key, (int)$revision->getId(), $new[$i]['link'] ) ) {
				$this->deliver( $feed, new Item( $page, $key ) );
			}
		}
	}

	/**
	 * Queues one delivery of the Create for each inbox, which is none without followers.
	 */
	private function deliver( Feed $feed, Item $item ): void {
		$inboxes = $this->followers->getInboxes( $feed );
		if ( !$inboxes ) {
			return;
		}
		$actorId = $this->notes->actors->getActorId( $feed->getHandle() );
		$activity = [ '@context' => Notes::CONTEXT ]
			+ $this->notes->getCreate( $feed, $item );
		$jobs = [];
		foreach ( $inboxes as $inbox ) {
			$jobs[] = new DeliveryJob( [ 'inbox' => $inbox, 'keyId' => "$actorId#main-key", 'activity' => $activity ] );
		}
		$this->jobQueueGroup->lazyPush( $jobs );
	}
}
