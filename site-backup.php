<?php
set_time_limit(0);
/*
Run the following via cron (or whatever) as often as you want
php8.1 /home/forge/site-backup/site-backup.php

Configure via .env:
s3_endpoint="your-s3-endpoint"
s3_bucket="your-s3-bucket"
s3_access_key="your-s3-access-key"
s3_secret_key="your-s3-secret-key"
storage_directory="/path/in/bucket" (no trailing slash)
sites="somesite.com,someothersite.com" (no slashes)
backups_to_keep=2
*/
###########################################
$version = 0.1;
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('This script must be run from the command line only');
###########################################
if ( !defined('BASE_DIR') ) {
 define('BASE_DIR', dirname(__FILE__) . '/');
 define('WORK_DIR', '/home/forge/site-backup-work/');
}
$now = time();
define('S3_BACKUP_DIR',date("YmdHis",$now));
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
function get_s3_client(){
  $client = new Aws\S3\S3Client([
          'version' => 'latest',
          'region'  => 'us-east-va',
          'endpoint' => S3_ENDPOINT,
          'credentials' => [
                  'key'    => S3_ACCESS_KEY,
                  'secret' => S3_SECRET_KEY,
              ],
  ]);
  return $client;
}

function get_s3_objects($path) {
  global $s3_config;
  $path = ltrim($path,'/');
  $client = get_s3_client();
  if (!$client) {
    return false;
  } else {
    $objects = $client->listObjects([
        'Bucket' => S3_BUCKET,
        'Prefix' => $path,
    ]);
    if (isset($objects['Contents'])) {
      $results = array();
      foreach ($objects['Contents'] as $object) {
        $results[] = $object['Key'];
      }
      return $results;
    } else {
      return array();
    }
  }
}

function put_s3_objects($files,$privacy,$path) {
  global $s3_config;
  $results = array();
  if (!is_array($files)) {
    $files = array($files);
  }
  $path = ltrim($path,'/');
  if ($privacy == 'public') {
    $privacy = 'public-read';
  } else {
    $privacy = 'private';
  }
  $client = get_s3_client();
  if (!$client) {
    return false;
  } else {
    foreach ($files as $file) {
      if (!file_exists($file)) {
        $results[$file] = false;
      } else {
        $path_parts = pathinfo($file);
        $upload = $client->putObject([
                'Bucket' => S3_BUCKET,
                'Key'    => $path . $path_parts['filename'] . '.' . $path_parts['extension'],
                'Body'   => fopen($file, 'r'),
                'ACL'    => $privacy,
            ]);
        if (isset($upload['ObjectURL'])) {
          $results[$file] = $upload['ObjectURL'];
        } else {
          $results[$file] = false;
        }
      }
    }
    return $results;
  }
}

function delete_s3_objects($objects) {
  global $s3_config;
  $results = array();
  if (!is_array($objects)) {
    $objects = array($objects);
  }
  $client = get_s3_client();
  if (!$client) {
    return false;
  } else {
    foreach ($objects as $object) {
      $object = ltrim($object,'/');
      $client->deleteObject([
          'Bucket' => S3_BUCKET,
          'Key' => $object,
      ]);
    }
    return true;
  }
}

function clean_older_than($path, $min = 960) {
  $now = time();
  $items = glob(rtrim(rtrim($path, "*"), "/") . '/*');
  foreach ($items as $item) {
    if (is_dir($item)) {
      clean_older_than($item, $min);
      if ($now - filemtime($item) >= 60 * $min) {
        @rmdir($item);
      }
    } else {
      if ($now - filemtime($item) >= 60 * $min) {
        @chmod($item, 0777);
        @unlink($item);
      }
    }
  }
}

function check_work_dir(){
  if (!file_exists(WORK_DIR)) {
    mkdir(WORK_DIR, 0777);
  }
  if (!file_exists(WORK_DIR)) {
    echo "Error: Could create work directory at '" . WORK_DIR . "'" . PHP_EOL; exit();
  }
  clean_older_than(WORK_DIR, 1);
}

function check_env_vars(){
  if (file_exists(BASE_DIR . '.env')) {
    $vars = array('S3_ENDPOINT','S3_BUCKET','S3_ACCESS_KEY','S3_SECRET_KEY','STORAGE_DIRECTORY','SITES','BACKUPS_TO_KEEP');
    foreach ($vars as $var) {
      if (!defined($var)) {
        echo "Error: Environment variable '" . $var . "' missing" . PHP_EOL; exit();
      }
    }
  } else {
    echo "Error: Could not find .env" . PHP_EOL; exit();
  }
}

function cleanup_old_backups(){
  $s3_objects = get_s3_objects(STORAGE_DIRECTORY);
  $backups = array();
  foreach ($s3_objects as $s3_object) {
    $s3_object = str_replace(STORAGE_DIRECTORY . '/','',$s3_object);
    $s3_object_pieces = explode('/',$s3_object);
    $date = DateTimeImmutable::createFromFormat('YmdHis', $s3_object_pieces[0]);
    $backups[$date->getTimestamp()] = STORAGE_DIRECTORY . '/' . $s3_object_pieces[0];
    unset($date);
  }
  krsort($backups); //oldest at top
  if (count($backups) > BACKUPS_TO_KEEP) {
    $backups_to_remove = array_slice($backups,0,count($backups) - BACKUPS_TO_KEEP);
    foreach ($backups_to_remove as $backup_to_remove) {
      delete_s3_objects(get_s3_objects($backup_to_remove));
      delete_s3_objects(array($backup_to_remove));
    }
  }
  return 'success';
}

function backup_site($site){
  $site = str_replace('/','',$site);
  $site_path = '/home/forge/' . $site . '/';
  $backup_file_path = WORK_DIR . $site . '.tar.gz';
  if (file_exists($site_path)) {
    //create compressed backup
    exec('tar -czf ' . $backup_file_path . ' ' . $site_path . ' 2>&1', $output, $return_var);
    if (file_exists($backup_file_path)) {
      //upload to s3
      put_s3_objects(array($backup_file_path),'private',STORAGE_DIRECTORY . S3_BACKUP_DIR . '/');
      //delete compressed backup
      @unlink($backup_file_path);
      return 'success';
    } else {
      return "Could not create compressed backup";
    }
  } else {
    return "Site not found - looked in '" . $site_path . "'";
  }
}
###########################################
if(file_exists(BASE_DIR . 'vendor/autoload.php')){ require_once BASE_DIR . 'vendor/autoload.php'; }
use Aws\S3\S3Client;
echo '===========================================' . PHP_EOL;
echo 'Forge Site Backup - Version: ' . $version . PHP_EOL;
echo '===========================================' . PHP_EOL;
check_env_vars();
check_work_dir();
echo 'Sites configured for backup: ' . PHP_EOL;
$sites = explode(',', str_replace(' ','',SITES));
foreach ($sites as $site) {
  echo '- ' . $site . PHP_EOL;
}
echo '===========================================' . PHP_EOL;
echo 'Cleanup old backups: ' . cleanup_old_backups() . PHP_EOL;
echo '===========================================' . PHP_EOL;
echo 'Backing up sites (this may take a while): ' . PHP_EOL;
$sites = explode(',', str_replace(' ','',SITES));
foreach ($sites as $site) {
  echo '- ' . $site . ': ' . backup_site($site) . PHP_EOL;
}
echo '===========================================' . PHP_EOL;
echo 'Completed in: ' . gmdate("H:i:s", (time() - $now)) . PHP_EOL;
?>
