<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Extension\FediFeeds\Signatures\GoneActors;
use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

/**
 * Drops what a remote actor that deleted itself left here: its follows of every feed, its
 * quote authorizations, its Likes and Announces, what is cached about it and its cached key
 */
class PurgeActorJob extends Job implements GenericParameterJob {

	public const TYPE = 'fedifeedsPurgeActor';

	/**
	 * @param array $params actor (string); check (bool), true when the Delete's signature did
	 *  not verify, so the actor has to be seen gone first; keyId (string, optional), the key
	 *  to forget
	 */
	public function __construct( array $params ) {
		parent::__construct( self::TYPE, $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$actor = (string)$this->params['actor'];
		if ( ( $this->params['check'] ?? true )
			&& !GoneActors::isGone( $services->getHttpRequestFactory(), $actor )
		) {
			return true;
		}
		/** @var Followers $followers */
		$followers = $services->getService( 'FediFeeds.Followers' );
		$followers->removeActor( $actor );
		/** @var Quotes $quotes */
		$quotes = $services->getService( 'FediFeeds.Quotes' );
		$quotes->removeByActor( $actor );
		/** @var Reactions $reactions */
		$reactions = $services->getService( 'FediFeeds.Reactions' );
		$reactions->removeActor( $actor );
		/** @var RemoteActors $remoteActors */
		$remoteActors = $services->getService( 'FediFeeds.RemoteActors' );
		$remoteActors->remove( $actor );
		/** @var RemoteKeys $remoteKeys */
		$remoteKeys = $services->getService( 'FediFeeds.RemoteKeys' );
		$remoteKeys->forget( (string)( $this->params['keyId'] ?? "$actor#main-key" ) );
		return true;
	}
}
