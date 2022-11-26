<?php
/**
 * Special page to show global file usage. Also contains hook functions for
 * showing usage on an image page.
 */

namespace MediaWiki\Extension\GlobalUsage;

use Html;
use Linker;
use MediaWiki\MediaWikiServices;
use OOUI\ButtonInputWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\FormLayout;
use OOUI\HtmlSnippet;
use OOUI\PanelLayout;
use OOUI\TextInputWidget;
use SpecialPage;
use Title;
use WikiMap;

class SpecialGlobalUsage extends SpecialPage {
	/**
	 * @var Title
	 */
	protected $target;

	/**
	 * @var bool
	 */
	protected $filterLocal;

	public function __construct() {
		parent::__construct( 'GlobalUsage' );
	}

	/**
	 * Entry point
	 * @param string $par
	 */
	public function execute( $par ) {
		$target = $par ?: $this->getRequest()->getVal( 'target' );
		$this->target = Title::makeTitleSafe( NS_FILE, $target );

		$this->filterLocal = $this->getRequest()->getCheck( 'filterlocal' );

		$this->setHeaders();
		$this->getOutput()->addWikiMsg( 'globalusage-header' );
		if ( $this->target !== null ) {
			$this->getOutput()->addWikiMsg( 'globalusage-header-image', $this->target->getText() );
		}
		$this->showForm();

		if ( $this->target === null ) {
			$this->getOutput()->setPageTitle( $this->msg( 'globalusage' ) );
			return;
		}

		$this->getOutput()->setPageTitle(
			$this->msg( 'globalusage-for', $this->target->getPrefixedText() ) );

		$this->showResult();
	}

	/**
	 * Shows the search form
	 */
	private function showForm() {
		global $wgScript;

		$this->getOutput()->enableOOUI();
		/* Build form */
		$form = new FormLayout( [
			'method' => 'get',
			'action' => $wgScript,
		] );

		$fields = [];
		$fields[] = new FieldLayout(
			new TextInputWidget( [
				'name' => 'target',
				'id' => 'target',
				'autosize' => true,
				'infusable' => true,
				'value' => $this->target === null ? '' : $this->target->getText(),
			] ),
			[
				'label' => $this->msg( 'globalusage-filename' )->text(),
				'align' => 'top',
			]
		);

		// Filter local checkbox
		$fields[] = new FieldLayout(
			new CheckboxInputWidget( [
				'name' => 'filterlocal',
				'id' => 'mw-filterlocal',
				'value' => '1',
				'selected' => $this->filterLocal,
			] ),
			[
				'align' => 'inline',
				'label' => $this->msg( 'globalusage-filterlocal' )->text(),
			]
		);

		// Submit button
		$fields[] = new FieldLayout(
			new ButtonInputWidget( [
				'value' => $this->msg( 'globalusage-ok' )->text(),
				'label' => $this->msg( 'globalusage-ok' )->text(),
				'flags' => [ 'primary', 'progressive' ],
				'type' => 'submit',
			] ),
			[
				'align' => 'top',
			]
		);

		$fieldset = new FieldsetLayout( [
			'label' => $this->msg( 'globalusage-text' )->text(),
			'id' => 'globalusage-text',
			'items' => $fields,
		] );

		$form->appendContent(
			$fieldset,
			new HtmlSnippet(
				Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() ) .
				Html::hidden( 'limit', $this->getRequest()->getInt( 'limit', 50 ) )
			)
		);

		$this->getOutput()->addHTML(
			new PanelLayout( [
				'expanded' => false,
				'padded' => true,
				'framed' => true,
				'content' => $form,
			] )
		);

