<?php
/**
 * This is a script for generating the popular pages reports for a
 * all configured WikiProjects. It takes a single command line argument: the wiki
 * in the form of lang.project.
 *
 * Example usage:
 *   php checkReports.php en.wikipedia
 */

// Exit if not run from command-line
if ( PHP_SAPI !== 'cli' ) {
	echo "This script should be ran from the command-line.";
	die();
}

if ( !isset( $argv[1] ) || preg_match( '/^\w+\.\w+$/', $argv[1] ) !== 1 ) {
	echo "Please specify wiki in the format lang.project (such as en.wikipedia)\n";
	die();
}

include_once 'vendor/autoload.php';

date_default_timezone_set( 'UTC' );

$api = new ApiHelper( $argv[1] );

wfLogToFile( 'Beginning new cycle' );

$notUpdated = $api->getStaleProjects();

wfLogToFile( 'Number of projects pending update: ' . count( $notUpdated ) );

// Instantiate a new ReportUpdater with projects not updated yet
$updater = new ReportUpdater( $argv[1] );
$updater->updateReports( $notUpdated );
