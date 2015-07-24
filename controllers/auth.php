<?php
/*
Controller Name: Auth
Controller Description: Authentication add-on controller for the Wordpress Mimetic Books API plugin. If this controller is activated, all requests to the mb/book controller will require authentication.
Controller Author: Matt Berg
Controller Author Twitter: @mimetic
*/

class MB_API_Auth_Controller {

	public function validate_auth_cookie() {
		global $mb_api;

		if (!$mb_api->query->cookie) {
			$mb_api->error("You must include a 'cookie' authentication cookie.");
		}		

    	$valid = wp_validate_auth_cookie($mb_api->query->cookie, 'logged_in') ? true : false;

		return array(
			"valid" => $valid
		);
	}

	public function generate_auth_cookie() {
		global $mb_api;

		$nonce_id = $mb_api->get_nonce_id('auth', 'generate_auth_cookie');
		if (!wp_verify_nonce($mb_api->query->nonce, $nonce_id)) {
			$mb_api->error("Your 'nonce' value was incorrect. Use the 'get_nonce' API method.");
		}

		if (!$mb_api->query->username) {
			$mb_api->error("You must include a 'username' var in your request.");
		}
		
		if (!$mb_api->query->password) {
			$mb_api->error("You must include a 'password' var in your request.");
		}		

    	$user = wp_authenticate($mb_api->query->username, $mb_api->query->password);
    	if (is_wp_error($user)) {
    		$mb_api->error("Invalid username and/or password.");
    		remove_action('wp_login_failed', $mb_api->query->username);
    	}

    	$expiration = time() + apply_filters('auth_cookie_expiration', 1209600, $user->ID, true);

    	$cookie = wp_generate_auth_cookie($user->ID, $expiration, 'logged_in');

		return array(
			"cookie" => $cookie,
			"user" => array(
				"id" => $user->ID,
				"username" => $user->user_login,
				"nicename" => $user->user_nicename,
				"email" => $user->user_email,
				"url" => $user->user_url,
				"registered" => $user->user_registered,
				"displayname" => $user->display_name,
				"firstname" => $user->user_firstname,
				"lastname" => $user->last_name,
				"nickname" => $user->nickname,
				"description" => $user->user_description,
				"capabilities" => $user->wp_capabilities,
			),
		);
	}
	
	public function get_currentuserinfo() {
		global $mb_api;

		if (!$mb_api->query->cookie) {
			$mb_api->error("You must include a 'cookie' var in your request. Use the `generate_auth_cookie` Auth API method.");
		}

		$user_id = wp_validate_auth_cookie($mb_api->query->cookie, 'logged_in');
		if (!$user_id) {
			$mb_api->error("Invalid authentication cookie. Use the `generate_auth_cookie` Auth API method.");
		}

		$user = get_userdata($user_id);

		return array(
			"user" => array(
				"id" => $user->ID,
				"username" => $user->user_login,
				"nicename" => $user->user_nicename,
				"email" => $user->user_email,
				"url" => $user->user_url,
				"registered" => $user->user_registered,
				"displayname" => $user->display_name,
				"firstname" => $user->user_firstname,
				"lastname" => $user->last_name,
				"nickname" => $user->nickname,
				"description" => $user->user_description,
				"capabilities" => $user->wp_capabilities,
			)
		);
	}	

}