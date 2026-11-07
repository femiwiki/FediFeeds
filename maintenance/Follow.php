<?php

namespace MediaWiki\Extension\FediFeeds\Maintenance;

use Femiwiki\WebFinger\Account;
use Femiwiki\WebFinger\Jrd;
use MediaWiki\Extension\FediFeeds\DeliveryJob;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Makes a feed follow a remote account, or withdraw the follow. A feed follows nothing by
 * itself; this is for a bridge such as Bridgy Fed, which bridges the accounts that follow it.
 */
class Follow extends Maintenance {

	private const ACTIVITY_TYPES = [ 'application/activity+json', 'application/ld+json' ];

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Make a feed follow a remote account, or withdraw the follow' );
		$this->addArg( 'feed', 'The handle of the feed, such as category_4521' );
		$this->addArg( 'account', 'The account to follow, as user@host or as its actor id' );
		$this->addOption( 'undo', 'Withdraw the follow instead' );
		$this->requireExtension( 'FediFeeds' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		/** @var FeedLookup $feedLookup */
		$feedLookup = $services->getService( 'FediFeeds.FeedLookup' );
		$handle = (string)$this->getArg( 0 );
		if ( !$feedLookup->getFeed( $handle ) ) {
			$this->fatalError( "No such feed: $handle" );
		}

		$account = (string)$this->getArg( 1 );
		$url = str_starts_with( $account, 'https://' ) ? $account : $this->resolve( $account );
		if ( $url === null ) {
			$this->fatalError( "WebFinger did not give an actor for $account" );
		}
		/** @var RemoteKeys $remoteKeys */
		$remoteKeys = $services->getService( 'FediFeeds.RemoteKeys' );
		$document = $remoteKeys->fetchDocument( $url );
		$target = is_array( $document ) && is_string( $document['id'] ?? null ) ? $document['id'] : null;
		$inbox = is_array( $document ) && is_string( $document['inbox'] ?? null ) ? $document['inbox'] : null;
		if ( $target === null || $inbox === null ) {
			$this->fatalError( "$url is not an actor with an inbox" );
		}

		/** @var Notes $notes */
		$notes = $services->getService( 'FediFeeds.Notes' );
		$actorId = $notes->actors->getActorId( $handle );
		$follow = [
			'id' => "$actorId#follows/" . sha1( $target ),
			'type' => 'Follow',
			'actor' => $actorId,
			'object' => $target,
		];
		$activity = $this->hasOption( 'undo' )
			? [ 'id' => $follow['id'] . '/undo', 'type' => 'Undo', 'actor' => $actorId, 'object' => $follow ]
			: $follow;
		$job = new DeliveryJob( [
			'inbox' => $inbox,
			'keyId' => "$actorId#main-key",
			'activity' => [ '@context' => 'https://www.w3.org/ns/activitystreams' ] + $activity,
		] );
		if ( !$job->run() ) {
			$this->fatalError( $job->getLastError() );
		}
		$this->output( "Sent {$activity['type']} of $target from $handle.\n" );
	}

	/**
	 * The actor id that WebFinger gives for user@host.
	 */
	private function resolve( string $account ): ?string {
		$parsed = Account::fromUri( 'acct:' . ltrim( $account, '@' ) );
		if ( !$parsed ) {
			return null;
		}
		$url = "https://{$parsed->host}/.well-known/webfinger?resource=" . rawurlencode( $parsed->toUri() );
		$request = $this->getServiceContainer()->getHttpRequestFactory()
			->create( $url, [ 'method' => 'GET', 'timeout' => 5 ], __METHOD__ );
		$request->setHeader( 'Accept', Jrd::CONTENT_TYPE );
		if ( !$request->execute()->isOK() ) {
			return null;
		}
		$jrd = json_decode( $request->getContent(), true );
		foreach ( is_array( $jrd['links'] ?? null ) ? $jrd['links'] : [] as $link ) {
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

// @codeCoverageIgnoreStart
$maintClass = Follow::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
