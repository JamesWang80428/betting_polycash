<?php
class App {
	public $dbh;
	
	public function __construct($dbh) {
		$this->dbh = $dbh;
	}
	
	public function quote_escape($string) {
		return $this->dbh->quote($string);
	}
	
	public function set_db($db_name) {
		$this->dbh->query("USE ".$db_name) or die("There was an error accessing the '".$db_name."' database.");
	}
	
	public function last_insert_id() {
		return $this->dbh->lastInsertId();
	}
	
	public function run_query($query) {
		if ($GLOBALS['show_query_errors'] == TRUE) $result = $this->dbh->query($query) or die("Error in query: ".$query.", ".$this->dbh->errorInfo()[2]);
		else $result = $this->dbh->query($query) or die("Error in query");
		return $result;
	}

	public function utf8_clean($str) {
		return iconv('UTF-8', 'UTF-8//IGNORE', $str);
	}

	public function make_alphanumeric($string, $extrachars) {
		$allowed_chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ".$extrachars;
		$new_string = "";
		
		for ($i=0; $i<strlen($string); $i++) {
			if (is_numeric(strpos($allowed_chars, $string[$i])))
				$new_string .= $string[$i];
		}
		return $new_string;
	}

	public function random_string($length) {
		$characters = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
		$string ="";

		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters)-1)];
		}

		return $string;
	}
	
	public function normalize_username($username) {
		return $this->make_alphanumeric(strip_tags($username), "$-()/!.,:;#@");
	}
	
	public function recaptcha_check_answer($recaptcha_privatekey, $ip_address, $g_recaptcha_response) {
		$response = json_decode(file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".$recaptcha_privatekey."&response=".$g_recaptcha_response."&remoteip=".$ip_address), true);
		if ($response['success'] == false) return false;
		else return true;
	}
	
	public function generate_games() {
		$q = "SELECT * FROM game_types ORDER BY game_type_id ASC;";
		$r = $this->run_query($q);
		while ($game_type = $r->fetch(PDO::FETCH_ASSOC)) {
			$this->generate_games_by_type($game_type);
		}
	}
	
	public function generate_games_by_type($game_type) {
		$q = "SELECT * FROM games WHERE game_type_id='".$game_type['game_type_id']."' AND game_status IN('editable','published','running');";
		$r = $this->run_query($q);
		$num_running_games = $r->rowCount();
		$needed_games = $game_type['target_open_games'] - $num_running_games;
		for ($i=0; $i<$needed_games; $i++) {
			$this->generate_game_by_type($game_type);
		}
	}
	
	public function option_index_to_voting_chars($option_index, $options_in_game) {
		$voting_chars = "0123456789abcdefghijklmnopqrstuvwxyz";
		$modulus = strlen($voting_chars);
		$length = ceil(log($options_in_game, strlen($voting_chars)));
		$chars = "";
		$current_num = $option_index;
		for ($i=0; $i<$length; $i++) {
			$remainder = $current_num%$modulus;
			$current_num = floor($current_num/$modulus);
			$chars .= $voting_chars[$remainder];
		}
		return strrev($chars);
	}
	
	public function generate_game_by_type($game_type) {
		$skip_game_type_vars = explode(",", "event_rule,event_entity_type_id,option_group_id,events_per_round,url_identifier,target_open_games,default_vote_effectiveness_function,default_max_voting_fraction");
		$url_identifier = $this->game_url_identifier($game_type['name']);
		$q = "INSERT INTO games SET url_identifier=".$this->quote_escape($url_identifier).", ";
		foreach ($game_type AS $var => $val) {
			if (!in_array($var, $skip_game_type_vars)) {
				if (!empty($val)) $q .= $var.'='.$this->quote_escape($val).', ';
			}
		}
		$q = substr($q, 0, strlen($q)-2).";";
		$r = $this->run_query($q);
		$game_id = $this->last_insert_id();
		
		if ($game_type['event_rule'] == "entity_type_option_group") {
			$q = "SELECT * FROM entity_types WHERE entity_type_id='".$game_type['event_entity_type_id']."';";
			$r = $this->run_query($q);
			
			if ($r->rowCount() == 1) {
				$entity_type = $r->fetch();
				
				$q = "SELECT * FROM option_groups WHERE group_id='".$game_type['option_group_id']."';";
				$r = $this->run_query($q);
				$option_group = $r->fetch();
				
				$q = "SELECT * FROM entities e JOIN option_group_memberships mem ON e.entity_id=mem.entity_id WHERE mem.option_group_id='".$game_type['option_group_id']."' ORDER BY e.entity_id ASC;";
				$r = $this->run_query($q);
				$db_option_entities = array();
				while ($option_entity = $r->fetch()) {
					$db_option_entities[count($db_option_entities)] = $option_entity;
				}
				
				$event_i = 0;
				$option_i = 0;
				$max_voting_chars = 0;
				
				$q = "SELECT * FROM entities WHERE entity_type_id='".$entity_type['entity_type_id']."' ORDER BY entity_id ASC;";
				$r = $this->run_query($q);
				
				$options_in_game = $r->rowCount()*count($db_option_entities);
				while ($event_entity = $r->fetch()) {
					if (count($db_option_entities) == 2 && !empty($db_option_entities[0]['last_name'])) {
						$event_type_name = $db_option_entities[0]['last_name']." vs ".$db_option_entities[1]['last_name']." in ".$event_entity['entity_name'];
						$event_type_identifier = strtolower($db_option_entities[0]['last_name']."-".$db_option_entities[1]['last_name']."-".$event_entity['entity_name']);
						$qq = "SELECT * FROM event_types WHERE url_identifier=".$this->quote_escape($event_type_identifier).";";
						$rr = $this->run_query($qq);
						if ($rr->rowCount() > 0) {
							$event_type = $rr->fetch();
						}
						else {
							$qq = "INSERT INTO event_types SET option_group_id='".$game_type['option_group_id']."', name='".$event_type_name."', url_identifier=".$this->quote_escape($event_type_identifier).", num_voting_options='".count($db_option_entities)."', vote_effectiveness_function='".$game_type['default_vote_effectiveness_function']."', max_voting_fraction='".$game_type['default_max_voting_fraction']."';";
							$rr = $this->run_query($qq);
							$event_type_id = $this->last_insert_id();
							
							$qq = "SELECT * FROM event_types WHERE event_type_id='".$event_type_id."';";
							$event_type = $this->run_query($qq)->fetch();
						}
						
						$qq = "SELECT * FROM events WHERE game_id='".$game_id."' AND event_type_id='".$event_type['event_type_id']."';";
						$rr = $this->run_query($qq);
						if ($rr->rowCount() > 0) {
							$option_i += count($db_option_entities);
						}
						else {
							$starting_round = floor($event_i/$game_type['events_per_round'])+1;
							$event_starting_block = ($starting_round-1)*$game_type['round_length']+1;
							$event_final_block = $starting_round*$game_type['round_length'];
							
							$qq = "INSERT INTO events SET game_id='".$game_id."', event_type_id='".$event_type['event_type_id']."', event_starting_block='".$event_starting_block."', event_final_block='".$event_final_block."', event_name=".$this->quote_escape($event_type_name).", option_name=".$this->quote_escape($option_group['option_name']).", option_name_plural=".$this->quote_escape($option_group['option_name_plural']).";";
							$rr = $this->run_query($qq);
							$event_id = $this->last_insert_id();
							
							for ($i=0; $i<count($db_option_entities); $i++) {
								$voting_character = $this->option_index_to_voting_chars($option_i, $options_in_game);
								if (strlen($voting_character) > $max_voting_chars) $max_voting_chars = strlen($voting_character);
								$qq = "INSERT INTO options SET event_id='".$event_id."', entity_id='".$db_option_entities[$i]['entity_id']."', membership_id='".$db_option_entities[$i]['membership_id']."', image_id='".$db_option_entities[$i]['default_image_id']."', name='".$db_option_entities[$i]['entity_name']." wins ".$event_entity['entity_name']."', voting_character='".$voting_character."';";
								$rr = $this->run_query($qq);
								$option_i++;
							}
						}
						$event_i++;
					}
				}
				
				$q = "UPDATE games SET game_status='published', max_voting_chars='".$max_voting_chars."' WHERE game_id='".$game_id."';";
				$r = $this->run_query($q);
			}
		}
	}
	
	public function get_redirect_url($url) {
		$q = "SELECT * FROM redirect_urls WHERE url=".$this->quote_escape($url).";";
		$r = $this->run_query($q);
		if ($r->rowCount() > 0) {
			$redirect_url = $r->fetch();
		}
		else {
			$q = "INSERT INTO redirect_urls SET url=".$this->quote_escape($url).", time_created='".time()."';";
			$r = $this->run_query($q);
			$redirect_url_id = $this->last_insert_id();
			
			$q = "SELECT * FROM redirect_urls WHERE redirect_url_id='".$redirect_url_id."';";
			$r = $this->run_query($q);
			$redirect_url = $r->fetch();
		}
		return $redirect_url;
	}

	public function mail_async($email, $from_name, $from, $subject, $message, $bcc, $cc) {
		$q = "INSERT INTO async_email_deliveries SET to_email=".$this->quote_escape($email).", from_name=".$this->quote_escape($from_name).", from_email=".$this->quote_escape($from).", subject=".$this->quote_escape($subject).", message=".$this->quote_escape($message).", bcc=".$this->quote_escape($bcc).", cc=".$this->quote_escape($cc).", time_created='".time()."';";
		$r = $this->run_query($q);
		$delivery_id = $this->last_insert_id();
		
		$command = $this->php_binary_location()." ".realpath(dirname(dirname(__FILE__)))."/scripts/async_email_deliver.php ".$delivery_id." > /dev/null 2>/dev/null &";
		exec($command);
		
		/*$curl_url = $GLOBALS['base_url']."/scripts/async_email_deliver.php?delivery_id=".$delivery_id;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $curl_url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$output = curl_exec($ch);
		curl_close($ch);*/

		return $delivery_id;
	}
	
	public function get_site_constant($constant_name) {
		$q = "SELECT * FROM site_constants WHERE constant_name='".$constant_name."';";
		$r = $this->run_query($q);
		if ($r->rowCount() == 1) {
			$constant = $r->fetch();
			return $constant['constant_value'];
		}
		else return "";
	}

	public function set_site_constant($constant_name, $constant_value) {
		$q = "SELECT * FROM site_constants WHERE constant_name='".$constant_name."';";
		$r = $this->run_query($q);
		if ($r->rowCount() > 0) {
			$constant = $r->fetch();
			$q = "UPDATE site_constants SET constant_value='".$constant_value."' WHERE constant_id='".$constant['constant_id']."';";
			$r = $this->run_query($q);
		}
		else {
			$q = "INSERT INTO site_constants SET constant_name='".$constant_name."', constant_value='".$constant_value."';";
			$r = $this->run_query($q);
		}
	}
	
	public function to_significant_digits($number, $significant_digits) {
		if ($number === 0) return 0;
		if ($number < 1) $significant_digits++;
		$number_digits = (int)(log10($number));
		$returnval = (pow(10, $number_digits - $significant_digits + 1)) * floor($number/(pow(10, $number_digits - $significant_digits + 1)));
		return $returnval;
	}

	public function format_bignum($number) {
		if ($number >= 0) $sign = "";
		else $sign = "-";
		
		$number = abs($number);
		if ($number > 1) $number = $this->to_significant_digits($number, 5);
		
		if ($number > pow(10, 9)) {
			return $sign.($number/pow(10, 9))."B";
		}
		else if ($number > pow(10, 6)) {
			return $sign.($number/pow(10, 6))."M";
		}
		else if ($number > pow(10, 4)) {
			return $sign.($number/pow(10, 3))."k";
		}
		else return $sign.rtrim(rtrim(number_format(sprintf('%.8F', $number), 8), '0'), ".");
	}
	
	public function to_ranktext($rank) {
		return $rank.date("S", strtotime("1/".$rank."/".date("Y")));
	}
	
	public function cancel_transaction($transaction_id, $affected_input_ids, $created_input_ids) {
		$q = "DELETE FROM transactions WHERE transaction_id='".$transaction_id."';";
		$r = $this->run_query($q);
		
		if (count($affected_input_ids) > 0) {
			$q = "UPDATE transaction_ios SET spend_status='unspent', spend_transaction_id=NULL, spend_block_id=NULL WHERE io_id IN (".implode(",", $affected_input_ids).")";
			$r = $this->run_query($q);
		}
		
		if ($created_input_ids && count($created_input_ids) > 0) {
			$q = "DELETE FROM transaction_ios WHERE io_id IN (".implode(",", $created_input_ids).");";
			$r = $this->run_query($q);
		}
	}

	public function transaction_coins_in($transaction_id) {
		$qq = "SELECT SUM(amount) FROM transaction_ios io JOIN addresses a ON io.address_id=a.address_id WHERE io.spend_transaction_id='".$transaction_id."';";
		$rr = $this->run_query($qq);
		$coins_in = $rr->fetch(PDO::FETCH_NUM);
		if ($coins_in[0] > 0) return $coins_in[0];
		else return 0;
	}

	public function transaction_coins_out($transaction_id) {
		$qq = "SELECT SUM(amount) FROM transaction_ios io JOIN addresses a ON io.address_id=a.address_id WHERE io.create_transaction_id='".$transaction_id."';";
		$rr = $this->run_query($qq);
		$coins_out = $rr->fetch(PDO::FETCH_NUM);
		if ($coins_out[0] > 0) return $coins_out[0];
		else return 0;
	}

	public function transaction_voted_coins_out($transaction_id) {
		$qq = "SELECT SUM(amount) FROM transaction_ios io JOIN addresses a ON io.address_id=a.address_id WHERE io.create_transaction_id='".$transaction_id."' AND a.option_id > 0;";
		$rr = $this->run_query($qq);
		$voted_coins_out = $rr->fetch(PDO::FETCH_NUM);
		if ($voted_coins_out[0] > 0) return $voted_coins_out[0];
		else return 0;
	}

	public function output_message($status_code, $message, $dump_object) {
		if (!$dump_object) $dump_object = array("status_code"=>$status_code, "message"=>$message);
		else {
			$dump_object['status_code'] = $status_code;
			$dump_object['message'] = $message;
		}
		echo json_encode($dump_object);
	}
	
	public function try_apply_invite_key($user_id, $invite_key, &$invite_game) {
		$reload_page = false;
		$invite_key = $this->quote_escape($invite_key);
		
		$q = "SELECT * FROM game_invitations WHERE invitation_key=".$invite_key.";";
		$r = $this->run_query($q);
		
		if ($r->rowCount() == 1) {
			$invitation = $r->fetch();
			
			if ($invitation['used'] == 0 && $invitation['used_user_id'] == "" && $invitation['used_time'] == 0) {
				$qq = "UPDATE game_invitations SET used_user_id='".$user_id."', used_time='".time()."', used=1";
				if ($GLOBALS['pageview_tracking_enabled']) $q .= ", used_ip='".$_SERVER['REMOTE_ADDR']."'";
				$qq .= " WHERE invitation_id='".$invitation['invitation_id']."';";
				$rr = $this->run_query($qq);
				
				if ($invitation['giveaway_id'] > 0) {
					$qq = "UPDATE game_giveaways SET user_id='".$user_id."', status='claimed' WHERE giveaway_id='".$invitation['giveaway_id']."';";
					$rr = $this->run_query($qq);
				}
				$user = new User($this, $user_id);
				$user->ensure_user_in_game($invitation['game_id']);
				
				$invite_game = new Game($this, $invitation['game_id']);
				
				return true;
			}
			else return false;
		}
		else return false;
	}
	
	public function format_seconds($seconds) {
		$seconds = intval($seconds);
		$weeks = floor($seconds/(3600*24*7));
		$days = floor($seconds/(3600*24));
		$hours = floor($seconds / 3600);
		$minutes = floor($seconds / 60);
		
		if ($weeks > 0) {
			if ($weeks == 1) $str = $weeks." week";
			else $str = $weeks." weeks";
			$days = $days - 7*$weeks;
			if ($days != 1) $str .= " and ".$days." days";
			else $str .= " and ".$days." day";
			return $str;
		}
		else if ($days > 1) {
			return $days." days";
		}
		else if ($hours > 0) {
			$str = "";
			if ($hours != 1) $str .= $hours." hours";
			else $str .= $hours." hour";
			$remainder_min = round(($seconds - (3600*$hours))/60);
			if ($remainder_min > 0) {
				$str .= " and ".$remainder_min." ";
				if ($remainder_min == '1') $str .= "minute";
				else $str .= "minutes";
			}
			return $str;
		}
		else if ($minutes > 0) {
			$remainder_sec = $seconds-$minutes*60;
			$str = "";
			if ($minutes != 1) $str .= $minutes." minutes";
			else return $str .= $minutes." minute";
			if ($remainder_sec > 0) $str .= " and ".$remainder_sec." seconds";
			return $str;
		}
		else {
			if ($seconds != 1) return $seconds." seconds";
			else return $seconds." second";
		}
	}
	
	public function game_url_identifier($game_name) {
		$url_identifier = "";
		$append_index = 0;
		$keeplooping = true;
		
		do {
			if ($append_index > 0) $append = "(".$append_index.")";
			else $append = "";
			$url_identifier = $this->make_alphanumeric(str_replace(" ", "-", strtolower($game_name.$append)), "-().:;");
			$q = "SELECT * FROM games WHERE url_identifier=".$this->quote_escape($url_identifier).";";
			$r = $this->run_query($q);
			if ($r->rowCount() == 0) $keeplooping = false;
			else $append_index++;
		} while ($keeplooping);
		
		return $url_identifier;
	}
	
	public function prepend_a_or_an($word) {
		$firstletter = strtolower($word[0]);
		if (strpos('aeiou', $firstletter)) return "an ".$word;
		else return "a ".$word;
	}
	
	public function friendly_intval($val) {
		if ($val > 0) return $val;
		else return 0;
	}
	
	public function fetch_game_from_url() {
		$login_url_parts = explode("/", rtrim(ltrim($_SERVER['REQUEST_URI'], "/"), "/"));
		if ($login_url_parts[0] == "wallet" && count($login_url_parts) > 1) {
			$q = "SELECT * FROM games WHERE url_identifier=".$this->quote_escape($login_url_parts[1]).";";
			$r = $this->run_query($q);
			if ($r->rowCount() == 1) {
				return $r->fetch();
			}
			else return false;
		}
		else return false;
	}
	
	/*public function process_join_requests($variation_id) {
		$q = "SELECT * FROM event_type_variations WHERE variation_id='".$variation_id."';";
		$r = $this->run_query($q);
		
		if ($r->rowCount() == 1) {
			$variation = $r->fetch();
			
			if (in_array($variation['giveaway_status'], array('public_free', 'public_pay'))) {
				$keeplooping = true;
				$last_request_id = 0;
				do {
					$q = "SELECT * FROM game_join_requests WHERE variation_id='".$variation['variation_id']."' AND request_status='outstanding' AND join_request_id > ".$last_request_id." ORDER BY join_request_id ASC LIMIT 1;";
					$r = $this->run_query($q);
					
					if ($r->rowCount() > 0) {
						$join_request = $r->fetch();
						$last_request_id = $join_request['join_request_id'];
						
						$join_user = new User($this, $join_request['user_id']);
						
						$qq = "SELECT * FROM event_types WHERE variation_id='".$variation['variation_id']."' AND event_status='published' ORDER BY event_id ASC LIMIT 1;";
						$rr = $this->run_query($qq);
						
						if ($rr->rowCount() == 1) {
							$db_join_event = $rr->fetch();
							
							$join_user->ensure_user_in_game($db_join_event['event_id']);
							
							$this->run_query("UPDATE game_join_requests SET request_status='complete', event_id='".$db_join_event['event_id']."' WHERE join_request_id='".$join_request['join_request_id']."';");
						}
					}
					else $keeplooping = false;
				}
				while ($keeplooping);
			}
		}
	}

	public function bet_transaction_payback_address($transaction_id) {
		$q = "SELECT * FROM transaction_ios i, transactions t, addresses a WHERE t.transaction_id='".$transaction_id."' AND i.spend_transaction_id=t.transaction_id AND i.address_id=a.address_id ORDER BY a.address ASC LIMIT 1;";
		$r = $this->run_query($q);
		if ($r->rowCount() == 1) {
			return $r->fetch();
		}
		else return false;
	}*/
	
	public function latest_currency_price($currency_id) {
		$q = "SELECT * FROM currency_prices WHERE currency_id='".$currency_id."' AND reference_currency_id='".$this->get_site_constant('reference_currency_id')."' ORDER BY price_id DESC LIMIT 1;";
		$r = $this->run_query($q);
		if ($r->rowCount() > 0) {
			return $r->fetch();
		}
		else return false;
	}
	
	public function get_currency_by_abbreviation($currency_abbreviation) {
		$q = "SELECT * FROM currencies WHERE abbreviation='".strtoupper($currency_abbreviation)."';";
		$r = $this->run_query($q);

		if ($r->rowCount() > 0) {
			return $r->fetch();
		}
		else return false;
	}
	
	public function get_reference_currency() {
		$q = "SELECT * FROM currencies WHERE currency_id='".$this->get_site_constant('reference_currency_id')."';";
		$r = $this->run_query($q);
		if ($r->rowCount() > 0) return $r->fetch();
		else die('Error, reference_currency_id is not set properly in site_constants.');
	}
	
	public function update_all_currency_prices() {
		$reference_currency_id = $this->get_site_constant('reference_currency_id');
		$q = "SELECT * FROM currencies c JOIN oracle_urls o ON c.oracle_url_id=o.oracle_url_id WHERE c.currency_id != '".$reference_currency_id."' GROUP BY o.oracle_url_id;";
		$r = $this->run_query($q);
		
		while ($currency_url = $r->fetch()) {
			$api_response_raw = file_get_contents($currency_url['url']);
			$api_response = json_decode($api_response_raw);
			
			$qq = "SELECT * FROM currencies WHERE oracle_url_id='".$currency_url['oracle_url_id']."';";
			$rr = $this->run_query($qq);
			
			while ($currency = $rr->fetch()) {
				if ($currency_url['format_id'] == 2) {
					$price = $api_response->USD->bid;
				}
				else if ($currency_url['format_id'] == 1) {
					if (!empty($api_response->rates)) {
						$api_rates = (array) $api_response->rates;
						$price = 1/($api_rates[$currency['abbreviation']]);
					}
				}
				
				$qqq = "INSERT INTO currency_prices SET currency_id='".$currency['currency_id']."', reference_currency_id='".$reference_currency_id."', price='".$price."', time_added='".time()."';";
				$rrr = $this->run_query($qqq);
			}
		}
	}
	
	public function update_currency_price($currency_id) {
		$q = "SELECT * FROM currencies WHERE currency_id='".$currency_id."';";
		$r = $this->run_query($q);

		if ($r->rowCount() > 0) {
			$currency = $r->fetch();

			if ($currency['abbreviation'] == "BTC") {
				$reference_currency = $this->get_reference_currency();
				
				$api_url = "https://api.bitcoinaverage.com/ticker/global/all";
				$api_response_raw = file_get_contents($api_url);
				$api_response = json_decode($api_response_raw);
				
				$price = $api_response->$reference_currency['abbreviation']->bid;

				if ($price > 0) {
					$q = "INSERT INTO currency_prices SET currency_id='".$currency_id."', reference_currency_id='".$reference_currency['currency_id']."', price='".$price."', time_added='".time()."';";
					$r = $this->run_query($q);
					$currency_price_id = $this->last_insert_id();

					$q = "SELECT * FROM currency_prices WHERE price_id='".$currency_price_id."';";
					$r = $this->run_query($q);
					return $r->fetch();
				}
				else return false;
			}
			else return false;
		}
		else return false;
	}
	
	public function currency_conversion_rate($numerator_currency_id, $denominator_currency_id) {
		$latest_numerator_rate = $this->latest_currency_price($numerator_currency_id);
		$latest_denominator_rate = $this->latest_currency_price($denominator_currency_id);

		$returnvals['numerator_price_id'] = $latest_numerator_rate['price_id'];
		$returnvals['denominator_price_id'] = $latest_denominator_rate['price_id'];
		$returnvals['conversion_rate'] = round(pow(10,8)*$latest_denominator_rate['price']/$latest_numerator_rate['price'])/pow(10,8);
		return $returnvals;
	}
	
	public function historical_currency_conversion_rate($numerator_price_id, $denominator_price_id) {
		$q = "SELECT * FROM currency_prices WHERE price_id='".$numerator_price_id."';";
		$r = $this->run_query($q);
		$numerator_rate = $r->fetch();

		$q = "SELECT * FROM currency_prices WHERE price_id='".$denominator_price_id."';";
		$r = $this->run_query($q);
		$denominator_rate = $r->fetch();

		return round(pow(10,8)*$denominator_rate['price']/$numerator_rate['price'])/pow(10,8);
	}
	
	public function new_currency_invoice($settle_currency_id, $settle_amount, $user_id, $game_id) {
		$q = "SELECT * FROM currencies WHERE currency_id='".$settle_currency_id."';";
		$r = $this->run_query($q);
		$settle_currency = $r->fetch();

		$pay_currency = $this->get_currency_by_abbreviation('btc');

		$conversion = $this->currency_conversion_rate($settle_currency_id, $pay_currency['currency_id']);
		$settle_curr_per_pay_curr = $conversion['conversion_rate'];

		$pay_amount = round(pow(10,8)*$settle_amount/$settle_curr_per_pay_curr)/pow(10,8);
		
		$invoice_address_id = $this->new_invoice_address();
		$q = "UPDATE invoice_addresses SET currency_id='".$pay_currency['currency_id']."' WHERE invoice_address_id='".$invoice_address_id."';";
		$r = $this->run_query($q);
		
		$time = time();
		$q = "INSERT INTO currency_invoices SET time_created='".$time."', invoice_address_id='".$invoice_address_id."', expire_time='".($time+$GLOBALS['invoice_expiration_seconds'])."', game_id='".$game_id."', user_id='".$user_id."', status='unpaid', invoice_key_string='".$this->random_string(32)."', settle_price_id='".$conversion['numerator_price_id']."', settle_currency_id='".$settle_currency['currency_id']."', settle_amount='".$settle_amount."', pay_price_id='".$conversion['denominator_price_id']."', pay_currency_id='".$pay_currency['currency_id']."', pay_amount='".$pay_amount."';";
		$r = $this->run_query($q);
		$invoice_id = $this->last_insert_id();

		$q = "SELECT * FROM currency_invoices WHERE invoice_id='".$invoice_id."';";
		$r = $this->run_query($q);
		return $r->fetch();
	}
	
	public function vote_details_general($mature_balance) {
		return "";
		/*$html = '
		<div class="row">
			<div class="col-xs-4">Your balance:</div>
			<div class="col-xs-8 greentext">'.number_format(floor($mature_balance/pow(10,5))/1000, 2).' EMP</div>
		</div>	';
		return $html;*/
	}

	public function vote_option_details($option, $rank, $confirmed_votes, $unconfirmed_votes, $sum_votes, $losing_streak) {
		$html = '
		<div class="row">
			<div class="col-xs-4">Current&nbsp;rank:</div>
			<div class="col-xs-8">'.$this->to_ranktext($rank).'</div>
		</div>
		<div class="row">
			<div class="col-xs-4">Confirmed Votes:</div>
			<div class="col-xs-8">'.$this->format_bignum($confirmed_votes/pow(10,8)).' votes ('.(empty($sum_votes)? 0 : (ceil(100*100*$confirmed_votes/$sum_votes)/100)).'%)</div>
		</div>
		<div class="row">
			<div class="col-xs-4">Unconfirmed Votes:</div>
			<div class="col-xs-8">'.$this->format_bignum($unconfirmed_votes/pow(10,8)).' votes ('.(empty($sum_votes)? 0 : (ceil(100*100*$unconfirmed_votes/$sum_votes)/100)).'%)</div>
		</div>
		<div class="row">
			<div class="col-xs-4">Last&nbsp;win:</div>
			<div class="col-xs-8">';
		if ($losing_streak === 0) $html .= "Last&nbsp;round";
		else if ($losing_streak) $html .= $losing_streak.'&nbsp;rounds&nbsp;ago';
		else $html .= "Never";
		$html .= '
			</div>
		</div>';
		return $html;
	}
	
	public function new_invoice_address() {
		$keySet = bitcoin::getNewKeySet();

		if (empty($keySet['pubAdd']) || empty($keySet['privWIF'])) {
			die("<p>There was an error generating the payment address. Please go back and try again.</p>");
		}

		$encWIF = bin2hex(bitsci::rsa_encrypt($keySet['privWIF'], $GLOBALS['rsa_pub_key']));

		$q = "INSERT INTO invoice_addresses SET currency_id=1, pub_key='".$keySet['pubAdd']."', priv_enc='".$encWIF."';";
		$r = $this->run_query($q);
		$address_id = $this->last_insert_id();
		
		return $address_id;
	}
	
	public function decimal_to_float($number) {
		if (strpos($number, ".") === false) return $number;
		else return rtrim(rtrim($number, '0'), '.');
	}
	
	public function display_featured_games() {
		echo '<div class="paragraph">';
		$q = "SELECT g.*, c.short_name AS currency_short_name FROM games g LEFT JOIN currencies c ON g.invite_currency=c.currency_id WHERE g.featured=1 AND (g.game_status='published' OR g.game_status='running') ORDER BY g.featured_score DESC;";
		$r = $this->run_query($q);
		$cell_width = 12;
		
		$counter = 0;
		echo '<div class="row">';
		
		while ($db_game = $r->fetch()) {
			$featured_game = new Game($this, $db_game['game_id']);
			$mining_block_id = $featured_game->last_block_id()+1;
			$current_round_id = $featured_game->block_to_round($mining_block_id);
			?>
			<script type="text/javascript">
			games.push(new Game(<?php
				echo $db_game['game_id'];
				echo ', false';
				echo ', false'.', ';
				echo 'false';
				echo ', ""';
				echo ', "'.$db_game['payout_weight'].'"';
				echo ', '.$db_game['round_length'];
				$bet_round_range = $featured_game->bet_round_range();
				$min_bet_round = $bet_round_range[0];
				echo ', '.$min_bet_round;
				echo ', 0';
				echo ', "'.$db_game['url_identifier'].'"';
				echo ', "'.$db_game['coin_name'].'"';
				echo ', "'.$db_game['coin_name_plural'].'"';
				echo ', "home", "'.$featured_game->event_ids().'"';
			?>));
			</script>
			<?php
			echo '<div class="col-md-'.$cell_width.'">';
			echo '<h3 style="display: inline-block" title="'.$featured_game->game_description().'">'.$featured_game->db_game['name'].'</h3>';
			if ($featured_game->db_game['short_description'] != "") echo "<br/>".$featured_game->db_game['short_description'];
			
			echo '<div id="game'.$counter.'_events"></div>';
			echo '<script type="text/javascript" id="game'.$counter.'_new_event_js">'.$featured_game->new_event_js($counter, false).'</script>';
			
			echo '<a href="/'.$featured_game->db_game['url_identifier'].'/" class="btn btn-success">Play Now</a>';
			echo ' <a href="/explorer/'.$featured_game->db_game['url_identifier'].'/rounds/" class="btn btn-primary">Blockchain Explorer</a>';
			echo '<br/><br/>';
			
			if ($counter%(12/$cell_width) == 1) echo '</div><div class="row">';
			$counter++;
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}
	
	public function refresh_utxo_user_ids($only_unspent_utxos) {
		$update_user_id_q = "UPDATE transaction_ios io JOIN addresses a ON io.address_id=a.address_id SET io.user_id=a.user_id";
		if ($only_unspent_utxos) $update_user_id_q .= " WHERE io.spend_status='unspent'";
		$update_user_id_q .= ";";
		$update_user_id_r = $this->run_query($update_user_id_q);
	}
	public function log($message) {
		$this->run_query("INSERT INTO log_messages SET message=".$this->quote_escape($message).";");
	}
	public function update_schema() {
		$migrations_path = realpath(dirname(__FILE__)."/../sql");
		
		$migration_id = ((int)$this->get_site_constant("last_migration_id"))+1;
		$keep_looping = true;
		do {
			$fname = $migrations_path."/".$migration_id.".sql";
			if (is_file($fname)) {
				$cmd = $this->mysql_binary_location()." -u ".$GLOBALS['mysql_user']." -h ".$GLOBALS['mysql_server'];
				if ($GLOBALS['mysql_password'] != "") $cmd .= " -p".$GLOBALS['mysql_password'];
				$cmd .= " ".$GLOBALS['mysql_database']." < ".$fname;
				exec($cmd);
				$this->set_site_constant("last_migration_id", $migration_id);
				$migration_id++;
			}
			else $keep_looping = false;
		}
		while ($keep_looping);
	}
	public function argv_to_array($argv) {
		$arr = array();
		$arg_i = 0;
		foreach ($argv as $arg) {
			if ($arg_i > 0) {
				$arg_parts = explode("=", $arg);
				if(count($arg_parts) == 2)
					$arr[$arg_parts[0]] = $arg_parts[1];
				else
					$arr[$arg_i-1] = $arg_parts[0];
			}
			$arg_i++;
		}
		return $arr;
	}
	
	public function mysql_binary_location() {
		if (!empty($GLOBALS['mysql_binary_location'])) return $GLOBALS['mysql_binary_location'];
		else {
			$var = $this->run_query("SHOW VARIABLES LIKE 'basedir';")->fetch();
			if (PHP_OS == "WINNT") return $var['Value']."bin/mysql.exe";
			else return $var['Value']."/bin/mysql";
		}
	}
	
	public function php_binary_location() {
		if (!empty($GLOBALS['php_binary_location'])) return $GLOBALS['php_binary_location'];
		else if (PHP_OS == "WINNT") return dirname(ini_get('extension_dir'))."\php.exe";
		else return PHP_BINDIR ."/php";
	}
	
	public function start_regular_background_processes($key_string) {
		$html = "";
		$process_count = 0;
		
		$pipe_config = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w')
		);
		$pipes = array();
		
		if (PHP_OS == "WINNT") $script_path_name = dirname(dirname(__FILE__));
		else $script_path_name = realpath(dirname(dirname(__FILE__)));
		
		$cmd = $this->php_binary_location().' "'.$script_path_name.'/cron/load_blocks.php" key='.$key_string;
		if (PHP_OS != "WINNT") $cmd .= " 2>&1 >/dev/null";
		$block_loading_process = proc_open($cmd, $pipe_config, $pipes);
		if (is_resource($block_loading_process)) $process_count++;
		else $html .= "Failed to start a process for loading blocks.<br/>\n";
		sleep(0.1);
		
		$cmd = $this->php_binary_location().' "'.$script_path_name.'/cron/minutely_main.php" key='.$key_string;
		if (PHP_OS != "WINNT") $cmd .= " 2>&1 >/dev/null";
		$main_process = proc_open($cmd, $pipe_config, $pipes);
		if (is_resource($main_process)) $process_count++;
		else $html .= "Failed to start the main process.<br/>\n";
		sleep(0.1);
		
		$cmd = $this->php_binary_location().' "'.$script_path_name.'/cron/minutely_check_payments.php" key='.$key_string;
		if (PHP_OS != "WINNT") $cmd .= " 2>&1 >/dev/null";
		$payments_process = proc_open($cmd, $pipe_config, $pipes);
		if (is_resource($payments_process)) $process_count++;
		else $html .= "Failed to start a process for processing payments.<br/>\n";
		sleep(0.1);
		
		$cmd = $this->php_binary_location().' "'.$script_path_name.'/cron/address_miner.php" key='.$key_string;
		if (PHP_OS != "WINNT") $cmd .= " 2>&1 >/dev/null";
		$address_miner_process = proc_open($cmd, $pipe_config, $pipes);
		if (is_resource($address_miner_process)) $process_count++;
		else $html .= "Failed to start a process for mining addresses.<br/>\n";
		sleep(0.1);
		
		$cmd = $this->php_binary_location().' "'.$script_path_name.'/cron/fetch_currency_prices.php" key='.$key_string;
		if (PHP_OS != "WINNT") $cmd .= " 2>&1 >/dev/null";
		$currency_prices_process = proc_open($cmd, $pipe_config, $pipes);
		if (is_resource($currency_prices_process)) $process_count++;
		else $html .= "Failed to start a process for updating currency prices.<br/>\n";
		
		$html .= $process_count." background processes were successfully started.<br/>\n";
		return $html;
	}
	
	public function image_url(&$db_image) {
		$url = '/images/custom/'.$db_image['image_id'];
		if ($db_image['access_key'] != "") $url .= '_'.$db_image['access_key'];
		$url .= '.'.$db_image['extension'];
		return $url;
	}
	
	public function delete_unconfirmable_transactions() {
		$start_time = microtime(true);
		$unconfirmed_tx_r = $this->run_query("SELECT * FROM transactions WHERE block_id IS NULL ORDER BY game_id ASC;");
		$game_id = false;
		
		while ($unconfirmed_tx = $unconfirmed_tx_r->fetch()) {
			if ($unconfirmed_tx['game_id'] != $game_id) {
				$game_id = $unconfirmed_tx['game_id'];
				$game = new Game($this, $game_id);
			}
			
			$coins_in = $this->transaction_coins_in($unconfirmed_tx['transaction_id']);
			//$coins_out = $this->transaction_coins_out($unconfirmed_tx['transaction_id']);
			if ($coins_in == 0) {
				$this->run_query("DELETE t.*, io.* FROM transactions t JOIN transaction_ios io ON t.transaction_id=io.create_transaction_id WHERE t.transaction_id='".$unconfirmed_tx['transaction_id']."';");
			}
			else if ($unconfirmed_tx['fee_amount'] < 0) {
				$this->run_query("DELETE t.*, io.* FROM transactions t JOIN transaction_ios io ON t.transaction_id=io.create_transaction_id WHERE t.transaction_id='".$unconfirmed_tx['transaction_id']."';");
			}
		}
		echo "\nTook ".(microtime(true)-$start_time)." sec to delete unconfirmable transactions.\n\n";
	}
	
	public function game_admin_row(&$thisuser, $user_game, $selected_game_id) {
		$html = '
		<div class="row game_row';
		if ($user_game['game_id'] == $selected_game_id) $html .= ' boldtext';
		$html .= '">
			<div class="col-sm-1 game_cell">
				'.ucwords($user_game['game_status']).'
			</div>
			<div class="col-sm-5 game_cell">
				<a target="_blank" href="/wallet/'.$user_game['url_identifier'].'/">'.$user_game['name'].'</a>
			</div>
			<div class="col-sm-3 game_cell">
				<a id="fetch_game_link_'.$user_game['game_id'].'" href="" onclick="switch_to_game('.$user_game['game_id'].', \'fetch\'); return false;">Settings</a>
			</div>
			<div class="col-sm-3 game_cell">';
			$perm_to_invite = $thisuser->user_can_invite_game($user_game);
			if ($perm_to_invite) {
				$html .= '
				<a href="" onclick="manage_game_invitations('.$user_game['game_id'].'); return false;">Invitations</a>';
			}
			$html .= '
			</div>
		</div>';
		return $html;
	}
	
	public function game_info_table(&$db_game) {
		$html = "";
		
		$blocks_per_hour = 3600/$db_game['seconds_per_block'];
		$round_reward = ($db_game['pos_reward']+$db_game['pow_reward']*$db_game['round_length'])/pow(10,8);
		$seconds_per_round = $db_game['seconds_per_block']*$db_game['round_length'];
		$db_game_url = $GLOBALS['base_url']."/".$db_game['url_identifier'];
		
		$invite_currency = false;
		if ($db_game['invite_currency'] > 0) {
			$q = "SELECT * FROM currencies WHERE currency_id='".$db_game['invite_currency']."';";
			$r = $this->run_query($q);
			$invite_currency = $r->fetch();
		}
		
		if ($db_game['game_id'] > 0) { // This public function can also be called with a game variation
			$html .= '<div class="row"><div class="col-sm-5">Game title:</div><div class="col-sm-7">'.$db_game['name']."</div></div>\n";
			
			$html .= '<div class="row"><div class="col-sm-5">Game URL:</div><div class="col-sm-7"><a href="'.$db_game_url.'">'.$db_game_url."</a></div></div>\n";
		}
		
		$html .= '<div class="row"><div class="col-sm-5">Length of game:</div><div class="col-sm-7">';
		if ($db_game['final_round'] > 0) $html .= $db_game['final_round']." rounds (".$this->format_seconds($seconds_per_round*$db_game['final_round']).")";
		else $html .= "Game does not end";
		$html .= "</div></div>\n";
		
		$html .= '<div class="row"><div class="col-sm-5">Cost to join:</div><div class="col-sm-7">';
		if ($db_game['giveaway_status'] == "invite_pay" || $db_game['giveaway_status'] == "public_pay") $html .= $this->format_bignum($db_game['invite_cost'])." ".$invite_currency['short_name']."s";
		else $html .= "Free";
		$html .= "</div></div>\n";
		
		$html .= '<div class="row"><div class="col-sm-5">Additional buy-ins?</div><div class="col-sm-7">';
		if ($db_game['buyin_policy'] == "unlimited") $html .= "Unlimited";
		else if ($db_game['buyin_policy'] == "none") $html .= "Not allowed";
		else if ($db_game['buyin_policy'] == "per_user_cap") $html .= "Up to ".$this->format_bignum($db_game['per_user_buyin_cap'])." ".$invite_currency['short_name']."s per player";
		else if ($db_game['buyin_policy'] == "game_cap") $html .= $this->format_bignum($db_game['game_buyin_cap'])." ".$invite_currency['short_name']."s available";
		else if ($db_game['buyin_policy'] == "game_and_user_cap") $html .= $this->format_bignum($db_game['per_user_buyin_cap'])." ".$invite_currency['short_name']."s per person until ".$this->format_bignum($db_game['game_buyin_cap'])." ".$invite_currency['short_name']."s are reached";
		$html .= "</div></div>\n";
		
		$html .= '<div class="row"><div class="col-sm-5">Inflation:</div><div class="col-sm-7">';	
		if ($db_game['inflation'] == "linear") $html .= "Linear (".$this->format_bignum($round_reward)." coins per round)";
		else if ($db_game['inflation'] == "fixed_exponential") $html .= "Fixed Exponential (".(100*$db_game['exponential_inflation_rate'])."% per round)";
		else $html .= "Exponential<br/>".$this->votes_per_coin($db_game)." votes per coin (".(100*$db_game['exponential_inflation_rate'])."% per round)";
		$html .= "</div></div>\n";
		
		$total_inflation_pct = $this->game_final_inflation_pct($db_game);
		if ($total_inflation_pct) {
			$html .= '<div class="row"><div class="col-sm-5">Total inflation:</div><div class="col-sm-7">'.number_format($total_inflation_pct)."%</div></div>\n";
		}
		
		$html .= '<div class="row"><div class="col-sm-5">Distribution:</div><div class="col-sm-7">';
		if ($db_game['inflation'] == "linear") $html .= $this->format_bignum($db_game['pos_reward']/pow(10,8))." to voters, ".$this->format_bignum($db_game['pow_reward']*$db_game['round_length']/pow(10,8))." to miners";
		else $html .= (100 - 100*$db_game['exponential_inflation_minershare'])."% to voters, ".(100*$db_game['exponential_inflation_minershare'])."% to miners";
		$html .= "</div></div>\n";
		
		$html .= '<div class="row"><div class="col-sm-5">Blocks per round:</div><div class="col-sm-7">'.$db_game['round_length']."</div></div>\n";
		
		$html .= '<div class="row"><div class="col-sm-5">Block target time:</div><div class="col-sm-7">'.$this->format_seconds($db_game['seconds_per_block'])."</div></div>\n";
		
		$html .= '<div class="row"><div class="col-sm-5">Average time per round:</div><div class="col-sm-7">'.$this->format_seconds($db_game['round_length']*$db_game['seconds_per_block'])."</div></div>\n";
		
		if ($db_game['maturity'] != 0) {
			$html .= '<div class="row"><div class="col-sm-5">Transaction maturity:</div><div class="col-sm-7">'.$db_game['maturity']." block";
			if ($db_game['maturity'] != 1) $html .= "s";
			$html .= "</div></div>\n";
		}
		
		return $html;
	}
	
	public function game_final_inflation_pct(&$db_game) {
		if ($db_game['final_round'] > 0) {
			if ($db_game['inflation'] == "fixed_exponential" || $db_game['inflation'] == "exponential") {
				$inflation_factor = pow(1+$db_game['exponential_inflation_rate'], $db_game['final_round']);
			}
			else {
				if ($db_game['start_condition'] == "players_joined") {
					$db_game['initial_coins'] = $db_game['start_condition_players']*$db_game['giveaway_amount'];
					$final_coins = $this->ideal_coins_in_existence_after_round($db_game, $db_game['final_round']);
					$inflation_factor = $final_coins/$db_game['initial_coins'];
				}
				else return false;
			}
			$inflation_pct = round(($inflation_factor-1)*100);
			return $inflation_pct;
		}
		else return false;
	}
	
	public function ideal_coins_in_existence_after_round(&$db_game, $round_id) {
		if ($db_game['inflation'] == "linear") return $db_game['initial_coins'] + $round_id*($db_game['pos_reward'] + $db_game['round_length']*$db_game['pow_reward']);
		else if ($db_game['inflation'] == "fixed_exponential") return floor($db_game['initial_coins'] * pow(1 + $db_game['exponential_inflation_rate'], $round_id));
		//else die("exponential inflation not implemented in global_functions.php");
	}
	
	public function coins_created_in_round(&$db_game, $round_id) {
		if ($db_game['inflation'] == "exponential") {
			$game = new Game($this, $db_game['game_id']);
			$coi_block = ($round_id-1)*$game->db_game['round_length'];
			$coins_in_existence = $game->coins_in_existence($coi_block);
			return $coins_in_existence*$game->db_game['exponential_inflation_rate'];
		}
		else {
			$thisround_coins = $this->ideal_coins_in_existence_after_round($db_game, $round_id);
			$prevround_coins = $this->ideal_coins_in_existence_after_round($db_game, $round_id-1);
			if (is_nan($thisround_coins) || is_nan($prevround_coins) || is_infinite($thisround_coins) || is_infinite($prevround_coins)) return 0;
			else return $thisround_coins - $prevround_coins;
		}
	}

	public function pow_reward_in_round(&$db_game, $round_id) {
		if ($db_game['inflation'] == "linear") return $db_game['pow_reward'];
		else if ($db_game['inflation'] == "fixed_exponential" || $db_game['inflation'] == "exponential") {
			$round_coins_created = $this->coins_created_in_round($db_game, $round_id);
			$round_pow_coins = floor($db_game['exponential_inflation_minershare']*$round_coins_created);
			return floor($round_pow_coins/$db_game['round_length']);
		}
		else return 0;
	}

	public function pos_reward_in_round(&$db_game, $round_id) {
		if ($db_game['inflation'] == "linear") return $db_game['pos_reward'];
		else if ($db_game['inflation'] == "fixed_exponential") {
			if ($round_id > 1 || empty($db_game['game_id'])) {
				$round_coins_created = $this->coins_created_in_round($db_game, $round_id);
			}
			else {
				$game = new Game($this, $db_game['game_id']);
				$round_coins_created = $game->coins_in_existence(false)*$db_game['exponential_inflation_rate'];
			}
			return floor((1-$db_game['exponential_inflation_minershare'])*$round_coins_created);
		}
		else {
			$game = new Game($this, $db_game['game_id']);
			$mining_block_id = $game->last_block_id()+1;
			$current_round = $game->block_to_round($mining_block_id);
			
			if ($round_id == $current_round) {
				$q = "SELECT SUM(".$game->db_game['payout_weight']."_score), SUM(unconfirmed_".$game->db_game['payout_weight']."_score) FROM options op JOIN events e ON op.event_id=e.event_id WHERE e.game_id='".$game->db_game['game_id']."';";
				$r = $this->run_query($q);
				$r = $r->fetch();
				$score = $r['SUM('.$game->db_game['payout_weight'].'_score)']+$r['SUM(unconfirmed_'.$game->db_game['payout_weight'].'_score)'];
			}
			else {
				$q = "SELECT SUM(".$game->db_game['payout_weight']."_score) FROM event_outcome_options eoo JOIN events e ON eoo.event_id=e.event_id WHERE e.game_id='".$game->db_game['game_id']."' AND round_id='".$round_id."';";
				$r = $this->run_query($q);
				$r = $r->fetch();
				$score = $r["SUM(".$game->db_game['payout_weight']."_score)"];
			}
			
			return $score/$this->votes_per_coin($db_game);
		}
	}
	
	public function votes_per_coin($db_game) {
		if ($db_game['inflation'] == "exponential") {
			$votes_per_coin = 1/$db_game['exponential_inflation_rate'];
			return $votes_per_coin;
		}
		else return 0;
	}
}
?>