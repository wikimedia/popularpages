<?php
/**
 * This file contains only the ApiHelper class.
 */

use Mediawiki\Api\ApiUser;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiSession;
use Symfony\Component\Yaml\Yaml;

/**
 * An ApiHelper assists with fetching data from the API and database.
 * Post-processing of this data is minimal.
 */
class ApiHelper {

	/** @var MediawikiApi The MediawikiApi interface. */
	protected $api;

	/** @var string URL to the wiki's API endpoint. */
	protected $apiurl;

	/** @var string The relevant wiki, in the form lang.project */
	protected $wiki;

	/** @var ApiUser The ApiUser instance. */
	protected $user;

	/** @var string[] The bot's credentials. */
	protected $creds;

	/** @var string[] Assessment configuration (colors, icons, etc.) */
	protected $assessmentConfig;

	/** @var string[] The wiki's configuration of where relevant pages live. */
	protected $wikiConfig;

	/**
	 * ApiHelper constructor.
	 *
	 * @param string $wiki Wiki in the format lang.project, such as en.wikipedia
	 */
	public function __construct( $wiki = 'en.wikipedia' ) {
		$this->wiki = $wiki;
		$this->apiurl = "https://$wiki.org/w/api.php";
		$this->api = MediawikiApi::newFromApiEndpoint( $this->apiurl );
		$this->login();
		$this->wikiConfig = Yaml::parseFile( __DIR__ . '/wikis.yml' )[$wiki];
	}

	/**
	 * Log in
	 */
	public function login() {
		$creds = parse_ini_file( 'config.ini' );
		$this->creds = $creds;
		$this->user = new ApiUser( $creds['botuser'], $creds['botpass'], $this->apiurl );
		$this->api->login( $this->user );
	}

	/**
	 * Get the configuration for the wiki as a whole. Includes 'index' (location of index page),
	 * 'config' (location of WikiProjects config) and 'category' (category the reports are put in).
	 * @return string[]
	 */
	public function getWikiConfig() {
		return $this->wikiConfig;
	}

	/**
	 * Check if a given title exists on the wiki.
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
	 * Checks if the first section is present already in a page.
	 *
	 * @param string $title The page title we're looking for first section in
	 * @return bool True if exists, else false
	 */
	public function hasLeadSection( $title ) {
		if ( !$this->doesTitleExist( $title ) ) {
			return false;
		}
		$params = [ 'page' => $title,  'prop' => 'sections' ];
		$result = $this->apiQuery( $params, 'parse' );
		if ( !isset( $result['parse']['sections'][0] ) ) {
			// We return false if we didn't find any section
			return false;
		}
		return true;
	}

