<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use MediaWiki\Extension\FediFeeds\Actors;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\Notes;
use MediaWiki\Extension\FediFeeds\Quotes;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Serves a QuoteAuthorization while the quote stands and its note is there
 */
class QuoteAuthorizationHandler extends SimpleHandler {

	public function __construct(
		private readonly Notes $notes,
		private readonly FeedLookup $feedLookup,
		private readonly Quotes $quotes,
	) {
	}

	public function run( string $handle, int $id ): Response {
		$feed = $this->feedLookup->getFeed( $handle );
		$quote = $feed ? $this->quotes->get( $feed, $id ) : null;
		$item = $feed && $quote ? $this->notes->getItem( $feed, (string)$quote->fq_item ) : null;
		if ( !$feed || !$quote || !$item ) {
			throw new LocalizedHttpException(
				( new MessageValue( 'fedifeeds-rest-no-quote-authorization' ) )
					->plaintextParams( "$handle/quote_authorizations/$id" ),
				404
			);
		}
		return $this->getResponseFactory()->createJson(
			$this->notes->getQuoteAuthorization( $feed, $item, $id, (string)$quote->fq_post ),
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
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsWriteAccess(): bool {
		return false;
	}
}
