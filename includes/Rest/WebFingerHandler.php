<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
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
 * Answers WebFinger for the feeds and the wiki's instance actor, on the wiki host and on the
 * actor host alike
 */
class WebFingerHandler extends SimpleHandler {

	private readonly Actors $actors;
	private readonly FeedLookup $feedLookup;

	public function __construct(
		Config $config,
		PageLookup $pageLookup,
		TitleFormatter $titleFormatter,
		PageProps $pageProps,
		RepoGroup $repoGroup,
	) {
		$this->actors = new Actors( $config, $titleFormatter, RequestContext::getMain(), $pageProps, $repoGroup );
		$this->feedLookup = new FeedLookup( $pageLookup );
	}

	public function run(): Response {
		$resource = $this->getValidatedParams()['resource'];
		$handle = $this->getHandle( $resource );
		if ( $handle === Actors::INSTANCE_HANDLE ) {
			return $this->respond( $handle, $this->actors->getInstanceActor( null )['url'] );
		}
		$feed = $handle === null ? null : $this->feedLookup->getFeed( $handle );
		if ( !$feed ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-feed' ) )->plaintextParams( $resource ),
				404
			);
		}
		return $this->respond( $feed->getHandle(), $this->actors->getPageUrl( $feed ) );
	}

	private function respond( string $handle, string $pageUrl ): Response {
		$actorId = $this->actors->getActorId( $handle );
		$response = $this->getResponseFactory()->createJson( [
			'subject' => 'acct:' . $handle . '@' . $this->actors->getWikiHost(),
			'aliases' => [ $actorId, $pageUrl ],
			'links' => [
				[
					'rel' => 'self',
					'type' => Actors::CONTENT_TYPE,
					'href' => $actorId,
				],
				[
					'rel' => 'http://webfinger.net/rel/profile-page',
					'type' => 'text/html',
					'href' => $pageUrl,
				],
			],
		], 'application/jrd+json' );
		$response->setHeader( 'Access-Control-Allow-Origin', '*' );
		return $response;
	}

	/**
	 * Gets the handle from an acct: URI on either host, or from an actor id.
	 */
	private function getHandle( string $resource ): ?string {
		if ( $resource === $this->actors->getActorId( Actors::INSTANCE_HANDLE ) ) {
			return Actors::INSTANCE_HANDLE;
		}
		if ( !str_starts_with( $resource, 'acct:' ) ) {
			return $this->actors->getHandleFromActorId( $resource );
		}
		$at = strrpos( $resource, '@' );
		if ( $at === false ) {
			return null;
		}
		$host = strtolower( substr( $resource, $at + 1 ) );
		if ( $host !== $this->actors->getWikiHost() && $host !== $this->actors->getActorHost() ) {
			return null;
		}
		return substr( $resource, strlen( 'acct:' ), $at - strlen( 'acct:' ) );
	}

	public function getParamSettings(): array {
		return [
			'resource' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return false;
	}
}