	/**
	 * Get titles & assessments for all pages in a wikiproject.
	 *
	 * @param string $project Name of the project, i.e. 'Medicine'
	 * @return array
	 */
	public function getProjectPages( $project ) {
		wfLogToFile( 'Fetching pages and assessments for project ' . $project );
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
			wfLogToFile( 'Project or project pages not found. Aborting!' );
			return [];
		}
		$pages = [];
		// Loop through the pages and assessment information we got
		foreach ( $projects as $p ) {
			if ( $p['ns'] === 0 ) {
				$class = $p['assessment']['class'];
				$importance = $p['assessment']['importance'];
				$pages[$p['title']] = [
					'class' => $class == '' ? 'Unknown' : $class,
					'importance' => $importance == '' ? 'Unknown' : $importance,
				];
			}
		}
		// Do any continuation queries that may be needed
		while ( isset( $result['continue']['wppcontinue'] ) ) {
			$params['wppcontinue'] = $result['continue']['wppcontinue'];
			$result = $this->apiQuery( $params );
			$projects = $result['query']['projects'][$project];
			foreach ( $projects as $p ) {
				if ( $p['ns'] === 0 ) {
					$class = $p['assessment']['class'];
					$importance = $p['assessment']['importance'];
					$pages[$p['title']] = [
						'class' => $class == '' ? 'Unknown' : $class,
						'importance' => $importance == '' ? 'Unknown' : $importance,
					];
				}
			}
		}
		wfLogToFile( 'Total number of pages fetched: ' . count( $pages ) );
		return $pages;
	}

	/**
	 * Get monthly pageviews for given page and its redirects between gives dates.
	 *
	 * @param array $pages Pages to query for.
	 * @param string $start Start date, in YYYYMMDD00 format.
	 * @param string $end End date, in YYYYMMDD00 format.
	 * @return array|int
	 */
	public function getMonthlyPageviews( $pages, $start, $end ) {
		wfLogToFile( 'Fetching monthly pageviews' );
		$client = new \GuzzleHttp\Client(); // Client for our promises
		$results = [];

		foreach ( $pages as $page ) {
			$results[$page] = 0; // Initialize with 0 views
			// Get redirects
			$redirects = $this->apiQuery( [
				'titles' => $page,
				'prop' => 'redirects',
				'rdlimit' => 500
			] );
			$titles = [ $page ]; // An array to hold the main page and its redirects
			// Extract all redirect titles
			if ( isset( $redirects['query']['pages'][0]['redirects'] ) ) {
				foreach ( $redirects['query']['pages'][0]['redirects'] as $r ) {
					$titles[] = $r['title'];
				}
			}
			unset( $redirects );

			// Get monthly pageviews for all of the titles i.e. original page
			// and its redirects using promises.
			$promises = [];
			foreach ( $titles as $title ) {
				$url = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/' . $this->wiki .
					'/all-access/user/' . rawurlencode( $title ) . '/monthly/' . $start . '/' . $end;
				$promise = $client->getAsync( $url );
				$promises[] = $promise; // Add the promise for each request to promises array
			}
			try {
				$responses = GuzzleHttp\Promise\settle( $promises )->wait();
			} catch ( Exception $e ) {
				// Ignore
			}
			unset( $promises, $titles );

			foreach ( $responses as $response ) {
				if ( $response['state'] !== 'fulfilled' ) {
					// Do nothing, API didn't have data most likely
				} else {
					$result = $response['value'];
					$result = json_decode( $result->getBody()->getContents(), true );
					if ( $result && isset( $result['items'][0]['views'] ) ) {
						$results[$page] += (int)$result['items'][0]['views'];
					}
				}
			}
			unset( $responses );
		}
		wfLogToFile( 'Pageviews fetch complete' );

		arsort( $results );
		return $results;
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
		if ( !$this->api->isLoggedin() ) {
			$this->login();
		}
		$session = new MediawikiSession( $this->api );
		$token = $session->getToken( 'edit' );
		wfLogToFile( 'Attempting to update wikipedia page' );
		$params = [
			'title' => $page,
			'text' => $text,
			'summary' => 'Popular pages report update',
			'token' => $token,
			'bot' => true
		];
		if ( $section ) {
			$params['section'] = $section;
		}

		$result = $this->apiQuery( $params, 'edit', 'post' );
		if ( $result ) {
			wfLogToFile( 'Page ' . $page . ' updated' );
		} else {
			wfLogToFile( 'Page ' . $page . ' could not be updated' );
		}
		return $result;
	}

	/**
	 * Fetch JSON config from wiki config page.
	 *
	 * @return array Config data.
	 */
	public function getJSONConfig() {
		$api = new ApiHelper();
		$params = [
			'page' => $this->wikiConfig['config'],
			'prop' => 'wikitext'
		];
		$res = $api->apiQuery( $params, 'parse' );
		$config = json_decode( $res['parse']['wikitext'], true );

		// Remove the 'description' entry which is meant only as explanatory text.
		unset( $config['description'] );

		return $config;
	}

	/**
	 * Get WikiProjects which have not been updated for current cycle using the API.
	 *
	 * @return array Config for WikiProjects which were not updated in the current month.
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
	 * Get config for a single WikiProject.
	 *
	 * @param string $projectName Name of WikiProject as specified in Name parameter
	 *     of the JSON config.
	 * @return array|null Config for a single WikiProject or null if project not found.
	 */
	public function getProject( $projectName ) {
		$config = $this->getJSONConfig();
		foreach ( $config as $project => $info ) {
			if ( $info['Name'] === $projectName ) {
				return [ $project => $info ];
			}
		}
		return null;
	}

	/**
	 * Get the date the bot last edited the given page.
	 * Used on the WikiProject index page, since sometimes we run one-off reports of a specific
	 * WikiProject, at which point we don't know the 'last updated' date for the other reports.
	 *
	 * @param  string $page
	 * @return string Date in YYYY-MM-DD format.
	 */
	public function getBotLastEditDate( $page ) {
		$params = [
			'prop' => 'revisions',
			'titles' => $page,
			'rvprop' => 'timestamp',
			'rvuser' => $this->creds['botuser'],
			'rvlimit' => 1
		];
		$res = $this->apiQuery( $params );

		if ( isset( $res['query']['pages'][0]['revisions'][0]['timestamp'] ) ) {
			return date( 'Y-m-d', strtotime( $res['query']['pages'][0]['revisions'][0]['timestamp'] ) );
		} else {
			return '';
		}
	}

	/**
	 * Wrapper to make simple API query for JSON and in formatversion 2
	 *
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
		$res = null;
		if ( $method == 'get' ) {
			if ( $async ) {
				try {
					$res = $this->api->getRequestAsync( $factory );
				} catch ( Exception $e ) {
					// Uh oh, we got an exception, let's log it and retry.
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage() );
					$res = $this->api->getRequestAsync( $factory );
				}
			} else {
				try {
					$res = $this->api->getRequest( $factory );
				} catch ( Exception $e ) {
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage() );
					$res = $this->api->getRequest( $factory );
				}
			}
		} else {
			if ( $async ) {
				try {
					$res = $this->api->postRequestAsync( $factory );
				} catch ( Exception $e ) {
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage() );
					$res = $this->api->postRequestAsync( $factory );
				}
			} else {
				try {
					$res = $this->api->postRequest( $factory );
				} catch ( Exception $e ) {
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage() );
					$res = $this->api->postRequest( $factory );
				}
			}
		}
		return $res;
	}

	/**
	 * Get the wiki's assessment configuration from the XTools API.
	 * This includes the colours and icons for each classification and importance level.
	 *
	 * @return string[]
	 */
	public function getAssessmentConfig() {
		if ( $this->assessmentConfig !== null ) {
			return $this->assessmentConfig;
		}

		$client = new GuzzleHttp\Client();
		$ret = $client->request(
			'GET',
			'https://xtools.wmflabs.org/api/project/assessments'
		)->getBody()->getContents();

		$this->assessmentConfig = json_decode( $ret, true )['config'][$this->wiki.'.org'];

		return $this->assessmentConfig;
	}
}
