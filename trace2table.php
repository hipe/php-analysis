<?php
/* 
this is a standalone file to be run from the commandline 
that imports a tab- delimited xdebug trace file into the database,
creating a table if necessary.

This might be useful if you want to run database-like queries against the output of a machine-readable xdebug trace.

It is basically a wrapper around the mysql LOAD DATA INFILE command with 
smarts for creating the table and erasing the data

The table it creates will match the "structure" of the csv file.

Note: 
  this uses "load data infile local" for now, that is the tab file must be 
  on the same server as the database *client* (not server).  this could be changed.
*/


/*

	# AWESOME QUERY DELTA SUM
	select t1.function, sum( t2.memory - t1.memory ) as deltaSum, count( t1.function)  from _trace as t1 
	join _trace as t2 on t1.function_num = t2.function_num and t2.is_exit = 1
	where t1.is_exit = 0
	group by ( t1.function )
	order by deltaSum desc


*/

error_reporting( E_NOTICE | E_ALL );


// ** clasess ** 

class StdLogger {
  function out( $msg ){
    CliCommon::stdout( $msg );
  }
  function err( $msg ){
    CliCommon::stderr( $msg );
  }
}

class QueryException extends Exception {}

/* 
the bulk of this is logic to build the structure of the table,
and a wrapper around "load data infile"
*/
class TableFromCsvBuilderPopulator{
  
  protected $createPrimaryKey = false;  
  
  public function __construct( $args ){
    $this->logger       = $args['logger'];
    $this->separator    = $args['separator'];
    $this->csv_filename = $args['csv_filename'];
    $this->username = $args['connection_parameters']['username'];
    $this->password = $args['connection_parameters']['password'];
    $this->database = $args['connection_parameters']['database'];
    $this->table_name   = $args['table_name'];
    $this->fp = null; 
    $this->out_sql_filepath = "temp.".$this->table_name.".sql";
    $this->primaryKeyName = '_id'; // something not in the tab file
  }
  
  public function setDoPrimaryKey( $x ){
    $this->createPrimaryKey = true;
  }
  
  public function get_table_name(){
    return $this->table_name;
  }
  
  public function table_exists(){
    $q = "show tables where Tables_in_".$this->database." = '$this->table_name'";
    $rs = mysql_query( $q );
    return (bool) mysql_num_rows( $rs ); 
  }
  
  public function get_numrows_in_table(){
    $q = "select count(*) from `$this->table_name`";
    $rs = mysql_query( $q );
    $row = mysql_fetch_row( $rs );
    return $row[0];
  }
  
