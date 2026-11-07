<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use RuntimeException;

/**
 * Sends a feed's Follow of an account in $wgFediFeedsAutoFollow, unless it was sent before
 */
class FollowJob extends Job implements GenericParameterJob {

	public const TYPE = 'fedifeedsFollow';

	/**
	 * @param array $params feed (handle), account (user@host or actor id)
	 */
	public function __construct( array $params ) {
		parent::__construct( self::TYPE, $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		/** @var Following $following */
		$following = MediaWikiServices::getInstance()->getService( 'FediFeeds.Following' );
		$handle = (string)$this->params['feed'];
		$account = (string)$this->params['account'];
		if ( $following->has( $handle, $account ) ) {
			return true;
		}
		try {
			$following->send( $handle, $account );
		} catch ( RuntimeException $e ) {
			$this->setLastError( $e->getMessage() );
			return false;
		}
		return true;
	}
}
