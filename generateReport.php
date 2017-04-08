<?php
// This is a script for manually regenerating the popular pages report for a
// single WikiProject. It takes a single command line argument: the name of the
// project (as recorded by the PageAssessments extension).
//
// Example usage:
//    php generateReport.php "Alternative education"

// Exit if not run from command-line
if  ( php_sapi_name() !== 'cli' ) {
	echo "This script should be run from the command-line.\n";
	die();
}

if ( !isset( $argv[1] ) ) {
	echo "Please specify the name of the project as a command line argument.\n";
	die();
} else {
	date_default_timezone_set( "UTC" );
	include_once 'vendor/autoload.php';

	$api = new ApiHelper();
	
	logToFile( 'Running script to generate report for project ' . $argv[1] );

	$projectConfig = $api->getProject( $argv[1] );

	// Instantiate a new ReportUpdater with the specified project
	$updater = new ReportUpdater();
	$updater->updateReports( $projectConfig );
}
