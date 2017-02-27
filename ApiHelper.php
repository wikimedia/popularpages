<?php
// Simple helper functions for API interactions

require 'vendor/autoload.php';
require 'Logger.php';

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiSession;
use GuzzleHttp\Promise;
use GuzzleHttp\Client;

/**
 * Class ApiHelper
 */
class ApiHelper {

	protected $api;
	protected $user;

	/**
	 * ApiHelper constructor.
	 *
	 * @param $apiurl string Url to build the Api endpoint and do all further queries against
	 */
	public function __construct( $apiurl ) {
		$this->api = MediawikiApi::newFromApiEndpoint( $apiurl );
		$creds = parse_ini_file( 'config.ini' );
		$this->user = new ApiUser( $creds['botuser'], $creds['botpass'], $apiurl );
		$this->api->login( $this->user );
	}

	/**
	 * Fetch projects from the PageAssessments database
	 *
	 * @param $limit int Number of projects to fetch
	 * @return array Projects
	 */
	public function getProjects( $limit ) {
		logToFile( 'Fetching projects list' );
		$params = [ 'list' => 'projects' ];
		$result = $this->apiQuery( $params );
		// Chop off the array to required limits
		return array_slice( $result['query']['projects'], 0, $limit );
	}

	/**
	 * Get project titles & assessments for all pages in a wikiproject
	 *
	 * @param $project
	 * @return array
	 */
	public function getProjectPages( $project ) {
		logToFile( 'Fetching pages and assessments for project ' . $project );
		$params = [
			'list' => 'projectpages',
			'wppprojects' => $project,
			'wpplimit' => 1000,
			'wppassessments' => true
		];
		$result = $this->apiQuery( $params );
		$projects = $result['query']['projects'][$project];
		$pages = [];
		if ( !$projects ) {
			return [];
		}
		foreach( $projects as $p ) {
			if ( $p['ns'] === 0 ) {
				$pages[$p['title']] = array(
					'class' => $p['assessment']['class'],
					'importance' => $p['assessment']['importance']
				);
			}
		}
		while ( isset( $result['continue']['wppcontinue'] ) ) {
			sleep( 0.1 );
			$params['wppcontinue'] = $result['continue']['wppcontinue'];
			$result = $this->apiQuery( $params );
			$projects = $result['query']['projects'][$project];
			foreach( $projects as $p ) {
				if ( $p['ns'] === 0 ) {
					$pages[$p['title']] = array(
						'class' => $p['assessment']['class'],
						'importance' => $p['assessment']['importance']
					);
				}
			}
		}
		logToFile( 'Total number of pages fetched: ' . count( $pages ) );
		return $pages;
	}

	/**
	 * Get monthly pageviews for given page between gives dates
	 *
	 * @param $pages array of pages to fetch pageviews for
	 * @param $start string Query datetime start string
	 * @param $end string Query datetime end string
	 * @return array|int
	 */
	public function getMonthlyPageviews( $pages, $start, $end ) {
		logToFile( 'Fetching monthly pageviews for pages' );
		$results = [];
		$lim = 30; // Throttling purposes
		foreach ( $pages as $page ) {
			try {
				$url = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/' . rawurlencode( $page ) . '/monthly/' . $start . '/' . $end;
				$result = json_decode( file_get_contents( $url ), true );
				$results[$page] = isset( $result['items'] ) ? $result['items'][0]['views'] : 0;
			} catch ( Exception $e ) {
				logToFile( $e->getCode() . $e->getMessage() );
			}
			$lim--;
			if ( $lim == 0 ) {
				sleep( 0.1 );
				$lim = 30;
			}
		}
		logToFile( 'Pageviews fetch complete' );
		arsort( $results );
		return $results;
	}

	/**
	 * Update a wikipedia page with the given text
	 *
	 * @param $page string Page to set text for
	 * @param $text string Text to set on the page
	 * @return array|\GuzzleHttp\Promise\PromiseInterface
	 */
	public function setText( $page, $text ) {
		logToFile( 'Attempting to update wikipedia page' );
		$session = new MediawikiSession( $this->api );
		$params = [
			'title' => $page,
			'text' => $text,
			'summary' => 'Popular pages report update. -- Community Tech bot',
			'token' => $session->getToken( 'edit' )
		];
		$result = $this->apiQuery( $params, 'edit', 'post' );
		logToFile( 'Page ' . $page . ' updated ' );
		return $result;
	}

	/**
	 * Wrapper to make simple API query for JSON and in formatversion 2
	 * @param string[] $params Params to add to the request
	 * @param boolean $async Pass 'true' to make asynchronous
	 * @return GuzzleHttp\Promise\PromiseInterface|array Promise if $async is true,
	 *   otherwise the API result in the form of an array
	 */
	public function apiQuery( $params, $action = 'query', $method = 'get', $async = false ) {
		$factory = FluentRequest::factory()->setAction( $action )
			->setParam( 'formatversion', 2 )
			->setParam( 'format', 'json' );
		foreach ( $params as $param => $value ) {
			$factory->setParam( $param, $value );
		}
		if ( $method == 'get' ) {
			if ( $async ) {
				return $this->api->getRequestAsync( $factory );
			} else {
				return $this->api->getRequest( $factory );
			}
		} else {
			if ( $async ) {
				return $this->api->postRequestAsync( $factory );
			} else {
				return $this->api->postRequest( $factory );
			}
		}
	}

}
