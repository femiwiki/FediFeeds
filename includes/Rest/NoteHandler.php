<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Page\PageLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Serves an item of a feed as a Note
 */
class NoteHandler extends SimpleHandler {

	private readonly FeedLookup $feedLookup;

	public function __construct(
		private readonly Notes $notes,
		PageLookup $pageLookup,
	) {
		$this->feedLookup = new FeedLookup( $pageLookup );
	}

	/**
	 * @param string $handle
	 * @param string $item The page id of a category item, or the encoded key of a section item
	 */
	public function run( string $handle, string $item ): Response {
		$feed = $this->feedLookup->getFeed( $handle );
		$found = $feed ? $this->notes->getItem( $feed, $item ) : null;
		if ( !$feed || !$found ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-note' ) )->plaintextParams( "$handle/notes/$item" ),
				404
			);
		}
		$note = $this->notes->getNote( $feed, $found );
		return BrowserRedirect::respond( $this,
			static fn () => $note['url'],
			fn () => $this->getResponseFactory()->createJson(
				[ '@context' => Notes::CONTEXT ] + $note,
				Actors::CONTENT_TYPE
			)
		);
	}

	public function getParamSettings(): array {
		return [
			'handle' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'item' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return false;
	}
}
