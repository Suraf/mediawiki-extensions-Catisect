<?php
namespace MediaWiki\Extension\Catisect;

use Article;
use HtmlArmor;
// begin wiki.gg: 1.43 compatibility
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkTarget;
// end wiki.gg
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;

class IntersectionPage extends Article {
	public $limit = 200;
	public $minColumnSize = 5;

	private $collation;

	function __construct(Title $title) {
		parent::__construct($title);

		$factory = MediaWikiServices::getInstance()->getCollationFactory();
		$this->collation = $factory->makeCollation( $factory->getDefaultCollationName() );
	}

	// begin wiki.gg: accept LinkTarget so the TitleIsAlwaysKnown hook can call this
	public static function isAutoIntersection( LinkTarget $t ) {
	// end wiki.gg
		$ns = $t->getNamespace();
		return ($ns == NS_INTERSECTION || $ns == NS_INTERSECTION_TALK) && strpos($t->getText(),'::') !== FALSE;
	}

	public function showMissingArticle() {
		if (self::isAutoIntersection($this->getTitle())) {
			$this->getContext()->getOutput()->addWikiTextAsInterface( wfMessage('intersection-notext') );
		} else {
			parent::showMissingArticle();
		}
	}

	function view() {
		$request = $this->getContext()->getRequest();
		$optionLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		$diff = $request->getVal( 'diff' );
		$diffOnly = $request->getBool( 'diffonly', $optionLookup->getBoolOption( $this->getContext()->getUser(), 'diffonly' ) );

		$title = $this->getTitle();

		parent::view();
		if ( isset( $diff ) && $diffOnly ) return;


		if (NS_INTERSECTION === $title->getNamespace()) {
			$categories = null;
			$isAuto = false;
			if (strpos($title->getText(), '::')) {
				$categories = explode('::', $title->getText());
				$isAuto = true;
				$this->getContext()->getOutput()->setRobotPolicy('noindex,nofollow');
			} else if (is_object($this->mParserOutput)) {
				$cats = $this->mParserOutput->getPageProperty('intersect');
				$categories = $cats ? explode('::', $cats) : null;
			}
			if ($categories !== null) {
				if (!$this->viewIntersection($title, $categories, $this->getContext()->getOutput(), $request)) {
					$this->getContext()->getOutput()->setStatusCode(404);
				} elseif ($isAuto) {
					$this->getContext()->getOutput()->setPageTitle(wfMessage('intersection-title'));
				}
			}
		}
	}

	function viewIntersection(Title $title, $categories, OutputPage $output, WebRequest $request) {
		$linker = MediaWikiServices::getInstance()->getLinkRenderer();
		$pageStore = MediaWikiServices::getInstance()->getPageStore();

		$sub = array();
		// begin wiki.gg: dedupe and cap categories; each one is a self-join and MySQL allows 61 tables
		$seen = array();
		foreach ($categories as $v) {
			$t = Title::newFromText($v, NS_CATEGORY);
			if (!$t || $t->getNamespace() !== NS_CATEGORY || isset($seen[$t->getDBkey()])) {
				continue;
			}
			$seen[$t->getDBkey()] = $t;
			$sub[] = $linker->makeLink($t, new HtmlArmor( htmlspecialchars($t->getText()) ));
		}
		$categories = array_values($seen);

		if (count($categories) <= 1) {
			$output->addWikiMsg('intersection-invalid');
			return false;
		}

		$max = MediaWikiServices::getInstance()->getMainConfig()->get('CatisectMaxCategories');
		if (count($categories) > $max) {
			$output->addWikiMsg('intersection-toomany', $max);
			return false;
		}
		// end wiki.gg

		// begin wiki.gg: interface message was injected as raw HTML
		$output->setSubtitle( Html::rawElement( 'span', [ 'id' => 'intersection-subtitle' ],
			wfMessage( 'intersection-subtitle' )->rawParams( $this->getContext()->getLanguage()->commaList( $sub ) )->parse()
		) );
		// end wiki.gg

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_REPLICA );

		$titleKeys = array();
		foreach ($categories as $c) $titleKeys[] = $c->getDBkey();
		$smallestCat = $dbr->selectRow(
			['category'],
			['cat_title'],
			['cat_title' => $titleKeys],
			__METHOD__,
			[
				'ORDER BY' => 'cat_pages ASC',
				'LIMIT' => 1
			]
		);
		if (is_object($smallestCat)) {
			foreach ($titleKeys as $k => $t) {
				if ($t == $smallestCat->cat_title) {
					$swap = $categories[0];
					$categories[0] = $categories[$k];
					$categories[$k] = $swap;
					break;
				}
			}
		}

		$tables = array('c0' => 'categorylinks');
		$conds = array('c0.cl_to' => $categories[0]->getDBkey());
		$opts = array('LIMIT' => $this->limit+1);
		for ($i = 1, $c = count($categories); $i < $c; $i++) {
			$tables['c'.$i] = 'categorylinks';
			$conds['c'.$i.'.cl_to'] = $categories[$i]->getDBkey();
			$conds[] = 'c'.$i.'.cl_from = c0.cl_from' ;
		}

