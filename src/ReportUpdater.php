<?php

use Krinkle\Intuition\Intuition;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A ReportUpdater is responsible for creating a report for one or more
 * WikiProjects on the given wiki.
 */
class ReportUpdater {

	/** @var WikiRepository WikiRepository instance. */
	protected WikiRepository $wikiRepository;

	/** @var string Target wiki such as 'en.wikipedia'. */
	protected string $wiki;

	/** @var int Unix timestamp of start date. */
	protected int $start;

	/** @var int Unix timestamp of end date. */
	protected int $end;

	/** @var Environment The Twig rendering engine. */
	private Environment $twig;

	/** @var Intuition Instance of Intuition translation service. */
	private Intuition $i18n;

	/**
	 * ReportUpdater constructor.
	 *
	 * @param string $wiki Target wiki in the format lang.project, such as 'en.wikipedia'.
	 * @param bool $dryRun Set to print output to stdout instead of saving to the wiki.
	 */
	public function __construct( string $wiki = 'en.wikipedia', bool $dryRun = false ) {
		// Instantiate the WikiRepository.
		$this->wikiRepository = new WikiRepository( $wiki, $dryRun );
		$this->wiki = $wiki;
		$this->i18n = $this->wikiRepository->getI18n();

		// Set dates for the previous month.
		$this->start = strtotime( 'first day of previous month' );
		$this->end = strtotime( 'last day of previous month' );

		// Setup Twig renderer.
		$loader = new FilesystemLoader( __DIR__ . '/../views' );
		$this->twig = new Environment( $loader );
		$this->addTwigFunctions();

		// Setup Intuition.
		$this->i18n = new Intuition( 'popular-pages' );
		$this->i18n->registerDomain( 'popular-pages', __DIR__ . '/../messages' );
		$this->i18n->setLang( explode( '.', $wiki )[0] );
	}

	private function addTwigFunctions(): void {
		// msg() function for i18n.
		$msgFunc = new TwigFunction( 'msg', function ( $key, $params = [] ) {
			$params = is_array( $params ) ? $params : [];
			return $this->i18n->msg(
				$key, [ 'domain' => 'popular-pages', 'variables' => $params ]
			);
		} );
		$this->twig->addFunction( $msgFunc );

		// Fetching assessments info, case-insensitive.
		$assessmentFunc = new TwigFunction(
			'assessments', function (
				string $type, string $value
			) {
				$dataset = $this->wikiRepository->getAssessmentConfig()[$type];
				foreach ( $dataset as $key => $values ) {
					if ( strtolower( $value ) === strtolower( $key ) ) {
						return $values;
					}
				}
				return $dataset['Unknown'];
			} );
		$this->twig->addFunction( $assessmentFunc );

		// Add ucfirst() (Twig's capitalize() will make the other chars lowercase).
		$ucfirstFunc = new TwigFilter( 'ucfirst', static function ( string $value ) {
			return ucfirst( $value );
		} );
		$this->twig->addFilter( $ucfirstFunc );
	}

	/**
	 * Update popular pages reports. Primary execution point.
	 *
	 * @param array $config The JSON config from the wiki page
	 */
	public function updateReports( array $config ): void {
		// Make sure config isn't empty
		if ( !$config ) {
			wfLogToFile( 'Error: Invalid config. Aborting!', $this->wiki );
			return;
		}

		foreach ( $config as $project => $projectConfig ) {
			if ( !$this->validateProjectConfig( $project, $projectConfig ) ) {
				continue;
			}

			// Generate and save the report.
			$this->processProject( $project, $projectConfig );

			wfLogToFile( 'Finished processing: ' . $projectConfig['Name'], $this->wiki );
		}

		// Update index page.
		$this->updateIndex();
	}

