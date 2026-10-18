<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Extension\FediFeeds\Signatures\HttpSignature;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKey;
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
		$key = LocalKey::newFromConfig( $services->getMainConfig() );
		$url = (string)$this->params['inbox'];
		if ( !$key || parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) {
			return true;
		}
		$body = json_encode( $this->params['activity'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$target = ( parse_url( $url, PHP_URL_PATH ) ?: '/' )
			. ( parse_url( $url, PHP_URL_QUERY ) !== null ? '?' . parse_url( $url, PHP_URL_QUERY ) : '' );
		$headers = [
			'host' => (string)parse_url( $url, PHP_URL_HOST ),
			'date' => gmdate( 'D, d M Y H:i:s \G\M\T' ),
			'digest' => HttpSignature::digest( $body ),
			'content-type' => Actors::CONTENT_TYPE,
		];
		$headers['signature'] = $key->sign( 'POST', $target, $headers, (string)$this->params['keyId'] );

		$request = $services->getHttpRequestFactory()->create( $url,
			[ 'method' => 'POST', 'postData' => $body, 'timeout' => 10 ], __METHOD__ );
		foreach ( $headers as $name => $value ) {
			$request->setHeader( $name, $value );
		}
		$status = $request->execute();
		if ( !$status->isOK() ) {
			$this->setLastError( "POST $url: " . $request->getStatus() );
			return false;
		}
		return true;
	}
}
