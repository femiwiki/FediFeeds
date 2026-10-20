<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Signatures\LocalKeyStore;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\PageProps;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Serves the actor of a feed, or the wiki's instance actor
 */
class ActorHandler extends SimpleHandler {

	private readonly Actors $actors;
	private readonly FeedLookup $feedLookup;

	public function __construct(
		Config $config,
		PageLookup $pageLookup,
		TitleFormatter $titleFormatter,
		PageProps $pageProps,
		private readonly LocalKeyStore $localKeyStore,
		RepoGroup $repoGroup,
	) {
		$this->actors = new Actors( $config, $titleFormatter, RequestContext::getMain(), $pageProps, $repoGroup );
		$this->feedLookup = new FeedLookup( $pageLookup );
	}

	public function run( string $handle ): Response {
		if ( $handle === Actors::INSTANCE_HANDLE ) {
			return BrowserRedirect::respond( $this,
				fn () => $this->actors->getInstanceActor( null )['url'],
				fn () => $this->getResponseFactory()->createJson(
					$this->actors->getInstanceActor( $this->localKeyStore->getKey()->getPublicKeyPem() ),
					Actors::CONTENT_TYPE
				)
			);
		}
		$feed = $this->feedLookup->getFeed( $handle );
		if ( !$feed ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-feed' ) )->plaintextParams( $handle ),
				404
			);
		}
		return BrowserRedirect::respond( $this,
			fn () => $this->actors->getPageUrl( $feed ),
			fn () => $this->getResponseFactory()->createJson(
				$this->actors->getActor( $feed, $this->localKeyStore->getKey()->getPublicKeyPem() ),
				Actors::CONTENT_TYPE
			)
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
