<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

/**
 * Records a new page as an item of each publishing category feed it is created in, and sends
 * its Create to the feed's followers
 */
class DeliveryHooks implements PageSaveCompleteHook {

	public function __construct(
		private readonly Notes $notes,
		private readonly Items $items,
		private readonly Followers $followers,
		private readonly PageStore $pageStore,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
	}

	/**
	 * Only a save that creates the page, so a later edit, null edit or reparse adds nothing.
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( !( $flags & EDIT_NEW ) ) {
			return;
		}
		$pageId = $wikiPage->getId();
		DeferredUpdates::addCallableUpdate( fn () => $this->recordAndDeliver( $pageId, $revisionRecord ) );
	}

	private function recordAndDeliver( int $pageId, RevisionRecord $revision ): void {
		$item = $this->pageStore->getPageById( $pageId );
		if ( !$item ) {
			return;
		}
		// The categories of the new revision's rendering, so categories from templates count
		$status = $this->parserOutputAccess->getParserOutput( $item, ParserOptions::newFromAnon(), $revision );
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
				&& $this->items->add( $feed, $pageId, $revision->getTimestamp() ?? wfTimestampNow() )
			) {
				$this->deliver( $feed, $item );
			}
		}
	}

	/**
	 * Queues one delivery of the Create for each inbox, which is none without followers.
	 */
	private function deliver( Feed $feed, ExistingPageRecord $item ): void {
		$inboxes = $this->followers->getInboxes( $feed );
		if ( !$inboxes ) {
			return;
		}
		$actorId = $this->notes->actors->getActorId( $feed->getHandle() );
		$activity = [ '@context' => 'https://www.w3.org/ns/activitystreams' ]
			+ $this->notes->getCreate( $feed, $item );
		$jobs = [];
		foreach ( $inboxes as $inbox ) {
			$jobs[] = new DeliveryJob( [ 'inbox' => $inbox, 'keyId' => "$actorId#main-key", 'activity' => $activity ] );
		}
		$this->jobQueueGroup->lazyPush( $jobs );
	}
}
