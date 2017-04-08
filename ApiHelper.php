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
	protected $creds;

	/**
	 * ApiHelper constructor.
	 *
	 * @param string $apiurl Url to build the Api endpoint and do all further queries against
	 */
	public function __construct( $apiurl = 'https://en.wikipedia.org/w/api.php' ) {
		$this->api = MediawikiApi::newFromApiEndpoint( $apiurl );
		$creds = parse_ini_file( 'config.ini' );
		$this->creds = $creds;
		$this->user = new ApiUser( $creds['botuser'], $creds['botpass'], $apiurl );
		$this->api->login( $this->user );
	}

	/**
	 * Check if a given title exists on wikipedia
	 *
	 * @param string $title Title to check existence for
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
	 * Checks if the first section is present already in a page
	 *
	 * @param string $title The page title we're looking for first section in
	 * @return bool True if exists, else false
	 */
	public function doesListSectionExist( $title ) {
		if ( !$this->doesTitleExist( $title ) ) {
			return false;
		}
		$params = [ 'page' => $title,  'prop' => 'sections' ];
		$result = $this->apiQuery( $params, 'parse' );
		if ( !isset( $result['parse']['sections'][0] ) ) {
			// We return false if we didn't fina any section
			return false;
		}
		return true;
	}

	/**
	 * Get project titles & assessments for all pages in a wikiproject
	 *
	 * @param string $project Name of the project, i.e. 'Medicine'
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
		if ( isset( $result['query']['projects'][$project] ) ) {
			$projects = $result['query']['projects'][$project];
		} else {
			logToFile( 'Project or project pages not found. Aborting!' );
			return [];
		}
		$pages = [];
		// Loop through the pages and assessment information we got
		foreach ( $projects as $p ) {
			if ( $p['ns'] === 0 ) {
				$pages[$p['title']] = array(
					'class' => $p['assessment']['class'],
					'importance' => $p['assessment']['importance']
				);
			}
		}
		// Do any continuation queries that may be needed
		while ( isset( $result['continue']['wppcontinue'] ) ) {
			$params['wppcontinue'] = $result['continue']['wppcontinue'];
			$result = $this->apiQuery( $params );
			$projects = $result['query']['projects'][$project];
			foreach ( $projects as $p ) {
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
	 * Get monthly pageviews for given page and its redirects between gives dates
	 *
	 * @param array $pages of pages to fetch pageviews for
	 * @param string $start Query datetime start string
	 * @param string $end Query datetime end string
	 * @return array|int
	 */
	public function getMonthlyPageviews( $pages, $start, $end ) {
		logToFile( 'Fetching monthly pageviews' );
		$results = [];
		$lim = 99;
		foreach ( $pages as $page ) {
			$results[$page] = 0; // Initialize with 0 views
			// Get redirects
			$redirects = $this->apiQuery( [ 'titles' => $page, 'prop' => 'redirects', 'rdlimit' => 500 ] );
			$titles = [$page]; // An array to hold the main page and its redirects
			// Extract all redirect titles
			if ( isset( $redirects['query']['pages'][0]['redirects'] ) ) {
				foreach ( $redirects['query']['pages'][0]['redirects'] as $r ) {
					$titles[] = $r['title'];
				}
			}
			// Get monthly pageviews for all of the titles i.e. original page and its redirects
			foreach ( $titles as $title ) {
				$url = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/' . rawurlencode( $title ) . '/monthly/' . $start . '/' . $end;
				$result = json_decode( @file_get_contents( $url ), true );
				if ( isset( $result['items'] ) ) {
					$results[$page] += (int)$result['items'][0]['views'];
				} else {
					// Report missing data
					$file = fopen( 'nopageviewdata.txt', 'a' );
					$output = date( 'Y-m-d H:i:s' ) . '  ' . $url;
					fwrite( $file, $output . PHP_EOL );
				}
				// Throttling purposes
				$lim--;
				if ( $lim == 0 ) {
					usleep( 10000 );
					$lim = 99;
				}
			}
			// Throttling purposes
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
	 * @param string $page Page link
	 */
	public function updateIndex( $page ) {
		$output = '
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
		$projects = $this->getJSONConfig();
		foreach ( $projects as $project => $info ) {
			$params = [
				'prop' => 'revisions',
				'titles' => $info['Report'],
				'rvprop' => 'timestamp',
				'rvuser' => $this->creds['botuser'],
				'rvlimit' => 1
			];
			$res = $this->apiQuery( $params );
			$timestamp = '';
			if ( isset( $res['query']['pages'][0]['revisions'][0]['timestamp'] ) ) {
				$timestamp = date( 'Y-m-d', strtotime( $res['query']['pages'][0]['revisions'][0]['timestamp'] ) );
			}
			$output .= '
|-
|[[' . $project . ']]
|[[' . $info['Report'] . ']]
|' . $info['Limit'] . '
|' . $timestamp . '
';
		}
		$this->setText( $page, $output );
	}

	/**
	 * Update a wikipedia page with the given text
	 *
	 * @param string $page Page to set text for
	 * @param string $text Text to set on the page
	 * @param bool|int $section section to update on the page
	 * @return array|\GuzzleHttp\Promise\PromiseInterface
	 */
	public function setText( $page, $text, $section = false ) {
		$session = new MediawikiSession( $this->api );
		$token = $session->getToken( 'edit' );
		logToFile( 'Attempting to update wikipedia page' );
		$params = [
			'title' => $page,
			'text' => $text,
			'summary' => 'Popular pages report update',
			'token' => $token
		];
		if ( $section ) {
			$params['section'] = $section;
		}
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
	 * @param string $page Wikipedia page title to fetch config from
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
	 * Get projects which have not been updated for current cycle using the API
	 *
	 * @return array Config for projects which were not updated in the current month
	 */
	public function getStaleProjects() {
		$config = $this->getJSONConfig();
		foreach ( $config as $project => $info ) {
			$params = [
				'prop' => 'revisions',
				'titles' => $info['Report'],
				'rvprop' => 'timestamp',
				'rvuser' => $this->creds['botuser'],
				'rvlimit' => 1
			];
			$res = $this->apiQuery( $params );
			if ( isset( $res['query']['pages'][0]['revisions'][0]['timestamp'] ) ) {
				$timestamp = $res['query']['pages'][0]['revisions'][0]['timestamp'];
				$rmonth = date( "F", strtotime( $timestamp ) );
				$cmonth = date( "F" );
				if ( $rmonth === $cmonth ) {
					unset( $config[$project] ); // If report was generated in the same month, skip it
				}
			}
		}
		return $config;
	}

	/**
	 * Get config for a single project
	 * @param string $projectName Name of project as specified in Name parameter
	 *     of JSON config
	 * @return array|null Config for a single project or null if project not found
	 */
	public function getProject( $projectName ) {
		$config = $this->getJSONConfig();
		foreach ( $config as $project => $info ) {
			if ( $info['Name'] === $projectName ) {
				return array( $project => $info );
			}
		}
		return null;
	}

	/**
	 * Wrapper to make simple API query for JSON and in formatversion 2
	 * @param array $params Params to add to the request
	 * @param string $action Query action
	 * @param string $method Get/post
	 * @param bool $async Pass 'true' to make asynchronous
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
