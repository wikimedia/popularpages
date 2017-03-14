<?php
// Simple helper functions for API interactions

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
	public function __construct( $apiurl = 'https://en.wikipedia.org/w/api.php' ) {
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
	public function getProjects( $limit = 5000 ) {
		logToFile( 'Fetching projects list' );
		$params = [
			'list' => 'projects',
			'pjsubprojects' => 'true'
		];
		$result = $this->apiQuery( $params );
		// Chop off the array to required limits
		return array_slice( $result['query']['projects'], 0, $limit );
	}

	/**
	 * Check if a given title exists on wikipedia
	 *
	 * @param $title string Title to check existence for
	 * @return bool True if title exists else false
	 */
	public function doesTitleExist( $title ) {
		$params = [ 'titles' => $title ];
		$result = $this->apiQuery( $params );
		foreach ( $result['query']['pages'] as $r ) {
			if ( isset( $r['missing'] ) || isset( $r['invalid'] ) ) {
				return false;
			}
		}
		return true;
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
			logToFile( 'Zero pages found. Aborting!' );
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
		logToFile( 'Total number of pages fetched: '. count( $pages ) );
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
		logToFile( 'Fetching monthly pageviews' );
		$results = [];
		$lim = 99; // Throttling purposes
		foreach ( $pages as $page ) {
			try {
				$url = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/'. rawurlencode( $page ) .'/monthly/'. $start .'/'. $end;
				$result = json_decode( file_get_contents( $url ), true );
				$results[$page] = isset( $result['items'] ) ? $result['items'][0]['views'] : 0;
			} catch ( Exception $e ) {
				logToFile( $e->getCode() . $e->getMessage() );
			}
			$lim--;
			if ( $lim == 0 ) {
				usleep( 10000 );
				$lim = 99;
			}
		}
		logToFile( 'Pageviews fetch complete' );
		arsort( $results );
		return $results;
	}

	/**
	 * Update index page for the bot, showing last update timestamps and projects
	 *
	 * @param $page string Page link
	 */
	public function updateIndex( $page ) {
		$creds = parse_ini_file( 'config.ini' );
		$link = mysqli_connect( $creds['dbhost'], $creds['dbuser'], $creds['dbpass'], $creds['dbname'] );
		$query = "SELECT * FROM checklist";
		$data = mysqli_query( $link, $query );
		$config = $this->getJSONConfig();
		$output = '';
		if ( $data->num_rows > 0 ) {
			$output .= '
The table below is the wikitext-table representation of the config used for generating Popular pages for wikiprojects. The actual config can be found at [[User:Community Tech bot/Popular pages config.json]].
\'\'\'Please do not edit this page\'\'\'. All edits will be overwritten the next time bot updates this page.

-- ~~~~

== List of projects ==
{| class="wikitable sortable"
!Project
!Report
!Limit
!Updated on
';
			while ( $row = $data->fetch_assoc() ) {
				if ( $config["Wikipedia:WikiProject " . $row['project']] ) {
					$config["Wikipedia:WikiProject " . $row['project']]['Updated'] = $row['updated'];
				}
			}
			foreach ( $config as $project => $info ) {
				$output .= '
|-
|[[' . $project . ']]
|[[' . $info['Report'] . ']]
|' . $info['Limit'] . '
|' . $info['Updated'] . '
';
			}
			$this->setText( $page, $output );
		}
	}

	/**
	 * Update a wikipedia page with the given text
	 *
	 * @param $page string Page to set text for
	 * @param $text string Text to set on the page
	 * @return array|\GuzzleHttp\Promise\PromiseInterface
	 */
	public function setText( $page, $text ) {
		$session = new MediawikiSession( $this->api );
		$token = $session->getToken( 'edit' );
		logToFile( 'Attempting to update wikipedia page' );
		$params = [
			'title' => $page,
			'text' => $text,
			'summary' => 'Popular pages report update. -- Community Tech bot',
			'token' => $token
		];
		$result = $this->apiQuery( $params, 'edit', 'post' );
		if ( $result ) {
			logToFile( 'Page ' . $page . ' updated' );
		} else {
			logToFile( 'Page ' . $page . ' could not be updated' );
		}
		return $result;
	}

	/**
	 * Fetch JSON config from wiki config page
	 *
	 * @param $page string Wikipedia page title to fetch config from
	 * @return array Config data
	 */
	public function getJSONConfig( $page = 'User:Community Tech bot/Popular pages config.json' ) {
		$api = new ApiHelper();
		$params = [
			'page' => $page,
			'prop' => 'wikitext'
		];
		$res = $api->apiQuery( $params, 'parse' );
		$res = json_decode( $res['parse']['wikitext'], true );
		$config = [];
		foreach ( $res as $k => $v ) {
			if ( $k === 'description' ) {
				continue;
			}
			$config[$k] = [
				'Report' => $v['Report'],
				'Limit' => $v['Limit'],
				'Name' => $v['Name']
			];
		}
		return $config;
	}

	/**
	 * Get projects which have not been updated for current cycle
	 *
	 * @return array List of projects which were updated more than 25 days ago from current date
	 */
	public function getStaleProjects() {
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

		return $notUpdated;
	}

	/**
	 * Update timestamp of report update for a project in the DB
	 *
	 * @param $project string Project name to against timestamp against
	 */
	public function updateDB( $project ) {
		$creds = parse_ini_file( 'config.ini' );
		$link = mysqli_connect( $creds['dbhost'], $creds['dbuser'], $creds['dbpass'], $creds['dbname'] );
		$date = date( 'Y-m-d' );
		$query = "UPDATE checklist SET updated = '" . (string)$date . "' WHERE project = '" . $project ."'";
		$res = mysqli_query( $link, $query );
		if ( $res ) {
			logToFile( 'Database updated' );
		} else {
			logToFile( 'Database update failed!' );
		}
	}

	/**
	 * Wrapper to make simple API query for JSON and in formatversion 2
	 * @param $params string Params to add to the request
	 * @param $action string Query action
	 * @param $method string Get/post
	 * @param $async bool Pass 'true' to make asynchronous
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
