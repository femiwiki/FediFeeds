<?php

namespace MediaWiki\Extension\FediFeeds;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * What remote actors that liked or shared a note say about themselves: a handle, a display name,
 * a profile page and whether they let themselves be discovered. Only a discoverable actor is
 * ever named; any other is only counted.
 */
class RemoteActors {

	/** How long a cached actor is trusted before it is read again, in seconds */
	public const MAX_AGE = 7 * 24 * 3600;

	/** The types an actor document can have */
	public const ACTOR_TYPES = [ 'Person', 'Service', 'Application', 'Group', 'Organization' ];

	private const MAX_LENGTH = 255;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Stores what the actor's document says, if it is the actor's own document.
	 *
	 * @param string $actor Id of the actor
	 * @param mixed $document The actor document, fetched or carried by a signed Update
	 * @return bool Whether it was stored
	 */
	public function update( string $actor, mixed $document ): bool {
		$row = self::parse( $actor, $document );
		if ( $row === null ) {
			return false;
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$row['fa_checked'] = $dbw->timestamp();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_actor' )
			->row( [ 'fa_actor' => $actor ] + $row )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'fa_actor' ] )
			->set( $row )
			->caller( __METHOD__ )->execute();
		return true;
	}

	/**
	 * The columns for an actor document, or null if it is not the actor's.
	 *
	 * @return array{fa_handle: string, fa_name: string, fa_url: string, fa_discoverable: int}|null
	 */
	public static function parse( string $actor, mixed $document ): ?array {
		if ( !is_array( $document ) || ( $document['id'] ?? null ) !== $actor
			|| !in_array( $document['type'] ?? null, self::ACTOR_TYPES, true )
			|| strlen( $actor ) > self::MAX_LENGTH
		) {
			return null;
		}
		$username = $document['preferredUsername'] ?? null;
		$host = parse_url( $actor, PHP_URL_HOST );
		$handle = is_string( $username ) && preg_match( '/^[^\s@]+$/uD', $username ) && is_string( $host )
			? "$username@$host" : '';
		$name = is_string( $document['name'] ?? null ) ? self::clean( $document['name'] ) : '';
		return [
			'fa_handle' => strlen( $handle ) > self::MAX_LENGTH ? '' : $handle,
			'fa_name' => mb_strcut( $name, 0, self::MAX_LENGTH ),
			'fa_url' => self::getProfileUrl( $document['url'] ?? null ) ?? $actor,
			// Only an explicit true lets the actor be named
			'fa_discoverable' => ( $document['discoverable'] ?? false ) === true ? 1 : 0,
		];
	}

	/**
	 * The first http or https URL that a url property gives, as a string or a Link.
	 */
	private static function getProfileUrl( mixed $url ): ?string {
		$candidates = is_array( $url ) && array_is_list( $url ) ? $url : [ $url ];
		foreach ( $candidates as $candidate ) {
			$href = is_array( $candidate ) ? ( $candidate['href'] ?? null ) : $candidate;
			if ( is_string( $href ) && preg_match( '#^https?://[^\s]+$#iD', $href ) ) {
				return $href;
			}
		}
		return null;
	}

	/**
	 * A display name as one line of plain text.
	 */
	private static function clean( string $name ): string {
		$name = html_entity_decode( strip_tags( $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string)preg_replace( '/[\p{C}\s]+/u', ' ', $name ) );
	}

	public function has( string $actor ): bool {
		return (bool)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_actor' )
			->where( [ 'fa_actor' => $actor ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Whether the actor is not cached, or was read longer than MAX_AGE ago.
	 */
	public function isStale( string $actor, ?int $now = null ): bool {
		$checked = $this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'fa_checked' )
			->from( 'fedifeeds_actor' )
			->where( [ 'fa_actor' => $actor ] )
			->caller( __METHOD__ )->fetchField();
		return $checked === false
			|| (int)wfTimestamp( TS::UNIX, $checked ) < ( $now ?? time() ) - self::MAX_AGE;
	}

	public function remove( string $actor ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_actor' )
			->where( [ 'fa_actor' => $actor ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * The discoverable actors that gave the page's notes that kind of reaction, the one that
	 * reacted last first.
	 *
	 * @param int $pageId
	 * @param string $type Reactions::LIKE or Reactions::ANNOUNCE
	 * @param int $limit
	 * @return array{actor: string, handle: string, name: string, url: string}[]
	 */
	public function getRecentDiscoverable( int $pageId, string $type, int $limit ): array {
		$rows = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'fa_actor', 'fa_handle', 'fa_name', 'fa_url', 'latest' => 'MAX(fr_timestamp)' ] )
			->from( 'fedifeeds_reaction' )
			->join( 'fedifeeds_actor', null, 'fa_actor = fr_actor' )
			->where( [ 'fr_page' => $pageId, 'fr_type' => $type, 'fa_discoverable' => 1 ] )
			->groupBy( [ 'fa_actor', 'fa_handle', 'fa_name', 'fa_url' ] )
			->orderBy( [ 'latest', 'fa_actor' ], SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )->fetchResultSet();
		$actors = [];
		foreach ( $rows as $row ) {
			'@phan-var stdClass $row';
			$actors[] = self::toArray( $row );
		}
		return $actors;
	}

	/**
	 * An actor as it is shown, from a row with the fa_ columns.
	 *
	 * @return array{actor: string, handle: string, name: string, url: string}
	 */
	public static function toArray( stdClass $row ): array {
		return [
			'actor' => (string)$row->fa_actor,
			'handle' => (string)$row->fa_handle,
			'name' => (string)$row->fa_name,
			'url' => (string)$row->fa_url,
		];
	}
}
