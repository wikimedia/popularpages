<?php
// This script pulls all report data from the checklist table and tries to update reports which have not been updated
// in the first run of the bot. It tries to update a report twice before moving on to the next one.
// At the end, it updates the popular pages report index page with udpated timestamps.

// Exit if not run from command-line
if  ( php_sapi_name() !== 'cli' ) {
	echo "This script should be run from the command-line.";
	die();
}

include_once 'vendor/autoload.php';

$api = new ApiHelper();

logToFile( 'Running recheck script' );

$notUpdated = $api->getStaleProjects();

logToFile( 'Number of projects not updated: ' . count( $notUpdated ) );

// Instantiate a new UpdateReport with projects not updated yet
new UpdateReports( $notUpdated );
