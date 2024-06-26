<?php
/**
 * This is a script for generating the popular pages reports for a
 * all configured WikiProjects. It takes a single command line argument: the wiki
 * in the form of lang.project.
 *
 * Example usage:
 *   php checkReports.php en.wikipedia
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

$dryRun = ( $argv[2] ?? '' ) === '--dry';
$api = new WikiRepository( $argv[1], $dryRun );

wfLogToFile( 'Beginning new cycle', $argv[1] );

$notUpdated = $api->getStaleProjects();

wfLogToFile( 'Number of projects pending update: ' . count( $notUpdated ), $argv[1] );

// Instantiate a new ReportUpdater with projects not updated yet
$updater = new ReportUpdater( $argv[1], $dryRun );
$updater->updateReports( $notUpdated );
