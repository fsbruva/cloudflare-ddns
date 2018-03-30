#!/usr/bin/env php
<?php

require __DIR__ . '/Cloudflare.php';
$verbose = !isset($argv[1]) || $argv[1] != '-s';

$ip4CacheFile = __DIR__ . '/cf_ip4.cache';
$confFile = __DIR__ . '/config.php';
if (!file_exists($confFile))
{
  echo "Missing config file. Please copy config.php.skel to config.php and fill out the values therein.\n";
  return 1;
}

$config = require $confFile;

foreach (['cloudflare_email', 'cloudflare_api_key', 'domain', 'record_name', 'ttl', 'protocol'] as $key)
{
  if (!isset($config[$key]) || $config[$key] === '')
  {
    echo "config.php is missing the '$key' config value\n";
    return 1;
  }
}

$api = new Cloudflare($config['cloudflare_email'], $config['cloudflare_api_key']);

$domain     = $config['domain'];
$recordName = $config['record_name'];

if (isset($config['auth_token']) && $config['auth_token'])
{
  // API mode. Use IP from request params.
  if (empty($_GET['auth_token']) || empty($_GET['ip']) || $_GET['auth_token'] != $config['auth_token'])
  {
    echo "Missing or invalid 'auth_token' param, or missing 'ip' param\n";
    return 1;
  }
  $ip = $_GET['ip'];
}
else
{
  // Local mode. Get IP from service.
  if ($verbose) echo "Local mode - getting IP from service.\n";
  $ip = getIP($config['protocol']);
  if ($verbose) echo "Service indicates IP address is: " . $ip . "\n";
}

if (!file_exists($ip4CacheFile))
{
  // We don't have any previous ip address saved
  if ($verbose) echo "No ipv4 cache file found.\n";

}
else
{
  // We have a previous address - we should load it in
  if ($verbose) echo "I found an ipv4 cache file - reading it.\n";
  $file_data = trim (file_get_contents ( $ip4CacheFile ));
  if ( strcmp ($config['protocol'], 'ipv4') === 0 )
  {
    if ( filter_var( $file_data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) )
    {
    // We have a valid ipv4 address - store it
      if ($verbose) echo "The ipv4 cache file contained a valid IP.\n";
      $oldIP4 = $file_data;
    }
  }
}

if ( empty( $oldIP4 ) || strcmp ( $oldIP4 , $ip ) !== 0 )
{
  try
  {
    $zone = $api->getZone($domain);
    if (!$zone)
    {
      echo "Domain $domain not found\n";
      return 1;
    }

    $records = $api->getZoneDnsRecords($zone['id'], ['name' => $recordName]);
    $record  = $records && $records[0]['name'] == $recordName ? $records[0] : null;

    if (!$record)
    {
      if ($verbose) echo "No existing record found. Creating a new one\n";
      $ret = $api->createDnsRecord($zone['id'], 'A', $recordName, $ip, ['ttl' => $config['ttl']]);
    }
    elseif ($record['type'] != 'A' || $record['content'] != $ip || $record['ttl'] != $config['ttl'])
    {
      if ($verbose) echo "Updating record.\n";
      $ret = $api->updateDnsRecord($zone['id'], $record['id'], [
        'type'    => 'A',
        'name'    => $recordName,
        'content' => $ip,
        'ttl'     => $config['ttl'],
      ]);
    }
    else
    {
      if ($verbose) echo "Record appears OK. No need to update.\n";
    }
    if ($verbose) echo "Done with CloudFlare. Storing new IP address.\n";
    file_put_contents ( $ip4CacheFile , $ip );
    return 0;
  }
  catch (Exception $e)
  {
    echo "Error: " . $e->getMessage() . "\n";
    return 1;
  }
}
if ($verbose) echo "Current IP matches cache. Nothing to do.\n";


// http://stackoverflow.com/questions/3097589/getting-my-public-ip-via-api
// http://major.io/icanhazip-com-faq/
function getIP($protocol)
{
  $prefixes = ['ipv4' => 'ipv4.', 'ipv6' => 'ipv6.', 'auto' => ''];
  if (!isset($prefixes[$protocol]))
  {
    throw new Exception('Invalid "protocol" config value.');
  }
  return trim(file_get_contents('http://' . $prefixes[$protocol] . 'icanhazip.com'));
}
