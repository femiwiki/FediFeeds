<?php

namespace MediaWiki\Extension\FediFeeds;

use Femiwiki\HttpSignatures\Digest;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

/**
 * Posts one activity to one inbox, signed with the wiki's key under an actor's key id
 */
class DeliveryJob extends Job implements GenericParameterJob {

	public const TYPE = 'fedifeedsDeliver';

	/**
	 * @param array $params inbox (URL), activity (array), keyId (string)
	 */
	public function __construct( array $params ) {
		parent::__construct( self::TYPE, $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$url = (string)$this->params['inbox'];
		if ( parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) {
			return true;
		}
		/** @var LocalKeyStore $localKeyStore */
		$localKeyStore = $services->getService( 'FediFeeds.LocalKeyStore' );
		$key = $localKeyStore->getKey();
		$body = json_encode( $this->params['activity'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $body === false ) {
			// Retrying would not encode it either
			$this->setLastError( 'The activity could not be encoded: ' . json_last_error_msg() );
			return true;
		}
		$target = ( parse_url( $url, PHP_URL_PATH ) ?: '/' )
			. ( parse_url( $url, PHP_URL_QUERY ) !== null ? '?' . parse_url( $url, PHP_URL_QUERY ) : '' );
		$headers = [
			'host' => (string)parse_url( $url, PHP_URL_HOST ),
			'date' => gmdate( 'D, d M Y H:i:s \G\M\T' ),
			'digest' => Digest::of( $body ),
			'content-type' => Actors::CONTENT_TYPE,
		];
		$headers['signature'] = $key->sign( 'POST', $target, $headers, (string)$this->params['keyId'] );

		$request = $services->getHttpRequestFactory()->create( $url,
			[ 'method' => 'POST', 'postData' => $body, 'timeout' => 10 ], __METHOD__ );
		foreach ( $headers as $name => $value ) {
			// Under its canonical name, since MediaWiki adds a form Content-Type to a string body
			// unless "Content-Type" is set, and the receiver would then see a header we did not sign
			$request->setHeader( ucwords( $name, '-' ), $value );
		}
		$status = $request->execute();
		if ( !$status->isOK() ) {
			$this->setLastError( "POST $url: " . $request->getStatus() );
			return false;
		}
		return true;
	}
}
