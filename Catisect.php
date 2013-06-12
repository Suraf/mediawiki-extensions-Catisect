<?php

$wgExtensionCredits['other'][] = array(
	'name' => 'Category Intersection',
	'author' => 'foxlit',
	'descriptionmsg' => 'catisect-description',
	'version' => '1.0.0',
);


define('NS_INTERSECTION', 600);
define('NS_INTERSECTION_TALK', 601);
$wgExtraNamespaces[NS_INTERSECTION] = "Intersection";
$wgExtraNamespaces[NS_INTERSECTION_TALK] = "Intersection_talk";

$wgHooks['ArticleFromTitle'][] = 'IntersectionPage::onArticleFromTitle';
$wgHooks['LinkBegin'][] = 'IntersectionPage::onLinkBegin';
$wgHooks['SkinTemplateNavigation'][] = 'IntersectionPage::onSkinTemplateNavigation';
$wgHooks['userCan'][] = 'IntersectionPage::onUserCan';
$wgHooks['ParserBeforeInternalParse'][] = 'IntersectionPage::onParserBeforeInternalParse';

$dir = dirname(__FILE__) . '/';
$wgAutoloadClasses['IntersectionPage'] = $dir . 'IntersectionPage.php';
$wgExtensionMessagesFiles['Catisect'] = $dir . 'Catisect.i18n.php';
