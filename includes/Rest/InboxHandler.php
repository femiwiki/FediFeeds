<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\PurgeActorJob;
use MediaWiki\Extension\FediFeeds\Quotes;
use MediaWiki\Extension\FediFeeds\Signatures\HttpSignature;
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
		private readonly Quotes $quotes,
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
		if ( $activity['type'] === 'Delete' && !$this->isKnown( $actor ) ) {
			return $this->accepted();
		}

		$headers = [];
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[strtolower( (string)$name )] = implode( ', ', $values );
		}
		$verified = $this->remoteKeys->verify( 'POST', $this->getSignedTarget(), $headers, $body ) === $actor;
		if ( $activity['type'] === 'Delete' && self::getId( $activity['object'] ?? null ) === $actor ) {
			// An account that deleted itself no longer serves its key, so a signature that does
			// not verify is left to the job, which purges the actor once it is seen gone
			return $this->purgeActor( $actor, $headers['signature'] ?? '', !$verified );
		}
		if ( !$verified ) {
			return $this->getResponseFactory()->createHttpError( 401, [ 'message' => 'Bad signature' ] );
		}

		switch ( $activity['type'] ) {
			case 'Follow':
				return $this->follow( $activity, $actor );
			case 'QuoteRequest':
				return $this->quoteRequest( $activity, $actor );
			case 'Undo':
				$object = $activity['object'] ?? null;
				$feed = is_array( $object ) && ( $object['type'] ?? null ) === 'Follow'
					? $this->getFeed( $object['object'] ?? null ) : null;
				if ( $feed ) {
					$this->followers->remove( $feed, $actor );
				}
				// A withdrawn quote request, given as itself or by its id
				$request = self::getId( $object );
				if ( !$feed && $request !== null
					&& ( !is_array( $object ) || ( $object['type'] ?? null ) === 'QuoteRequest' )
				) {
					$this->quotes->removeByRequest( $actor, $request );
				}
				return $this->accepted();
			case 'Delete':
				// A deleted quoting post loses its authorization; a deleted actor was handled above
				$object = self::getId( $activity['object'] ?? null );
				if ( $object !== null ) {
					$this->quotes->removeByPost( $actor, $object );
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
		$inbox = $this->getInbox( $actor );
		if ( $inbox === null ) {
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

	/**
	 * Authorizes a quote of one of our notes, which anyone may quote, and answers with an
	 * Accept whose result is the QuoteAuthorization (FEP-044f). A request for a note we do not
	 * have, or one whose ids are not on the actor's server, is dropped.
	 */
	private function quoteRequest( array $activity, string $actor ): Response {
		$request = self::getId( $activity['id'] ?? null );
		$post = self::getId( $activity['instrument'] ?? null );
		$note = $this->notes->findNote( (string)self::getId( $activity['object'] ?? null ) );
		// The ids are stored in 255-byte columns, which the ids of real servers fit
		if ( $request === null || $post === null || !$note
			|| !self::sameHost( $request, $actor ) || !self::sameHost( $post, $actor )
			|| strlen( $request ) > 255 || strlen( $post ) > 255 || strlen( $actor ) > 255
		) {
			return $this->accepted();
		}
		$inbox = $this->getInbox( $actor );
		if ( $inbox === null ) {
			return $this->getResponseFactory()->createHttpError( 400, [ 'message' => 'The actor has no inbox' ] );
		}
		[ $feed, $item ] = $note;
		$id = $this->quotes->add( $feed, $this->notes->getItemId( $feed, $item ), $actor, $post, $request );

		$actorId = $this->notes->actors->getActorId( $feed->getHandle() );
		$this->jobQueueGroup->lazyPush( new DeliveryJob( [
			'inbox' => $inbox,
			'keyId' => "$actorId#main-key",
			'activity' => [
				'@context' => Notes::CONTEXT,
				'id' => "$actorId#accepts/quote_requests/" . sha1( $request ),
				'type' => 'Accept',
				'actor' => $actorId,
				'to' => [ $actor ],
				'object' => [
					'id' => $request,
					'type' => 'QuoteRequest',
					'actor' => $actor,
					'object' => $this->notes->getNoteId( $feed, $item ),
					'instrument' => $post,
				],
				'result' => $this->notes->getQuoteAuthorizationId( $feed, $id ),
			],
		] ) );
		return $this->accepted();
	}

	/**
	 * Where to deliver to the actor: its shared inbox, or else its inbox.
	 */
	private function getInbox( string $actor ): ?string {
		$document = $this->remoteKeys->fetchDocument( $actor );
		$inbox = is_array( $document )
			? ( $document['endpoints']['sharedInbox'] ?? $document['inbox'] ?? null )
			: null;
		return is_string( $inbox ) ? $inbox : null;
	}

	private static function sameHost( string $url, string $other ): bool {
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && $host === parse_url( $other, PHP_URL_HOST );
	}

	/**
	 * Whether the actor has anything here that its Delete would remove, which decides if the
	 * Delete is worth verifying.
	 */
	private function isKnown( string $actor ): bool {
		return $this->followers->isFollower( $actor ) || $this->quotes->isQuoter( $actor );
	}

	private function purgeActor( string $actor, string $signature, bool $check ): Response {
		$params = [ 'actor' => $actor, 'check' => $check ];
		$keyId = HttpSignature::parse( $signature )['keyId'] ?? null;
		if ( is_string( $keyId ) && str_starts_with( $keyId, $actor ) ) {
			$params['keyId'] = $keyId;
		}
		$this->jobQueueGroup->lazyPush( new PurgeActorJob( $params ) );
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
