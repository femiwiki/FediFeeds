<?php

namespace MediaWiki\Extension\FediFeeds\Specials;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\FediFeeds\FeedLookup;
use MediaWiki\Extension\FediFeeds\InfoHooks;
use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\Extension\FediFeeds\RemoteActors;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\PageStore;
use MediaWiki\Pager\IndexPager;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The Likes and Announces of a page's notes, grouped by note, the newest note and the newest
 * reaction first. An account is named once it is cached.
 */
class ReactionsPager extends IndexPager {

	public const LIMIT = 50;

	/** @inheritDoc */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	/** The note of the rows output so far, by fi_id */
	private ?int $group = null;

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		IConnectionProvider $dbProvider,
		private readonly PageStore $pageStore,
		private readonly int $pageId,
	) {
		$this->mDb = $dbProvider->getReplicaDatabase();
		$limit = $context->getRequest()->getInt( 'limit', self::LIMIT );
		$this->mLimit = $limit > 0 ? min( $limit, 500 ) : self::LIMIT;
		parent::__construct( $context, $linkRenderer );
		$this->mDefaultLimit = self::LIMIT;
	}

	/** @inheritDoc */
	public function getQueryInfo() {
		return [
			'tables' => [ 'fedifeeds_reaction', 'fedifeeds_item', 'fedifeeds_actor' ],
			'fields' => [
				'fr_id', 'fr_type', 'fr_actor', 'fr_timestamp', 'fr_feed', 'fr_key', 'fi_id',
				'fa_actor', 'fa_handle', 'fa_name', 'fa_url',
			],
			'conds' => [ 'fr_page' => $this->pageId ],
			'options' => [],
			'join_conds' => [
				'fedifeeds_item' => [ 'JOIN', [ 'fi_feed = fr_feed', 'fi_page = fr_page', 'fi_key = fr_key' ] ],
				'fedifeeds_actor' => [ 'LEFT JOIN', 'fa_actor = fr_actor' ],
			],
		];
	}

	/** @inheritDoc */
	public function getIndexField() {
		// The note, then the time; every one a number, as the offset in a link joins them with |
		return [ [ 'fi_id', 'fr_timestamp', 'fr_id' ] ];
	}

	/** @inheritDoc */
	public function getNavigationBar() {
		if ( !$this->isNavigationBarShown() ) {
			return '';
		}
		return $this->getNavigationBuilder()
			->setPrevMsg( 'pager-newer-n' )
			->setNextMsg( 'pager-older-n' )
			->setFirstMsg( 'histlast' )
			->setLastMsg( 'histfirst' )
			->getHtml();
	}

	/** @inheritDoc */
	protected function getStartBody() {
		$this->group = null;
		return '';
	}

	/** @inheritDoc */
	protected function getEndBody() {
		return $this->group === null ? '' : Html::closeElement( 'table' );
	}

	/** @inheritDoc */
	public function formatRow( $row ) {
		'@phan-var stdClass $row';
		$html = '';
		if ( (int)$row->fi_id !== $this->group ) {
			$html .= ( $this->group === null ? '' : Html::closeElement( 'table' ) )
				. Html::element( 'h3', [], $this->getNoteLabel( (string)$row->fr_feed, (string)$row->fr_key ) )
				. Html::openElement( 'table', [ 'class' => 'wikitable mw-fedifeeds-reactions' ] )
				. Html::rawElement( 'tr', [],
					Html::element( 'th', [], $this->msg( 'fedifeeds-reactions-time' )->text() )
					. Html::element( 'th', [], $this->msg( 'fedifeeds-reactions-type' )->text() )
					. Html::element( 'th', [], $this->msg( 'fedifeeds-reactions-account' )->text() )
				);
			$this->group = (int)$row->fi_id;
		}
		$lang = $this->getLanguage();
		// fedifeeds-reactions-announce, fedifeeds-reactions-like
		$type = $this->msg( 'fedifeeds-reactions-' . ( $row->fr_type === Reactions::ANNOUNCE ? 'announce' : 'like' ) )
			->escaped();
		$account = $row->fa_actor !== null
			? InfoHooks::makeActorLink( RemoteActors::toArray( $row ) )
			: Html::element( 'span', [ 'class' => 'mw-fedifeeds-reactions-uncached' ],
				$this->msg( 'fedifeeds-reactions-uncached' )->text() );
		$time = htmlspecialchars( $lang->userTimeAndDate( $row->fr_timestamp, $this->getUser() ) );
		return $html . Html::rawElement( 'tr', [],
			Html::rawElement( 'td', [], $time ) . Html::rawElement( 'td', [], $type )
			. Html::rawElement( 'td', [], $account )
		);
	}

	/**
	 * The heading of a note's group: a section's heading path, or the category whose feed has
	 * the page.
	 */
	private function getNoteLabel( string $handle, string $key ): string {
		[ $kind, $feedPageId ] = FeedLookup::parseHandle( $handle ) ?? [ '', 0 ];
		if ( $kind === 'section' ) {
			if ( str_starts_with( $key, 'sha256:' ) ) {
				return $this->msg( 'fedifeeds-reactions-section-unknown' )->text();
			}
			$lines = explode( "\n", $key );
			$ordinal = 1;
			if ( count( $lines ) > 1 && str_starts_with( (string)end( $lines ), ' ' ) ) {
				$ordinal = (int)array_pop( $lines );
			}
			$path = implode( $this->msg( 'fedifeeds-reactions-path-separator' )->text(), $lines );
			return $ordinal > 1
				? $this->msg( 'fedifeeds-reactions-section-nth' )->plaintextParams( $path )->numParams( $ordinal )
					->text()
				: $path;
		}
		$category = $this->pageStore->getPageById( $feedPageId );
		return $this->msg( 'fedifeeds-reactions-category' )
			->plaintextParams( $category ? str_replace( '_', ' ', $category->getDBkey() ) : $handle )
			->text();
	}
}
