<?php
/*
Controller name: Shelves
Controller description: Mimetic Book Shelf methods
*/

class MB_API_Shelf_Controller {
	
/*

Example URL:
http://myblog.com/mb/shelf/items_by_user/?id=userid


*/
	

	/*
	 * Return the shelves array as a JSON string for a particular user
	 * This is a json file, "shelves.json", used by the Mimetic Books app to know
	 * what is available for download.
	 */
	public function shelf_by_user() {
		global $mb_api;
		global $wpdb, $table_prefix;
		
		$purchase_history = array();
		
		$mb_api->write_log(__FUNCTION__);

		if (! $this->confirm_auth() ) {
			$this->write_log("Authorization not accepted.");
			return false;
		}

		$this->shelf_init();


		// Get book ID, username, password
		extract($mb_api->query->get(array('id')));

		$user_id = $id;
		
// testing
$user_id = 5;
		
		// --------
		// Get all books owned (purchased) by this user (customer?)
		$purchase_history = $this->get_users_purchases($user_id);


print_r($purchase_history);


		$shelves = array (
			'path'		=> "shelves",
			'title'		=> "mylib",
			'maxsize'	=> 100,
			'id'		=> "shelves",
			'password'	=> "mypassword",
			'filename'	=> "shelves.json",
			'itemsByID'	=> array ()
		);
		
		
		
		
		// --------
		// Build the shelf array
		
		$posts = $mb_api->introspector->get_posts(array(
				'post_type' => 'book',
				'posts_per_page'	=> -1,
				'post_status' => 'any'
			), true);
	
		$posts = $mb_api->introspector->get_posts(array(
				'post_type' => 'book',
				'posts_per_page'	=> -1,
				'post_status' => 'any',
				'post__in' => $purchase_history
			), true);
	

		/*
		 * Book Info:
			'id'			=> $book_id, 
			'title'			=> $title, 
			'author'		=> $author, 
			'theme'			=> $theme, 
			'publisher_id'	=> $publisher_id,  
			'description'	=> $description, 
			'short_description'		=> $short_description, 
			'type'			=> $type, 
			'datetime'		=> $post->date, 
			'modified'		=> $post->modified, 
			'icon_url'		=> $icon_url, 
			'poster_url'	=> $poster_url,
			'category_id'	=> $category_id
		 */
		foreach ($posts as $post) {
			$info = $mb_api->get_book_info_from_post($post->ID);
			$book_id = $info['id'];
			// Only add the item if it is marked published with our custom meta field.
			$is_published = get_post_meta($post->ID, "mb_published", true);
			// Also check the package's directory is there
			$tarfilepath = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . $book_id . DIRECTORY_SEPARATOR . "item.tar";
			$is_published =  (file_exists($tarfilepath) && $is_published);

			if ($is_published) {
			
				// almost all names are same, but not completely....
				// I've included some extras...maybe they'll be useful later.

				$item = array (
					'id'				=> $book_id, 
					'title'				=> $info['title'], 
					'author'			=> $info['author'], 
					'publisherid'		=> $info['publisher_id'],  
					'description'		=> $info['description'], 
					'shortDescription'	=> $info['short_description'], 
					'type'				=> $info['type'], 
					'datetime'			=> $info['datetime'], 
					'modified'			=> $info['modified'],
					'path'				=> $book_id,
					'shelfpath'			=> $mb_api->settings['shelves_dir_name'],
//					'itemShelfPath'		=>$mb_api->settings['shelves_dir_name'] . DIRECTORY_SEPARATOR . $info['id'],
					'theme'				=> $info['theme'],				
					'hideHeaderOnPoster'	=> $info['hideHeaderOnPoster']
				);
				$shelves['itemsByID'][$book_id] = $item;
			}
		}
		   $output = json_encode($shelves);
		   return $output;
	}


	private function shelf_init() {
		global $mb_api;
	
	}
	
	
	// Update the publishers file of the list of publishers
	public function write_publishers_file() {
		global $mb_api;
		$result = $mb_api->write_publishers_file();
		return $result;
	}


	public function get_users_purchases($id = null) {
		global $mb_api;
		
		if (!$id) {
			extract($mb_api->query->get(array('id')));
		}
		
		$user_id = $id;
	
		$purchases = $mb_api->commerce->get_users_purchases($user_id);
		return $purchases;
		
	}


	// ----------------------------------------------------------------------

	/*
	 * Confirms that the transaction is authorized, i.e. remote has signed in properly.
	 * If the authorization module of this plugin is not activated, just return true,
	 * allowing all access. This is useful for testing.
	*/
	protected function confirm_auth() {
		global $mb_api;
		
		// Check to see if the Auth controller is active.
		// If Auth is not activated, then don't authenticate, just return 'true'.
		$controller = "auth";
		$active = in_array($controller, $mb_api->get_controllers());
		$available_controllers = $mb_api->get_controllers();
		$active_controllers = explode(',', get_option('mb_api_controllers', 'core'));
		
		if (count($active_controllers) == 1 && empty($active_controllers[0])) {
			$active_controllers = array();
		}
		$active = in_array($controller, $active_controllers);
		if (!$active) {
			return true;
		}
		
		// ----- Auth is activate, so do authenticate!
		
		/*
		if (!$mb_api->query->nonce) {
			$mb_api->error("You must include a 'nonce' value to create posts. Use the `get_nonce` Core API method.");
		}
		*/
	
		if (!$mb_api->query->cookie) {
			$mb_api->error("You must include a 'cookie' authentication cookie.");
			return false;
		}
		
		/*
		$nonce_id = $mb_api->get_nonce_id('posts', 'create_post');
		if (!wp_verify_nonce($mb_api->query->nonce, $nonce_id)) {
			$mb_api->error("Your 'nonce' value was incorrect. Use the 'get_nonce' API method.");
			return false;
		}
		*/
		
		$user_id = wp_validate_auth_cookie($mb_api->query->cookie, 'logged_in');
		if (!$user_id) {
			$mb_api->error("Invalid authentication cookie. Use the `generate_auth_cookie` Auth API method.");
			return false;
		}
	
		if (!user_can($user_id, 'edit_posts')) {
			$mb_api->error("You need to login with a user capable of creating posts.");
			return false;
		}
	
		nocache_headers();
		
		return true;
	}


}

?>
