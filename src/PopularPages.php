<?php

namespace Wikimedia\PopularPages;

use Silex\Application;
use Silex\Provider\TwigServiceProvider;
use Wikimedia\PopularPages\Controllers\HomeController;
use Wikimedia\SimpleI18n\I18nContext;
use Wikimedia\SimpleI18n\JsonCache;
use Wikimedia\SimpleI18n\TwigExtension;

class PopularPages {

	/** @var Application */
	protected $app;

	/**
	 * Main application constructor.
	 * @param string $baseDir The top-level directory of the application.
	 */
	public function __construct( $baseDir ) {
		$this->app = new Application();

		// Config. @TODO Do a proper thing here.
		require CONFIG_FILE;

		// Debug?
		$this->app['debug'] = isset( $debug ) ? $debug : false; 

		// Internationalisation.
		$i18nContext = new I18nContext( new JsonCache( $baseDir . '/i18n' ) );

		// Templates.
		$this->app->register( new TwigServiceProvider(), [
			'twig.path' => $baseDir . '/tpl',
		] );
		$this->app['twig']->addExtension( new TwigExtension( $i18nContext ) );

		// Routes.
		$this->app->get( '/', HomeController::class . '::getHome' );
	}

	public function run() {
		$this->app->run();
	}
}
