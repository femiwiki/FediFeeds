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
 * of an existing page is an item of the page's section feed if that publishes. A save that
 * changes the text of a section item sends its Update.
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
	 * makes section items. Null edits, reverts and reparses add nothing, though a revert still
	 * updates the sections it changes.
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$pageId = $wikiPage->getId();
		if ( $flags & EDIT_NEW ) {
			DeferredUpdates::addCallableUpdate( fn () => $this->recordCategoryItems( $pageId, $revisionRecord ) );
		} elseif ( !$editResult->isNullEdit() && $revisionRecord->getParentId() ) {
			// A revert brings back headings that were there before, which are not news
			$adds = !$editResult->isRevert();
			DeferredUpdates::addCallableUpdate(
				fn () => $this->recordSectionItems( $pageId, $revisionRecord, $adds ) );
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
	 * revision did not have, or had fewer times, unless $adds is false. Then sends an Update
	 * for each recorded item both revisions have whose note text the new one changed.
	 */
	private function recordSectionItems( int $pageId, RevisionRecord $revision, bool $adds ): void {
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
		$revId = (int)$revision->getId();
		$added = [];
		foreach ( $adds ? Sections::findNew( $old, $new ) : [] as $i => $ordinal ) {
			$key = Sections::getKey( $new[$i]['base'], $ordinal );
			$added[$key] = true;
			if ( $this->items->add( $feed, $pageId, $timestamp, $key, $revId, $new[$i]['link'] ) ) {
				$this->deliver( $feed, new Item( $page, $key ) );
			}
		}

		// Compared as the note would say it, so an edit past the cut or to markup alone sends nothing
		$before = $this->notes->getSections( $page, $parentStatus->getValue() );
		foreach ( $this->notes->getSections( $page, $newStatus->getValue() ) as $key => $section ) {
			$key = (string)$key;
			if ( !isset( $added[$key] ) && isset( $before[$key] )
				&& Notes::getSectionText( $section ) !== Notes::getSectionText( $before[$key] )
				&& $this->items->setRevision( $feed, $pageId, $key, $revId, $section['link'] )
			) {
				$this->deliver( $feed, new Item( $page, $key ), $revId );
			}
		}
	}

	/**
	 * Queues one delivery of the Create, or with a revision the Update it made, for each inbox,
	 * which is none without followers.
	 */
	private function deliver( Feed $feed, Item $item, ?int $updatedIn = null ): void {
		$inboxes = $this->followers->getInboxes( $feed );
		if ( !$inboxes ) {
			return;
		}
		$actorId = $this->notes->actors->getActorId( $feed->getHandle() );
		$activity = [ '@context' => Notes::CONTEXT ] + ( $updatedIn === null
			? $this->notes->getCreate( $feed, $item )
			: $this->notes->getUpdate( $feed, $item, $updatedIn ) );
		$jobs = [];
		foreach ( $inboxes as $inbox ) {
			$jobs[] = new DeliveryJob( [ 'inbox' => $inbox, 'keyId' => "$actorId#main-key", 'activity' => $activity ] );
		}
		$this->jobQueueGroup->lazyPush( $jobs );
	}
}
