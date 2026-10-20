<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\Feed;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\FetchActorJob;
use MediaWiki\Extension\FediFeeds\Followers;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\PurgeActorJob;
use MediaWiki\Extension\FediFeeds\Quotes;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Extension\FediFeeds\RemoteActors;
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
		private readonly Reactions $reactions,
		private readonly RemoteActors $remoteActors,
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
		// Only an Update of an actor whose reactions are shown is used, so others are dropped
		// before their signature is checked
		if ( $activity['type'] === 'Update' && !$this->remoteActors->has( $actor ) ) {
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
				$objectType = is_array( $object ) ? ( $object['type'] ?? null ) : null;
				$feed = $objectType === 'Follow' ? $this->getFeed( $object['object'] ?? null ) : null;
				if ( $feed ) {
					$this->followers->remove( $feed, $actor );
				}
				// A withdrawn quote request, given as itself or by its id
				$request = self::getId( $object );
				if ( !$feed && $request !== null
					&& ( !is_array( $object ) || $objectType === 'QuoteRequest' )
				) {
					$this->quotes->removeByRequest( $actor, $request );
				}
				// A withdrawn Like or Announce, given as itself or by its id
				if ( !$feed && ( $objectType === null || isset( Reactions::ACTIVITY_TYPES[$objectType] ) ) ) {
					$this->undoReaction( $object, $actor );
				}
				return $this->accepted();
			case 'Update':
				// The actor's own document, signed by it, refreshes what is cached about it
				if ( self::getId( $activity['object'] ?? null ) === $actor ) {
					$this->remoteActors->update( $actor, $activity['object'] );
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
				// Reactions are only kept when interactions are on; until then they are dropped
				if ( $this->notes->interactions && isset( Reactions::ACTIVITY_TYPES[$activity['type']] ) ) {
					$this->react( $activity, $actor );
				}
				// Replies and the rest are taken and dropped, as are reactions to what is not ours
				return $this->accepted();
		}
	}

	/**
	 * Records the signer's Like or Announce of one of our notes.
	 *
	 * @param array $activity A Like, an emoji reaction or an Announce
	 * @param string $actor The signer
	 */
	private function react( array $activity, string $actor ): void {
		$item = $this->getReactedItem( $activity );
		if ( !$item ) {
			return;
		}
		$this->reactions->add( $item['feed'], $item['page'], $item['key'],
			Reactions::ACTIVITY_TYPES[$activity['type']], $actor, self::getId( $activity['id'] ?? null ) ?? '' );
		if ( $this->remoteActors->isStale( $actor ) ) {
			// The actor document the signature's key came from, if it was fetched just now, or
			// else a job reads it, so the request waits on no other server
			$document = $this->remoteKeys->getFetchedDocument( $actor );
			if ( !$this->remoteActors->update( $actor, $document ) ) {
				$this->jobQueueGroup->lazyPush( new FetchActorJob( [ 'actor' => $actor ] ) );
			}
		}
	}

	/**
	 * Removes the signer's reaction that an Undo names, by the activity's id, or else by its
	 * kind and object when the Undo carries them.
	 *
	 * @param mixed $undone The undone activity or its id
	 * @param string $actor The signer, which is the only actor whose reaction this can touch
	 */
	private function undoReaction( mixed $undone, string $actor ): void {
		$activityId = self::getId( $undone );
		if ( $activityId !== null && $this->reactions->removeByActivity( $actor, $activityId ) ) {
			return;
		}
		$type = is_array( $undone ) ? Reactions::ACTIVITY_TYPES[$undone['type'] ?? ''] ?? null : null;
		$item = $type !== null ? $this->getReactedItem( $undone ) : null;
		if ( $item && $type !== null ) {
			$this->reactions->remove( $item['feed'], $item['page'], $item['key'], $type, $actor );
		}
	}

	/**
	 * The recorded item whose note a reaction is to, if it is ours.
	 *
	 * @param array $activity
	 * @return array{feed: Feed, page: int, key: string}|null
	 */
	private function getReactedItem( array $activity ): ?array {
		$noteId = self::getId( $activity['object'] ?? null );
		return $noteId === null ? null : $this->notes->resolveNoteId( $noteId );
	}

	private function follow( array $activity, string $actor ): Response {
		// The instance actor takes no followers, and a Follow of it is dropped unanswered
		if ( self::getId( $activity['object'] ?? null )
			=== $this->notes->actors->getActorId( Actors::INSTANCE_HANDLE )
		) {
			return $this->accepted();
		}
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
	 * Accept whose result is the QuoteAuthorization (FEP-044f). While interactions are off, it
	 * answers with a Reject instead and stores nothing, so the quoting server does not leave the
	 * quote pending. A request for a note we do not have, or one whose ids are not on the
	 * actor's server, is dropped.
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
		$actorId = $this->notes->actors->getActorId( $feed->getHandle() );
		$answer = [
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
		];
		if ( $this->notes->interactions ) {
			$id = $this->quotes->add( $feed, $this->notes->getItemId( $feed, $item ), $actor, $post, $request );
			$answer['result'] = $this->notes->getQuoteAuthorizationId( $feed, $id );
		} else {
			$answer['id'] = "$actorId#rejects/quote_requests/" . sha1( $request );
			$answer['type'] = 'Reject';
		}
		$this->jobQueueGroup->lazyPush( new DeliveryJob( [
			'inbox' => $inbox,
			'keyId' => "$actorId#main-key",
			'activity' => $answer,
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
		return $this->followers->isFollower( $actor ) || $this->quotes->isQuoter( $actor )
			|| $this->reactions->isReactor( $actor );
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
