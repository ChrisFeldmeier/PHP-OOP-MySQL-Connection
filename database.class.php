<?php
/** 
*	@name: 	db
*	@description: connect to database and get/set data
*	@author: christoph feldmeier - 2013-08-15
**/
namespace db {


	interface iDB
	{
		public static function connect();
		public static function close();
		public static function query($query, $objs);
		public static function DatabaseMonitor();
		public static function objectExists($query, $objs);
		public static function escape_string($string);
		public static function insert_id();
		public static function getObject($query, $objs);
		public static function getObjects($query, $objs);
		public static function getTable($tableName);
		public static function getTableRow($tableName, $field, $value);
		public static function getTableRows($tableName, $field, $value, $sortField, $sortDesc);
		
	}
	
	class db implements iDB {
			public static $host = null;
			public static $user = null;
			public static $password = null;
			public static $database = null;
			public static $charset = null;
			public static $db = null;
			public static $DatabaseMonitor = false; // show sql queries and time
			public static $CountQueries = 0;
			public static $queries = array();
			
			public function __construct($host, $user, $password, $database, $charset = 'utf8') {
				self::$host 		= $host;
				self::$user 		= $user;
				self::$password 	= $password;
				self::$database		= $database;
				self::$charset 		= $charset;
				self::connect();
	
				}
				
			public function __destruct() {
			
				self::DatabaseMonitor();
	
			}
			
			//connect to the database
			public static function connect() {
				self::$db = new \mysqli(self::$host, self::$user , self::$password, self::$database); 
				if (mysqli_connect_errno()) {
					throw new \Exception('connection failed: ' . mysqli_connect_error());
				}
				self::$db->set_charset(self::$charset);
		
			}
			
			//close the connection
			public static function close() {
			
				if (self::$db) {
					self::$db->close();
				}
			}
	
			/**
			* Run a query and return the result
			* @param {string} query to run (with '?' for values)
			* @param {array} values to execute in prepared statement (optional)
			* @return {resource} result
			*/
			public static function query($query, $objs = array()) {
				if (!self::$db) self::connect();
				
				if(self::$DatabaseMonitor): $QueryTimeStart = microtime(true); endif;
				
				$objs = (array)$objs; //automagically cast single values into an array
				$statement = self::$db->prepare($query);
				
				if (!$statement) {
					throw new \Exception('Query failed: ' . self::$db->error);
				}
				
				//go through all of the provided objects and bind them
				$types = array();
				$values = array();
				
				if (count($objs)>0) {
					foreach ($objs as $obj) {
						//get the object type and translate it ready for bind parameter
						$type = gettype($obj);
						
						switch ($type) {
							case 'boolean': case 'integer':
								$types[] = 'i';
								$values[] = intval($obj);
								break;
							case 'double':
								$types[] = 'd';
								$values[] = doubleval($obj);
								break;
							case 'string':
								$types[] = 's';
								$values[] = (string)$obj;
								break;
							case 'array': case 'object':
								$types[] = 's';
								$values[] = json_encode($obj);
								break;
							case 'resource': case 'null': case 'unknown type': default:
								throw new \Exception('Unsupported object passed through as query prepared object!');
						}
					}
					
					$params = makeRefArr($values);
					array_unshift($params, implode('', $types));
					call_user_func_array(array($statement, 'bind_param'), $params);
				}
				
				if(self::$DatabaseMonitor)
				{
					self::$CountQueries++;
					$QueryTimeEnd = microtime(true);
					self::$queries[] = array(
					'number' => self::$CountQueries,
					'query' => $query,
					'time' => (number_format($QueryTimeEnd-$QueryTimeStart,5)*1000)
					);
				}
								
				if (!$statement->execute()) {
					return null;
				} else {
					$statement->store_result();
					return $statement;
				}
				
				
			}
			
		/**
		* shows database monitor
		* @param nothing
		* @return nothing
		* show databasemonitor if $DatabaseMonitor = true
		*/	
		public static function DatabaseMonitor()
		{
				// Show DatabaseMonitor on every bottom page
				if(self::$DatabaseMonitor)
				{
					$total_queries = 0;
					foreach(self::$queries as $query) {$total_queries+= $query['time'];};
							echo '
					<div align="center">
					<table style="border: 1px solid #000;" rules="all">
					<caption>queries total: '.self::$CountQueries.' total duration:  '.$total_queries.' ms</caption>
					<tr>
						<th>Count</th>
						<th>Query</th>
						<th width="70">Time</th>
					</tr>';
					foreach(self::$queries as $query) : 
					echo '<tr>
						<td>'.$query['number'].'</td>
						<td>'.$query['query'].'</td>
						<td>'.$query['time'].' ms</td>
					</tr>';
					endforeach ;
			        '</table></div>';
				}
		}
			