  public function create_table() {
		$this->run_sql("
		  create table $this->table_name (
				level integer not null,
				function_num integer not null,
				is_exit integer (1) not null,
				time float not null,
				memory integer not null,
				function varchar(255),
				is_user_defined integer (1) not null,
				included_fn varchar(255),
				filename varchar(255),
				line_no integer
			)
		");
		$this->run_sql( "alter table $this->table_name add index ( function_num ) " );
    $this->logger->out( "created table $this->table_name.\n" );
  }

  public function drop_table(){
    $q = "drop table `".$this->table_name."`";
    $result = $this->run_sql( $q );
    $this->logger->out( "dropped table $this->table_name.\n" );
  }

    
  public function delete_all_from_table() {
    $q = "delete from `".$this->table_name."`";
    $this->run_sql( $q );
    $num = mysql_affected_rows();
    $this->logger->out( "deleted $num rows from $this->table_name.\n" );
    if ($this->createPrimaryKey) {
      $this->run_sql( "alter table `$this->table_name` drop column 
        $this->primaryKeyName
      ");
      $this->logger->out( "dropped $this->primaryKeyName column from $this->table_name.\n" );      
    }
  }
    
  public function populate_table() {
    $this->run_sql( $this->get_load_data_query() );
    $this->pkCheck();
  }
  
  public function try_populate_table_workaround() {
    $fn = "temp.load_data_sql_statement.sql";
    if (false === file_put_contents($fn, $this->get_load_data_query())){
      $this->fatal( "couldn't open tempfile: $fn" );
    }
    $passwordArg = (''===$this->password) ? '' : (" -p=".$this->password);
    $command = "mysql ".$this->database." --local_infile=1 -u ".$this->username.$passwordArg." < $fn ;$this->password";
    $results = shell_exec( $command );
    if (NULL!==$results){ $this->fatal( "expected NULL had: '$results'"); }
    // we might want to keep the below file around for debugging purposes
    if (!unlink($fn)){ $this->fatal("couldn't erase file: $fn"); }
    $this->pkCheck();
  }
  
  private function pkCheck(){
    if (!$this->createPrimaryKey) return;
    $this->run_sql( "alter table `$this->table_name` 
      add column`$this->primaryKeyName` int(11) not null auto_increment primary key first");
    $this->logger->out( "added primary key column to table\n" );    
  }
      
  // ---------------- Protected Methods ----------------------
  
  protected function get_load_data_query(){
    switch( $this->separator ){
      case "\t": $terminator = '\\t'; break;
      case ',' : $terminator = ','; break;
      default:  $this->fatal( "we need to code for this terminator: \"$this->separator\"" );
    }    
    return "
    load data low_priority local infile '$this->csv_filename'
    into table `$this->table_name`
    fields terminated by '$terminator' enclosed by '' escaped by '\\\\'
    lines terminated by '\\n'
    ignore 1 lines
    ";
  }
  
  
  protected function run_sql( $q ){
    $ret = mysql_query( $q );
    if (false===$ret){
      throw new QueryException( 
        "something wrong with this query: ".var_export( $q,1 )."\n\n".
        "mysql returned the error: ".mysql_error()."\n"
      );
    }
    return $ret;
  }

}

/**
* candidates for abstraction
*/
class CliCommon {

  /*
    ask a user a question on the command-line and require a yes or no answer (or cancel.) 
    return 'yes' | 'no' | 'cancel'   loops until one of these is entered 
    if default value is provided it will be used if the user just presses enter w/o entering anything.
    if default value is not provided it will loop.
  */
  public static function yes_no_cancel( $prompt, $defaultValue = null){
    $done = false;
    if (null!==$defaultValue) {
      $defaultMessage  =" (default: $defaultValue)";
    }
    while (!$done){
      echo $prompt;
      if (strlen($prompt)&&"\n"!==$prompt[strlen($prompt)-1]){ echo "\n"; }
      echo "Please enter [y]es, [n]o, or [c]ancel".$defaultMessage.": ";
      $input = strtolower( trim( fgets( STDIN ) ) );
      if (''===$input && null!== $defaultValue) { 
        $input = $defaultValue;
      }
      $done = true;
      switch( $input ){
        case 'y': case 'yes': $return = 'yes'; break;
        case 'n': case 'no':  $return = 'no'; break;
        case 'c': case 'cancel': $return = 'cancel'; break;
        default: 
        $done = false;
      }
    }
    echo "\n"; // 2 newlines after user input for readability (the first was when they hit enter).
    return $return;
  }  
  
  public static function stdout( $str ) {
    fwrite( STDOUT, $str );
  }

  public static function stderr( $str ) {
    fwrite( STDERR, $str );
  }
}

/**
* in a standalone manner, handle all the issues with command line proccessing
*/
class Tab2TableCli {
  
  public function processArgs( $argv ){
    if (count($argv) < 2 || (!in_array($argv[1], array('import','example')))) {
      $this->fatal( $this->get_usage_message() );  
    }
    array_shift( $argv );
    $verb = array_shift( $argv );
    $methName = 'do_'.str_replace( ' ','_', strtolower( $verb ) );
    $this->$methName( $argv );
  }
    
  // **** Protected Methods ****

  function get_usage_message(){
    return "Usage:\n".
    "    php ".$GLOBALS['argv'][0].' import <parameters file> <input csv>'."\n".
    "\n".
    "to output an example parameters file:\n".
    "    php ". $GLOBALS['argv'][0].' example <parameters file name>'."\n "
    ;
  }  
  
  protected function do_example( $args ){
    if (1 !== count( $args ) ) {
      $this->fatal( 
        "expecting exactly one argument for filename.\n".$this->get_usage_message() 
      );
    }
    $fn = $args[0];
    if (file_exists( $fn )){
      $this->fatal( "example parameters file must not already exist \"$fn\"");
    }
    $s = <<<TO_HERE
<?php
    return array(
      'connection' => array(
        'username' => 'root',
        'password' => '',
        'server'   => 'localhost',
        'database' => 'sf3_dev',
      ),
      'table_name' => '_trace',
    );
TO_HERE;
    file_put_contents( $fn, $s );
    $this->stdout( "wrote example parameters file to \"$fn\".\n" );
  }
  
  protected function do_import( $argv ){
    if (count($argv) != 2){
      $this->fatal( "needed exactly two arguments.\n". $this->get_usage_message() );
    }
    $args['data_file'] = $argv[0];
    $args['input_csv'] = $argv[1];
    foreach( array( 'data_file','input_csv') as $filename_name ) {
      if (!file_exists( $filename = $args[$filename_name])) {
        $this->fatal( "there is no $filename_name with the path \"$filename\"" );
      }
    }   
    $data = require_once( $args['data_file'] );
    $tbl_name = $data['table_name'];
    $c = $data['connection'];
    if (! $cn = mysql_connect( $c['server'], $c['username'], $c['password'] ) ){
      $this->fatal( "can't connect with ".var_export( $c, 1));
    }
    if (!mysql_select_db( $c['database'] ) ) { 
      $this->fatal( "can't select database: ".$c['database'] ); 
    }
    if (!preg_match('/^_/', $tbl_name)) {
      $this->fatal( "table name must start with underscore (\"_\") -- invalid table name \"$tbl_name\"" );
    } 
    $builder = new TableFromCsvBuilderPopulator(array(
      'logger'       => new StdLogger(),
      'separator'    => "\t",
      'csv_filename' => $args['input_csv'],
      'connection_parameters' => $c,
      'table_name'   => $tbl_name
    ));
    $builder->setDoPrimaryKey( true );
    $doDropTable = false;
    $doCreateTable = false;    
    if ($builder->table_exists()) {
      $choice = CliCommon::yes_no_cancel( "table \"$tbl_name\" exists. Should we recreate its structure? (say 'yes' if you think the struture has changed.)\n", "no" );
      if ('cancel'===$choice) { 
        $this->quit();
      } elseif ( 'yes' === $choice ) {
        $doDropTable = true;
        $doCreateTable = true;
      } 
    } else {
      $doCreateTable = true;
    }
    if ($doDropTable) { $builder->drop_table(); }
    if ($doCreateTable) { $builder->create_table(); }
    if ($num = $builder->get_numrows_in_table()){
      $choice = CliCommon::yes_no_cancel( "table ".$builder->get_table_name()." has $num rows of data in it.  Is it ok to delete this data?", 'yes' );
      if ($choice != 'yes') { $this->quit(); }
      $builder->delete_all_from_table();  
    }
    try { 
      $builder->populate_table();
    } catch ( QueryException $e ) {
      $str = "mysql returned the error: The used command is not allowed with this MySQL version";
      if (false !== strstr( $e->getMessage(), $str)){
        $this->stdout( "$str\n" );
        $this->stdout( "it is expected to be because the mysql server wasn't started with --local-infile enabled. \n");
        $choice = CliCommon::yes_no_cancel( "Should we try the workaround, to exec LOAD DATA INFILE from the command line? ",'yes' );
        if ($choice !== 'yes') { $this->quit(); }
        $builder->try_populate_table_workaround();   
      } else {
        throw $e;
      }
    }
    $this->stdout("done.\n"); 
  }

  function quit(){
    $this->stdout( "quitting.\n");
    exit();
  }

  // php != mixins
  function stdout( $str ){ CliCommon::stdout( $str ); }
  function stderr( $str ){ CliCommon::stderr( $str ); }

  function fatal( $msg ){
    $this->stdout( $msg."\n" );
    die();
  }

}

$cli = new Tab2TableCli();
$cli->processArgs( $argv );