<?php
/**
 * This file contains only the ReportUpdater class.
 */

use Krinkle\Intuition\Intuition;

/**
 * A ReportUpdater is responsible for creating a report for one or more
 * WikiProjects on the given wiki.
 */
<<<<<<< HEAD
class ReportUpdater
{

    /**
 * @var ApiHelper ApiHelper instance. 
*/
    protected $api;

    /**
 * @var string Target wiki such as 'en.wikipedia'. 
*/
    protected $wiki;

    /**
 * @var int Unix timestamp of start date. 
*/
    protected $start;

    /**
 * @var int Unix timestamp of end date. 
*/
    protected $end;

    /**
 * @var Twig_Environment The Twig rendering engine. 
*/
    private $twig;

    /**
 * @var Intuition Instance of Intuition translation service. 
*/
    private $i18n;

    /**
     * ReportUpdater constructor.
     *
     * @param string $wiki Target wiki in the format lang.project, such as 'en.wikipedia'.
     */
    public function __construct( $wiki = 'en.wikipedia' ) 
    {
        $this->api = new ApiHelper($wiki);
        $this->wiki = $wiki;

        // Set dates for the previous month.
        $this->start = strtotime('first day of previous month');
        $this->end = strtotime('last day of previous month');

        // Setup Twig renderer.
        $loader = new Twig_Loader_Filesystem(__DIR__ . '/views');
        $this->twig = new Twig_Environment($loader);
        $this->addTwigFunctions();

        // Setup Intuition.
        $this->i18n = new Intuition('popular-pages');
        $this->i18n->registerDomain('popular-pages', __DIR__ . '/messages');
        $this->i18n->setLang(explode('.', $wiki)[0]);
    }

    private function addTwigFunctions() : void 
    {
        // msg() function for i18n.
        $msgFunc = new Twig_SimpleFunction(
            'msg', function ( $key, $params = [] ) {
                $params = is_array($params) ? $params : [];
                return $this->i18n->msg(
                    $key, [ 'domain' => 'popular-pages', 'variables' => $params ]
                );
            } 
        );
        $this->twig->addFunction($msgFunc);

        // Fetching assessments info, case-insensitive.
        $assessmentFunc = new Twig_SimpleFunction(
            'assessments', function (
                string $type, string $value
            ) {
                $dataset = $this->api->getAssessmentConfig()[$type];
                foreach ( $dataset as $key => $values ) {
                    if (strtolower($value) === strtolower($key) ) {
                        return $values;
                    }
                }
                return $dataset['Unknown'];
            } 
        );
        $this->twig->addFunction($assessmentFunc);

        // Add ucfirst() (Twig's capitalize() will make the other chars lowercase).
        $ucfirstFunc = new Twig_SimpleFilter(
            'ucfirst', function ( string $value ) {
                return ucfirst($value);
            } 
        );
        $this->twig->addFilter($ucfirstFunc);
    }

    /**
     * Update popular pages reports. Primary execution point.
     *
     * @param array $config The JSON config from the wiki page
     */
    public function updateReports( $config ) 
    {
        // Make sure config isn't empty
        if (!is_array($config) || count($config) < 1 ) {
            wfLogToFile('Error: Invalid config. Aborting!');
            return;
        }

        foreach ( $config as $project => $projectConfig ) {
            if (!$this->validateProjectConfig($project, $projectConfig) ) {
                continue;
            }

            // Generate and save the report.
            $this->processProject($project, $projectConfig);

            wfLogToFile('Finished processing: ' . $projectConfig['Name']);
        }

        // Update index page.
        $this->updateIndex();
    }

    /**
     * Process an individual WikiProject and update its popular pages report.
     *
     * @param string $project
     * @param array  $config  As specified in the on-wiki JSON config.
     */
    private function processProject( $project, $config ) : void 
    {
        /**
 * @var mysqli_result $pageStmt 
*/
        $pageStmt = $this->api->getProjectPages($config['Name']);

        if (0 === $pageStmt->num_rows ) {
            wfLogToFile("No pages found for \"$project\"");
            return;
        }

        if ($pageStmt->num_rows > 1000000 ) { // See T164178
            wfLogToFile('Error: ' . $project . ' is too large. Skipping.');
            return;
        }

        $startDate = date('Ymd00', $this->start);
        $endDate = date('Ymd00', $this->end);

        [ $data, $totalViews ] = $this->api->getMonthlyPageviewsAndAssessments(
            $pageStmt,
            $startDate,
            $endDate,
            $config['Limit']
        );

        /**
 * @var DateTime $startDateTime 
*/
        $startDateTime = DateTime::createFromFormat('Ymd00', $startDate);
        $endDateTime = DateTime::createFromFormat('Ymd00', $endDate);
        $daysInMonth = $endDateTime->diff($startDateTime)->days + 1;

        // Add in averages.
        foreach ( $data as $title => $datum ) {
            $data[$title]['avgPageviews'] = floor($datum['pageviews'] / $daysInMonth);
        }

        $hasLeadSection = $this->api->hasLeadSection($config['Report']);

        // Generate and return wikitext.
        $output = $this->twig->render(
            'report.wikitext.twig', [
            'hasLeadSection' => $hasLeadSection,
            'wiki' => $this->wiki,
            'start' => $this->start,
            'end' => $this->end,
            'project' => $project,
            'pages' => $data,
            'totalViews' => $totalViews,
            'category' => $this->api->getWikiConfig()['category'],
            ] 
        );

        $this->api->setText($config['Report'], $output, $this->i18n->msg('edit-summary'), (int)$hasLeadSection);
    }

