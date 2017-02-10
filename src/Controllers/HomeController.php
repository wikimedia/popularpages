<?php

namespace Wikimedia\PopularPages\Controllers;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class HomeController {

	/**
	 *
	 */
	public function getHome( Request $request, Application $app ) {
		return $app['twig']->render( 'base.twig', [
			'debug' => $app['debug'],
		] );
	}

}
