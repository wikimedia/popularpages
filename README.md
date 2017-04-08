Popular Pages
=============
[![Build Status](https://travis-ci.org/wikimedia/popularpages.svg?branch=master)](https://travis-ci.org/wikimedia/popularpages)

A tool for generating popular pages reports for WikiProjects.

See [the tool's homepage](https://wikitech.wikimedia.org/wiki/Tool:Popular_Pages) for more information.

##### How does the bot work?
* Fetch config from [on wiki config page](https://en.wikipedia.org/wiki/User:Community_Tech_bot/Popular_pages_config.json).
* Run the bot on all of the projects listed in config. The bot completes a run once every month.
* Update [the info page on wiki](https://en.wikipedia.org/wiki/User:Community_Tech_bot/Popular_pages) with the timestamp of page update.

##### App structure:
* **`checkReports.php`**: Starting point for a new bot run. Updates all projects irrespective of last update timestamp.
* **`recheckReports.php`**: Starting point for running a subsequent bot run, to cover any projects that were not updated in the initial bot run. It gets an array of all projects not already updated for past month and then passes it to `UpdateReports.php`.
* **`UpdateReports.php`**: The file that actually updates projects. Takes list of projects to update as an optional param, else updates all projects.
* **`ApiHelper.php`**: Contains all helper functions for dealing with the Api and Database (bit of a misnomer).
* **`Logger.php`**: Responsible for logging updates to `log.txt`.
* **`generateReport.php`**: Script to manually regenerate a report for a single project.
* **`generateIndex.php`**: Script for generating the index page.
