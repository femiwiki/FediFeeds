<?php

namespace MediaWiki\Extension\FediFeeds;

use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * The items each feed has had, recorded when they appear
 */
class Items {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Records the item unless it is recorded already.
	 *
	 * @return bool Whether it was new
	 */
	public function add( Feed $feed, int $pageId, string $timestamp ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_item' )
			->ignore()
			->row( [
				'fi_feed' => $feed->getHandle(),
				'fi_page' => $pageId,
				'fi_timestamp' => $dbw->timestamp( $timestamp ),
			] )
			->caller( __METHOD__ )->execute();
		return $dbw->affectedRows() > 0;
	}

	public function has( Feed $feed, int $pageId ): bool {
		return (bool)$this->newQuery( $feed )
			->select( '1' )
			->andWhere( [ 'fi_page' => $pageId ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Page ids of the newest items, newest first.
	 *
	 * @return int[]
	 */
	public function getRecent( Feed $feed, int $limit ): array {
		return array_map( 'intval', $this->newQuery( $feed )
			->select( 'fi_page' )
			->orderBy( [ 'fi_timestamp', 'fi_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )->fetchFieldValues() );
	}

	public function count( Feed $feed ): int {
		return (int)$this->newQuery( $feed )
			->select( 'COUNT(*)' )
			->caller( __METHOD__ )->fetchField();
	}

	private function newQuery( Feed $feed ): SelectQueryBuilder {
		return $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->from( 'fedifeeds_item' )
			->where( [ 'fi_feed' => $feed->getHandle() ] );
	}
}
