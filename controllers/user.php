<?php
/*
Controller Name: User
Controller Description: Controller to allow adding/modification of users. If the AUTH controller is activated, all requests to the controller will require authentication.
Controller Author: David Gross
Controller Author Twitter: @mimetic

validate_user
get_user_info

delete_user
update_user

user_email_is_unused


handle_user_register:
example: http://localhost/photobook/wordpress/mb/user/handle_user_register/?first_name=David&last_name=Gross&email=david.mimetic@gmail.com

You can add &dev=1 to the URL to use developing feedback


Some error types:
empty_user_login, Cannot create a user with an empty login name.
existing_user_login, This username is already registered.
existing_user_email, This email address is already registered.


*/

class MB_API_User_Controller {

	public function validate_user() {
		global $mb_api;

		if (!$mb_api->query->cookie) {
			$mb_api->error("You must include a 'cookie' authentication cookie. Use the `create_auth_cookie` Auth API method.");
		}		

    	$valid = wp_validate_auth_cookie($mb_api->query->cookie, 'logged_in') ? true : false;
		if ($valid) {		
			$mb_api->error('valid_user', 'ok');
		} else {
			$mb_api->error('invalid_user', 'error');
		}
	}

	// Get most info for the current user (not including password)
	public function get_currentuser_info() {
		global $mb_api;

		if (! $mb_api->confirm_auth() ) {
			$this->write_log("Authorization not accepted.");
			return false;
		}

		$user_id = wp_validate_auth_cookie($mb_api->query->cookie, 'logged_in');
		if (!$user_id) {
			$mb_api->error("Invalid authentication cookie. Use the `generate_auth_cookie` Auth API method.");
		}

		$user = get_userdata($user_id);

		return array(
			"status" => "ok",
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
	

	
	public function delete_user() {
		global $mb_api;
		
	}
	

	private function error($message = 'Unknown error', $status = 'error') {
		global $mb_api;		
		$mb_api->error($message, $status);
	}


	public function email_debug_data($msg = "") {
		if ($msg) {
			$email_to = 'david.mimetic@gmail.com';		
			mail($email_to, 'From mb_api_user plugin', $msg);
		}
	}



	public function new_user() {
		global $mb_api;
		
		$dev = 0;
		
		$username = "";
		$password = "";
		$firstname = "";
		$lastname = "";
		$mba_api_suppress_welcome = false;
		
		$settings = $mb_api->settings;

		extract($_REQUEST);
		
		if ($dev) {
			$mb_api->write_log("new_user ($username, $password, $firstname, $lastname) ");
		}
		
		$password || $password = wp_generate_password();
		
		// If no username provided, create username from first/last, or use "Anonymous".
		$username || $username = strtolower(trim(trim($firstname) . " " .  trim($lastname)));
		$username || $username = "Anonymous";
		$username = str_replace(" ", "_", $username);
		
		// Sanitization security
		$username = sanitize_text_field ($username);
		$email = sanitize_text_field ($email);
		$firstname = sanitize_text_field ($firstname);
		$lastname = sanitize_text_field ($lastname);
		
		/*
		// Be sure the username doesn't exist. If it does, replace it with username_x,
		// where x is the next highest possibility.
		$x = 2;
		$u = $username;
		while ( username_exists( $username ) ) {
			$suf = sprintf("%03s",$x++); // Zero-padding
			$username = $u . '_' . $suf;
		}
		*/
		
		if (isset($mb_api->settings['mb_api_key']) && ($mb_api_key != $mb_api->settings['mb_api_key']) ) {
			$mb_api->error('api_key_invalid'); //invalid api key if one was set
		}
		
		$username_passed = false;
		$email_key = $username_key = false;
		
		//validation block
		if (username_exists($username) ) {
			$mb_api->error('username_exists'); //username exists
		} else if (!is_email($email)) {
			$mb_api->error('email_invalid'); //invalid email address (according to Wordpress)
		} else if (email_exists($email) ) {
			$mb_api->error('email_exists'); //some other inconsiderate user dared have the same email (or they are already registered)
		} else if (!validate_username($username)) {
			$mb_api->error('username_invalid'); //username not in right format for Wordpress (apparently)
		}
		//end validation block

		//signup user block
		// Notice first_name, last_name in WP style, but firstname, lastname externally.
		$user_data = array(
			'ID'=>''
			, 'user_login'=> $username
			, 'user_email'=> $email
			, 'user_pass'=> $password
			, 'first_name'=> $firstname
			, 'last_name'=> $lastname
		);
		
				
		if ($user_id = wp_insert_user($user_data)) {
			if (is_numeric($user_id)) {
				if (!$mba_api_suppress_welcome) {
					wp_new_user_notification($user_id, $password);
				}
			}

			$r = array(
				"status" => "ok",
				"error" => "",
				"message" => "Account created.",
				"username" => $username,
				"password" => $password,
				"email" => $email,
				"firstname" => $firstname,
				"lastname" => $lastname
				);
			//$mb_api->response->respond($r, "ok");			
			return $r;

		} else {
			$mb_api->error('user_creation_failed'); //Unknown error message. Insert or update probably failed on something I have not validated against.
			//email admin here to say that it has failed passing the user object?
		}
		//end signup user block
		$mb_api->error("user_creation_failed");; //if the script gets to here (which it shouldn't ever do then kill it anyway)
	}
	
	// -----
} 	// end class