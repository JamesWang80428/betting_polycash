<?php
class PageviewController {
	public $app;
	
	function __construct($app) {
		$this->app = $app;
	}
	function get_viewer($viewer_id) {
		return $this->app->run_query("SELECT * FROM viewers WHERE viewer_id='".$viewer_id."';")->fetch();
	}
	function ip_identifier() {
		return $this->app->run_query("SELECT * FROM viewer_identifiers WHERE type='ip' AND identifier=".$this->app->quote_escape($_SERVER['REMOTE_ADDR']).";")->fetch();
	}
	function cookie_identifier() {
		if (isset($_COOKIE["cookie_str"])) {
			return $this->app->run_query("SELECT * FROM viewer_identifiers WHERE type='cookie' AND identifier=".$this->app->quote_escape($_COOKIE["cookie_str"]).";")->fetch();
		}
		else return false;
	}
	function insert_pageview($thisuser) {
		$ip_identifier = $this->ip_identifier();
		$cookie_identifier = $this->cookie_identifier();
		$cookie_time_sec = 365*24*60*60;
		$cookie_length = 32;
		
		if ($ip_identifier && $cookie_identifier) {}
		else if (!$ip_identifier && !$cookie_identifier) {
			$this->app->run_query("INSERT INTO viewers SET time_created='".time()."';");
			$viewer_id = $this->app->last_insert_id();
			
			$this->app->run_query("INSERT INTO viewer_identifiers SET type='ip', identifier=".$this->app->quote_escape($_SERVER['REMOTE_ADDR']).", viewer_id='".$viewer_id."';");
			
			$cookie_str = $this->app->random_string($cookie_length);
			
			$this->app->run_query("INSERT INTO viewer_identifiers SET type='cookie', identifier=".$this->app->quote_escape($cookie_str).", viewer_id='".$viewer_id."';");
			
			setcookie("cookie_str", $cookie_str, time()+$cookie_time_sec);
		}
		else if (!$ip_identifier) {
			$this->app->run_query("INSERT INTO viewer_identifiers SET type='ip', identifier=".$this->app->quote_escape($_SERVER['REMOTE_ADDR']).", viewer_id='".$cookie_identifier['viewer_id']."';");
			$ip_id = $this->app->last_insert_id();
			
			$ip_identifier = $this->app->run_query("SELECT * FROM viewer_identifiers WHERE identifier_id='".$ip_id."';")->fetch();
		}
		else if (!$cookie_identifier) {
			$cookie_str = $this->app->random_string($cookie_length);
			setcookie("cookie_str", $cookie_str, time()+$cookie_time_sec);
			
			$this->app->run_query("INSERT INTO viewer_identifiers SET viewer_id='".$ip_identifier['viewer_id']."', type='cookie', identifier=".$this->app->quote_escape($cookie_str).";");
			$cookie_id = $this->app->last_insert_id();
			
			$cookie_identifier = $this->app->run_query("SELECT * FROM viewer_identifiers WHERE identifier_id='".$cookie_id."';")->fetch();
		}
		
		$refer_url = "";
		if (!empty($_SERVER['HTTP_REFERER'])) {
			$domain = $_SERVER['HTTP_REFERER'];
			if (substr($domain, 0, 8) == "https://") $domain = substr($domain, 8, strlen($domain)-8);
			if (substr($domain, 0, 7) == "http://") $domain = substr($domain, 7, strlen($domain)-7);
			if (substr($domain, 0, 4) == "www.") $domain = substr($domain, 4, strlen($domain)-4);
			$domain = str_replace("?", "/", $domain);
			$domain = explode("/", $domain);
			$domain = $domain[0];
			$domain_parts = explode(".", $domain);
			$domain = $domain_parts[count($domain_parts)-1];
			if (count($domain_parts) > 1) $domain = $domain_parts[count($domain_parts)-2].".".$domain;
			
			$refer_url = $_SERVER['HTTP_REFERER'];
		}
		
		if (strlen($_SERVER['REQUEST_URI']) > 255) $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], 0, 255);
		
		$pv_page_id = (int)($this->app->run_query("SELECT page_url_id FROM page_urls WHERE url=".$this->app->quote_escape($_SERVER['REQUEST_URI']).";")->fetch(PDO::FETCH_NUM)[0]);
		
		if (!$pv_page_id) {
			$this->app->run_query("INSERT INTO page_urls SET url=".$this->app->quote_escape($_SERVER['REQUEST_URI']).";");
			$pv_page_id = $this->app->last_insert_id();
		}
		
		$new_pv_q = "INSERT INTO pageviews SET ";
		if ($thisuser) $new_pv_q .= "user_id='".$thisuser->db_user['user_id']."', ";
		$new_pv_q .= "viewer_id='".$cookie_identifier['viewer_id']."', ip_id='".$ip_identifier['identifier_id']."', cookie_id='".$cookie_identifier['identifier_id']."', time='".time()."', pv_page_id='".$pv_page_id."', refer_url=".$this->app->quote_escape($refer_url).";";
		$this->app->run_query($new_pv_q);
		$pageview_id = $this->app->last_insert_id();
		
		$result[0] = $pageview_id;
		$result[1] = $cookie_identifier['viewer_id'];
		
		return $result[1];
	}
}
?>