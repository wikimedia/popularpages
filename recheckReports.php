<?php
// This script pulls all report data from the checklist table and tries to update reports which have not been updated
// in the first run of the bot. It tries to update a report twice before moving on to the next one.
// At the end, it updates the popular pages report index page with udpated timestamps.
include_once 'vendor/autoload.php';
include_once 'index.php';

$api = new ApiHelper();

logToFile( 'Running recheck script' );

$creds = parse_ini_file( 'config.ini' );
$link = mysqli_connect( $creds['dbhost'], $creds['dbuser'], $creds['dbpass'], $creds['dbname'] );
$query = "SELECT * FROM checklist";
$data = mysqli_query( $link, $query );
$notUpdated = [];

if ( $data->num_rows > 0 ) {
	while ( $row = $data->fetch_assoc() ) {
		$project = $row['project'];
		$lastUpdate = $row['updated'];
		if ( !isset( $lastUpdate ) ) {
			$notUpdated[] = $project;
			continue;
		} else {
			$dateDiff = date_diff( new DateTime(), new DateTime( $lastUpdate ), true );
			if ( (int)$dateDiff->format( '%d' ) > 25 ) {
				// We found a project not updated for current month yet, add it to the array of projects not updated
				$notUpdated[] = $project;
			}
		}
	}
}

logToFile( 'Number of projects not updated: ' . count( $notUpdated ) );

// Instantiate a new UpdateReport with projects not updated yet
new UpdateReport( $notUpdated );
