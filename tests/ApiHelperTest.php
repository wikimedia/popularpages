<?php
/**
 * Tests for functions in ApiHelper.php
 */
require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set( 'UTC' );

class ApiHelperTest extends \PHPUnit_Framework_TestCase {

	protected $apiHelper;

	public function __construct() {
		parent::__construct();
		$this->apiHelper = new ApiHelper();
	}

	/**
	 * Test the getProjects function
	 * Check we get back 2000 projects when we ask for 2000 and check for presence
	 * of two key projects 'Medicine' and 'Disney'
	 */
	public function testGetProjects() {
		$projects = $this->apiHelper->getProjects( 2000 );
		$this->assertCount( 2000, $projects );
		$this->assertContains( 'Medicine', $projects );
		$this->assertContains( 'Disney', $projects );
	}

	/**
	 * Test the doesTitleExist function
	 */
	public function testDoesTitleExist() {
		$this->assertTrue( $this->apiHelper->doesTitleExist( 'Barack Obama' ) );
		$this->assertTrue( $this->apiHelper->doesTitleExist( 'Mickey Mouse' ) );
		$this->assertFalse( $this->apiHelper->doesTitleExist( 'DumDeeDooDum' ) );
		$this->assertFalse( $this->apiHelper->doesTitleExist( 'Invalid title' ) );
	}

	/**
	 * Test the doesListSectionExist function
	 */
	public function testDoesListSectionExist() {
		$this->assertTrue( $this->apiHelper->doesListSectionExist( 'Wikipedia:WikiProject Medicine/Popular pages' ) );
		$this->assertFalse( $this->apiHelper->doesListSectionExist( 'User:Community Tech bot/Popular pages config.json' ) );
	}

	/**
	 * Test the getProjectPages function
	 *
	 * Check for presence of two titles in Disney project and the presence of their importance and class params
	 */
	public function ertestGetProjectPages() {
		$pages = $this->apiHelper->getProjectPages( 'Disney' );
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
	public function ertestGetMonthlyPageviews() {
		$pages = [ 'Star Wars', 'Zootopia', 'The Lion King' ];
		$expectedResult = [
			'Star Wars' => 517930,
			'Zootopia' => 313960,
			'The Lion King' => 211521
		];
		$actualResult = $this->apiHelper->getMonthlyPageviews( $pages, '2017020100', '2017022800' );
		$this->assertEquals( $expectedResult, $actualResult );
	}

	/**
	 * Test setText function
	 *
	 * Update sandbox page with dummy text
	 */
	public function testSetText() {
		$result = $this->apiHelper->setText( 'User:NKohli (WMF)/sandbox', 'Hi there! This is a test' );
		var_dump( $result );
		$this->assertEquals( $result['edit']['result'], "Success" );
	}
}
