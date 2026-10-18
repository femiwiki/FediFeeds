<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageProps;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MessageLocalizer;

/**
 * Builds the URLs and the ActivityPub actor of a feed
 */
class Actors {

	public const CONTENT_TYPE = 'application/activity+json';

	private string $actorBase;
	private string $wikiHost;

	public function __construct(
		Config $config,
		private readonly TitleFormatter $titleFormatter,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly PageProps $pageProps,
	) {
		$server = $config->get( MainConfigNames::CanonicalServer );
		$this->actorBase = $config->get( 'FediFeedsActorBase' )
			?? $server . $config->get( MainConfigNames::RestPath ) . '/fedifeeds/actors/';
		$this->wikiHost = (string)parse_url( $server, PHP_URL_HOST );
	}

	/**
	 * The host in handles, which is the wiki's own
	 */
	public function getWikiHost(): string {
		return $this->wikiHost;
	}

	/**
	 * The host of actor ids, which may differ from the wiki's
	 */
	public function getActorHost(): string {
		return (string)parse_url( $this->actorBase, PHP_URL_HOST );
	}

	public function getActorId( string $handle ): string {
		return $this->actorBase . $handle;
	}

	/**
	 * Gets the handle from an actor id of this wiki, without checking that the feed exists.
	 */
	public function getHandleFromActorId( string $url ): ?string {
		if ( !str_starts_with( $url, $this->actorBase ) ) {
			return null;
		}
		$handle = substr( $url, strlen( $this->actorBase ) );
		return FeedLookup::parseHandle( $handle ) ? $handle : null;
	}

	public function getPageUrl( Feed $feed ): string {
		return Title::newFromPageIdentity( $feed->page )->getFullURL( '', false, PROTO_CANONICAL );
	}

	/**
	 * The display name the page set for the feed's kind, else for every kind, else its title.
	 */
	public function getName( Feed $feed ): string {
		$forKind = Hooks::NAME_PROPERTY . '-' . $feed->kind;
		$props = $this->pageProps->getProperties( $feed->page, [ $forKind, Hooks::NAME_PROPERTY ] )
			[$feed->page->getId()] ?? [];
		return $props[$forKind] ?? $props[Hooks::NAME_PROPERTY]
			?? $this->titleFormatter->getPrefixedText( $feed->page );
	}

	public function getActor( Feed $feed, ?string $publicKeyPem ): array {
		$id = $this->getActorId( $feed->getHandle() );
		$title = $this->titleFormatter->getPrefixedText( $feed->page );
		$actor = [
			'@context' => [
				'https://www.w3.org/ns/activitystreams',
				'https://w3id.org/security/v1',
			],
			'id' => $id,
			'type' => 'Service',
			'preferredUsername' => $feed->getHandle(),
			'name' => $this->getName( $feed ),
			// fedifeeds-summary-category, fedifeeds-summary-section
			'summary' => $this->messageLocalizer->msg( 'fedifeeds-summary-' . $feed->kind )
				->inContentLanguage()
				->plaintextParams( $title )
				->escaped(),
			'url' => $this->getPageUrl( $feed ),
			'inbox' => "$id/inbox",
			'outbox' => "$id/outbox",
			'followers' => "$id/followers",
			'endpoints' => [
				'sharedInbox' => $this->actorBase . 'inbox',
			],
			'manuallyApprovesFollowers' => false,
			'discoverable' => true,
		];
		if ( $publicKeyPem !== null ) {
			$actor['publicKey'] = [
				'id' => "$id#main-key",
				'owner' => $id,
				'publicKeyPem' => $publicKeyPem,
			];
		}
		return $actor;
	}
}
