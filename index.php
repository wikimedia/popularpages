<?php
use GuzzleHttp\Promise\Promise;

require 'vendor/autoload.php';
require 'ApiHelper.php';

date_default_timezone_set( 'UTC' );

$api_url = 'https://en.wikipedia.org/w/api.php';

$api = new ApiHelper( $api_url );
$projects = $api->getProjects( 50 );

foreach ( $projects as $project ) {
	logToFile( 'Beginning to process: ' . $project );
	$pages = $api->getProjectPages( $project ); // Returns { 'title' => array( 'class' => '', 'importance' => '' ),... }
	$month = (int)date( 'm' ) - 1;
	$year = (int)date( 'Y' );
	if ( $month == 0 ) {
		$month = 12;
		$year = $year - 1;
	}
	$days = cal_days_in_month( CAL_GREGORIAN, $month, $year );
	$viewstart = (string)$year . (string)sprintf( "%02d", $month ) . '0100';
	$viewend = (string)$year . (string)sprintf( "%02d", $month ) . (string)$days . '00';
	$pageviewstart = $year . '-' . $month . '-' . $days;
	$pageviewend = $year . '-' . $month . '-' . $days;
	$views = $api->getMonthlyPageviews( array_keys( $pages ), $viewstart, $viewend );
	$views = array_slice( $views, 0, 1000, true );
    $output = '
This is a list of pages in the scope of WikiProject ' . $project . ' along with pageviews.

To report bugs, please write on the [[meta:User_talk:Community_Tech_bot| Community tech bot]] talk page on Meta.

Period: '. $pageviewstart . ' to '. $pageviewend . '.

Updated on: ~~~~~

{| class="wikitable sortable" style="text-align: left;"
! Rank
! Page title
! Views
! Views per day (average)
! Assessment
! Importance
! Link to pageviews tool
|-
';
	$index = 1;
	foreach ( $views as $title => $view ) {
		$output .= '| ' . $index . '
| [[' . $title . ']]
| ' . $view. '
| ' . floor( $view / (int)date('t') ) . '
{{class|' . $pages[$title]['class'] . '}}
{{importance|' . $pages[$title]['importance'] . '}}
| [https://tools.wmflabs.org/pageviews/?project=en.wikipedia.org&range=this-month&pages='. str_replace( ' ', '_', $title ) .' Link]
|-
';
		$index++;
	}
	$output .= '|}';
	$api->setText( 'Wikipedia:WikiProject_' . $project . '/Popular_pages', $output );
	logToFile( 'Finished processing: ' . $project );
}