		$from = $request->getVal('from');
		$until = $from == null ? $request->getVal('until') : null;
		$flip = false;
		// begin wiki.gg: always order, and query per cl_type so the (cl_to, cl_type, cl_sortkey) index serves the range and order without a filesort
		$opts['ORDER BY'] = 'c0.cl_sortkey ASC';
		if ($from != null) {
			$conds[] = 'c0.cl_sortkey >= '.$dbr->addQuotes($this->collation->getSortKey($from));
		} elseif ($until != null) {
			$conds[] = 'c0.cl_sortkey < '.$dbr->addQuotes($this->collation->getSortKey($until));
			$opts['ORDER BY'] = 'c0.cl_sortkey DESC';
			$flip = true;
		}

		$keys = array(); $pages = array(); $rows = array(); $i = 0; $moreKey = null;
		$qs = microtime(true);

		$fetched = array();
		foreach (array('page', 'subcat', 'file') as $type) {
			$typeConds = $conds;
			$typeConds['c0.cl_type'] = $type;
			foreach ($dbr->select($tables, array('c0.cl_sortkey', 'c0.cl_from', 'c0.cl_sortkey_prefix', 'c0.cl_collation'), $typeConds, __METHOD__, $opts) as $row) {
				$fetched[] = $row;
			}
		}
		usort($fetched, function ($a, $b) use ($flip) {
			return $flip ? strcmp($b->cl_sortkey, $a->cl_sortkey) : strcmp($a->cl_sortkey, $b->cl_sortkey);
		});
		foreach (array_slice($fetched, 0, $this->limit + 1) as $row) {
			$rows[$row->cl_from] = $row;
			$pages[$i++] = $row->cl_from;
			if ($i > $this->limit) {
				$moreKey = $row->cl_from;
			}
		}
		// end wiki.gg
		$qt = microtime(true)-$qs;
		$output->addHTML('<!-- Intersection time: '.$qt.' sec. -->');

		$navLinks = '';

		/** @var array<int, ExistingPageRecord> $pages2 */
		$pages2 = $pageStore
			->newSelectQueryBuilder()
			->wherePageIds( $pages )
			->caller( __METHOD__ )
			->fetchPageRecordArray();
		foreach ($pages2 as $k => $pageRecord) {
			$page = Title::castFromPageIdentity( $pageRecord );
			$pid = $page->getId();
			$key = ($rows[$pid]->cl_collation === '') ? $rows[$pid]->cl_collation : $page->getCategorySortkey( $rows[$pid]->cl_sortkey_prefix );
			if ($pid == $moreKey) {
				$moreKey = $key;
				unset($pages2[$k]);
			} else {
				$keys[$pid] = $key;
			}
		}

		if ($moreKey != null) {
			// begin wiki.gg: paging backwards must continue from the last row shown, not the extra row, or that row is skipped
			$boundary = $flip ? ($keys[$pages[$this->limit - 1]] ?? $moreKey) : $moreKey;
			// end wiki.gg
			$navLinks = '('.$linker->makeKnownLink($title, ($flip ? 'previous ' : 'next ').$this->limit, array(), array(($flip ? 'until' : 'from') => $boundary)).')';
		}
		if (!empty($pages) && ($from != null || $until != null)) {
			if ($flip) {
				$navLinks .= ' ('.$linker->makeKnownLink($title, 'next '.$this->limit, array(), array('from' => $until)).')';
			} else {
				$navLinks = '('.$linker->makeKnownLink($title, 'previous '.$this->limit, array(), array('until' => $keys[$pages[0]])) .') ' . $navLinks;
			}
		}

		$this->generatePageList($output, $pages2, $keys, $navLinks);
		return true;
	}

	/**
	 * @param OutputPage $output
	 * @param ExistingPageRecord[] $pages
	 * @param int[] $keys
	 * @param string[] $nav
	 * @return void
	 */
	function generatePageList(OutputPage $output, $pages, $keys, $nav) {
		$ofc = null;

		usort($pages, function($a, $b) use ($keys) { return strnatcmp($keys[$a->getId()], $keys[$b->getId()]); });

		$c = count($pages);
		if ($c == 0) {
			// begin wiki.gg: explicit parse() instead of relying on Message::__toString()
			$output->addHTML('<h2>'.wfMessage('intersection-header')->parse().'</h2><p>'.wfMessage('intersection-empty')->escaped().'</p>');
			// end wiki.gg
			return;
		}
		$cellMod = max($this->minColumnSize, ceil($c / 3));

		$linker = MediaWikiServices::getInstance()->getLinkRenderer();

		$out = '<table width="100%" id="intersection-page-table"><tr valign="top"><td>';
		foreach ($pages as $k => $page) {
			$firstChar = $this->collation->getFirstLetter($keys[$page->getId()]);
			if ($ofc == null || $firstChar != $ofc || ($k > 0 && $k % $cellMod == 0)) {
				$out .= ($ofc == null ? '' : '</ul>');
				if ($k > 0 && $k % $cellMod == 0) $out .= '</td><td>';
				$out .= '<h3>'.htmlspecialchars($firstChar).($firstChar == $ofc ? ' ' . wfMessage( 'listingcontinuesabbrev' )->escaped() : '').'</h3><ul>';
				$ofc = $firstChar;
			}
			$out .= '<li>'.$linker->makeKnownLink($page).'</li>';
		}
		if ($ofc != null) $out .= '</ul>';
		$out .= '</td></tr></table>';
		// begin wiki.gg: explicit parse() instead of relying on Message::__toString()
		$output->addHTML('<h2>'.wfMessage('intersection-header')->parse().'</h2>'.($nav ? '<p>'.$nav.'</p>' : '').$out);
		// end wiki.gg
	}
}
