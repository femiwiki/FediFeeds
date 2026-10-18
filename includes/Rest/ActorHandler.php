<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Page\PageLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Serves the actor of a feed
 */
class ActorHandler extends SimpleHandler {

	private readonly Actors $actors;
	private readonly FeedLookup $feedLookup;

	public function __construct(
		private readonly Config $config,
		PageLookup $pageLookup,
		TitleFormatter $titleFormatter,
	) {
		$this->actors = new Actors( $config, $titleFormatter, RequestContext::getMain() );
		$this->feedLookup = new FeedLookup( $pageLookup );
	}

	public function run( string $handle ): Response {
		$feed = $this->feedLookup->getFeed( $handle );
		if ( !$feed ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-feed' ) )->plaintextParams( $handle ),
				404
			);
		}
		return $this->getResponseFactory()->createJson(
			$this->actors->getActor( $feed, $this->config->get( 'FediFeedsPublicKeyPem' ) ),
			Actors::CONTENT_TYPE
		);
	}

	public function getParamSettings(): array {
		return [
			'handle' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return false;
	}
}
