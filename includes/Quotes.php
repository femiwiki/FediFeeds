<?php

namespace MediaWiki\Extension\FediFeeds;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The quotes of notes that were authorized, each served as a QuoteAuthorization until the
 * quoting post goes away or its author withdraws the request
 */
class Quotes {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Records the authorization, or keeps the one the post has from the feed already, taking
	 * the item and the request's id in case it is a new request for the same post.
	 *
	 * @param Feed $feed
	 * @param string $item The last part of the quoted note's id, from Notes::getItemId()
	 * @param string $actor
	 * @param string $post
	 * @param string $request
	 * @return int The authorization's id
	 */
	public function add( Feed $feed, string $item, string $actor, string $post, string $request ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_quote' )
			->row( [
				'fq_feed' => $feed->getHandle(),
				'fq_item' => $item,
				'fq_actor' => $actor,
				'fq_post' => $post,
				'fq_request' => $request,
				'fq_timestamp' => $dbw->timestamp(),
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'fq_post', 'fq_feed' ] )
			->set( [ 'fq_item' => $item, 'fq_request' => $request ] )
			->caller( __METHOD__ )->execute();
		return (int)$dbw->newSelectQueryBuilder()
			->select( 'fq_id' )
			->from( 'fedifeeds_quote' )
			->where( [ 'fq_post' => $post, 'fq_feed' => $feed->getHandle() ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * The authorization with the id, if the feed gave it.
	 *
	 * @return stdClass|null With fq_item, fq_post
	 */
	public function get( Feed $feed, int $id ): ?stdClass {
		$row = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'fq_item', 'fq_post' ] )
			->from( 'fedifeeds_quote' )
			->where( [ 'fq_id' => $id, 'fq_feed' => $feed->getHandle() ] )
			->caller( __METHOD__ )->fetchRow();
		return $row ?: null;
	}

	/**
	 * Removes what the actor's request authorized, for an Undo of the request.
	 */
	public function removeByRequest( string $actor, string $request ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_quote' )
			->where( [ 'fq_actor' => $actor, 'fq_request' => $request ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Removes what the actor's post was authorized, for a Delete of the post.
	 */
	public function removeByPost( string $actor, string $post ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_quote' )
			->where( [ 'fq_post' => $post, 'fq_actor' => $actor ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Removes everything the actor was authorized, for a Delete of the actor.
	 */
	public function removeByActor( string $actor ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_quote' )
			->where( [ 'fq_actor' => $actor ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Whether the actor quotes any note, which decides if its Delete is worth verifying.
	 */
	public function isQuoter( string $actor ): bool {
		return (bool)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_quote' )
			->where( [ 'fq_actor' => $actor ] )
			->caller( __METHOD__ )->fetchField();
	}
}
