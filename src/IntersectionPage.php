<?php
namespace MediaWiki\Extension\Catisect;

use Article;
use HtmlArmor;
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

	public static function isAutoIntersection(Title $t) {
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
					$this->getContext()->getOutput()->addWikiMsg('intersection-invalid');
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
		foreach ($categories as $k => $v) {
			$t = Title::newFromText($v, NS_CATEGORY);
			if (is_object($t)) {
				$sub[] = $linker->makeLink($t, new HtmlArmor( htmlspecialchars($t->getText()) ));
				$categories[$k] = $t;
			} else {
				unset($categories[$k]);
			}
		}

		if (count($categories) <= 1) {
			return false;
		}

		$output->setSubTitle('<span id="intersection-subtitle">'.wfMessage('intersection-subtitle', implode(', ', $sub))->plain().'</span>');

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
		if ($from != null) {
			$conds[] = 'c0.cl_sortkey >= '.$dbr->addQuotes($this->collation->getSortKey($from));
			$opts['ORDER BY'] = 'c0.cl_sortkey ASC';
		} elseif ($until != null) {
			$conds[] = 'c0.cl_sortkey < '.$dbr->addQuotes($this->collation->getSortKey($until));
			$opts['ORDER BY'] = 'c0.cl_sortkey DESC';
			$flip = true;
		}

		$keys = array(); $pages = array(); $rows = array(); $i = 0; $moreKey = null;
		$qs = microtime(true);

		foreach($dbr->select($tables, array('c0.cl_sortkey', 'c0.cl_from', 'c0.cl_sortkey_prefix', 'c0.cl_collation'), $conds, __METHOD__, $opts) as $row) {
			$rows[$row->cl_from] = $row;
			$pages[$i++] = $row->cl_from;
			if ($i > $this->limit) {
				$moreKey = $row->cl_from;
			}
		}
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
			$navLinks = '('.$linker->makeKnownLink($title, ($flip ? 'previous ' : 'next ').$this->limit, array(), array(($flip ? 'until' : 'from') => $moreKey)).')';
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
			$output->addHTML('<h2>'.wfMessage('intersection-header').'</h2><p>'.wfMessage('intersection-empty')->escaped().'</p>');
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
		$output->addHTML('<h2>'.wfMessage('intersection-header').'</h2>'.($nav ? '<p>'.$nav.'</p>' : '').$out);
	}
}
