<?php
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode("/", $uri);

if ($uri_parts[1] == "reset_password") {
	include("reset_password.php");
}
else if ($uri_parts[1] == "wallet") {
	include("wallet.php");
}
else if ($uri_parts[1] == "games") {
	include("games.php");
}
else if ($uri_parts[1] == "api") {
	include("api.php");
}
else if ($uri_parts[1] == "explorer") {
	include("explorer.php");
}
else {
	include("includes/connect.php");
	include("includes/get_session.php");

	$q = "SELECT * FROM game_types t JOIN game_type_variations v ON t.game_type_id=v.game_type_id JOIN voting_option_groups og ON t.option_group_id=og.option_group_id WHERE v.url_identifier='".mysql_real_escape_string($uri_parts[1])."';";
	$r = run_query($q);
	if (mysql_numrows($r) == 1) {
		$game_variation = mysql_fetch_array($r);
		include("variation_homepage.php");
	}
	else {
		$q = "SELECT * FROM games WHERE url_identifier='".mysql_real_escape_string($uri_parts[1])."';";
		$r = run_query($q);
		if (mysql_numrows($r) == 1) {
			$game = mysql_fetch_array($r);
			if (in_array($game['game_status'], array("running","published","completed"))) {
				include("game_homepage.php");
			}
			else echo "404 - Page not found";
		}
		else echo "404 - Page not found";
	}
}
?>