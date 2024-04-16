Popular Pages
=============
![CI](https://github.com/wikimedia/popularpages/workflows/CI/badge.svg)

A tool for generating monthly popular pages reports for WikiProjects.

See [the tool's homepage](https://wikitech.wikimedia.org/wiki/Tool:Popular_Pages) for more information.

##### Setting up the bot
* Copy `config.ini.example` to `config.ini` and add the bot's username and password.
* Run `composer install` from the command line.
* Either run the bot manually or set up a cron job to run it once a month.

##### How does the bot work?
* Fetches config from [on wiki config page](https://en.wikipedia.org/wiki/User:Community_Tech_bot/Popular_pages_config.json) (example for English Wikipedia).
* Runs on all projects listed in the config, compiling pageviews statistics for the previous month.
* Updates [the info page on wiki](https://en.wikipedia.org/wiki/User:Community_Tech_bot/Popular_pages) with the timestamp of page update.

##### App structure:
* **`bin/checkReports.php`**: Starting point for a new bot run. Gets config info for all projects not already updated for past month and then passes it to `ReportUpdater`.
* **`bin/generateReport.php`**: Script to manually regenerate a report for a single project.
* **`bin/generateIndex.php`**: Script for generating the index page.
* **`src/ReportUpdater.php`**: The file that actually updates projects.
* **`src/WikiRepository.php`**: Contains all helper functions for dealing with the API and Database (bit of a misnomer).
* **`src/PageviewsRepository.php`**: Contains all helper functions for dealing with the Pageviews API.
* **`src/Logger.php`**: Responsible for logging updates to the files in the `logs` directory.

##### Setting up a new wiki
* Make sure the translations for the language are in the /messages directory.
* Add the configuration for the project in `wikis.yaml`. This indicates where the WikiProjects configuration and index pages live.
* Add your WikiProjects configuration on the corresponding on-wiki JSON page.
* Add a new cron job for the wiki, such as `0 0 1 * * checkReports.php en.wikipedia`.
