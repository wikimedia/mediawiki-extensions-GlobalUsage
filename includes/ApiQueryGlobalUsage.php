<?php
/**
 * Created on November 8, 2009
 *
 * API for MediaWiki 1.8+
 *
 * Copyright (C) 2009 Bryan Tong Minh <bryan.tongminh@gmail.com>
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
 */

namespace MediaWiki\Extension\GlobalUsage;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Site\SiteLookup;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiQueryGlobalUsage extends ApiQueryBase {
	private SiteLookup $siteLookup;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		SiteLookup $siteLookup
	) {
		parent::__construct( $query, $moduleName, 'gu' );
		$this->siteLookup = $siteLookup;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$prop = array_flip( $params['prop'] );

		$allPageIds = $this->getPageSet()->getAllTitlesByNamespace();
		if ( !empty( $allPageIds[NS_FILE] ) ) {
			# Create a query and set parameters
			$pageIds = $allPageIds[NS_FILE];
			$query = new GlobalUsageQuery( array_map( 'strval', array_keys( $pageIds ) ) );
			if ( $params['continue'] !== null ) {
				$this->dieContinueUsageIf( !$query->setOffset( $params['continue'] ) );
			}
			$query->setLimit( $params['limit'] );
			$query->filterLocal( $params['filterlocal'] );
			$query->filterNamespaces( $params['namespace'] );
			if ( $params['site'] ) {
				$query->filterSites( $params['site'] );
			}

			# Execute the query
			$query->execute();

			# Create the result
			$apiResult = $this->getResult();

			foreach ( $query->getResult() as $image => $wikis ) {
				$pageId = intval( $pageIds[$image] );
				foreach ( $wikis as $wiki => $result ) {
					foreach ( $result as $item ) {
						if ( $item['namespace'] ) {
							$title = "{$item['namespace']}:{$item['title']}";
						} else {
							$title = $item['title'];
						}
						$result = [
							'title' => $title,
							'wiki' => WikiMap::getWikiName( $wiki )
						];
						if ( isset( $prop['url'] ) ) {
							// We expand the url because we don't want protocol relative urls
							// in API results
							$result['url'] = wfExpandUrl(
								WikiMap::getForeignUrl( $item['wiki'], $title ), PROTO_CURRENT );
						}
						if ( isset( $prop['pageid'] ) ) {
							$result['pageid'] = $item['id'];
						}
						if ( isset( $prop['namespace'] ) ) {
							$result['ns'] = $item['namespace_id'];
						}

						$fit = $apiResult->addValue(
							[ 'query', 'pages', $pageId, 'globalusage' ],
							null,
							$result
						);

						if ( !$fit ) {
							$continue = "{$item['image']}|{$item['wiki']}|{$item['id']}";
							$this->setIndexedTagName();
							$this->setContinueEnumParameter( 'continue', $continue );
							return;
						}
					}
				}
			}
			$this->setIndexedTagName();

			if ( $query->hasMore() ) {
				$this->setContinueEnumParameter( 'continue', $query->getContinueString() );
			}
		}
	}

	private function setIndexedTagName() {
		$result = $this->getResult();
		$pageIds = $this->getPageSet()->getAllTitlesByNamespace();
		foreach ( $pageIds[NS_FILE] as $id ) {
			$result->addIndexedTagName(
				[ 'query', 'pages', $id, 'globalusage' ],
				'gu'
			);
		}
	}

	public function getAllowedParams() {
		$sites = $this->siteLookup->getSites()->getGlobalIdentifiers();
		return [
			'prop' => [
				ParamValidator::PARAM_DEFAULT => 'url',
				ParamValidator::PARAM_TYPE => [
					'url',
					'pageid',
					'namespace',
				],
				ParamValidator::PARAM_ISMULTI => true,
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'limit' => [
				ParamValidator::PARAM_DEFAULT => 10,
				ParamValidator::PARAM_TYPE => 'limit',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'namespace' => [
				ParamValidator::PARAM_TYPE => 'namespace',
				ParamValidator::PARAM_DEFAULT => '*',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'site' => [
				ParamValidator::PARAM_TYPE => $sites,
				ParamValidator::PARAM_ISMULTI => true
			],
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
			'filterlocal' => false,
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&prop=globalusage&titles=File:Example.jpg'
				=> 'apihelp-query+globalusage-example-1',
		];
	}

	public function getCacheMode( $params ) {
		return 'public';
	}
}
