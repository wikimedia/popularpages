Popular Pages
=============
[![Build Status](https://travis-ci.org/wikimedia/popularpages.svg?branch=master)](https://travis-ci.org/wikimedia/popularpages)

A tool for generating popular pages reports for WikiProjects.

See [the tool's homepage](https://wikitech.wikimedia.org/wiki/Tool:Popular_Pages) for more information.

##### Setting up the bot
* Add the bot's username and password to the `config.ini.example` file and rename it `config.ini`.
* Run `composer install` from the command line.

##### How does the bot work?
* Fetches config from [on wiki config page](https://en.wikipedia.org/wiki/User:Community_Tech_bot/Popular_pages_config.json).
* Runs on all of the projects listed in the config. The bot completes a run once every month.
* Updates [the info page on wiki](https://en.wikipedia.org/wiki/User:Community_Tech_bot/Popular_pages) with the timestamp of page update.

##### App structure:
* **`checkReports.php`**: Starting point for a new bot run. Gets config info for all projects not already updated for past month and then passes it to `ReportUpdater`.
* **`ReportUpdater.php`**: The file that actually updates projects.
* **`ApiHelper.php`**: Contains all helper functions for dealing with the API and Database (bit of a misnomer).
* **`Logger.php`**: Responsible for logging updates to `log.txt`.
* **`generateReport.php`**: Script to manually regenerate a report for a single project.
* **`generateIndex.php`**: Script for generating the index page.
