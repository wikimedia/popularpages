<?php
/**
 * This script generates the WikiProject index page. It takes a single command line argument:
 * the wiki in the form of lang.project.
 *
 * Example usage:
 *   php generateIndex.php en.wikipedia
 *
 * Pass in --dry as a second argument to print the output to stdout instead of editing the wiki.
 */

include_once __DIR__ . '/../vendor/autoload.php';

// Exit if not run from command-line
if ( PHP_SAPI !== 'cli' ) {
	echo "This script should be ran from the command-line.";
	die();
}

if ( !isset( $argv[1] ) || preg_match( '/^\w+\.\w+$/', $argv[1] ) !== 1 ) {
	echo "Please specify wiki in the format lang.project (such as en.wikipedia)\n";
	die();
}

date_default_timezone_set( 'UTC' );

// Instantiate the ReportUpdater and create the index page.
$updater = new ReportUpdater( $argv[1], ( $argv[2] ?? '' ) === '--dry' );
$updater->updateIndex();
