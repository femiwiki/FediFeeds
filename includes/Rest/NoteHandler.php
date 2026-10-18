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

	public function run( string $handle, int $item ): Response {
		$feed = $this->feedLookup->getFeed( $handle );
		$page = $feed ? $this->notes->getItem( $feed, $item ) : null;
		if ( !$feed || !$page ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-note' ) )->plaintextParams( "$handle/notes/$item" ),
				404
			);
		}
		return $this->getResponseFactory()->createJson(
			[ '@context' => 'https://www.w3.org/ns/activitystreams' ] + $this->notes->getNote( $feed, $page ),
			Actors::CONTENT_TYPE
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
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return false;
	}
}
