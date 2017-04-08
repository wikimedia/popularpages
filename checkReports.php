<?php
// Exit if not run from command-line
if  ( php_sapi_name() !== 'cli' ) {
	echo "This script should be run from the command-line.";
	die();
}

include_once 'vendor/autoload.php';

date_default_timezone_set( 'UTC' );

$api = new ApiHelper();

logToFile( 'Beginning new cycle' );

$notUpdated = $api->getStaleProjects();

logToFile( 'Number of projects pending update: ' . count( $notUpdated ) );

// Instantiate a new ReportUpdater with projects not updated yet
$updater = new ReportUpdater();
$updater->updateReports( $notUpdated );
