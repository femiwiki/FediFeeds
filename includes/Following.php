<?php

namespace MediaWiki\Extension\FediFeeds;

use Femiwiki\WebFinger\Account;
use Femiwiki\WebFinger\Jrd;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Http\HttpRequestFactory;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The follows that feeds send. A feed follows nothing by itself; this is for a bridge such as
 * Bridgy Fed, which bridges the accounts that follow it. Each follow a feed sent is recorded, so
 * that it is sent once even if the account later stops following back, as Bridgy Fed's bot
 * does when the bridge is turned off by blocking it.
 */
class Following {

	private const ACTIVITY_TYPES = [ 'application/activity+json', 'application/ld+json' ];

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param RemoteKeys $remoteKeys
	 * @param Actors $actors
	 * @param string[] $autoFollow Accounts each feed follows once it has an item, as user@host
	 *  or as actor ids
	 */
	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly RemoteKeys $remoteKeys,
		private readonly Actors $actors,
		public readonly array $autoFollow = [],
	) {
	}

	/**
	 * Whether the feed has sent a follow of the account, as written in $autoFollow or given to
	 * maintenance/Follow.php. A leading @ of user@host is ignored.
	 */
	public function has( string $handle, string $account ): bool {
		$account = ltrim( $account, '@' );
		return (bool)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'fedifeeds_following' )
			->where( [ 'fg_feed' => $handle, 'fg_account' => $account ] )
			->caller( __METHOD__ )->fetchField();
	}

	/**
	 * Sends the feed's Follow of the account, or with $undo its Undo, and records it.
	 *
	 * @param string $handle The feed's handle
	 * @param string $account user@host, or an actor id
	 * @param bool $undo
	 * @return string The id of the actor followed
	 * @throws RuntimeException When the account is not found or the delivery fails
	 */
	public function send( string $handle, string $account, bool $undo = false ): string {
		$account = ltrim( $account, '@' );
		$url = str_starts_with( $account, 'https://' ) ? $account : $this->resolve( $account );
		if ( $url === null ) {
			throw new RuntimeException( "WebFinger did not give an actor for $account" );
		}
		$document = $this->remoteKeys->fetchDocument( $url );
		$target = is_array( $document ) && is_string( $document['id'] ?? null ) ? $document['id'] : null;
		$inbox = is_array( $document ) && is_string( $document['inbox'] ?? null ) ? $document['inbox'] : null;
		if ( $target === null || $inbox === null ) {
			throw new RuntimeException( "$url is not an actor with an inbox" );
		}

		$actorId = $this->actors->getActorId( $handle );
		$follow = [
			'id' => "$actorId#follows/" . sha1( $target ),
			'type' => 'Follow',
			'actor' => $actorId,
			'object' => $target,
		];
		$activity = $undo
			? [ 'id' => $follow['id'] . '/undo', 'type' => 'Undo', 'actor' => $actorId, 'object' => $follow ]
			: $follow;
		$job = new DeliveryJob( [
			'inbox' => $inbox,
			'keyId' => "$actorId#main-key",
			'activity' => [ '@context' => 'https://www.w3.org/ns/activitystreams' ] + $activity,
		] );
		if ( !$job->run() ) {
			throw new RuntimeException( $job->getLastError() );
		}
		$undo ? $this->remove( $handle, $account ) : $this->add( $handle, $account );
		return $target;
	}

	private function add( string $handle, string $account ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'fedifeeds_following' )
			->ignore()
			->row( [ 'fg_feed' => $handle, 'fg_account' => $account, 'fg_timestamp' => $dbw->timestamp() ] )
			->caller( __METHOD__ )->execute();
	}

	private function remove( string $handle, string $account ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'fedifeeds_following' )
			->where( [ 'fg_feed' => $handle, 'fg_account' => $account ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * The actor id that WebFinger gives for user@host.
	 */
	private function resolve( string $account ): ?string {
		$parsed = Account::fromUri( "acct:$account" );
		if ( !$parsed ) {
			return null;
		}
		$url = "https://{$parsed->host}/.well-known/webfinger?resource=" . rawurlencode( $parsed->toUri() );
		$request = $this->httpRequestFactory->create( $url, [ 'method' => 'GET', 'timeout' => 5 ], __METHOD__ );
		$request->setHeader( 'Accept', Jrd::CONTENT_TYPE );
		if ( !$request->execute()->isOK() ) {
			return null;
		}
		$jrd = json_decode( $request->getContent(), true );
		$links = is_array( $jrd ) && is_array( $jrd['links'] ?? null ) ? $jrd['links'] : [];
		foreach ( $links as $link ) {
			if ( is_array( $link ) && ( $link['rel'] ?? null ) === Jrd::REL_SELF
				&& in_array( explode( ';', (string)( $link['type'] ?? '' ) )[0], self::ACTIVITY_TYPES, true )
				&& is_string( $link['href'] ?? null ) && str_starts_with( $link['href'], 'https://' )
			) {
				return $link['href'];
			}
		}
		return null;
	}
}
