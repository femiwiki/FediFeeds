<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageProps;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Utils\UrlUtils;
use MessageLocalizer;

/**
 * Builds the URLs and the ActivityPub actor of a feed
 */
class Actors {

	public const CONTENT_TYPE = 'application/activity+json';

	/** Media types of the raster images an icon may be, by extension */
	private const ICON_TYPES = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
	];

	private string $actorBase;
	private string $wikiHost;
	private ?array $icon;

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
		$this->icon = self::findIcon( $config, $server );
	}

	/**
	 * The image every actor shows as its avatar: FediFeedsIcon, else the Apple touch icon,
	 * else a logo of the wiki. SVG is skipped, since Mastodon takes only raster avatars.
	 */
	private static function findIcon( Config $config, string $server ): ?array {
		$logos = $config->get( MainConfigNames::Logos );
		$candidates = [
			$config->get( 'FediFeedsIcon' ),
			$config->get( MainConfigNames::AppleTouchIcon ),
			$logos['1x'] ?? null,
			$logos['icon'] ?? null,
		];
		$urlUtils = new UrlUtils( [
			UrlUtils::SERVER => $server,
			UrlUtils::CANONICAL_SERVER => $server,
		] );
		foreach ( $candidates as $candidate ) {
			if ( !is_string( $candidate ) || $candidate === '' ) {
				continue;
			}
			$url = $urlUtils->expand( $candidate, PROTO_CANONICAL );
			if ( $url === null ) {
				continue;
			}
			$extension = strtolower( pathinfo( (string)parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( $extension === 'svg' || $extension === 'svgz' ) {
				continue;
			}
			$icon = [ 'type' => 'Image' ];
			if ( isset( self::ICON_TYPES[$extension] ) ) {
				$icon['mediaType'] = self::ICON_TYPES[$extension];
			}
			$icon['url'] = $url;
			return $icon;
		}
		return null;
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
		if ( $this->icon !== null ) {
			$actor['icon'] = $this->icon;
		}
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
