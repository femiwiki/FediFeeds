<?php

namespace MediaWiki\Extension\FediFeeds;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The remote actors that follow each feed, and where to deliver to them
 */
class Followers {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	public function add( Feed $feed, string $actor, string $inbox ): void {
		$this->dbProvider->getPrimaryDatabase()->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_follower' )
			->row( [
				'ff_feed' => $feed->getHandle(),
				'ff_actor' => $actor,
				'ff_inbox' => $inbox,
				'ff_timestamp' => $this->dbProvider->getPrimaryDatabase()->timestamp(),
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'ff_feed', 'ff_actor' ] )
			->set( [ 'ff_inbox' => $inbox ] )
			->caller( __METHOD__ )->execute();
	}

	public function remove( Feed $feed, string $actor ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_follower' )
			->where( [ 'ff_feed' => $feed->getHandle(), 'ff_actor' => $actor ] )
			->caller( __METHOD__ )->execute();
	}

	public function count( Feed $feed ): int {
		return (int)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'fedifeeds_follower' )
			->where( [ 'ff_feed' => $feed->getHandle() ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Where to deliver the feed's activities, each inbox once, so a server's shared inbox
	 * gets one request however many of its actors follow.
	 *
	 * @return string[]
	 */
	public function getInboxes( Feed $feed ): array {
		return $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'ff_inbox' )
			->distinct()
			->from( 'fedifeeds_follower' )
			->where( [ 'ff_feed' => $feed->getHandle() ] )
			->caller( __METHOD__ )->fetchFieldValues();
	}

	/**
	 * Whether the actor follows any feed, which decides if its Delete is worth verifying.
	 */
	public function isFollower( string $actor ): bool {
		return (bool)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_follower' )
			->where( [ 'ff_actor' => $actor ] )
			->caller( __METHOD__ )->fetchField();
	}
}
