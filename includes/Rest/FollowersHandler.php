<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Serves the size of a feed's followers collection, and not who they are
 */
class FollowersHandler extends SimpleHandler {

	public function __construct(
		private readonly Notes $notes,
		private readonly FeedLookup $feedLookup,
		private readonly Followers $followers,
	) {
	}

	public function run( string $handle ): Response {
		$feed = $this->feedLookup->getFeed( $handle );
		if ( !$feed ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-feed' ) )->plaintextParams( $handle ),
				404
			);
		}
		return $this->getResponseFactory()->createJson( [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $this->notes->actors->getActorId( $handle ) . '/followers',
			'type' => 'OrderedCollection',
			'totalItems' => $this->followers->count( $feed ),
		], Actors::CONTENT_TYPE );
	}

	public function getParamSettings(): array {
		return [
			'handle' => [
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
