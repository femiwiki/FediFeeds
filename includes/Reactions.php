<?php

namespace MediaWiki\Extension\FediFeeds;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The Likes and Announces that remote actors gave the notes of the feeds' items, one per actor,
 * item and kind
 */
class Reactions {

	public const LIKE = 'like';
	public const ANNOUNCE = 'announce';

	/** The longest actor or activity id that is kept, in bytes */
	public const MAX_ID_LENGTH = 255;

	/** The kind of reaction each activity type gives */
	public const ACTIVITY_TYPES = [
		'Like' => self::LIKE,
		// An emoji reaction, under either name in use
		'EmojiReact' => self::LIKE,
		'EmojiReaction' => self::LIKE,
		'Announce' => self::ANNOUNCE,
	];

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Records the reaction unless the actor gave it already.
	 *
	 * @param Feed $feed
	 * @param int $pageId The item's page id
	 * @param string $key The item's key
	 * @param string $type self::LIKE or self::ANNOUNCE
	 * @param string $actor Id of the remote actor
	 * @param string $activityId Id of the Like or Announce, which an Undo may name it by
	 * @return bool Whether it was new
	 */
	public function add(
		Feed $feed, int $pageId, string $key, string $type, string $actor, string $activityId
	): bool {
		if ( strlen( $actor ) > self::MAX_ID_LENGTH ) {
			return false;
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_reaction' )
			->ignore()
			->row( [
				'fr_feed' => $feed->getHandle(),
				'fr_page' => $pageId,
				'fr_key' => $key,
				'fr_type' => $type,
				'fr_actor' => $actor,
				'fr_activity' => strlen( $activityId ) > self::MAX_ID_LENGTH ? '' : $activityId,
				'fr_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * Removes the actor's reaction that the activity id names.
	 *
	 * @return bool Whether there was one
	 */
	public function removeByActivity( string $actor, string $activityId ): bool {
		if ( $activityId === '' ) {
			return false;
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_reaction' )
			->where( [ 'fr_actor' => $actor, 'fr_activity' => $activityId ] )
			->caller( __METHOD__ )->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * Removes the actor's reaction of that kind to the item.
	 */
	public function remove( Feed $feed, int $pageId, string $key, string $type, string $actor ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_reaction' )
			->where( [
				'fr_feed' => $feed->getHandle(),
				'fr_page' => $pageId,
				'fr_key' => $key,
				'fr_type' => $type,
				'fr_actor' => $actor,
			] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Removes every reaction of the actor, which is gone.
	 */
	public function removeActor( string $actor ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_reaction' )
			->where( [ 'fr_actor' => $actor ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Whether the actor reacted to anything, which decides if its Delete is worth verifying.
	 */
	public function isReactor( string $actor ): bool {
		return (bool)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_reaction' )
			->where( [ 'fr_actor' => $actor ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * How many actors gave the notes of the page's items each kind, across every feed.
	 *
	 * @return array{like: int, announce: int}
	 */
	public function countActorsForPage( int $pageId ): array {
		$counts = [ self::LIKE => 0, self::ANNOUNCE => 0 ];
		$rows = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'fr_type', 'count' => 'COUNT(DISTINCT fr_actor)' ] )
			->from( 'fedifeeds_reaction' )
			->where( [ 'fr_page' => $pageId ] )
			->groupBy( 'fr_type' )
			->caller( __METHOD__ )->fetchResultSet();
		foreach ( $rows as $row ) {
			'@phan-var stdClass $row';
			if ( isset( $counts[$row->fr_type] ) ) {
				$counts[$row->fr_type] = (int)$row->count;
			}
		}
		return $counts;
	}

	/**
	 * How many of each kind the notes of the page's items got, across every feed.
	 *
	 * @return array{like: int, announce: int}
	 */
	public function countForPage( int $pageId ): array {
		$counts = [ self::LIKE => 0, self::ANNOUNCE => 0 ];
		$rows = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'fr_type', 'count' => 'COUNT(*)' ] )
			->from( 'fedifeeds_reaction' )
			->where( [ 'fr_page' => $pageId ] )
			->groupBy( 'fr_type' )
			->caller( __METHOD__ )->fetchResultSet();
		foreach ( $rows as $row ) {
			'@phan-var stdClass $row';
			if ( isset( $counts[$row->fr_type] ) ) {
				$counts[$row->fr_type] = (int)$row->count;
			}
		}
		return $counts;
	}
}