			/**
			* Determine if an object exists
			* @param {string} query to run
			* @param {array} objects to use in prepare query (optional)
			* @return {boolean} object exists in database
			*/
			public static function objectExists($query, $objs = array()) {
				$statement = self::query($query, $objs);
				
				return (is_object($statement) && $statement->num_rows>0);
			}
			
			
			/**
			* escapes a string for sql query
			* @param {string}
			* @return {string} escaped string
			*/		
			public static function escape_string($string)
			{
				return self::$db->escape_string($string);
			}
			
			/**
			* get last query insert id
			* @param nothing
			* @return insert_id
			*/				
			public static function insert_id()
			{
				
				return self::$db->insert_id;
			}
			
			/**
			* Make an associative array of field names from a statement
			* @param {resource} mysqli statement
			* @return {array} field names array
			*/
			private static function getFieldNames($statement) {
				$result = $statement->result_metadata();
				$fields = $result->fetch_fields();
				
				$fieldNames = array();
				foreach($fields as $field) {
					$fieldNames[$field->name] = null;
				}
				
				return $fieldNames;
			}
			
			/**
			* Get an object from a query
			* @param {string} query to execute
			* @param {array} objects to use as the values (optional) 
			* @return {assoc} sinulatobject
			*/
			public static function getObject($query, $objs = array()) {
				$statement = self::query($query, $objs);
				
				if (!is_object($statement) || $statement->num_rows<1) {
					return null;
				}
				
				$fieldNames = self::getFieldNames($statement);
				call_user_func_array(array($statement, 'bind_result'), makeRefArr($fieldNames));
				
				$statement->fetch();
				$statement->close();
				
				return $fieldNames;
			}
			
			/**
			* Get a list of objects from the database
			* @param {string} query
			* @return {array} objects
			*/
			public static function getObjects($query, $objs = array()) {
				$statement = self::query($query, $objs);
				
				if (!is_object($statement) || $statement->num_rows<1) {
					return array();
				}
				
				$fieldNames = self::getFieldNames($statement);
				call_user_func_array(array($statement, 'bind_result'), makeRefArr($fieldNames));
				$results = array();
				
				while ($statement->fetch()) {
					$results[] = array_copy($fieldNames);
				}
				
				
				$statement->close();
				
				return $results;
			}
			
			/**
			* Get all of the data from a table
			* @param {string} table name
			* @return {array} table data
			*/
			public static function getTable($tableName) {
				if (!self::$db) self::connect();
				
				$tableName = self::$db->escape_string($tableName);
				
				return self::getObjects('SELECT * FROM `' . $tableName . '`;');
			}
			
			/**
			* Get a field from a table based on a field having a specific value
			* @param {string} table name
			* @param {string} field name
			* @param {mixed} field value
			* @return {array} table row data
			*/
			public static function getTableRow($tableName, $field, $value) {
				if (!self::$db) self::connect();
				
				$tableName = self::$db->escape_string($tableName);
				$field = self::$db->escape_string($field);
				
				return self::getObject('SELECT * FROM `' . $tableName . '` WHERE `' . $field . '` = ? LIMIT 1;', $value);
			}
			
			/**
			* Get all related rows from a table based on a field having a specific value
			* @param {string} table name
			* @param {string} field name
			* @param {mixed} field value
			* @return {array} table row data
			*/
			public static function getTableRows($tableName, $field, $value, $sortField = null, $sortDesc = false) {
				if (!self::$db) self::connect();
				
				$tableName = self::$db->escape_string($tableName);
				$field = self::$db->escape_string($field);
				
				if ($sortField == null) {
					$sortField = $field;
				} else {
					$sortField = self::$db->escape_string($sortField);
				}
				
				return self::getObjects('SELECT * FROM `' . $tableName . '` WHERE `' . $field . '` = ? ORDER BY `' . $sortField . '` ' . ($sortDesc ? 'DESC' : 'ASC') . ';', $value);
			}
		}
		
		
	/**
		* Make an array of references to the values of another array
		* Note: useful when references rather than values are required
		* @param {array} array of values 
		* @return {array} references array
		*/
		function makeRefArr(&$arr) {
			$refs = array();
			
			foreach($arr as $key => &$val) {
				$refs[$key] = &$val;
			}
			
			return $refs;
		}
	
		/**
		* Make a recursive copy of an array
		* @param {array} original array
		* @param {boolean} should the values to be cloned too?
		* @return {array} copy of source array
		*/
		function array_copy($arr, $deep= true) {
			$newArr = array();
			
			if ($deep) {
				foreach ($arr as $key=>$val) {
					if (is_object($val)) {
						$newArr[$key] = clone($val);
					} else if (is_array($val)) {
						$newArr[$key] = array_copy($val);
					} else {
						$newArr[$key] = $val;
					}
				}
			} else {
				foreach ($arr as $key=>$val) {
					$newArr[$key] = $val;
				}
			}
			
			return $newArr;
		}
	
}	
	
?>
