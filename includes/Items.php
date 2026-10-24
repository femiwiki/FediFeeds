<?php

namespace MediaWiki\Extension\FediFeeds;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * The items each feed has had, recorded when they appear. An item is a page id and a key, which
 * is the heading path of a section item and empty for a category item.
 */
class Items {

	/** The longest key that fits fi_key, in bytes */
	public const MAX_KEY_LENGTH = 255;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Records the item unless it is recorded already.
	 *
	 * @param Feed $feed
	 * @param int $pageId
	 * @param string $timestamp When it appeared
	 * @param string $key
	 * @param int $revId The revision it appeared in
	 * @param string $anchor The link anchor of a section item's heading in that revision
	 * @return bool Whether it was new
	 */
	public function add(
		Feed $feed, int $pageId, string $timestamp, string $key = '', int $revId = 0, string $anchor = ''
	): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_item' )
			->ignore()
			->row( [
				'fi_feed' => $feed->getHandle(),
				'fi_page' => $pageId,
				'fi_key' => $key,
				'fi_rev' => $revId,
				'fi_anchor' => strlen( $anchor ) > self::MAX_KEY_LENGTH ? '' : $anchor,
				'fi_timestamp' => $dbw->timestamp( $timestamp ),
			] )
			->caller( __METHOD__ )->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * Moves a recorded section item to a later revision that changed it, so its note links
	 * to what followers were told last.
	 *
	 * @param Feed $feed
	 * @param int $pageId
	 * @param string $key
	 * @param int $revId
	 * @param string $anchor The link anchor of the item's heading in that revision
	 * @return bool Whether the item is recorded
	 */
	public function setRevision( Feed $feed, int $pageId, string $key, int $revId, string $anchor ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( 'fedifeeds_item' )
			->set( [
				'fi_rev' => $revId,
				'fi_anchor' => strlen( $anchor ) > self::MAX_KEY_LENGTH ? '' : $anchor,
			] )
			->where( [ 'fi_feed' => $feed->getHandle(), 'fi_page' => $pageId, 'fi_key' => $key ] )
			->caller( __METHOD__ )->execute();
		return $dbw->affectedRows() > 0;
	}

	public function has( Feed $feed, int $pageId, string $key = '' ): bool {
		return $this->get( $feed, $pageId, $key ) !== null;
	}

	/**
	 * When and in which revision the item appeared, and its anchor there, if it is recorded.
	 *
	 * @return array{timestamp: string, rev: int, anchor: string}|null
	 */
	public function get( Feed $feed, int $pageId, string $key = '' ): ?array {
		$row = $this->newQuery( $feed )
			->select( [ 'fi_timestamp', 'fi_rev', 'fi_anchor' ] )
			->andWhere( [ 'fi_page' => $pageId, 'fi_key' => $key ] )
			->caller( __METHOD__ )->fetchRow();
		if ( !$row ) {
			return null;
		}
		return [
			'timestamp' => (string)wfTimestamp( TS::MW, $row->fi_timestamp ),
			'rev' => (int)$row->fi_rev,
			'anchor' => (string)$row->fi_anchor,
		];
	}

	/**
	 * The newest items, newest first.
	 *
	 * @return array{page: int, key: string}[]
	 */
	public function getRecent( Feed $feed, int $limit ): array {
		$rows = $this->newQuery( $feed )
			->select( [ 'fi_page', 'fi_key' ] )
			->orderBy( [ 'fi_timestamp', 'fi_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )->fetchResultSet();
		$items = [];
		foreach ( $rows as $row ) {
			'@phan-var stdClass $row';
			$items[] = [ 'page' => (int)$row->fi_page, 'key' => (string)$row->fi_key ];
		}
		return $items;
	}

	/**
	 * Whether any of the feeds recorded an item of the page.
	 *
	 * @param int $pageId
	 * @param string[] $handles
	 */
	public function hasPage( int $pageId, array $handles ): bool {
		return $handles && $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_item' )
			->where( [ 'fi_feed' => $handles, 'fi_page' => $pageId ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Whether the page is an item of any category feed, of the pages or of a language's
	 * translations
	 */
	public function isCategoryItem( int $pageId ): bool {
		$dbr = $this->dbProvider->getReplicaDatabase();
		return (bool)$dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_item' )
			->where( [
				'fi_page' => $pageId,
				'fi_key' => '',
				$dbr->expr( 'fi_feed', IExpression::LIKE, new LikeValue( 'category_', $dbr->anyString() ) ),
			] )
			->caller( __METHOD__ )->fetchField();
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
