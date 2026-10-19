<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Extension\FediFeeds\Signatures\RemoteKeys;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

/**
 * Reads a remote actor that liked or shared a note into the cache of RemoteActors, outside the
 * request that brought its reaction
 */
class FetchActorJob extends Job implements GenericParameterJob {

	public const TYPE = 'fedifeedsFetchActor';

	/**
	 * @param array $params actor (string)
	 */
	public function __construct( array $params ) {
		parent::__construct( self::TYPE, $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$actor = (string)$this->params['actor'];
		/** @var Reactions $reactions */
		$reactions = $services->getService( 'FediFeeds.Reactions' );
		/** @var RemoteActors $remoteActors */
		$remoteActors = $services->getService( 'FediFeeds.RemoteActors' );
		// An actor whose reactions were undone or purged meanwhile is not kept
		if ( !$reactions->isReactor( $actor ) || !$remoteActors->isStale( $actor ) ) {
			return true;
		}
		/** @var RemoteKeys $remoteKeys */
		$remoteKeys = $services->getService( 'FediFeeds.RemoteKeys' );
		$remoteActors->update( $actor, $remoteKeys->fetchDocument( $actor ) );
		return true;
	}
}
