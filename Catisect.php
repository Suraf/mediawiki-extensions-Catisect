<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'Catisect' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['Catisect'] = __DIR__ . '/i18n';
	/* wfWarn(
		'Deprecated PHP entry point used for Catisect extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return;
} else {
	die( 'This version of the Catisect extension requires MediaWiki 1.25+' );
}

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
$wgAutoloadClasses['IntersectionPage'] = $dir . 'classes/IntersectionPage.php';
$wgMessagesDirs['Catisect']					= "{$extDir}/i18n";
?>
