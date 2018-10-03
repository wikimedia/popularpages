<?php
/**
 * This file contains only the ReportUpdater class.
 */

/**
 * A ReportUpdater is responsible for creating a report for one or more
 * WikiProjects on the given wiki.
 */
class ReportUpdater {

	/** @var ApiHelper ApiHelper instance. */
	protected $api;

	/** @var string Target wiki such as 'en.wikipedia'. */
	protected $wiki;

	/** @var int Unix timestamp of start date. */
	protected $start;

	/** @var int Unix timestamp of end date. */
	protected $end;

	/** @var Twig_Environment The Twig rendering engine. */
	private $twig;

	/** @var Intuition Instance of Intuition translation service. */
	private $i18n;

	/**
	 * ReportUpdater constructor.
	 *
	 * @param string $wiki Target wiki in the format lang.project, such as 'en.wikipedia'.
	 */
	public function __construct( $wiki = 'en.wikipedia' ) {
		$this->api = new ApiHelper( $wiki );
		$this->wiki = $wiki;

		// Set dates for the previous month.
		$this->start = strtotime( 'first day of previous month' );
		$this->end = strtotime( 'last day of previous month' );

		// Setup Twig renderer.
		$loader = new Twig_Loader_Filesystem( __DIR__ . '/views' );
		$this->twig = new Twig_Environment( $loader );

		// Setup Intuition.
		$this->i18n = new Intuition( 'popular-pages' );
		$this->i18n->registerDomain( 'popular-pages', __DIR__ . '/messages' );
		$this->i18n->setLang( explode( '.', $wiki )[0] );

		// Set up msg function in Twig.
		$msgFunc = new Twig_SimpleFunction( 'msg', function ( $key, $params = [] ) {
			$params = is_array( $params ) ? $params : [];
			return $this->i18n->msg(
				$key, [ 'domain' => 'popular-pages', 'variables' => $params ]
			);
		} );
		$this->twig->addFunction( $msgFunc );
	}

	/**
	 * Update popular pages reports. Primary execution point.
	 *
	 * @param array $config The JSON config from the wiki page
	 */
	public function updateReports( $config ) {
		// Make sure config isn't empty
		if ( !is_array( $config ) || count( $config ) < 1 ) {
			wfLogToFile( 'Error: Invalid config. Aborting!' );
			return;
		}

		foreach ( $config as $project => $projectConfig ) {
			if ( !$this->validateProjectConfig( $project, $projectConfig ) ) {
				continue;
			}

			// Generate and save the report.
			$this->processProject( $project, $projectConfig );

			wfLogToFile( 'Finished processing: ' . $projectConfig['Name'] );
		}

		// Update index page.
		$this->updateIndex();
	}

	/**
	 * Process an individual WikiProject and update its popular pages report.
	 *
	 * @param  string $project
	 * @param  array $config As specified in the on-wiki JSON config.
	 * @return string Date when report completed.
	 */
	private function processProject( $project, $config ) {
		// Returns { 'title' => ['class'=>'', 'importance'=>''],...}
		$pages = $this->api->getProjectPages( $config['Name'] );

		if ( empty( $pages ) ) {
			return;
		}

		if ( count( $pages ) > 1000000 ) { // See T164178
			wfLogToFile( 'Error: ' . $project . ' is too large. Skipping.' );
			return;
		}

		$pageviews = $this->api->getMonthlyPageviews(
			array_keys( $pages ),
			date( 'Ymd00', $this->start ),
			date( 'Ymd00', $this->end )
		);

		// Compute total views for the month
		$totalViews = array_sum( array_values( $pageviews ) );

		// Truncate to configured limit.
		$pageviews = array_slice( $pageviews, 0, $config['Limit'], true );

		// Populate new reported pages.
		foreach ( $pageviews as $title => $views ) {
			$pageviews[$title] = array_merge( $pages[$title], [
				'views' => $views,
				'avgViews' => floor(
					$views / ( floor( ( $this->end - $this->start ) / ( 60 * 60 * 24 ) ) )
				),
			] );

			// Attempt to reduce memory usage, probably unneeded.
			unset( $pages[$title] );
		}

		$hasLeadSection = $this->api->hasLeadSection( $config['Report'] );

		// Generate and return wikitext.
		$output = $this->twig->render( 'report.wikitext.twig', [
			'hasLeadSection' => $hasLeadSection,
			'wiki' => $this->wiki,
			'start' => $this->start,
			'end' => $this->end,
			'project' => $project,
			'pages' => $pageviews,
			'totalViews' => $totalViews,
			'assessments' => $this->api->getAssessmentConfig(),
			'category' => $this->api->getWikiConfig()['category'],
		] );

		$this->api->setText( $config['Report'], $output, (int)$hasLeadSection );
	}

	/**
	 * Update index page for the bot, listing each WikiProject, its report
	 * and when it was last updated.
	 */
	public function updateIndex() {
		$projectsConfig = $this->api->getJSONConfig();

		foreach ( $projectsConfig as $project => &$data ) {
			$data['Updated'] = $this->api->getBotLastEditDate( $project );
		}

		// Generate and return wikitext.
		$output = $this->twig->render( 'index.wikitext.twig', [
			'projects' => $projectsConfig,
			'configPage' => $this->api->getWikiConfig()['config'],
		] );

		$this->api->setText( $this->api->getWikiConfig()['index'], $output );
	}

	/**
	 * Validate the WikiProject config entry, including the syntax within the JSON config,
	 * whether target page is the correct namespace, and if the target page actually exists.
	 *
	 * @param string $project The WikiProject.
	 * @param array $config The WikiProject's configuration.
	 * @return bool true if valid, false otherwise.
	 */
	private function validateProjectConfig( $project, $config ) {
		// Check that config values are set
		if ( !isset( $config['Name'] ) || !isset( $config['Limit'] ) || !isset( $config['Report'] ) ) {
			wfLogToFile( 'Error: Incomplete data in config for ' . $project . '. Skipping.' );
			return false;
		}

		// Don't allow writing report to main namespace. There is no easy way to grab the
		// namespace ID so just reject titles that don't have a colon in them.
		if ( false === strpos( $config['Report'], ':' ) ) {
			wfLogToFile( "Error: $project is configured to write to the mainspace. Skipping." );
			return false;
		}
		wfLogToFile( 'Beginning to process: ' . $config['Name'] );

		// Check the project exists
		if ( !$this->api->doesTitleExist( $project ) ) {
			wfLogToFile( 'Error: Project page for ' . $config['Name'] . ' does not exist! Skipping.' );
			return false;
		}

		return true;
	}
}
