<?php
/**
 * @todo see if the script can detect column renames, instead of just drop and add. 
 * @todo column charsets
 * @todo constraints
 * @todo indexes that have been modifed
 * @todo primary keys that have been modified
 * @todo views
 * @todo foreign keys
 * @todo phpmyadmin erd
 */
$databases = array(
	'brian' => array( 'db'=>'brian_approve_ca', 'host'=>'localhost', 'username'=>'root', 'password'=>'34st3rn' ),
	'qa' => array( 'db'=>'brian_approve_ca_tmp', 'host'=>'localhost', 'username'=>'root', 'password'=>'34st3rn' )
);
interface databaseSchema {
	public function getTables();
	public function hasTable( $tableName );
	public function getColumns( $tableName );
	public function exportTableSchema( $tableName );
	public function exportDropTable( $tableName );
	public function getIndexes( $tableName );
}
class consoleColors{
	static public $doColor = true;
	static public $colors = array( 'grey'=>30, 'red'=>31, 'green'=>32, 'yellow'=>33, 'blue'=>34, 'purple'=>35, 'gold'=>36 );
	static public function setColor( $color=null ) {
		if( self::$doColor ) {
			if( isset( self::$colors[$color] ) )
				return "\033[".self::$colors[$color].";1m";
			else 
				return self::resetColor();
		}else
			return '';
	}
	static public function resetColor() {
		if( self::$doColor )
			return "\033[0m";
		else	
			return '';
	} 
}
class MySQLDatabaseSchema extends consoleColors implements databaseSchema{
	static public $doColor = true; 
	const eol = "\n\r";
	protected $connection = null;
	protected $tables = null;
	protected $databaseName = null;
	protected $host;
	protected $port;
	static protected $charsetmap = array();
	public function __construct( $databaseinfo ) {
		$this->databaseName = $databaseinfo->database;
		$this->host = $databaseinfo->host;
		$this->dsn  = "mysql:dbname=$this->databaseName;";
		$this->dsn .= "host=$this->host";
		if( !empty( $databaseinfo->port ) )
			$this->dsn .= ";port=".$databaseinfo->port;
		print "\n".$this->dsn."\n";
		$this->connection = new PDO( $this->dsn, $databaseinfo->username, $databaseinfo->password );
		$statement = $this->connection->prepare( "SELECT * FROM information_schema.`CHARACTER_SETS`" );
		$statement->execute();
		$rows = $statement->fetchAll( PDO::FETCH_ASSOC );
		foreach( $rows as $row ) {
			self::$charsetmap[ $row['DEFAULT_COLLATE_NAME'] ] = $row['CHARACTER_SET_NAME']; 
		}
		parent::$doColor = self::$doColor;
	}
	public function getTables() {
		if( is_null( $this->tables ) ) {
			$sql = "SHOW TABLES";
			$statement = $this->connection->prepare( $sql );
			$statement->execute();
			$this->tables = $statement->fetchall( PDO::FETCH_COLUMN );
		}
		return $this->tables;
	}
	public function getColumns( $tableName ) {
		//retrieve columns from db
			$sql = "DESC $tableName";
			$statement = $this->connection->prepare( $sql );
			$statement->execute();
			$tmps = $statement->fetchall( PDO::FETCH_ASSOC );
		//make associative array out of results
			$columns = array();
			foreach( $tmps as $tmp )
				$columns[ $tmp['Field'] ] = $tmp;
			unset( $tmps, $tmp );
		return $columns;
	}
	public function getOptions( $tableName ) {
		$statement = $this->connection->prepare( 'SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?' );
		$statement->execute( array( $this->databaseName, $tableName ) );
		$options = $statement->fetch( PDO::FETCH_ASSOC );
		return $options;
	}
	public function exportTableSchema( $tableName ) {
		$sql  = self::eol. self::setColor( 'grey' ) . "#export table schema $tableName" . self::resetColor() .self::eol;
		$sql .= self::setColor( 'green' );
		$sql .= "CREATE TABLE IF NOT EXISTS `$tableName` (".self::eol; 
		foreach( $this->getColumns($tableName) as $column ) {
			$sql .= "\t`".$column['Field']."` ". $column['Type'] . "";
			if( $column['Null'] == 'YES' )
				$sql .= " DEFAULT NULL";
			else if( $column['Null'] ) 
				$sql .= " NOT NULL";
			if( !empty( $column['Default'] ) ) {
				$sql .= " DEFAULT ".$column['Default'];
			}
			if( !empty( $column['Extra'] ) )
				$sql .= " ".strtoupper( $column['Extra'] );
			$sql .= ",". self::eol;
			if( $column['Key'] == 'PRI' )
				$pk = $column['Field'];
		}
		$indexes = $this->exportIndexes( $tableName );
		if( isset( $pk ) ) {
			//@todo compound primary keys
			$sql .= "\tPRIMARY KEY (`$pk`)";
			if( !empty( $indexes ) )
				$sql .= ','; 
			$sql .= self::eol;
		}
		$sql .= $indexes;
		//@todo foreign keys
		$sql .= ")";
		$options = $this->getOptions( $tableName );
		//set details
		$sql .= " ENGINE=".$options['ENGINE'];
		$sql .= " DEFAULT CHARSET=".self::$charsetmap[ $options['TABLE_COLLATION'] ];
 		$sql .= " AUTO_INCREMENT=".$options['AUTO_INCREMENT'];
 		$sql .= ' ;';
		$sql .= self::eol;
		$sql .= self::resetColor();
		return $sql;
	}
	public function getIndexes( $tableName ) {
		$statement = $this->connection->prepare( "SELECT * FROM information_schema.STATISTICS 
			WHERE `TABLE_SCHEMA` = :databaseName AND 
			`TABLE_NAME` = :tableName AND INDEX_NAME != 'PRIMARY' 
			ORDER BY `INDEX_NAME` ASC, `SEQ_IN_INDEX` ASC" );
		$statement->execute( array( 'databaseName'=>$this->databaseName, 'tableName'=>$tableName ) );
		$tmp = $statement->fetchall( PDO::FETCH_ASSOC );
		return $tmp;		
	}
	public function exportIndexChanges( $tableName, $indexesInB ) {
		$sql = self::setColor( 'yellow' );
		$indexes = $this->getIndexes($tableName);
		$finishedIndexes = array();
		$a = array();
		$b = array();
		//get a list of all index names in A and B
			foreach( $this->getIndexes( $tableName ) as $k=>$i ) {
				if( !isset( $a[ $i['INDEX_NAME'] ] ) )
					$a[$i['INDEX_NAME']] = array();
				$a[$i['INDEX_NAME']][] = $i;
			}
			foreach( $indexesInB as $k=>$i ) {
				if( !isset( $b[$i['INDEX_NAME']] ) )
					$b[$i['INDEX_NAME']] = array();
				$b[$i['INDEX_NAME']][] = $i;
			}
		//see what indexes have changed, or been added
			foreach( $a as $indexName=>$array ) {
				if( !isset( $b[ $indexName ] ) ) {
					//add index to b
					$columns = array();
					foreach( $array as $k=>$indexRecord ) {
						$columns[] = $indexRecord['COLUMN_NAME'];
						$indexName = $indexRecord['INDEX_NAME'];
					}
					$columns = "`".implode( '`,`', $columns )."`";
					$sql .= "ALTER TABLE `$tableName` ADD INDEX `$indexName` ( $columns );\n"; 					
				}else{
					//look for changes in the index
				}
				unset( $b[ $indexName ] ); //makes it easier to look for indexes in B but not A
			}
		//remove unwanted indexes in b
			foreach( $b as $indexName=>$index ) {
				//remove index
				$sql .= "ALTER TABLE `$tableName` DROP INDEX $indexName;" . self::eol;
			}
		$sql .= self::resetColor();
		return $sql;
	}
	protected function exportIndexes( $tableName ) {
		$rows = $this->getIndexes($tableName);
		$sql = '';
		$last = null;
		foreach( $rows as $row ) {
			if( $last == $row['INDEX_NAME'] ) { 
				//this is really hackish, but it's a solid case of "good enough for now"
				$sql  = substr( $sql, 0, strlen( $sql )-strlen( "), ".self::eol ) );
				$sql .= ", `{$row['COLUMN_NAME']}` ), ".self::eol;
			}else
				$sql .= "\tKEY `".$row['INDEX_NAME']."` (`".$row['COLUMN_NAME']."`), ".self::eol;
			$last = $row['INDEX_NAME'];
		}
		if( !empty( $sql ) ) {
			$sql = substr( $sql, 0, strlen( $sql ) - strlen( ", ".self::eol ) );
			$sql .= self::eol;
		}
		return $sql;
	}
	public function exportTableChanges( $tableName, $columsnIn_b ) {
		$sql = '';
		$columns = $this->getColumns( $tableName );
		foreach( $columns as $column=>$columnInfo ) {
			if( isset( $columsnIn_b[ $column ] ) ) {
				//check for type changes
				if( $columsnIn_b[ $column ]['Type'] != $columnInfo['Type'] ) {
					$sql .= "ALTER TABLE `$tableName` CHANGE `$column` `$column` {$columnInfo['Type'] }";
					if( $columnInfo['Null'] == 'NO' )
						$sql .= " NOT NULL";
					$sql .= self::eol;
				}
			}else{
				//create alter for missing column
				$sql .= "ALTER TABLE `$tableName` ADD `$column` ".$columnInfo['Type'];
				$pos = array_search( $column, array_keys( $columns ) );
				$values = array_values( $columns );
				if( $pos == 0 ) 
					$sql .= " FIRST";
				else
					$sql .= " AFTER `".$values[$pos-1]["Field"]."`";
				if( !empty( $columnInfo['Default'] ) )
					$sql .= " DEFAULT ".PDO::$this->connection->quote( $columnInfo['Default'] );
				$sql .= ";" . self::eol;
			}
		}
		foreach( $columsnIn_b as $column=>$columnInfo ) {
			if( !key_exists( $column, $columns ) ) {
				$sql .= "ALTER TABLE `$tableName` DROP `$column`;".self::eol;
			}
		}
		if( !empty( $sql ) ) {
			$sql = self::eol . self::setColor('grey') . "#schema changes for $tableName" . self::eol . self::setColor( 'gold' ) . $sql . self::resetColor();
		}
		return $sql;
	}
	public function hasTable( $tableName ) {
		return in_array( $tableName, $this->getTables() );
	}
	public function exportDropTable( $tableName ) {
		$sql  = self::setColor( 'red' );
		$sql .= self::eol."#DROP TABLE `$tableName`;".self::eol;
		$sql .= self::resetColor();
		return $sql;
	}
	public function exportPHPMyAdminERD( $b ) {
		$tables = array( 'pma_designer_coords', 'pma_relation' /* 'pma_pdf_pages'/*, 'pma_table_coords', 'pma_table_info'*/ );
		$sql = '';
		foreach( $tables as $table ) {
			if( $table != 'pma_relation' ) { 
				$subsql = "";
				$query = "SELECT * FROM `phpmyadmin`.`$table` WHERE `db_name` = ?";
				$statement = $this->connection->prepare( $query );
				$statement->execute( array( $this->databaseName ) );
				$rows = $statement->fetchall( PDO::FETCH_ASSOC );
				foreach( $rows as $row ) {
					$row['db_name'] = $b->databaseName;
					$values = "'".implode( "','", array_values( $row ) )."'"; 
					$subsql .= "INSERT INTO `phpmyadmin`.`$table` VALUES( $values );".self::eol;
				}
				//add delete statement
				$subsql = trim( $subsql );
				if( !empty( $subsql ) ) {
					$subsql = self::eol."DELETE FROM `phpmyadmin`.`$table` WHERE `db_name` = '$b->databaseName'; ".self::eol . $subsql ;
					$sql .= $subsql;
				}
			}else{
				$subsql = "";
				$query = "SELECT * FROM `phpmyadmin`.`pma_relation` WHERE `master_db` = ? AND foreign_db = ?";
				$statement = $this->connection->prepare( $query );
				$statement->execute( array( $this->databaseName, $this->databaseName ) );
				$rows = $statement->fetchall( PDO::FETCH_ASSOC );
				foreach( $rows as $row ) {
					$row['master_db'] = $b->databaseName;
					$row['foreign_db'] = $b->databaseName;
					$values = "'".implode( "','", array_values( $row ) )."'"; 
					$subsql .= "INSERT INTO `phpmyadmin`.`$table` VALUES( $values );".self::eol;
				}
				//add delete statement
				$subsql = trim( $subsql );
				if( !empty( $subsql ) ) {
					$subsql = self::eol."DELETE FROM `phpmyadmin`.`pma_relation` WHERE `master_db` = '$b->databaseName' AND `foreign_db` = '$b->databaseName'; ".self::eol . $subsql ;
					$sql .= $subsql;
				}
			}
		}
		if( empty( $sql ) )
			return '';
		return $sql.self::eol;
	}
}
class schemaChecker {
	protected $a;
	protected $b;
	public function __construct( $source, $dest ) {
		$this->a = new MySQLDatabaseSchema( $source );
		$this->b = new MySQLDatabaseSchema( $dest ); 
	}
	public function checkSchema() {
		$sql = '';
		foreach( $this->a->getTables() as $table ) {
			if( $this->b->hasTable( $table ) ) {
				//check table schema
				$sql .= $this->a->exportTableChanges( $table, $this->b->getColumns( $table ) );
				$sql .= $this->a->exportIndexChanges( $table, $this->b->getIndexes( $table ) );
			}else{
				//export table schema from a
				$sql .= $this->a->exportTableSchema( $table );
			}
		}
		foreach( $this->b->getTables() as $table ) {
			if( $this->a->hasTable( $table ) ) {
				//do nothing, it was already checked just above
			}else
				$sql .= $this->b->exportDropTable( $table );
		}
		$sql = trim( $sql );
		if( empty( $sql ) )
			echo "#no schema changes \n";
		else
			echo $sql . "\n";
	}
	public function exportPHPMyAdminERD() {
		return $this->a->exportPHPMyAdminERD( $this->b );
	}
}
class config{
	public $colorOutput = '1';
	public $profiles = null;
	public $sync_phpmyadmin_erd = '1';
	public function __construct( $obj=null ) {
		if( is_object( $obj ) ) {
			foreach( $obj as $k=>$v ) {
				if( key_exists( $k, $this ) )
					$this->$k = $v;
			}
		}
		if( is_null( $this->profiles ) ) {
			$this->profiles = new stdClass();
			$this->profiles->a = new profile();
			$this->profiles->b = new profile();
		}
		foreach( $this->profiles as $k=>$v )
			$this->profiles->$k = new profile( $v );
	}
}
class profile{
	public $host = '';
	public $username = '';
	public $password = '';
	public $database = '';
	public $port = '';
	public $engine = 'mysql';
	public function __construct( $obj=null ) {
		if( is_object( $obj ) ) {
			foreach( get_object_vars( $obj ) as $k=>$v ) {
				if( key_exists( $k, $this ) )
					$this->$k = $v;
			}
		}
	}
}
class hasJSONConfigFile {
	public $config = null;
	public function __construct() {
		$this->loadConfig();
	}
	protected function loadConfig() {
		$file = $this->getFilePath();
		if( file_exists( $file ) ) {
			$json = file_get_contents( $file );
			$this->config = new config( json_decode( $json ) );
		}else{
			//create new config file and save for next time
			$this->config = new config();
			$this->saveConfig();
		}
	}
	private function saveConfig() {
		file_put_contents( $this->getFilePath(), json_encode( $this->config ) );
	}
	private function getFilePath() {
		return "/home/".get_current_user()."/.schemaCheck.json";
	}
}
class App extends hasJSONConfigFile {
	public function __construct() {
		parent::__construct();
		$argv = $GLOBALS['argv'];
		if( count( $argv ) !== 3 ) {
			print "\n";
			print "php schemaCheck.php sourceProfile targetProfile \n";
			print "===============================================\n";
			exit(0);
		}
		$source = $argv[1];
		$target = $argv[2];
		if( !isset( $this->config->profiles->$source ) )
			die( "Source profile does not exist\n" );
		if( !isset( $this->config->profiles->$target ) )
			die( "Target profile does not exist\n" );
		$schemaChecker = new schemaChecker( $this->config->profiles->$source, $this->config->profiles->$target );
		if( isset( $this->config->colorOutput ) )
			consoleColors::$doColor = $this->config->colorOutput == '1';
		print $schemaChecker->checkSchema();
		if( $this->config->sync_phpmyadmin_erd == '1' )
			print $schemaChecker->exportPHPMyAdminERD();
	}
}
$app = new App();
