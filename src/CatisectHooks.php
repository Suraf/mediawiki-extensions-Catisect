<?php
namespace MediaWiki\Extension\Catisect;

// begin wiki.gg: 1.43 compatibility
use MediaWiki\Linker\LinkTarget;
// end wiki.gg
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use SkinTemplate;

class CatisectHooks {
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

				// begin wiki.gg: setProperty() was removed in 1.41
				$parser->getOutput()->setUnsortedPageProperty('intersect', $categories);
				// end wiki.gg
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

	// begin wiki.gg: replaces the LinkBegin hook removed in 1.36; auto-intersection links render as existing
	public static function onTitleIsAlwaysKnown( LinkTarget $title, &$isKnown ) {
		if ( IntersectionPage::isAutoIntersection( $title ) ) {
			$isKnown = true;
		}
	}
	// end wiki.gg

	public static function onUserCan(Title &$title, User &$user, $action, &$result) {
		if (IntersectionPage::isAutoIntersection($title) && ($action == 'edit' || $title->getNamespace() == NS_INTERSECTION_TALK)) {
			$result = false;
			return false;
		}
		return true;
	}

	// begin wiki.gg: SkinTemplateNavigation was removed in 1.41, moved to ::Universal
	public static function onSkinTemplateNavigationUniversal( SkinTemplate $sk, &$content_navigation ) {
	// end wiki.gg
		$title = $sk->getRelevantTitle();
		if (isset($content_navigation['namespaces']) && isset($content_navigation['namespaces']['intersection']) && strpos($title->getText(), '::') !== FALSE) {
			$content_navigation['namespaces']['intersection']['class'] = 'selected';
			$content_navigation['namespaces']['intersection']['href'] = $title->getLocalURL();
			unset($content_navigation['namespaces']['intersection_talk']);
		}
		return true;
	}
}
