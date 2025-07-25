<?php
/**
 * Implements Special:GloballyUnusedFiles, the global equivalent to
 * Special:UnusedFiles
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\SpecialPage\ImageQueryPage;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * A special page that lists globally unused files
 *
 * @ingroup SpecialPage
 */
class SpecialGloballyUnusedFiles extends ImageQueryPage {
	public function __construct(
		IConnectionProvider $dbProvider
	) {
		parent::__construct( 'GloballyUnusedFiles' );
		$this->setDatabaseProvider( $dbProvider );
	}

	/**
	 * Check if we are on wiki with globalimagelinks table in database.
	 * @return bool
	 */
	private function isOnGlobalUsageDatabase() {
		return GlobalUsage::onSharedRepo();
	}

	/**
	 * Main execution function. Use the parent if we're on the right wiki.
	 * @param string $par
	 * @throws ErrorPageError if we are not on a wiki with GlobalUsage database
	 */
	public function execute( $par ) {
		if ( $this->isOnGlobalUsageDatabase() ) {
			parent::execute( $par );
		} else {
			throw new ErrorPageError( 'globallyunusedfiles', 'globallyunusedfiles-error-nonsharedrepo' );
		}
	}

	/**
	 * Allow to cache only if globalimagelinks table exists in database.
	 * @return bool
	 */
	public function isCacheable() {
		return $this->isOnGlobalUsageDatabase();
	}

	/**
	 * Only list this special page on the wiki that has globalimagelinks table.
	 * @return bool Should this be listed in Special:SpecialPages
	 */
	public function isListed() {
		return $this->isOnGlobalUsageDatabase();
	}

	/** @inheritDoc */
	public function isExpensive() {
		return true;
	}

	/** @inheritDoc */
	public function sortDescending() {
		return false;
	}

	/** @inheritDoc */
	public function isSyndicated() {
		return false;
	}

	/** @inheritDoc */
	public function getQueryInfo() {
		if ( !$this->isOnGlobalUsageDatabase() ) {
			throw new RuntimeException( "This wiki is not on shared repo" );
		}

		$retval = [
			'tables' => [ 'image', 'globalimagelinks' ],
			'fields' => [
				'namespace' => NS_FILE,
				'title' => 'img_name',
				'value' => 'img_timestamp',
			],
			'conds' => [ 'gil_to' => null ],
			'join_conds' => [ 'globalimagelinks' => [ 'LEFT JOIN', 'gil_to = img_name' ] ]
		];

		if ( $this->getConfig()->get( 'CountCategorizedImagesAsUsed' ) ) {
			// Order is significant
			$retval['tables'] = [ 'image', 'page', 'categorylinks',
				'globalimagelinks' ];
			$retval['conds']['page_namespace'] = NS_FILE;
			$retval['conds']['cl_from'] = null;
			$retval['conds'][] = 'img_name = page_title';
			$retval['join_conds']['categorylinks'] = [
				'LEFT JOIN', 'cl_from = page_id' ];
			$retval['join_conds']['globalimagelinks'] = [
				'LEFT JOIN', 'gil_to = page_title' ];
		}

		return $retval;
	}

	/** @inheritDoc */
	public function usesTimestamps() {
		return true;
	}

	/** @inheritDoc */
	public function getPageHeader() {
		return $this->msg( 'globallyunusedfilestext' )->parseAsBlock();
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'maintenance';
	}
}
