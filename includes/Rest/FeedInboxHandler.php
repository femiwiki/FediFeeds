<?php

namespace MediaWiki\Extension\FediFeeds\Rest;

use Wikimedia\ParamValidator\ParamValidator;

/**
 * A feed's own inbox, which takes what the shared inbox takes; the handle is read from the
 * activity, so the path only has to declare it
 */
class FeedInboxHandler extends InboxHandler {

	public function getParamSettings(): array {
		return [
			'handle' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
