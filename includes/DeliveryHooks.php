<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\ExistingPageRecord;
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
 *
 * A translation page of Extension:Translate is never an item of those feeds. Once its
 * translation is complete it is an item of the category feeds of its language instead.
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
		private readonly Translations $translations,
	) {
	}

	/**
	 * A save that creates the page makes category items, and a later save that adds headings
	 * makes section items. Null edits, reverts and reparses add nothing, though a revert still
	 * updates the sections it changes.
	 *
	 * Translate saves a translation page each time a unit of it is translated, so any save of
	 * one may be the one that completes it.
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
		if ( $this->translations->isTranslationPage( $wikiPage ) ) {
			DeferredUpdates::addCallableUpdate( fn () => $this->recordTranslationItems( $pageId, $revisionRecord ) );
		}
	}

	private function recordCategoryItems( int $pageId, RevisionRecord $revision ): void {
		$page = $this->pageStore->getPageById( $pageId );
		// A translation page is not a new page but one translated, which its language's feeds take
		if ( $page && !$this->translations->isTranslationPage( $page ) ) {
			$this->recordInCategories( $page, $revision, null );
		}
	}

	/**
	 * Makes a translation page an item of its language's category feeds once its translation is
	 * complete. The items are recorded once, so a translation that falls behind and is completed
	 * again sends nothing more.
	 */
	private function recordTranslationItems( int $pageId, RevisionRecord $revision ): void {
		$page = $this->pageStore->getPageById( $pageId );
		$language = $page ? $this->translations->getLanguage( $page ) : null;
		if ( $page && $language !== null && $this->translations->isComplete( $page ) ) {
			$this->recordInCategories( $page, $revision, $language );
		}
	}

	/**
	 * Records the page as an item of the publishing category feeds that the revision puts it in,
	 * or with a language their feeds of that language's translations, and sends its Create.
	 */
	private function recordInCategories( ExistingPageRecord $page, RevisionRecord $revision, ?string $language ): void {
		// The categories of the new revision's rendering, so categories from templates count
		$status = $this->parserOutputAccess->getParserOutput( $page, ParserOptions::newFromAnon(), $revision );
		if ( !$status->isOK() ) {
			return;
		}
		foreach ( $status->getValue()->getCategoryNames() as $name ) {
			$name = $language === null ? $name : $this->translations->getSourceCategory( $name, $language );
			$category = $this->pageStore->getExistingPageByText( $name, NS_CATEGORY );
			if ( !$category ) {
				continue;
			}
			$feed = new Feed( 'category', $category, $language );
			// Recorded whether or not anyone follows, so the outbox lists it
			if ( $this->notes->publishes( $feed )
				&& $this->items->add( $feed, $page->getId(), $revision->getTimestamp() ?? wfTimestampNow(), '',
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
		// A translation page's headings change from the source language's as their units are
		// translated, which is no news
		if ( !$page || !$parent || $this->translations->isTranslationPage( $page ) ) {
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