	/**
	 * Process an individual WikiProject and update its popular pages report.
	 *
	 * @param string $project
	 * @param array $config As specified in the on-wiki JSON config.
	 */
	private function processProject( string $project, array $config ): void {
		$pageStmt = $this->wikiRepository->getProjectPages( $config['Name'] );

		if ( $pageStmt->num_rows === 0 ) {
			wfLogToFile( "No pages found for \"$project\"", $this->wiki );
			return;
		}

		// See T164178
		if ( $pageStmt->num_rows > 1000000 ) {
			wfLogToFile( 'Error: ' . $project . ' is too large. Skipping.', $this->wiki );
			return;
		}

		$startDate = date( 'Ymd00', $this->start );
		$endDate = date( 'Ymd00', $this->end );

		[ $data, $totalViews ] = $this->wikiRepository->getMonthlyPageviewsAndAssessments(
			$pageStmt,
			$startDate,
			$endDate,
			$config['Limit']
		);

		/** @var DateTime $startDateTime */
		$startDateTime = DateTime::createFromFormat( 'Ymd00', $startDate );
		$endDateTime = DateTime::createFromFormat( 'Ymd00', $endDate );
		$daysInMonth = $endDateTime->diff( $startDateTime )->days + 1;

		// Add in averages.
		foreach ( $data as $title => $datum ) {
			$data[$title]['avgPageviews'] = floor( $datum['pageviews'] / $daysInMonth );
		}

		$hasLeadSection = $this->wikiRepository->hasLeadSection( $config['Report'] );

		// Generate and return wikitext.
		$output = $this->twig->render( 'report.wikitext.twig', [
			'hasLeadSection' => $hasLeadSection,
			'wiki' => $this->wiki,
			'start' => $this->start,
			'end' => $this->end,
			'project' => $project,
			'pages' => $data,
			'totalViews' => $totalViews,
			'category' => $this->wikiRepository->getWikiConfig()['category'],
		] );

		$this->wikiRepository->setText(
			$config['Report'],
			$output,
			$this->i18n->msg( 'edit-summary' ),
			$hasLeadSection
		);
	}

	/**
	 * Update index page for the bot, listing each WikiProject, its report
	 * and when it was last updated.
	 */
	public function updateIndex(): void {
		wfLogToFile( 'Updating index page', $this->wiki );

		$projectsConfig = $this->wikiRepository->getJSONConfig();
		$lastEdits = $this->wikiRepository->getProjectsWithLastBotTimestamp();

		// Add the last updated date to the config.
		foreach ( $lastEdits as $row ) {
			$projectsConfig[$row['name']]['Updated'] = date( 'Y-m-d', strtotime( $row['rev_timestamp'] ) );
		}

		// Generate and return wikitext.
		$output = $this->twig->render( 'index.wikitext.twig', [
			'projects' => $projectsConfig,
			'configPage' => $this->wikiRepository->getWikiConfig()['config'],
		] );

		$this->wikiRepository->setText(
			$this->wikiRepository->getWikiConfig()['index'],
			$output,
			$this->i18n->msg( 'edit-summary' )
		);
	}

	/**
	 * Validate the WikiProject config entry, including the syntax within the JSON config,
	 * whether target page is the correct namespace, and if the target page actually exists.
	 *
	 * @param string $project The WikiProject.
	 * @param array $config The WikiProject's configuration.
	 * @return bool true if valid, false otherwise.
	 */
	private function validateProjectConfig( string $project, array $config ): bool {
		// Check that config values are set
		if ( !isset( $config['Name'] ) || !isset( $config['Limit'] ) || !isset( $config['Report'] ) ) {
			wfLogToFile( 'Error: Incomplete data in config for ' . $project . '. Skipping.', $this->wiki );
			return false;
		}

		// Don't allow writing report to main namespace. There is no easy way to grab the
		// namespace ID so just reject titles that don't have a colon in them.
		if ( !strpos( $config['Report'], ':' ) ) {
			wfLogToFile( "Error: $project is configured to write to the mainspace. Skipping.", $this->wiki );
			return false;
		}
		wfLogToFile( 'Beginning to process: ' . $config['Name'], $this->wiki );

		// Check the project exists
		if ( !$this->wikiRepository->doesTitleExist( $project ) ) {
			wfLogToFile(
				'Error: Project page for ' . $config['Name'] . ' does not exist! Skipping.',
				$this->wiki
			);
			return false;
		}

		return true;
	}
}
