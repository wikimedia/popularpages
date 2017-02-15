<?php

/**
 * Composer.
 */
if ( !file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	echo "Please run <code>composer install</code>";
	exit( 1 );
}
require __DIR__ . '/vendor/autoload.php';

/**
 * Configuration file. When testing, the tests/config.php file is used.
 */
$configFilename = 'config.inc.php';
if ( substr( basename( $_SERVER['PHP_SELF'] ), 0, 7 )==='phpunit' ) {
	define( 'CONFIG_FILE', __DIR__ . '/tests/' . $configFilename );
} else {
	define( 'CONFIG_FILE', __DIR__ . '/' . $configFilename );
}
if ( !file_exists( CONFIG_FILE ) ) {
	echo "Please copy <code>$configFilename</code> to <code>".CONFIG_FILE."</code> and edit it.\n";
	exit( 1 );
}
