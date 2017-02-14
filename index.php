<?php
use GuzzleHttp\Promise\Promise;

require 'vendor/autoload.php';
require 'helpers.php';
date_default_timezone_set( 'UTC' );

$api_url = 'https://en.wikipedia.org/w/api.php';

$api = new ApiHelper( $api_url );
//$projects = $api->getProjects();
$projects = [ 'Artemis Fowl' ];

foreach ( $projects as $project ) {
    $pages = $api->getProjectPages( $project );
    $month = (int)date( 'm' ) - 1;
    $year = (int)date( 'Y' );
    if ( $month == 0 ) {
        $month = 12;
        $year = $year - 1;
    }
    $days = cal_days_in_month( CAL_GREGORIAN, $month, $year );
    $start = (string)$year . (string)sprintf( "%02d", $month ) . '0100';
    $end = (string)$year . (string)sprintf( "%02d", $month ) . (string)$days . '00';
    $views = $api->getMonthlyPageviews( array_keys( $pages ), $start, $end );
    $output = '
This is a list of pages in the scope of WikiProject ' . $project . 'along with pageviews.

To report bugs, please write on the [[meta:User_talk:Community_Tech_bot| Community tech bot]] talk page on Meta.

Period: '. $year . '-' . $month .'-01 to '. $year .'-' . $month . '-' . $days . '.

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
    foreach ( $pages as $p => $r ) {
        $output .= '| ' . $index . ' 
| [[' . $p . ']]
| ' . $views[$p]. '
| ' . floor( $views[$p] / (int)date('t') ). '
{{class|' . $r['class'] . '}}
{{importance|' . $r['importance'] . '}}
| [https://tools.wmflabs.org/pageviews/?project=en.wikipedia.org&range=this-month&pages='. str_replace( ' ', '_', $p ) .' Link]
|-
';
        $index++;
    }
    $output .= '|}';

//    $api->setText( 'Wikipedia:WikiProject_' . $project . '/Popular_pages', $output );
    $api->setText( 'User:NiharikaKohli/Test popular pages', $output );
}