		if ( $this->target !== null ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $this->target );
			if ( $file ) {
				// Show the image if it exists
				$html = Linker::makeThumbLinkObj(
					$this->target,
					$file,
					/* $label */ $this->target->getPrefixedText(),
					/* $alt */ '', /* $align */ $this->getLanguage()->alignEnd()
				);
				$this->getOutput()->addHtml( $html );
			}
		}
	}

	/**
	 * Creates as queryer and executes it based on $this->getRequest()
	 */
	private function showResult() {
		$query = new GlobalUsageQuery( $this->target );
		$request = $this->getRequest();

		// Extract params from $request.
		if ( $request->getText( 'from' ) ) {
			$query->setOffset( $request->getText( 'from' ) );
		} elseif ( $request->getText( 'to' ) ) {
			$query->setOffset( $request->getText( 'to' ), true );
		}
		$query->setLimit( $request->getInt( 'limit', 50 ) );
		$query->filterLocal( $this->filterLocal );

		// Perform query
		$query->execute();

		// Don't show form element if there is no data
		if ( $query->count() == 0 ) {
			$this->getOutput()->addWikiMsg( 'globalusage-no-results', $this->target->getPrefixedText() );
			return;
		}

		$navbar = $this->getNavBar( $query );
		$targetName = $this->target->getText();
		$out = $this->getOutput();

		// Top navbar
		$out->addHtml( $navbar );

		$out->addHtml( '<div id="mw-globalusage-result">' );
		foreach ( $query->getSingleImageResult() as $wiki => $result ) {
			$out->addHtml(
				'<h2>' . $this->msg(
					'globalusage-on-wiki',
					$targetName, WikiMap::getWikiName( $wiki ) )->parse()
					. "</h2><ul>\n" );
			foreach ( $result as $item ) {
				$out->addHtml( "\t<li>" . self::formatItem( $item ) . "</li>\n" );
			}
			$out->addHtml( "</ul>\n" );
		}
		$out->addHtml( '</div>' );

		// Bottom navbar
		$out->addHtml( $navbar );
	}

	/**
	 * Helper to format a specific item
	 * @param array $item
	 * @return string
	 */
	public static function formatItem( $item ) {
		if ( !$item['namespace'] ) {
			$page = $item['title'];
		} else {
			$page = "{$item['namespace']}:{$item['title']}";
		}

		$link = WikiMap::makeForeignLink(
			$item['wiki'], $page,
			str_replace( '_', ' ', $page )
		);
		// Return only the title if no link can be constructed
		return $link === false ? htmlspecialchars( $page ) : $link;
	}

	/**
	 * Helper function to create the navbar, stolen from wfViewPrevNext
	 *
	 * @param GlobalUsageQuery $query An executed GlobalUsageQuery object
	 * @return string Navbar HTML
	 */
	protected function getNavBar( $query ) {
		$target = $this->target->getText();
		$limit = $query->getLimit();

		# Find out which strings are for the prev and which for the next links
		$offset = $query->getOffsetString();
		$continue = $query->getContinueString();
		if ( $query->isReversed() ) {
			$from = $offset;
			$to = $continue;
		} else {
			$from = $continue;
			$to = $offset;
		}

		# Get prev/next link display text
		$prevMsg = $this->msg( 'prevn' )->numParams( $limit );
		$nextMsg = $this->msg( 'nextn' )->numParams( $limit );
		# Get prev/next link title text
		$pTitle = $this->msg( 'prevn-title' )->numParams( $limit )->escaped();
		$nTitle = $this->msg( 'nextn-title' )->numParams( $limit )->escaped();

		# Fetch the title object
		$title = $this->getPageTitle();
		$linkRenderer = $this->getLinkRenderer();

		# Make 'previous' link
		if ( $to ) {
			$attr = [ 'title' => $pTitle, 'class' => 'mw-prevlink' ];
			$q = [ 'limit' => $limit, 'to' => $to, 'target' => $target ];
			if ( $this->filterLocal ) {
				$q['filterlocal'] = '1';
			}
			$plink = $linkRenderer->makeLink( $title, $prevMsg->text(), $attr, $q );
		} else {
			$plink = $prevMsg->escaped();
		}

		# Make 'next' link
		if ( $from ) {
			$attr = [ 'title' => $nTitle, 'class' => 'mw-nextlink' ];
			$q = [ 'limit' => $limit, 'from' => $from, 'target' => $target ];
			if ( $this->filterLocal ) {
				$q['filterlocal'] = '1';
			}
			$nlink = $linkRenderer->makeLink( $title, $nextMsg->text(), $attr, $q );
		} else {
			$nlink = $nextMsg->escaped();
		}

		# Make links to set number of items per page
		$numLinks = [];
		$lang = $this->getLanguage();
		foreach ( [ 20, 50, 100, 250, 500 ] as $num ) {
			$fmtLimit = $lang->formatNum( $num );

			$q = [ 'from' => $to, 'limit' => $num, 'target' => $target ];
			if ( $this->filterLocal ) {
				$q['filterlocal'] = '1';
			}
			$lTitle = $this->msg( 'shown-title' )->numParams( $num )->escaped();
			$attr = [ 'title' => $lTitle, 'class' => 'mw-numlink' ];

			$numLinks[] = $linkRenderer->makeLink( $title, $fmtLimit, $attr, $q );
		}
		$nums = $lang->pipeList( $numLinks );

		return $this->msg( 'viewprevnext' )->rawParams( $plink, $nlink, $nums )->escaped();
	}

	/**
	 * Return an array of subpages beginning with $search that this special page will accept.
	 *
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return (usually 10)
	 * @param int $offset Number of results to skip (usually 0)
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		if ( !GlobalUsage::onSharedRepo() ) {
			// Local files on non-shared wikis are not useful as suggestion
			return [];
		}
		$title = Title::newFromText( $search, NS_FILE );
		if ( !$title || $title->getNamespace() !== NS_FILE ) {
			// No prefix suggestion outside of file namespace
			return [];
		}
		$searchEngine = MediaWikiServices::getInstance()->getSearchEngineFactory()->create();
		$searchEngine->setLimitOffset( $limit, $offset );
		// Autocomplete subpage the same as a normal search, but just for (local) files
		$searchEngine->setNamespaces( [ NS_FILE ] );
		$result = $searchEngine->defaultPrefixSearch( $search );

		return array_map( static function ( Title $t ) {
			// Remove namespace in search suggestion
			return $t->getText();
		}, $result );
	}

	protected function getGroupName() {
		return 'media';
	}
}
