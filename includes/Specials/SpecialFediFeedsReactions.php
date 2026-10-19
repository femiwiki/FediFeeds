<?php

namespace MediaWiki\Extension\FediFeeds\Specials;

use MediaWiki\Extension\FediFeeds\Reactions;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Page\PageStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Special:FediFeedsReactions/<page> lists the Likes and Announces of the page's notes
 */
class SpecialFediFeedsReactions extends SpecialPage {

	public function __construct(
		private readonly PageStore $pageStore,
		private readonly IConnectionProvider $dbProvider,
		private readonly Reactions $reactions,
	) {
		parent::__construct( 'FediFeedsReactions' );
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->setRobotPolicy( 'noindex,nofollow' );

		$target = $par ?? $this->getRequest()->getText( 'page' );
		$title = $target !== '' ? Title::newFromText( $target ) : null;
		$this->showForm( $title ? $title->getPrefixedText() : $target );
		if ( $target === '' ) {
			return;
		}
		$page = $title ? $this->pageStore->getPageByName( $title->getNamespace(), $title->getDBkey() ) : null;
		if ( !$title || !$page ) {
			$out->addHTML( $this->msg( 'fedifeeds-reactions-no-page' )->plaintextParams( $target )->parseAsBlock() );
			return;
		}
		$out->setPageTitleMsg(
			$this->msg( 'fedifeeds-reactions-title' )->plaintextParams( $title->getPrefixedText() )
		);
		$counts = $this->reactions->countForPage( $page->getId() );
		$out->addHTML( $this->msg( 'fedifeeds-reactions-totals' )
			->numParams( $counts[Reactions::ANNOUNCE], $counts[Reactions::LIKE] )->parseAsBlock() );

		$pager = new ReactionsPager( $this->getContext(), $this->getLinkRenderer(), $this->dbProvider,
			$this->pageStore, $page->getId() );
		if ( !$pager->getNumRows() ) {
			$out->addHTML( $this->msg( 'fedifeeds-reactions-none' )->parseAsBlock() );
			return;
		}
		$out->addHTML( $pager->getNavigationBar() . $pager->getBody() . $pager->getNavigationBar() );
	}

	private function showForm( string $target ): void {
		HTMLForm::factory( 'ooui', [
			'page' => [
				'type' => 'title',
				'name' => 'page',
				'label-message' => 'fedifeeds-reactions-page',
				'default' => $target,
				'exists' => true,
				'required' => true,
			],
		], $this->getContext() )
			->setMethod( 'get' )
			->setTitle( $this->getPageTitle() )
			->setWrapperLegendMsg( 'fedifeeds-reactions-legend' )
			->setSubmitTextMsg( 'fedifeeds-reactions-submit' )
			->prepareForm()
			->displayForm( false );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'pages';
	}
}