    /**
     * Update index page for the bot, listing each WikiProject, its report
     * and when it was last updated.
     */
    public function updateIndex() 
    {
        $projectsConfig = $this->api->getJSONConfig();

        foreach ( $projectsConfig as $project => $config ) {
            $projectsConfig[$project]['Updated'] = $this->api->getBotLastEditDate($config['Report']);
        }

        // Generate and return wikitext.
        $output = $this->twig->render(
            'index.wikitext.twig', [
            'projects' => $projectsConfig,
            'configPage' => $this->api->getWikiConfig()['config'],
            ] 
        );

        $this->api->setText($this->api->getWikiConfig()['index'], $output, $this->i18n->msg('edit-summary'));
    }

    /**
     * Validate the WikiProject config entry, including the syntax within the JSON config,
     * whether target page is the correct namespace, and if the target page actually exists.
     *
     * @param  string $project The WikiProject.
     * @param  array  $config  The WikiProject's configuration.
     * @return bool true if valid, false otherwise.
     */
    private function validateProjectConfig( $project, $config ) 
    {
        // Check that config values are set
        if (!isset( $config['Name'] ) || !isset( $config['Limit'] ) || !isset( $config['Report'] ) ) {
            wfLogToFile('Error: Incomplete data in config for ' . $project . '. Skipping.');
            return false;
        }

        // Don't allow writing report to main namespace. There is no easy way to grab the
        // namespace ID so just reject titles that don't have a colon in them.
        if (false === strpos($config['Report'], ':') ) {
            wfLogToFile("Error: $project is configured to write to the mainspace. Skipping.");
            return false;
        }
        wfLogToFile('Beginning to process: ' . $config['Name']);

        // Check the project exists
        if (!$this->api->doesTitleExist($project) ) {
            wfLogToFile('Error: Project page for ' . $config['Name'] . ' does not exist! Skipping.');
            return false;
        }

        return true;
    }
=======
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
		$this->addTwigFunctions();

		// Setup Intuition.
		$this->i18n = new Intuition( 'popular-pages' );
		$this->i18n->registerDomain( 'popular-pages', __DIR__ . '/messages' );
		$this->i18n->setLang( explode( '.', $wiki )[0] );
	}

	private function addTwigFunctions() : void {
		// msg() function for i18n.
		$msgFunc = new Twig_SimpleFunction( 'msg', function ( $key, $params = [] ) {
			$params = is_array( $params ) ? $params : [];
			return $this->i18n->msg(
				$key, [ 'domain' => 'popular-pages', 'variables' => $params ]
			);
		} );
		$this->twig->addFunction( $msgFunc );

		// Fetching assessments info, case-insensitive.
		$assessmentFunc = new Twig_SimpleFunction(
		    'assessments', function (
				string $type, string $value
			) {
				$dataset = $this->api->getAssessmentConfig()[$type];
				foreach ( $dataset as $key => $values ) {
					if ( strtolower( $value ) === strtolower( $key ) ) {
						return $values;
					}
				}
				return $dataset['Unknown'];
			   } );
		$this->twig->addFunction( $assessmentFunc );

		// Add ucfirst() (Twig's capitalize() will make the other chars lowercase).
		$ucfirstFunc = new Twig_SimpleFilter( 'ucfirst', function ( string $value ) {
		    return ucfirst( $value );
		} );
		$this->twig->addFilter( $ucfirstFunc );
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
	 */
	private function processProject( $project, $config ) : void {
		/** @var mysqli_result $pageStmt */
		$pageStmt = $this->api->getProjectPages( $config['Name'] );

		if ( 0 === $pageStmt->num_rows ) {
			wfLogToFile( "No pages found for \"$project\"" );
			return;
		}

		if ( $pageStmt->num_rows > 1000000 ) { // See T164178
			wfLogToFile( 'Error: ' . $project . ' is too large. Skipping.' );
			return;
		}

		$startDate = date( 'Ymd00', $this->start );
		$endDate = date( 'Ymd00', $this->end );

		[ $data, $totalViews ] = $this->api->getMonthlyPageviewsAndAssessments(
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

		$hasLeadSection = $this->api->hasLeadSection( $config['Report'] );

		// Generate and return wikitext.
		$output = $this->twig->render( 'report.wikitext.twig', [
			'hasLeadSection' => $hasLeadSection,
			'wiki' => $this->wiki,
			'start' => $this->start,
			'end' => $this->end,
			'project' => $project,
			'pages' => $data,
			'totalViews' => $totalViews,
			'category' => $this->api->getWikiConfig()['category'],
		] );

		$this->api->setText( $config['Report'], $output, $this->i18n->msg('edit-summary'), (int)$hasLeadSection );
	}

	/**
	 * Update index page for the bot, listing each WikiProject, its report
	 * and when it was last updated.
	 */
	public function updateIndex() {
		$projectsConfig = $this->api->getJSONConfig();

		foreach ( $projectsConfig as $project => $config ) {
			$projectsConfig[$project]['Updated'] = $this->api->getBotLastEditDate( $config['Report'] );
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
>>>>>>> parent of e1a8fca... Update ReportUpdater.php
}
