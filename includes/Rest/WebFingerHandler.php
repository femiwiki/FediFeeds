<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use Femiwiki\WebFinger\Account;
use Femiwiki\WebFinger\Jrd;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Language\LanguageNameUtils;
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

	public function __construct(
		Config $config,
		private readonly FeedLookup $feedLookup,
		TitleFormatter $titleFormatter,
		PageProps $pageProps,
		RepoGroup $repoGroup,
		LanguageNameUtils $languageNameUtils,
	) {
		$this->actors = new Actors( $config, $titleFormatter, RequestContext::getMain(), $pageProps, $repoGroup,
			$languageNameUtils );
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
		$response = $this->getResponseFactory()->createJson( Jrd::document(
			( new Account( $handle, $this->actors->getWikiHost() ) )->toUri(),
			[ $actorId, $pageUrl ],
			[
				Jrd::link( Jrd::REL_SELF, $actorId, Actors::CONTENT_TYPE ),
				Jrd::link( Jrd::REL_PROFILE_PAGE, $pageUrl, 'text/html' ),
			]
		), Jrd::CONTENT_TYPE );
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
		$account = Account::fromUri( $resource );
		if ( !$account
			|| ( $account->host !== $this->actors->getWikiHost() && $account->host !== $this->actors->getActorHost() )
		) {
			return null;
		}
		return $account->user;
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
