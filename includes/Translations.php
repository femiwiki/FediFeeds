<?php

namespace MediaWiki\Extension\FediFeeds;

use MediaWiki\Extension\Translate\MessageLoading\MessageCollection;
use MediaWiki\Extension\Translate\MessageLoading\MessageHandle;
use MediaWiki\Extension\Translate\PageTranslation\TranslatablePage;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\Title;

/**
 * What Extension:Translate says of a page: whether it is a translation page, into which language,
 * and whether its translation is complete. Without Translate no page is a translation page.
 */
class Translations {

	/**
	 * @param bool $enabled Whether Translate is loaded, so that its classes may be used
	 */
	public function __construct(
		private readonly bool $enabled,
	) {
	}

	/**
	 * Whether the page is a translation page of a page marked for translation, such as Foo/en,
	 * including the subpage in the source language that Translate may make, such as Foo/ko.
	 */
	public function isTranslationPage( PageIdentity $page ): bool {
		return $this->getTranslation( $page ) !== null;
	}

	/**
	 * The MediaWiki code of the language the translation page translates its page into, or null
	 * for any other page, the subpage in the source language included.
	 */
	public function getLanguage( PageIdentity $page ): ?string {
		$translation = $this->getTranslation( $page );
		return $translation !== null && !$translation['source'] ? $translation['language'] : null;
	}

	/**
	 * Whether every unit of the page the translation page translates, its title included when
	 * that is translatable, has a translation into its language that is not outdated. This is
	 * worked out from the units, so it holds as soon as the last of them is saved.
	 */
	public function isComplete( PageIdentity $page ): bool {
		$translation = $this->getTranslation( $page );
		if ( $translation === null || $translation['source'] || !$translation['page']->getMessageGroup() ) {
			return false;
		}
		$collection = $translation['page']->getTranslationPage( $translation['language'] )
			->getMessageCollection();
		$total = count( $collection );
		$collection->loadTranslations();
		$collection->filter( MessageCollection::FILTER_TRANSLATED, MessageCollection::INCLUDE_MATCHING );
		return $total > 0 && count( $collection ) === $total;
	}

	/**
	 * The name of the category that a translation page's category stands for: the translated
	 * category's own page when the category is a translation page into the language, such as
	 * Posts for Posts/en, as a translatable page names its categories with {{#translation:}}.
	 *
	 * @param string $name A category name without its namespace
	 * @param string $language
	 * @return string
	 */
	public function getSourceCategory( string $name, string $language ): string {
		if ( !$this->enabled || !str_ends_with( $name, "/$language" ) ) {
			return $name;
		}
		$title = Title::makeTitleSafe( NS_CATEGORY, $name );
		$translation = $title ? $this->getTranslation( $title ) : null;
		return $translation !== null && $translation['language'] === $language
			? $translation['page']->getTitle()->getDBkey()
			: $name;
	}

	/**
	 * @return array{page: TranslatablePage, language: string, source: bool}|null
	 */
	private function getTranslation( PageIdentity $page ): ?array {
		if ( !$this->enabled || !$page->canExist() ) {
			return null;
		}
		$title = Title::newFromPageIdentity( $page );
		$translatable = TranslatablePage::isTranslationPage( $title );
		if ( !$translatable instanceof TranslatablePage ) {
			return null;
		}
		$language = ( new MessageHandle( $title ) )->getCode();
		return [
			'page' => $translatable,
			'language' => $language,
			'source' => $language === $translatable->getSourceLanguageCode(),
		];
	}
}
