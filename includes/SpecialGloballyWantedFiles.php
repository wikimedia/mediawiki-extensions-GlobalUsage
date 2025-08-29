<?php
/**
 * Implements Special:GloballyWantedFiles, the global equivalent to
 * Special:WantedFiles
 *
 * @file
 * @author Brian Wolff <bawolff+wn@gmail.com>
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Html\Html;
use MediaWiki\Skin\Skin;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\WantedQueryPage;
use MediaWiki\Title\Title;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialGloballyWantedFiles extends WantedQueryPage {

	public function __construct(
		IConnectionProvider $dbProvider,
		LinkBatchFactory $linkBatchFactory,
		private readonly RepoGroup $repoGroup,
	) {
		parent::__construct( 'GloballyWantedFiles' );
		$this->setDatabaseProvider( $dbProvider );
		$this->setLinkBatchFactory( $linkBatchFactory );
	}

	/**
	 * Main execution function. Use the parent if we're on the right wiki.
	 * If we're not on a shared repo, try to redirect there.
	 * @param string $par
	 */
	public function execute( $par ) {
		if ( GlobalUsage::onSharedRepo() ) {
			parent::execute( $par );
		} else {
			GlobalUsage::redirectSpecialPageToSharedRepo( $this->getContext() );
		}
	}

	/** @inheritDoc */
	protected function forceExistenceCheck() {
		// Same as MediaWiki core WantedFiles
		return true;
	}

	/** @inheritDoc */
	protected function existenceCheck( Title $title ) {
		// Same as MediaWiki core WantedFiles
		return (bool)$this->repoGroup->findFile( $title );
	}

	/**
	 * Output an extra header
	 *
	 * @return string html to output
	 */
	public function getPageHeader() {
		if ( $this->repoGroup->hasForeignRepos() ) {
			return $this->msg( 'globallywantedfiles-foreign-repo' )->parseAsBlock();
		} else {
			return parent::getPageHeader();
		}
	}

	/**
	 * Don't want to do cached handling on non-shared repo, since we only redirect.
	 *
	 * @return bool
	 */
	public function isCacheable() {
		return GlobalUsage::onSharedRepo();
	}

	/**
	 * Only list this special page on the wiki that is the shared repo.
	 *
	 * @return bool Should this be listed in Special:SpecialPages
	 */
	public function isListed() {
		return GlobalUsage::onSharedRepo();
	}

	/** @inheritDoc */
	public function getQueryInfo() {
		return GlobalUsage::getWantedFilesQueryInfo();
	}

	/**
	 * Format a row of the results
	 *
	 * We need to override this in order to link to Special:GlobalUsage
	 * instead of Special:WhatLinksHere.
	 *
	 * @param Skin $skin
	 * @param stdClass $result A row from the database
	 * @return string HTML to output
	 */
	public function formatResult( $skin, $result ) {
		// If some of the client wikis are $wgCapitalLinks = false
		// but the shared repo is not, then we will get some false positives
		// here. To avoid as much confusion as possible, use the raw (lowercase) version
		// of the title for displaying, but the safe (properly cased) version of
		// the title for any checks. (Bug 71359)
		$title = Title::makeTitle( $result->namespace, $result->title );
		$safeTitle = Title::makeTitleSafe( $result->namespace, $result->title );
		if ( $title instanceof Title && $safeTitle instanceof Title ) {
			$linkRenderer = $this->getLinkRenderer();
			$pageLink = $linkRenderer->makeLink( $title );
			if ( $safeTitle->isKnown() &&
				$this->repoGroup->findFile( $safeTitle )
			) {
				// If the title exists and is a file, than strike.
				// The RepoGroup::findFile call should already be cached from LinkRenderer::makeLink call
				// so it shouldn't be too expensive. However a future @todo would be
				// to do preload existence checks for files all at once via RepoGroup::findFiles.
				$pageLink = Html::rawElement( 'del', [], $pageLink );
			}

			$gu = SpecialPage::getTitleFor( 'GlobalUsage', $title->getDBKey() );
			$label = $this->msg( 'nlinks' )->numParams( $result->value )->text();
			$usages = $linkRenderer->makeLink( $gu, $label );

			return $this->getLanguage()->specialList( $pageLink, $usages );
		} else {
			return $this->msg( 'wantedpages-badtitle', $result->title )->escaped();
		}
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'maintenance';
	}
}
