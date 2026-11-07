<?php

namespace MediaWiki\Extension\FediFeeds\Maintenance;

use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Following;
use MediaWiki\Maintenance\Maintenance;
use RuntimeException;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Makes a feed follow a remote account, or withdraw the follow, as $wgFediFeedsAutoFollow does
 * for every feed with an item. The follow is recorded either way, so the job does not send it
 * again; an Undo removes the record.
 */
class Follow extends Maintenance {

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
		/** @var Following $following */
		$following = $services->getService( 'FediFeeds.Following' );
		$undo = $this->hasOption( 'undo' );
		try {
			$target = $following->send( $handle, (string)$this->getArg( 1 ), $undo );
		} catch ( RuntimeException $e ) {
			$this->fatalError( $e->getMessage() );
		}
		$this->output( 'Sent ' . ( $undo ? 'Undo' : 'Follow' ) . " of $target from $handle.\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = Follow::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
