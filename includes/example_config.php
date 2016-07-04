<?php
error_reporting(0);

$GLOBALS['mysql_server'] = "localhost";
$GLOBALS['mysql_user'] = "root";
$GLOBALS['mysql_password'] = "";
$GLOBALS['mysql_database'] = "empirecoin";

$GLOBALS['signup_captcha_required'] = false;
$GLOBALS['recaptcha_publickey'] = "";
$GLOBALS['recaptcha_privatekey'] = "";

$GLOBALS['outbound_email_enabled'] = false;
$GLOBALS['sendgrid_user'] = "";
$GLOBALS['sendgrid_pass'] = "";

$GLOBALS['show_query_errors'] = true;
$GLOBALS['cron_key_string'] = "";

$GLOBALS['coin_port'] = 11111;
$GLOBALS['coin_testnet_port'] = 22222;
$GLOBALS['coin_rpc_user'] = "";
$GLOBALS['coin_rpc_password'] = "";

$GLOBALS['bitcoin_port'] = 8332;
$GLOBALS['bitcoin_rpc_user'] = "bitcoinrpc";
$GLOBALS['bitcoin_rpc_password'] = "";

$GLOBALS['always_generate_coins'] = false;
$GLOBALS['restart_generation_seconds'] = 60;

$GLOBALS['walletnotify_by_cron'] = true;
$GLOBALS['pageview_tracking_enabled'] = false;
$GLOBALS['min_unallocated_addresses'] = 40;

$GLOBALS['currency_price_refresh_seconds'] = 30;
$GLOBALS['invoice_expiration_seconds'] = 60*10;

$GLOBALS['new_games_per_user'] = "unlimited";

$GLOBALS['coin_brand_name'] = "EmpireCoin";
$GLOBALS['site_name_short'] = "EmpireCoin";
$GLOBALS['site_name'] = "EmpireCoin.org";
$GLOBALS['site_domain'] = strtolower($GLOBALS['site_name']);
$GLOBALS['base_url'] = "http://".$GLOBALS['site_domain'];

$GLOBALS['default_timezone'] = 'America/Chicago';

$GLOBALS['rsa_keyholder_email'] = "";
$GLOBALS['rsa_pub_key'] = "";
$GLOBALS['profit_btc_address'] = "";

$GLOBALS['api_proxy_url'] = "";

$GLOBALS['default_server_api_access_key'] = false;
?>