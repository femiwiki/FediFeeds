<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\PageLookup;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestInterface;
use MediaWiki\Rest\Response;

/**
 * Takes activities posted to a feed's inbox or to the shared inbox
 */
class InboxHandler extends Handler {

	private const ROUTE = '/fedifeeds/actors/';

	private readonly FeedLookup $feedLookup;

	public function __construct(
		private readonly Notes $notes,
		PageLookup $pageLookup,
		private readonly RemoteKeys $remoteKeys,
		private readonly Followers $followers,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
		$this->feedLookup = new FeedLookup( $pageLookup );
	}

	public function execute(): Response {
		$request = $this->getRequest();
		$body = $request->getBody()->getContents();
		$activity = json_decode( $body, true );
		if ( !is_array( $activity ) ) {
			return $this->getResponseFactory()->createHttpError( 400, [ 'message' => 'Not an activity' ] );
		}
		$actor = self::getId( $activity['actor'] ?? null );
		if ( $actor === null || !is_string( $activity['type'] ?? null ) ) {
			return $this->getResponseFactory()->createHttpError( 400, [ 'message' => 'Not an activity' ] );
		}
		if ( $activity['type'] === 'Delete' && !$this->followers->isFollower( $actor ) ) {
			return $this->accepted();
		}

		$headers = [];
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[strtolower( (string)$name )] = implode( ', ', $values );
		}
		if ( $this->remoteKeys->verify( 'POST', $this->getSignedTarget(), $headers, $body ) !== $actor ) {
			return $this->getResponseFactory()->createHttpError( 401, [ 'message' => 'Bad signature' ] );
		}

		switch ( $activity['type'] ) {
			case 'Follow':
				return $this->follow( $activity, $actor );
			case 'Undo':
				$follow = $activity['object'] ?? null;
				$feed = is_array( $follow ) && ( $follow['type'] ?? null ) === 'Follow'
					? $this->getFeed( $follow['object'] ?? null ) : null;
				if ( $feed ) {
					$this->followers->remove( $feed, $actor );
				}
				return $this->accepted();
			default:
				// Likes, boosts, replies and the rest are taken and dropped
				return $this->accepted();
		}
	}

	private function follow( array $activity, string $actor ): Response {
		$feed = $this->getFeed( $activity['object'] ?? null );
		if ( !$feed ) {
			return $this->getResponseFactory()->createHttpError( 404, [ 'message' => 'No such feed' ] );
		}
		$document = $this->remoteKeys->fetchDocument( $actor );
		$inbox = is_array( $document )
			? ( $document['endpoints']['sharedInbox'] ?? $document['inbox'] ?? null )
			: null;
		if ( !is_string( $inbox ) ) {
			return $this->getResponseFactory()->createHttpError( 400, [ 'message' => 'The actor has no inbox' ] );
		}
		$this->followers->add( $feed, $actor, $inbox );

		$actorId = $this->notes->actors->getActorId( $feed->getHandle() );
		$this->jobQueueGroup->lazyPush( new DeliveryJob( [
			'inbox' => $inbox,
			'keyId' => "$actorId#main-key",
			'activity' => [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => "$actorId#accepts/" . sha1( self::getId( $activity['id'] ?? null ) ?? $actor ),
				'type' => 'Accept',
				'actor' => $actorId,
				'object' => $activity,
			],
		] ) );
		return $this->accepted();
	}

	private function getFeed( mixed $object ): ?Feed {
		$handle = $this->notes->actors->getHandleFromActorId( (string)self::getId( $object ) );
		return $handle === null ? null : $this->feedLookup->getFeed( $handle );
	}

	/**
	 * The path and query the sender signed. The web server rewrites the actor URL to the REST
	 * route, so it is rebuilt from the actor base.
	 */
	private function getSignedTarget(): string {
		$uri = $this->getRequest()->getUri();
		$path = $uri->getPath();
		$at = strpos( $path, self::ROUTE );
		$rest = $at === false ? '' : substr( $path, $at + strlen( self::ROUTE ) );
		$base = (string)parse_url( $this->notes->actors->getActorId( '' ), PHP_URL_PATH );
		$query = $uri->getQuery();
		return $base . $rest . ( $query !== '' ? "?$query" : '' );
	}

	/**
	 * The id of an object given as its id or as itself.
	 */
	private static function getId( mixed $object ): ?string {
		if ( is_array( $object ) ) {
			$object = $object['id'] ?? null;
		}
		return is_string( $object ) && $object !== '' ? $object : null;
	}

	private function accepted(): Response {
		$response = $this->getResponseFactory()->create();
		$response->setStatus( 202 );
		return $response;
	}

	public function getSupportedRequestTypes(): array {
		return [
			RequestInterface::JSON_CONTENT_TYPE,
			'application/activity+json',
			'application/ld+json',
		];
	}
}
