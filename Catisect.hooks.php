<?php

class CatisectHooks {
    /**
     * Setup Constants used in Catisect
	 *
	 * @access	public
     * @return	void
     */
    static public function onRegistration() {
		global $wgExtraNamespaces;

		define('NS_INTERSECTION', 600);
		define('NS_INTERSECTION_TALK', 601);

		$wgExtraNamespaces[NS_INTERSECTION] = 'Intersection';
		$wgExtraNamespaces[NS_INTERSECTION_TALK] = 'Intersection_talk';
	}
}
