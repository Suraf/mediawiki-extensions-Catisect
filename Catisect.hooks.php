<?php

class CatisectHooks {

    /**
     * Setup Constants used in Catisect
     * @return nothing
     */
    public static function onRegistration() {
		define('NS_INTERSECTION', 600);
		define('NS_INTERSECTION_TALK', 601);
	}
}
