<?php
namespace MediaWiki\Extension\Catisect;

use MediaWiki\Title\Title;
use MediaWiki\User\User;
use SkinTemplate;

class CatisectHooks {
    /**
     * Setup Constants used in Catisect
	 *
	 * @access	public
     * @return	void
     */
    public static function onRegistration() {
		global $wgExtraNamespaces;

		define('NS_INTERSECTION', 600);
		define('NS_INTERSECTION_TALK', 601);

		$wgExtraNamespaces[NS_INTERSECTION] = 'Intersection';
		$wgExtraNamespaces[NS_INTERSECTION_TALK] = 'Intersection_talk';
	}

	public static function onParserBeforeInternalParse( &$parser, &$text, &$strip_state ) {
		if ($parser->getTitle()->getNamespace() == NS_INTERSECTION) {
			if (preg_match('/^[\r\n]*#INTERSECT\s+(.+)[\n]*/', $text, $matches)) {
				$text = substr($text, strlen($matches[0]));
				preg_match_all('/\[\[([^|\[\]]+)\]\]/', $matches[1], $catTitles);

				$categories = '';
				foreach ($catTitles[1] as $ttext) {
					$title = Title::newFromText($ttext, NS_CATEGORY);
					if (is_object($title) && $title->getNamespace() == NS_CATEGORY) {
						$categories .= ($categories == '' ? '' : '::').$title->getText();
					}
				}

				$parser->getOutput()->setProperty('intersect', $categories);
			}
		}
		return true;
	}

	public static function onArticleFromTitle(&$title, &$page) {
		if ($title->getNamespace() == NS_INTERSECTION) {
			$page = new IntersectionPage($title);
			return false;
		}
		return true;
	}

	public static function onLinkBegin($dummy, $target, &$html, &$customAttribs, &$query, &$options, &$ret) {
		if (is_object($target) && $target instanceof Title && IntersectionPage::isAutoIntersection($target) ) {
			if (is_array($options)) {
				if (in_array('broken', $options)) {
					foreach ($options as $k => $v) if ($v == 'broken') $options[$k] = 'known';
				} else {
					$options[] = 'known';
				}
			} else {
				$opt = $options == 'broken' ? 'known' : array('known', $options);
			}
		}
		return true;
	}

	public static function onUserCan(Title &$title, User &$user, $action, &$result) {
		if (IntersectionPage::isAutoIntersection($title) && ($action == 'edit' || $title->getNamespace() == NS_INTERSECTION_TALK)) {
			$result = false;
			return false;
		}
		return true;
	}

	public static function onSkinTemplateNavigation(SkinTemplate &$sk, &$content_navigation) {
		$title = $sk->getRelevantTitle();
		if (isset($content_navigation['namespaces']) && isset($content_navigation['namespaces']['intersection']) && strpos($title->getText(), '::') !== FALSE) {
			$content_navigation['namespaces']['intersection']['class'] = 'selected';
			$content_navigation['namespaces']['intersection']['href'] = $title->getLocalURL();
			unset($content_navigation['namespaces']['intersection_talk']);
		}
		return true;
	}
}
