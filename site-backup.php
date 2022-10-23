<?php
/*
Configure via .env:
s3_endpoint="your-s3-endpoint"
s3_bucket="your-s3-bucket"
s3_access_key="your-s3-access-key"
s3_secret_key="your-s3-secret-key"
storage_directory="/path/in/bucket"
sites="somesite.com,someothersite.com"
says_to_keep=2
*/
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('This script must be run from the command line only');
###########################################
use Aws\S3\S3Client;
###########################################
if ( !defined('BASE_DIR') ) {
 define('BASE_DIR', dirname(__FILE__) . '/');
}
$now = time();
if (file_exists(BASE_DIR . '.env')) {
  $env_vars = file(BASE_DIR . '.env');
  foreach($env_vars as $env_var) {
    #echo str_replace('export ','',$env_var) . '\n';
    $env_var_pieces = explode('=', str_replace('export ','',$env_var));
    if (!defined($env_var_pieces[0])) {
      define(strtoupper($env_var_pieces[0]), str_replace('"',"",str_replace(PHP_EOL, '', $env_var_pieces[1])));
    }
  }
}
###########################################
###########################################
?>
