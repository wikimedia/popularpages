<?php
// Exit if not run from command-line
if  ( php_sapi_name() !== 'cli' ) {
	echo "This script should be run from the command-line.";
	die();
}

include_once 'vendor/autoload.php';

date_default_timezone_set( 'UTC' );

$start = new UpdateReports();
