<?php

use GuzzleHttp\Promise\PromiseInterface;
use Krinkle\Intuition\Intuition;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiSession;
use Symfony\Component\Yaml\Yaml;

/**
 * An WikiRepository assists with fetching data from the API and database.
 * Post-processing of this data is minimal.
 */
class WikiRepository {

	/** @var MediawikiApi The MediawikiApi interface. */
	protected MediawikiApi $api;

	/** @var string URL to the wiki's API endpoint. */
	protected string $apiUrl;

	/** @var mysqli Connection to replica database. */
	protected mysqli $db;

	/** @var string The relevant wiki, in the form lang.project */
	protected string $wiki;

	/** @var ApiUser The ApiUser instance. */
	protected ApiUser $user;

	/** @var string The bot's username (not BotPassword username). */
	protected string $username;

	/** @var string[] The bot's credentials. */
	protected array $creds;

	/** @var string[][] Assessment configuration (colors, icons, etc.) */
	protected array $assessmentConfig;

	/** @var string[] The wiki's configuration of where relevant pages live. */
	protected array $wikiConfig;

	/** @var string[][] Pages queued for processing. With keys 'target' and ' */
	protected array $queue;

	/** @var PageviewsRepository Repository for fetching pageviews. */
	protected PageviewsRepository $pageviewsRepo;

	/** @var Intuition Instance of Intuition translation service. */
	protected Intuition $i18n;

	/** @var bool Print to stdout instead of editing. */
	protected bool $dryRun;

	/**
	 * WikiRepository constructor.
	 *
	 * @param string $wiki Wiki in the format lang.project, such as en.wikipedia
	 * @param bool $dryRun Set to print output to stdout instead of saving to the wiki.
	 */
	public function __construct( string $wiki = 'en.wikipedia', bool $dryRun = false ) {
		$this->creds = parse_ini_file( 'config.ini' );
		$this->wiki = $wiki;
		$this->dryRun = $dryRun;
		$this->apiUrl = "https://$wiki.org/w/api.php";
		$this->api = MediawikiApi::newFromApiEndpoint( $this->apiUrl );
		$this->login();
		$this->wikiConfig = Yaml::parseFile( __DIR__ . '/../config/wikis.yaml' )[$wiki];
		$this->pageviewsRepo = new PageviewsRepository( $this->wiki );

		// Setup Intuition.
		$this->i18n = new Intuition( 'popular-pages' );
		$this->i18n->registerDomain( 'popular-pages', __DIR__ . '/../messages' );
		$this->i18n->setLang( explode( '.', $wiki )[0] );
	}

	/**
	 * Getter for the Intuitions service, which is instantiated in this class.
	 * In lieu of proper dependency injection, this provides a way for other classes
	 * to access the same Intuition instance.
	 * @return Intuition
	 */
	public function getI18n(): Intuition {
		return $this->i18n;
	}

	private function connectDb(): void {
		// In production, the host is *.web.db.svc.wikimedia.cloud, where the asterisk
		// is dynamically replaced with the database name.
		$db = str_replace( '_p', '', $this->wikiConfig['database'] );
		$host = str_replace( '*', $db, $this->creds['dbhost'] );

		$this->db = new mysqli(
			$host,
			$this->creds['dbuser'],
			$this->creds['dbpass'],
			$db . '_p',
			$this->creds['dbport']
		);
	}

	/**
	 * Disconnect from the database.
	 */
	private function disconnectDb(): void {
		$this->db->close();
	}

	/**
	 * Log in
	 */
	public function login(): void {
		$this->user = new ApiUser( $this->creds['botuser'], $this->creds['botpass'], $this->apiUrl );
		$this->username = explode( '@', $this->creds['botuser'] )[ 0 ];
		$this->api->login( $this->user );
	}

	/**
	 * Get the configuration for the wiki as a whole. Includes 'index' (location of index page),
	 * 'config' (location of WikiProjects config) and 'category' (category the reports are put in).
	 * @return string[]
	 */
	public function getWikiConfig(): array {
		return $this->wikiConfig;
	}

