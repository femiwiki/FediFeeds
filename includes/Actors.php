<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Config\Config;
use MediaWiki\FileRepo\RepoGroup;
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

	/**
	 * Handle of the wiki's instance actor, which signs fetches of remote documents. A feed's
	 * handle always ends in _<page id>, so no feed can take it.
	 */
	public const INSTANCE_HANDLE = 'wiki';

	private const CONTEXT = [
		'https://www.w3.org/ns/activitystreams',
		'https://w3id.org/security/v1',
	];

	/** Media types of the raster images an icon may be, by extension */
	private const ICON_TYPES = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
	];

	/** Width in pixels of the thumbnail of a file the page set as a feed's icon */
	public const ICON_WIDTH = 400;

	private string $actorBase;
	private string $wikiHost;
	private string $sitename;
	private string $server;
	private UrlUtils $urlUtils;
	private ?array $wikiIcon;

	public function __construct(
		Config $config,
		private readonly TitleFormatter $titleFormatter,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly PageProps $pageProps,
		private readonly RepoGroup $repoGroup,
	) {
		$server = $config->get( MainConfigNames::CanonicalServer );
		$this->actorBase = $config->get( 'FediFeedsActorBase' )
			?? $server . $config->get( MainConfigNames::RestPath ) . '/fedifeeds/actors/';
		$this->wikiHost = (string)parse_url( $server, PHP_URL_HOST );
		$this->sitename = (string)$config->get( MainConfigNames::Sitename );
		$this->server = $server;
		$this->urlUtils = new UrlUtils( [
			UrlUtils::SERVER => $server,
			UrlUtils::CANONICAL_SERVER => $server,
		] );
		$this->wikiIcon = $this->findWikiIcon( $config );
	}

	/**
	 * The icon of the feed: a thumbnail of the file its page set, else the wiki's.
	 */
	public function getIcon( Feed $feed ): ?array {
		$forKind = Hooks::ICON_PROPERTY . '-' . $feed->kind;
		$props = $this->pageProps->getProperties( $feed->page, [ $forKind, Hooks::ICON_PROPERTY ] )
			[$feed->page->getId()] ?? [];
		$name = $props[$forKind] ?? $props[Hooks::ICON_PROPERTY] ?? null;
		return ( is_string( $name ) ? $this->getFileIcon( $name ) : null ) ?? $this->wikiIcon;
	}

	/**
	 * A raster thumbnail of the file, which MediaWiki renders from SVG too, or null when the
	 * file is missing or cannot be thumbnailed.
	 */
	private function getFileIcon( string $name ): ?array {
		$file = $this->repoGroup->findFile( $name );
		if ( !$file || !$file->exists() || !$file->canRender() ) {
			return null;
		}
		$params = [ 'width' => self::ICON_WIDTH ];
		$thumb = $file->transform( $params );
		$url = $thumb && !$thumb->isError() ? $thumb->getUrl() : false;
		$url = $url === false ? null : $this->urlUtils->expand( $url, PROTO_CANONICAL );
		if ( $url === null ) {
			return null;
		}
		$handler = $file->getHandler();
		$mime = $handler ? $handler->getThumbType( $file->getExtension(), $file->getMimeType(), $params )[1] : null;
		return self::makeIcon( $url, is_string( $mime ) && in_array( $mime, self::ICON_TYPES, true )
			? $mime : self::getMediaType( $url ) );
	}

	/**
	 * The image an actor shows as its avatar unless its page sets one: FediFeedsIcon, else the
	 * Apple touch icon, else a logo of the wiki. SVG is skipped, since Mastodon takes only raster
	 * avatars.
	 */
	private function findWikiIcon( Config $config ): ?array {
		$logos = $config->get( MainConfigNames::Logos );
		$candidates = [
			$config->get( 'FediFeedsIcon' ),
			$config->get( MainConfigNames::AppleTouchIcon ),
			$logos['1x'] ?? null,
			$logos['icon'] ?? null,
		];
		foreach ( $candidates as $candidate ) {
			if ( !is_string( $candidate ) || $candidate === '' ) {
				continue;
			}
			$url = $this->urlUtils->expand( $candidate, PROTO_CANONICAL );
			$extension = $url === null ? '' : self::getExtension( $url );
			if ( $url === null || $extension === 'svg' || $extension === 'svgz' ) {
				continue;
			}
			return self::makeIcon( $url, self::getMediaType( $url ) );
		}
		return null;
	}

	private static function getExtension( string $url ): string {
		return strtolower( pathinfo( (string)parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
	}

	/**
	 * The media type of a raster image by its extension, or null when it is not one of them
	 */
	private static function getMediaType( string $url ): ?string {
		return self::ICON_TYPES[self::getExtension( $url )] ?? null;
	}

	private static function makeIcon( string $url, ?string $mediaType ): array {
		$icon = [ 'type' => 'Image' ];
		if ( $mediaType !== null ) {
			$icon['mediaType'] = $mediaType;
		}
		$icon['url'] = $url;
		return $icon;
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
	 * The key id that fetches of remote documents are signed with.
	 */
	public function getInstanceKeyId(): string {
		return $this->getActorId( self::INSTANCE_HANDLE ) . '#main-key';
	}

	/**
	 * Gets the handle of a feed from an actor id of this wiki, without checking that the feed
	 * exists. The instance actor is not a feed, so its id gives null.
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
			'@context' => self::CONTEXT,
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
		$icon = $this->getIcon( $feed );
		if ( $icon !== null ) {
			$actor['icon'] = $icon;
		}
		return $this->addPublicKey( $actor, $publicKeyPem );
	}

	/**
	 * The wiki's own actor, whose key signs fetches of remote documents, as Mastodon's
	 * instance actor does. It posts nothing, and what is sent to it is dropped.
	 */
	public function getInstanceActor( ?string $publicKeyPem ): array {
		$id = $this->getActorId( self::INSTANCE_HANDLE );
		$actor = [
			'@context' => self::CONTEXT,
			'id' => $id,
			'type' => 'Application',
			'preferredUsername' => self::INSTANCE_HANDLE,
			'name' => $this->sitename,
			'url' => $this->server . '/',
			'inbox' => $this->actorBase . 'inbox',
			'endpoints' => [
				'sharedInbox' => $this->actorBase . 'inbox',
			],
			'manuallyApprovesFollowers' => true,
		];
		if ( $this->wikiIcon !== null ) {
			$actor['icon'] = $this->wikiIcon;
		}
		return $this->addPublicKey( $actor, $publicKeyPem );
	}

	private function addPublicKey( array $actor, ?string $publicKeyPem ): array {
		if ( $publicKeyPem !== null ) {
			$actor['publicKey'] = [
				'id' => $actor['id'] . '#main-key',
				'owner' => $actor['id'],
				'publicKeyPem' => $publicKeyPem,
			];
		}
		return $actor;
	}
}
