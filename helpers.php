<?php
// Simple helper functions for API interactions

require 'vendor/autoload.php';
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiSession;
use GuzzleHttp\Promise;
use GuzzleHttp\Client;

class ApiHelper {

	protected $api;
	protected $user;

	public function __construct( $apiurl ) {
		$this->api = MediawikiApi::newFromApiEndpoint( $apiurl );
		$creds = parse_ini_file( 'config.ini' );
		$this->user = new ApiUser( $creds['botuser'], $creds['botpass'], $apiurl );
		$this->api->login( $this->user );
	}

	function getProjects() {
		$params = [ 'list' => 'projects' ];
		$result = $this->apiQuery( $params );
		return $result['query']['projects'];
	}

	function getProjectPages( $project ) {
		$params = [
			'list' => 'projectpages',
			'wppprojects' => $project,
			'wppassessments' => true,
			'wpplimit' => 1000
		];
		$result = $this->apiQuery( $params );
		$pages = [];
		foreach( $result['query']['projects'][$project] as $p ) {
			if ( $p['ns'] === 0 ) {
				$pages[$p['title']]['class'] = $p['assessment']['class'];
				$pages[$p['title']]['importance'] = $p['assessment']['importance'];
			}
		}
		return $pages;
	}

	function setText( $page, $text ) {
		$session = new MediawikiSession( $this->api );
		$params = [
			'title' => $page,
			'text' => $text,
			'summary' => 'Popular pages report update. -- Community Tech bot',
			'token' => $session->getToken( 'edit' )
		];
		$result = $this->apiQuery( $params, 'edit', 'post' );
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

	public function getMonthlyPageviews( $pages, $start, $end ) {
		$results = [];
		foreach ( $pages as $page ) {
			try {
				$url = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/' . $page . '/monthly/' . $start . '/' . $end;
				echo $url;
				$result = json_decode( @file_get_contents( $url ), true );
				$results[$page] = $result['items'][0]['views'];
			} catch ( Exception $e ) {
				return 0;
			}
		}
		return $results;
	}

}