	/**
	 * Check if a given title exists on the wiki.
	 *
	 * @param string $title Title to check existence for
	 * @return bool True if title exists else false
	 */
	public function doesTitleExist( string $title ): bool {
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
	public function hasLeadSection( string $title ): bool {
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
	 * @return mysqli_result
	 */
	public function getProjectPages( string $project ): mysqli_result {
		wfLogToFile( 'Fetching pages and assessments for project ' . $project, $this->wiki );

		$this->connectDb();
		$stmt = $this->db->prepare( "
			SELECT page_title, pa_class, pa_importance, (
				SELECT rp.page_title
				FROM page rp
				WHERE rd_from = page_id
				AND rp.page_namespace = 0
			) AS redir_title
			FROM page
			JOIN page_assessments ON page_id = pa_page_id
			LEFT OUTER JOIN redirect ON rd_title = page_title AND rd_namespace = 0
			WHERE pa_project_id = (
				SELECT pap_project_id
				FROM page_assessments_projects
				WHERE pap_project_title = ?
			)
			AND page_namespace = 0" );
		$stmt->bind_param( 's', $project );
		$stmt->execute();
		$ret = $stmt->get_result();
		$this->disconnectDb();
		return $ret;
	}

	/**
	 * Get monthly pageviews for given page and its redirects between given dates.
	 *
	 * @param mysqli_result $result
	 * @param string $start Start date, in YYYYMMDD00 format.
	 * @param string $end End date, in YYYYMMDD00 format.
	 * @param int $limit Max number of pages to show in the report.
	 *   This is used here only for memory management.
	 * @return array [$out, $totalPageviews], where $out is an array with page titles as keys,
	 *   and 'pageviews', 'class', and 'importance' as values. $totalPageviews is an integer.
	 */
	public function getMonthlyPageviewsAndAssessments(
		mysqli_result $result,
		string $start,
		string $end,
		int $limit
	): array {
		wfLogToFile( 'Fetching monthly pageviews', $this->wiki );

		$out = [];

		/** @var array $batch Keys are the target article, values are an array of the page + redirects. */
		$batch = [];

		/** @var int $batchCount How many pages (including redirects) are queued. */
		$batchCount = 0;

		$numResults = $result->num_rows;
		$totalPageviews = 0;
		$index = 0;

		while ( $row = $result->fetch_assoc() ) {
			$index++;
			$target = str_replace( '_', ' ', $row['page_title'] );
			$redir = str_replace( '_', ' ', $row['redir_title'] );

			// Initialize with 0 views
			if ( !isset( $out[$target] ) ) {
				$unknownMsg = $this->i18n->msg( 'unknown' );
				$out[$target] = [
					'pageviews' => 0,
					'class' => $row['pa_class'] === '' ? $unknownMsg : $row['pa_class'],
					'importance' => $row['pa_importance'] === '' ? $unknownMsg : $row['pa_importance'],
				];
			}

			// Queue up pages to be batched-processed.
			if ( !isset( $batch[$target] ) ) {
				// Make sure $target is in the list, too.
				$batch[$target] = [ $target, $redir ];
			} else {
				// Append to existing batch.
				$batch[$target][] = $redir;
			}

			// The $batchCount represents how many pages will be queried for in one go.
			// The 60 is arbitrary. The >60 check means we might end up with 60-200 pages when we
			// call PageviewsRepository::getPageviews(). This means we can exceed the 100 req/sec
			// limit imposed by the API, but the retry handler will automatically slow down the
			// script to ensure every page is processed. The 60 is just a guess at ensuring we
			// have as close to 100 pages per run as possible.
			if ( ++$batchCount > 60 ) {
				wfLogToFile( "Processing page $index of $numResults", $this->wiki );

				$this->processBatch( $batch, $out, $start, $end, $totalPageviews );
				$batchCount = 0;
			}
		}

		// Finish processing any leftover pages.
		$this->processBatch( $batch, $out, $start, $end, $totalPageviews );

		$result->close();
		wfLogToFile( 'Pageviews fetch complete', $this->wiki );

		return [ $this->sortAndTruncatePagesList( $out, $limit ), $totalPageviews ];
	}

	/**
	 * @param array $out
	 * @param int $limit
	 * @return array
	 */
	private function sortAndTruncatePagesList( array $out, int $limit ): array {
		// Sort by pageviews descending.
		uasort( $out, static function ( $a, $b ) {
			if ( $a['pageviews'] === $b['pageviews'] ) {
				return 0;
			}
			return $a['pageviews'] > $b['pageviews'] ? -1 : 1;
		} );

		// Truncate to configured limit.
		return array_slice( $out, 0, $limit, true );
	}

	/**
	 * Process one batch of pages.
	 * @param array $batch Keyed by target page, values are the target + redirects.
	 * @param array $out
	 * @param string $start
	 * @param string $end
	 * @param int $totalPageviews
	 */
	private function processBatch(
		array &$batch,
		array &$out,
		string $start,
		string $end,
		int &$totalPageviews
	): void {
		$batchResult = $this->pageviewsRepo->getPageviews( $batch, $start, $end );
		foreach ( $batchResult as $title => $count ) {
			$out[$title]['pageviews'] += $count;
			$totalPageviews += $count;

			// Clear out batch only for this title, otherwise the target page might
			// get re-added in the next batch.
			$batch[$title] = [];
		}
	}

	/**
	 * Update a wikipedia page with the given text
	 *
	 * @param string $page Page to set text for
	 * @param string $text Text to set on the page
	 * @param string|null $summary Edit summary
	 * @param bool $section section to update on the page
	 * @return array|PromiseInterface|null
	 */
	public function setText(
		string $page,
		string $text,
		?string $summary = null,
		bool $section = false
	): PromiseInterface|array|null {
		if ( !$this->api->isLoggedin() ) {
			$this->login();
		}
		$session = new MediawikiSession( $this->api );
		$token = $session->getToken( 'edit' );
		wfLogToFile( "Attempting to update \"$page\"", $this->wiki );
		$params = [
			'title' => $page,
			'text' => $text,
			'summary' => $summary ?? 'Popular pages report update',
			'token' => $token,
			'bot' => true
		];
		if ( $section ) {
			$params['section'] = $section;
		}

		$result = null;
		try {
			if ( $this->dryRun ) {
				print_r( $params );
			} else {
				$result = $this->apiQuery( $params, 'edit', 'post' );
			}
		} catch ( Exception $e ) {
			// Silently fail, otherwise this could break this halts execution
			// and the bot fails to update all subsequent reports.
			// The below messaging is enough for debugging purposes, as we can
			// run generateReport.php on the one that failed to see what went wrong.
		}

		if ( $result ) {
			wfLogToFile( "\"$page\" updated", $this->wiki );
		} else {
			wfLogToFile( "\"$page\" could not be updated", $this->wiki );
		}
		return $result;
	}

	/**
	 * Fetch JSON config from wiki config page.
	 *
	 * @return array Config data.
	 */
	public function getJSONConfig(): array {
		$params = [
			'page' => $this->wikiConfig['config'],
			'prop' => 'wikitext'
		];
		$res = $this->apiQuery( $params, 'parse' );
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
	public function getStaleProjects(): array {
		wfLogToFile( 'Checking for stale projects', $this->wiki );
		$config = $this->getJSONConfig();

		$botTimestamps = $this->getProjectsWithLastBotTimestamp();
		// Remove projects from the config that have already been updated.
		foreach ( $botTimestamps as $row ) {
			$revTimestamp = strtotime( $row['rev_timestamp'] );
			if ( $revTimestamp >= strtotime( date( 'Y-m-01' ) ) ) {
				unset( $config[$row['name']] );
			}
		}

		return $config;
	}

	/**
	 * Get timestamps of the bot's last edits for all WikiProjects.
	 *
	 * @return array
	 */
	public function getProjectsWithLastBotTimestamp(): array {
		wfLogToFile( "Fetching timestamps of the bot's last edits", $this->wiki );
		$config = $this->getJSONConfig();
		// Get the project names from the config, keyed by db key.
		$projects = array_flip( array_map( static function ( $info ) {
			// FIXME: assumes reports are in the Project namespace.
			return preg_replace( '/^.*?:/', '', str_replace( ' ', '_', $info['Report'] ) );
		}, $config ) );
		// For use in the query.
		$titles = array_keys( $projects );

		$this->connectDb();
		$paramsStr = rtrim( str_repeat( '?,', count( $titles ) ), ',' );
		$stmt = $this->db->prepare( "
			SELECT page_title, MAX(rev_timestamp) AS rev_timestamp
			FROM revision_userindex
			JOIN page ON rev_page = page_id
			WHERE rev_actor = (
				SELECT actor_id
				FROM actor
				WHERE actor_name = ?
			)
			AND page_title IN ($paramsStr)
			AND page_namespace = 4 -- FIXME: assumes reports are in the Project namespace
			GROUP BY page_title" );
		$stmt->bind_param( 's' . str_repeat( 's', count( $titles ) ), $this->username, ...$titles );
		$stmt->execute();
		$ret = $stmt->get_result()->fetch_all( MYSQLI_ASSOC );

		// Add back in the project names for easier reference.
		$ret = array_map( static function ( $row ) use ( $projects ) {
			$row['name'] = $projects[$row['page_title']];
			return $row;
		}, $ret );
		$this->disconnectDb();
		return $ret;
	}

	/**
	 * Get config for a single WikiProject.
	 *
	 * @param string $projectName Name of WikiProject as specified in Name parameter
	 *     of the JSON config.
	 * @return array|null Config for a single WikiProject or null if project not found.
	 */
	public function getProject( string $projectName ): ?array {
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
	 * @param string $page
	 * @return string Date in YYYY-MM-DD format.
	 */
	public function getBotLastEditDate( string $page ): string {
		$params = [
			'prop' => 'revisions',
			'titles' => $page,
			'rvprop' => 'timestamp',
			'rvuser' => $this->username,
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
	public function apiQuery(
		array $params,
		string $action = 'query',
		string $method = 'get',
		bool $async = false
	): PromiseInterface|array {
		$factory = FluentRequest::factory()->setAction( $action )
			->setParam( 'formatversion', 2 )
			->setParam( 'format', 'json' );
		foreach ( $params as $param => $value ) {
			$factory->setParam( $param, $value );
		}
		if ( $method == 'get' ) {
			if ( $async ) {
				try {
					$res = $this->api->getRequestAsync( $factory );
				} catch ( Exception $e ) {
					// Uh oh, we got an exception, let's log it and retry.
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage(), $this->wiki );
					$res = $this->api->getRequestAsync( $factory );
				}
			} else {
				try {
					$res = $this->api->getRequest( $factory );
				} catch ( Exception $e ) {
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage(), $this->wiki );
					$res = $this->api->getRequest( $factory );
				}
			}
		} else {
			if ( $async ) {
				try {
					$res = $this->api->postRequestAsync( $factory );
				} catch ( Exception $e ) {
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage(), $this->wiki );
					$res = $this->api->postRequestAsync( $factory );
				}
			} else {
				try {
					$res = $this->api->postRequest( $factory );
				} catch ( Exception $e ) {
					wfLogToFile( 'Exception caught during API request: ' . $e->getMessage(), $this->wiki );
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
	 * @return string[][]
	 */
	public function getAssessmentConfig(): array {
		if ( isset( $this->assessmentConfig ) ) {
			return $this->assessmentConfig;
		}

		$client = new GuzzleHttp\Client();
		$ret = $client->request(
			'GET',
			'https://xtools.wmflabs.org/api/project/assessments'
		)->getBody()->getContents();

		$this->assessmentConfig = json_decode( $ret, true )['config'][$this->wiki . '.org'];

		return $this->assessmentConfig;
	}
}
