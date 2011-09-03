<?php
require_once "PHPUnit/Extensions/Database/TestCase.php";
DEFINE( "_DONT_EXECUTE", 1 );
require_once '../schemaCheck.php';

class testSchemaCheck extends PHPUnit_Extensions_Database_TestCase {
    // only instantiate pdo once for test clean-up/fixture load
    static private $pdo = null;
    // only instantiate PHPUnit_Extensions_Database_DB_IDatabaseConnection once per test
    private $conn = null;
	
	private $profiles = array( 
		'A'=>array( 'host'=>'localhost', 'username'=>'root', 'password'=>'pass', 'database'=>"schemaCheck_A", 'port'=>'3306', 'engine'=>'mysql' ),
		'B'=>array( 'host'=>'localhost', 'username'=>'root', 'password'=>'pass', 'database'=>"schemaCheck_B", 'port'=>'3306', 'engine'=>'mysql' ) 
	);
	
	public function setup() {
		//clear remove all tables for both connections.
		foreach( array( $this->getConnectionA(), $this->getConnectionB() ) as $x ) {
			$sql = "SHOW TABLES";
			$statement = $x->prepare( $sql );
			$statement->execute();
			$tables = $statement->fetchall( PDO::FETCH_COLUMN );
			
			foreach( $tables as $table ) {
				$statement = $x->prepare( "DROP TABLE $table;" );
				$statement->execute();
			}
		}
	}
	private function getConnectionA() {
		return new PDO('mysql:dbname=schemaCheck_A;host=localhost;port=3306', 'root', 'pass' );
	}
	private function getConnectionB() {
		return new PDO('mysql:dbname=schemaCheck_B;host=localhost;port=3306', 'root', 'pass');
	}
	protected function getDataSet() {
		 return $this->createFlatXmlDataSet( 'fixtures/blank.xml' );
	}
	private function runSQLFixture( $name ) {
		foreach( array( 'A'=>$this->getConnectionA(), "B"=>$this->getConnectionB() ) as $which=>$x ) {
			$sql = file_get_contents( "fixtures/{$name}_{$which}.sql" );
			$statement = $x->prepare( $sql );
			$statement->execute();
		}
	}
    final public function getConnection() { 
        if ($this->conn === null) {
            if (self::$pdo == null) {
                self::$pdo = $this->getConnectionA();
            }
            $this->conn = $this->createDefaultDBConnection(self::$pdo, 'schemaCheck_A');
        }
        return $this->conn;
    }
	public function testConnection() {
		$this->setup();
		
		$x = $this->getConnection()->getConnection();
		$sql = "SHOW TABLES";
		$statement = $x->prepare( $sql );
		$statement->execute();
		$tmps = $statement->fetchall( PDO::FETCH_COLUMN );
		
		$this->assertEquals( $tmps, array() );
	}
	public function testRunningFixtureWorks() {
		$this->setup();
		$this->runSQLFixture( 'a_couple_of_different_columns' );
		
		$sql = "SHOW TABLES";
		$statement = $this->getConnectionA()->prepare( $sql );
		$statement->execute();
		$tables = $statement->fetchall( PDO::FETCH_COLUMN );
		
		$this->assertEquals( $tables, array( 'test' ) );
		
		$statement = $this->getConnectionA()->prepare( $sql );
		$statement->execute();
		$tables = $statement->fetchall( PDO::FETCH_COLUMN );
		
		$this->assertEquals( $tables, array( 'test' ) );
	}
	public function testACoupleOfDifferentColumns() {
		$this->setup();
		$this->runSQLFixture( 'a_couple_of_different_columns' );
		
		$schemaChecker = new schemaChecker( (object)$this->profiles['B'], (object)$this->profiles['A'] );
		consoleColors::$doColor = false;
		$diff = $schemaChecker->checkSchema( );
		
		$first = 'ALTER TABLE `test` CHANGE `first` `first` int(11) NOT NULL;';
		$second = 'ALTER TABLE `test` CHANGE `second` `second` text NOT NULL;';
		$third = 'ALTER TABLE `test` CHANGE `third` `third` varchar(64) NOT NULL;';	

		$this->assertContains( $first, $diff );
		$this->assertContains( $second, $diff );
		$this->assertContains( $third, $diff );
	}
	public function testACoupleOfDifferentColumnsInReverse() {
		$this->setup();
		$this->runSQLFixture( 'a_couple_of_different_columns' );

		$schemaChecker = new schemaChecker( (object)$this->profiles['A'], (object)$this->profiles['B'] );
		consoleColors::$doColor = false;
		$diff = $schemaChecker->checkSchema( );
		
		$first = 'ALTER TABLE `test` CHANGE `first` `first` int(10) NOT NULL;';
		$second = 'ALTER TABLE `test` CHANGE `second` `second` varchar(64) NOT NULL;';
		$third = 'ALTER TABLE `test` CHANGE `third` `third` char(64) NOT NULL;';	
		
		$this->assertContains( $first, $diff );
		$this->assertContains( $second, $diff );
		$this->assertContains( $third, $diff );
	}
	public function testDropColumn() {
		$this->setup();
		$this->runSQLFixture( 'add_column_drop_column' );

		$schemaChecker = new schemaChecker( (object)$this->profiles['B'], (object)$this->profiles['A'] );
		consoleColors::$doColor = false;
		$diff = $schemaChecker->checkSchema( );
		
		$expected = "ALTER TABLE `test` DROP `third`;";
		
		$this->assertContains( $expected, $diff );
	}
	public function testAddColumn() {
		$this->setup();
		$this->runSQLFixture( 'add_column_drop_column' );

		$schemaChecker = new schemaChecker( (object)$this->profiles['A'], (object)$this->profiles['B'] );
		consoleColors::$doColor = false;
		$diff = $schemaChecker->checkSchema( );
		
		$expected = "ALTER TABLE `test` ADD `third` char(64) AFTER `second`;";
		
		$this->assertContains( $expected, $diff );	
	}
	public function testOneChangeAndOneDrop() {
		$this->setup();
		$this->runSQLFixture( 'change_and_drop' );
		
		$schemaChecker = new schemaChecker( (object)$this->profiles['A'], (object)$this->profiles['B'] );
		consoleColors::$doColor = false;
		$diff = $schemaChecker->checkSchema( );
		
		$change = "ALTER TABLE `test` CHANGE `first` `first` int(11) NOT NULL;"; 
		$drop = "ALTER TABLE `test` DROP `third`;";
		
		$this->assertContains( $change, $diff );
		$this->assertContains( $drop, $diff );
	}
}
?>