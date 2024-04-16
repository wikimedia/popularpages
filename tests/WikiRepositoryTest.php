<?php
/**
 * This file contains only the WikiRepositoryTest class.
 */

/** Require dependencies and set the timezone to UTC (may vary on local machines). */

use PHPUnit\Framework\TestCase;

require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set( 'UTC' );

/**
 * Tests for functions in WikiRepository.php.
 * @covers WikiRepository
 */
class WikiRepositoryTest extends TestCase {

	/** @var WikiRepository The WikiRepository instance. */
	protected WikiRepository $wikiRepository;

	/**
	 * Constructor for the WikiRepositoryTest.
	 */
	public function __construct() {
		parent::__construct();
		$this->wikiRepository = new WikiRepository();
	}

	/**
	 * Test the doesTitleExist function
	 */
	public function testDoesTitleExist(): void {
		$this->assertTrue( $this->wikiRepository->doesTitleExist( 'Barack Obama' ) );
		$this->assertTrue( $this->wikiRepository->doesTitleExist( 'Mickey Mouse' ) );
		$this->assertFalse( $this->wikiRepository->doesTitleExist( 'DumDeeDooDum' ) );
		$this->assertFalse( $this->wikiRepository->doesTitleExist( 'Invalid title' ) );
	}

	/**
	 * Test the hasLeadSection function
	 */
	public function testHasLeadSection(): void {
		$this->assertTrue(
			$this->wikiRepository->hasLeadSection(
				'Wikipedia:WikiProject Medicine/Popular pages'
			)
		);
		$this->assertFalse(
			$this->wikiRepository->hasLeadSection(
				'User:Community Tech bot/Popular pages config.json'
			)
		);
	}

	/**
	 * Test the getProjectPages function
	 *
	 * Check for presence of two titles in Disney project and the presence
	 * of their importance and class params.
	 */
	public function ertestGetProjectPages(): void {
		$pages = $this->wikiRepository->getProjectPages( 'Disney' );
		$this->assertArrayHasKey( 'Walt Disney', $pages );
		$this->assertArrayHasKey( 'Pixar', $pages );
		$this->assertArrayHasKey( 'importance', $pages['Pixar'] );
		$this->assertArrayHasKey( 'class', $pages['Walt Disney'] );
	}

	/**
	 * Test getMonthlyPageviews function
	 *
	 * Test for pageviews for the month of February for three known pages
	 */
	public function ertestGetMonthlyPageviews(): void {
		$pages = [ 'Star Wars', 'Zootopia', 'The Lion King' ];
		$expectedResult = [
			'Star Wars' => 517930,
			'Zootopia' => 313960,
			'The Lion King' => 211521
		];
		$actualResult = $this->wikiRepository->getMonthlyPageviews( $pages, '2017020100', '2017022800' );
		$this->assertEquals( $expectedResult, $actualResult );
	}

	/**
	 * Test setText function
	 *
	 * Update sandbox page with dummy text
	 */
	public function testSetText(): void {
		$result = $this->wikiRepository->setText( 'User:NKohli (WMF)/sandbox', 'Hi there! This is a test' );
		$this->assertEquals( $result['edit']['result'], "Success" );
	}
}
