<?php
/*
Controller name: Book
Controller description: Mimetic Book methods
*/

class MB_API_Book_Controller {
	
/*
build:
Build the book object from the Wordpress site.
We use categories to divide the book into chapters.
The Re-Order plugin lets us control page order beyond the standard WP ordering of date, etc.

Example URL:
http://myblog.com/mb/book/build/?category_slug_and=book1,chapter-1

arguments:
 * id or post_id = page id(s), e.g 1 or 1,4
 * slug or post_slug = page slug(s), e.g. myslug or myslug,yourslug
 * category_id = all posts in category id
 * category_slug = all posts in category slug
 * category_id_and = all posts in the list of categories, e.g. 1,2,5
 * category_slug_and = all posts in the list of categories, e.g. book1,chaper1,chapter2

We use some of the WP fields for our own purposes:
- format_id : The template to apply to the page. This is actually quite limiting?
- category : "book" --- this could tell us that the page is to be in the book, since not all blog pages will be.
- category : This could tell us the chapter, e.g. "chapter 1", or "intro", etc.


*/
	
	/*
	 * Get a converted tar file book from a client site, or from this site.
	 * $id = book unique id, NOT the WordPress book post internal ID.
	 * 
	 * 
	 * LOCAL
	 * Get the file from the mb-book-packages directory.
	 * testing: http://localhost/photobook/wordpress/mb/book/receive_book_package_from_client/?dev=1&id=123456&DEBUG=true
	 * 
	 * 
	 * REMOTE
	 * If we are getting the file from a remote site, then this probably works best with a POST, not a GET!
	 * You can't really send a tar file in a GET, after all.
	 * Required params:
	 * 
	 * example : http://localhost/photobook/wordpress/mb/book/receive_book_package_from_client/?id=123456&u=test&p=pass&f=(filedata)
	 */
	public function publish_book_package() {
		global $mb_api;
		
		$local = false;		
		$distribution_url = trim(get_option('mb_api_book_publisher_url'));
		$error = "";
		
		if ($distribution_url) {
			// REMOTE PUBLISHING

			// Get book ID, username, password
			extract($mb_api->query->get(array('book_id', 'u', 'p', 'f')));

			if (!$book_id || !$u || !$p || !$f) {
				$mb_api->error(__FUNCTION__.": Remote publishing requires book id, username, password, and file data ($book_id, $u, $p).");
			}

			$book_post = $this->get_book_post_from_book_id($book_id);
			$id = $book_post->ID;

			// Make a dir to hold the book package
			$dir = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . strtolower($id);
			if(! is_dir($dir))
				mkdir($dir);

			if (! $this->confirm_auth() ) {
				return false;
			}

			$pkg = base64_decode($f);

			// Overwrite any existing file without asking
			$filename = $dir . DIRECTORY_SEPARATOR . "item.tar";
			$handle = fopen($filename,"w+"); 
			if(!fwrite($handle,$pkg)) { 
				$mb_api->error(__FUNCTION__.": Could not write the file, $filename.");
			}

		
			// book posts belong to the user(?)
			$user = get_user_by('login', $u);
			if (!$user) {
				$user = get_userdata( 1 );
			}
		} else {
			// LOCAL PUBLISHING
			$user = wp_get_current_user();
			
			// Get book ID, username, password
			extract($mb_api->query->get(array('id')));
			
			// TESTING
			isset($book_id) || $book_id = "123456";
			
			if ($book_id) {
				$book_post = $this->get_book_post_from_book_id($book_id);
				$id = $book_post->ID;
			} elseif ($id) {
				$book_post = $this->get_book_post($id);
			} else {
				$mb_api->error(__FUNCTION__.": Local publishing must include book post id (id) or book id (book_id).");
			}
			

			$this->build_book_package($id);
					
			$dir = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . $book_id;

			// Overwrite any existing file without asking
			$filename = $dir . DIRECTORY_SEPARATOR . "item.tar";
			$src = $mb_api->package_dir . DIRECTORY_SEPARATOR . "$book_id.tar";
			
			if (!file_exists($src)) {
				$mb_api->error(__FUNCTION__.": " . basename($src) . " does not exist.");
			}
			
			// Make a dir to hold the book package
			if(! is_dir($dir))
				mkdir($dir);

			// Copy the local file to the shelves directory and delete the package
			$success = copy($src,$filename);
			if (!$success) {
				$mb_api->error(__FUNCTION__.": Failed to copy $src to $filename.");
			} else {
				unlink ($src);
			}
			
		}
		
		// we use $book_id for directories, and WP insists on lowercase
		$book_id = strtolower($book_id);

		
		// Extract the icon, poster, and item.json
		// Don't fail if this fails, just throw a warning?
		try {
			$phar = new PharData($filename);
			$phar->extractTo($dir, array('icon.png', 'poster.jpg', 'item.json'), true);
		} catch (Exception $e) {
			// handle errors
			// This includes missing files, when the poster or icon files are missing.
			// DON'T quit here, it is probably just be a missing file.
			$error = "Error extracting icon or poster or item from the book package. Probably missing poster or icon.";
			//$mb_api->error(__FUNCTION__.": Failed to open the tar file to get the icon, poster, and item: " . $e);
		}

		// ------------------------------------------------------------
		// Create or Update a post entry in the Wordpress for this book!
		// First, look for an existing entry with this ID
		$book_post = $this->get_book_post($id);

		$user_id = $user->ID;

		$info = json_decode( file_get_contents($dir . DIRECTORY_SEPARATOR . "item.json") );


		// If post does not exist, create it.
		// This must be a remote publish, since the book post does not exist.
		if ($book_post) {
			$post_id = $book_post->id;
		} else {
			
			// see: http://codex.wordpress.org/Function_Reference/wp_insert_post
			$post = array(
				'comment_status' => 'open', // 'closed' means no comments.
				'ping_status'    => 'closed', // 'closed' means pingbacks or trackbacks turned off
				'post_status'    => 'private',  //Set the status of the new post.
				'post_type'      => 'book',
				'tags_input'     => 'book',
				'ID'             => $post_id,
				'post_content'   => $info->description,
				'post_excerpt'   => $info->shortDescription,
				'post_date'      => $info->date,
				'post_name'      => "item_{$book_id}",	// slug
				'post_modified'  => $info->modificationDate,
				'post_title'     => $info->title,
				'post_author'    => $user_id //The user ID number of the author.
				);

			$post_id = wp_insert_post( $post, true );
			if ( is_wp_error($post_id) ) {
				return $post_id->get_error_message();
			}

			// Delete all attachments to the post, so they can be replaced.
			$this->delete_all_attachments($post_id);
			// Do NOT delete the tar file.
			// $this->delete_all_attachments($post_id, "item.tar");
			
			// Attach new files to the book post:
			// 
			// you must first include the image.php file
			// for the function wp_generate_attachment_metadata() to work
			@include_once (ABSPATH . 'wp-admin/includes/image.php');

			// Attach the tar package file to the book posting
			$this->attach_file_to_post($filename, $post_id);

			// Attach the icon file to the book posting
			$filename = $dir . DIRECTORY_SEPARATOR . "icon.png";
			$this->attach_file_to_post($filename, $post_id);

			// Attach the poster file to the book posting
			$filename = $dir . DIRECTORY_SEPARATOR . "poster.jpg";
			$this->attach_file_to_post($filename, $post_id);

			// Attach the item.json file to the book posting
			$filename = $dir . DIRECTORY_SEPARATOR . "item.json";
			$this->attach_file_to_post($filename, $post_id);
		}
		

		
		// Custom fields:

		// The user's login is their publisher ID
		update_post_meta($post_id, "mb_publisher_id", $user->data->user_login);
		
		// Book author field
		update_post_meta($post_id, "mb_book_author", $info->author);

		
		if ($error) {
			//data,textStatus
			$error['data'] = $error;
			$error = json_encode($error);
		}
		
		return $error;
	}
	
		
		
	/*
	 * Convert blog posts to a complete book package.
	 * $id = the WordPress book post internal ID, and not the book's unique id
	 * Writes a .tar file into the packages folder in the uploads dir.
	 * Clears out the build files in the build dir.
	 * Looks for the theme in the book settings
	 * Example: http://localhost/photobook/wordpress/mb/book/build_book_package/?dev=1&category_slug=book2
	 * 
	 * Choose which book to publish by provided one of these options, OR by passing the 
	 * values in the WordPress query:
	 * $id : ID of the book page we are publishing
	 * $category_id : category ID of the book's category
	 * $category_slug : slug of the book's category
	 */
	function build_book_package($id = null, $category_id = null, $category_slug = null) {
		global $mb_api;
		
		if (! $this->confirm_auth() ) {
			return false;
		}
		
	   	if (!($id || $category_id || $category_slug)) {
	   		extract($mb_api->query->get(array('id', 'category_id', 'category_slug' )));
	   	}
    
		// Build the book object from the posts
		$mb = $this->build_book($id, $category_id, $category_slug);
		
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		
		// Set build and package directories
		$build_dir = $mb->build_dir;
		$build_files_dir = $mb->build_files_dir;
		
		$theme_id = $mb->book['theme_id'];
		$mb->get_theme_files();
		
		// Write the XML to the book.xml file.
		$xml = $mb->book_to_xml();
		$filename = "book.xml";
		file_put_contents ( $build_files_dir.DIRECTORY_SEPARATOR.$filename , $xml, LOCK_EX );		

		// Copy the book promo art, i.e. icon and poster files, based on the book info.
		$mb->get_book_promo_art( $build_files_dir);
		
		
		$this->write_book_info_file($mb);
		
		// Build the tar file from the files, ready for sending.
		//$success = $mb_api->funx->tar_dir($mb->tempDir, "{$mb->id}.tar");
		$tarfilename = $mb_api->package_dir . DIRECTORY_SEPARATOR . $mb->id . ".tar";
		
		// Delete previous version of the package
		if (file_exists($tarfilename))
			unlink ($tarfilename);
		
		// Build the tar file package in the packages folder.
		try {
			$tarfile = new PharData($tarfilename);
			$tarfile->buildFromDirectory($build_files_dir);
		} catch (Exception $e) {
			$mb_api->error("$e: Unable to create tar file: $tarfilename");
		}
		
		// Submit it to the library distribution site?
		
		
		// Delete the build files
		$mb->cleanup();
		
		// This will use the query to get the book post.
		$book_post = $this->get_book_post($id);
		
		// Mark the book as published so it will appear in the shelves
		update_post_meta($book_post->id, 'mb_published', true);
		$meta_values = get_post_meta($book_post->id, "mb_published", true);
		
		// Update the shelves file with the new book
		$this->write_shelves_file();
		
		return true;
	}
	

	
	/*
	 *  ****** SELECTING USING CATEGORY ISN'T GOING TO WORK! ONLY ID IS WORKING! ****
	 * Build a book object from the posts
	 * Method #1:
	 * Gets the info from the query: 
	 * The book to build has book post id=id, OR category id=category_id, OR slug=category_slug
	 * All posts with the selected category are in the book.
	 * http://mysite/mb/book/build_book/?category_slug=book2
	 * 
	 * Method #2:
	 * Use the settings page to build the book.
	 * The category is taken from the selected book post on the settings page.
	 */
	protected function build_book($id= null, $category_id = null, $category_slug = null) {
		global $mb_api;
		
	   if (! $this->confirm_auth() ) {
		return false;
	   }
    
		$dir = mb_api_dir();
		require_once "$dir/library/MB.php";
		
		$book_category_id = null;

	   	if (!($id || $category_id || $category_slug)) {
	   		extract($mb_api->query->get(array('id', 'category_id', 'category_slug' )));
	   	}

		if ($id || $category_slug || $category_id) {
			
			// Method #1			
			// Get book category from the query
			if ($id) {
				$post = $this->get_book_post ($id);
				$book_category_id = $post->categories[0]->id;
			} elseif ($category_id) {
				$book_category_id = $mb_api->introspector->get_category_by_id($category_id)->id;
			} elseif ($category_slug) {
				$book_category_id = $mb_api->introspector->get_category_by_slug($category_slug)->id;
			}

		}

		// This will use query values id,post,post_type to determine 
		// which book post page to get info from, 
		//  OR
		// the $id value passed to the function
		//  OR
		// If it cannot find those values, it will use the 
		// book page ID from the settings page.
		$book_info = $this->get_book_info_from_post($id);
		$book_category_id = $book_info['category_id'];
		$book_post_id = $id;
		
		if (!$book_category_id) {
			$mb_api->error("The book page does not have a category assigned.");
		}

		
		$options = array ('tempDir' => $mb_api->tempDir);
		$params = array (
			'book_id'			=> $book_info['id'],
			'title'				=> $book_info['title'],
			'author'			=> $book_info['author'],
			'publisher_id'		=> $book_info['publisher_id'],
			'theme'				=> $book_info['theme']
			);
		
		
		$mb = new Mimetic_Book($book_info, $options);
		
		// get the array of wp chapters using id or slug of the book category
		$book = $this->get_book($book_post_id);
		
		// Add chapters to new $mb book object.
		// Chapters are arrays of posts/pages
		foreach($book['chapters'] as $chapter) {
			$mb->convert_chapter($chapter);
		}
		
		// Return the book object
		return $mb;
	}
	
	

	/*
	 * Write Book description file
	 * This is a json file, "item.json", used by the reader to learn about the package
	 */
	protected function write_book_info_file($book_obj = null) {
		global $mb_api;
		
		if ($book_obj) {
			$info = array (
				'type'					=> $book_obj->type,
				'id'					=> $book_obj->id,
				'title'					=> $book_obj->title,
				'description'			=> $book_obj->description,
				'shortDescription'		=> $book_obj->short_description,
				'author'				=> $book_obj->author,
				'date'					=> $book_obj->date,
				'datetime'				=> $book_obj->datetime,
				'modificationDate'		=> $book_obj->modified
			);
			
			$output = json_encode($info);
	
			$fn = $book_obj->build_files_dir . DIRECTORY_SEPARATOR . "item.json";
			
			// Delete previous version of the package
			if (file_exists($fn))
				unlink ($fn);
	
			file_put_contents ($fn, $output, LOCK_EX);
		} else {
			$mb_api->error(__FUNCTION__.": No book object passed.");
			return false;
		}
		
	}
	
	

	
		/*
	 * Write the Shelves file
	 * This is a json file, "shelves.json", used by the Mimetic Books app to know
	 * what is available for download.
	 */
	public function write_shelves_file() {
		global $mb_api;
		
		$shelves = array (
			'path'		=> "shelves",
			'title'		=> "mylib",
			'maxsize'	=> 100,
			'id'		=> "shelves",
			'password'	=> "mypassword",
			'filename'	=> "shelves.json",
			'itemsByID'	=> array ()
		);
		

		$posts = $mb_api->introspector->get_posts(array(
				'post_type' => 'book',
				'numberposts'	=> 1
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
			// Only add the item if it is marked published with our custom meta field.
			$is_published = get_post_meta($post->ID, "mb_published", true);
			if ($is_published) {
			
				$info = $this->get_book_info_from_post($post->ID);
				// almost all names are same, but not completely....
				// I've included some extras...maybe they'll be useful later.

				$item = array (
					'id'				=> $info['id'], 
					'title'				=> $info['title'], 
					'author'			=> $info['author'], 
					'publisherid'		=> $info['publisher_id'],  
					'description'		=> $info['description'], 
					'shortDescription'	=> $info['short_description'], 
					'type'				=> $info['type'], 
					'datetime'			=> $info['datetime'], 
					'modified'			=> $info['modified'],
					'path'				=> $info['id'],
					'shelfpath'			=> $mb_api->settings['shelves_dir_name'],
//					'itemShelfPath'		=>$mb_api->settings['shelves_dir_name'] . DIRECTORY_SEPARATOR . $info['id'],
					'theme'				=> $info['theme']
				);
				$shelves['itemsByID'][$info['id']] = $item;
			}
		}
		   $output = json_encode($shelves);
		   $fn = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . "shelves.json";
		   // Delete previous version of the shelves
		   if (file_exists($fn))
			   unlink ($fn);
		   file_put_contents ($fn, $output, LOCK_EX);
		   return $output;
	}
	
	


	/*
	 * Delete all attachments to a post
	 * $filesToKeep = string "file1.ext, file2.text, ...)
	 */
	private function delete_all_attachments($post_id, $filesToKeep="")
	{
		$goodfiles = split(",", $filesToKeep);
		$args = array(
			'post_type' => 'attachment',
			'numberposts' => -1,
			'post_status' => null,
			'post_parent' => $post_id
		);
		$attachments = get_posts( $args );
		if ( $attachments ) {
			foreach ( $attachments as $attachment ) {
				if (!in_array(basename($attachment->guid), $goodfiles)) {
					wp_delete_attachment( $attachment->ID, true );
				}
			}
		}
	}

		
	/*
	 * Attach file to a post
	 */
	private function attach_file_to_post($filename, $post_id) {
		global $mb_api;
		
		$wp_upload_dir = wp_upload_dir();
		
		// Check to see if file is already attached to the post.
		$args = array(
			'post_type' => 'attachment',
			'numberposts' => -1,
			'post_status' => null,
			'post_parent' => $post_id
		);
		$attachments = get_posts($args, true);
		$guids = array();
		foreach ($attachments as $attachment) {
			$guids[] = $attachment->guid;
		}
		
		
		$guid = $wp_upload_dir['baseurl'] . DIRECTORY_SEPARATOR . _wp_relative_upload_path( $filename );

		
		if (!in_array($guid, $guids)) {
			$wp_filetype = wp_check_filetype(basename($filename), null );
			$attachment = array(
				'guid' => $wp_upload_dir['baseurl'] . DIRECTORY_SEPARATOR . _wp_relative_upload_path( $filename ), 
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
				'post_content' => '',
				'post_status' => 'inherit'
			);
			$attach_id = wp_insert_attachment( $attachment, $filename, $post_id );
			$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		} else {
			//print __FUNCTION__. ": $filename : " . $guid."<BR>";
		}
	}
	
	
	/*
	 * Send a converted tar file book from a client site. 
	 * testing: http://localhost/photobook/wordpress/mb/book/send_book_package/?dev=1&id=123456
	 * params:
	 * 		id = book unique id, used as the folder for the book
	 *		u = the user_login on the receiving Wordpress site, should match the publisher ID in the
	 *			plugin options.
	 *		p = password, not used right now?
	 */
	public function send_book_package( ) {
		global $mb_api;
		
		// Get book ID, username, password
		extract($mb_api->query->get(array('id')));

		if (!$id) {
			$mb_api->error(__FUNCTION__.": No book object passed to this function.");
		}
		
		$url = $mb_api->settings['distribution_url'] . "mb/book/receive_book_package_from_client/";
		
		$publisher_id = (string)get_option('mb_api_book_publisher_id', '?');
		$p = "password";
		
		$_POST['id'] = $id;
		$_POST['u'] = $publisher_id;
		$_POST['p'] = $p;

		$localfile = $mb_api->package_dir . DIRECTORY_SEPARATOR . "$id.tar";
		$transFile = chunk_split(base64_encode(file_get_contents($localfile))); 
		$_POST['f'] = $transFile ;
		

		$ch = curl_init($url); 
		curl_setopt($ch, CURLOPT_HEADER, 0); 
		curl_setopt($ch, CURLOPT_POST, 0); 
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $_POST);	
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

		$output = curl_exec($ch); 
		curl_close($ch); 
		return $output; 
	} 

	
	
	
	
	
	/*
	 * get_book_info
	 * Return an array of the book settings from the plugin
	 * settings page:
	 * Returns: $book_id, $title, $author, $book_id, $theme_id, $theme (array), $publisher_id
	 */
	 /*
	public function get_book_info() {
		global $mb_api;

		$title = get_option('mb_api_book_title', 'Untitled');
		$author = get_option('mb_api_book_author', 'Anonymous');
		$book_id = get_option('mb_api_book_id', "mb_".uniqid() );
		
		$theme_id =  (string)get_option('mb_api_book_theme', 1);
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		$theme = $mb_api->themes->themes[$theme_id];
		
		$publisher_id = (string)get_option('mb_api_book_publisher_id', '?');
		
		$result = array (
			'id'			=> $book_id, 
			'title'			=> $title, 
			'author'		=> $author, 
			'theme'			=> $theme, 
			'publisher_id'	=> $publisher_id,  
			'description'	=> $description, 
			'short_description'		=> $short_description, 
			'type'			=> $type, 
			'date'			=> $post->date, 
			'modified'		=> $post->modified, 
			'icon_url'		=> $icon_url, 
			'poster_url'	=> $poster_url
			);

		return $result;
	}
	*/

	
	/*
	 * Get a book post.
	 * If no $post_id is spec'd, then check the query
	 */
	
	private function get_book_post($post_id = null, $category_id = null, $category_slug = null) {
		global $mb_api;
		
		if ($post_id) {
			$response = $mb_api->introspector->get_posts(array(
				'p' => $post_id,
				'post_type' => 'book'
			));
			$post = $response[0];
		} elseif ($category_id) {
			$post = get_book_post_from_category_id( $category_id );
		} elseif ($category_slug) {
			$post = get_book_post_from_category_slug( $category_slug );
		} else {
			extract($mb_api->query->get(array('id', 'slug', 'post_type')));
			if (!($id or $slug)) {
				// If no id/slug specified, use the setting on the settings page (not post!)
				$post_id = get_option('mb_api_book_info_post_id');
				$response = $mb_api->introspector->get_posts(array(
					'p' => $post_id,
					'post_type' => 'book'
				));
				$post = $response[0];
			} else {
				if ($post_type != "page") {
					$response = $this->get_post();
					$post = $response['post'];
				} else {
					$response = $this->get_page();
					$post = $response['page'];
				}

				if (!$response) {
					$mb_api->error("Not found.");
					return false;
				}
			}
		}
		return $post;
	}
	
	private function get_book_post_from_book_id( $id ) {
		global $mb_api;
		$posts = $mb_api->introspector->get_posts(array(
			'meta_key' => 'mb_book_id',
			'meta_value' => $id,
			'post_type' => 'book',
			'numberposts'	=> 1
		), true);
		if ($posts) {
			$book_post = $posts[0];
		}
		return $book_post;
	}
	
	private function get_book_post_from_category_id( $id ) {
		global $mb_api;
		$posts = $mb_api->introspector->get_posts(array( 'cat' => $id, 'post-type' => 'book' ));	
		if ($posts) {
			$book_post = $posts[0];
		}
		return $book_post;
	}

	private function get_book_post_from_category_slug( $slug ) {
		global $mb_api;
		$posts = $mb_api->introspector->get_posts(array( 'category_name' => $slug, 'post-type' => 'book' ));	
		if ($posts) {
			$book_post = $posts[0];
		}
		return $book_post;
	}


	/*
	 * get_book_info_from_post
	 * Return an array of the book settings from a book-type post
	 * Example using API:
	 * http://localhost/photobook/wordpress/mb/book/get_post/?dev=1&slug=my-new-book-page&post_type=book
	 * Returns: $book_id, $title, $author, $book_id, $theme_id, $theme (array), $publisher_id
	 */
	public function get_book_info_from_post( $post_id = null, $category_id = null ) {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		if ($post_id) {
			$post = $this->get_book_post($post_id);
		} elseif ($category_id) {
			$post = get_book_post_from_category_id($category_id);
		} else {
			$mb_api->error(__FUNCTION__.": Missing post id.");
		}
		
		
		// Get custom fields
		$custom_fields = get_post_custom($post->id);
		//print_r($custom_fields);
		
		if (isset($custom_fields['mb_book_id']) && $custom_fields['mb_book_id']) {
			$book_id = $custom_fields['mb_book_id'][0];
		} elseif (isset($post->slug)) {
			$book_id = $post->slug;
		} else {
			$book_id = "mb_".uniqid();
			add_post_meta( $post->id, 'mb_book_id', $book_id );
		}

		$title = $post->title_plain;
		$author = join (" ", array ($post->author->first_name, $post->author->last_name));
		
		// Theme is set with a custom field, or taken from the settings page, or is the default theme.
		// default theme is 0, I think.
		if (isset($custom_fields['mb_theme_id']) && $custom_fields['mb_theme_id']) {
			$theme_id =  $custom_fields['mb_theme_id'];
		} else {
			$theme_id = (string)get_option('mb_api_book_theme', 1);
			$theme_id || $theme_id = "0";
		}
			
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		$theme = $mb_api->themes->themes[$theme_id];
		
		// Publisher still comes from either the page or the plugin settings page.
		if (isset($custom_fields['mb_publisher_id']) && isset($custom_fields['mb_publisher_id'][0]) && $custom_fields['mb_publisher_id'][0]) {
			$publisher_id = $custom_fields['mb_publisher_id'][0];
		} else {
			$publisher_id = (string)get_option('mb_api_book_publisher_id', '?');
		}
		
		 //$description, $short_description, $type
		 $description = $post->content;
		 // remove images and links from the content
		 $description = preg_replace ("/<img.*?\>/","",  $description);
		 $description = preg_replace ("/<\/?a.*?\>/","",  $description);

		 $short_description = $post->excerpt;
		 
		if (isset($custom_fields['mb_publication_type']) && $custom_fields['mb_publication_type']) {
			$type = $custom_fields['mb_publication_type'][0];
		} else {
			$type = "book";
		}

		// Use the post thumbnail as the icon. It will be small, so it won't get
		// cropped by the theme. A large file is cropped to fit the header...not good for us.
		$t = wp_get_attachment_image_src( get_post_thumbnail_id( $post->id, 'full'));
		
		if ($t) {
			$icon_url = $t[0];
		} else {
			$icon_url = '';
		}
	
	// Use the post thumbnail as the icon. It will be small, so it won't get
		// cropped by the theme. A large file is cropped to fit the header...not good for us.
		$t = wp_get_attachment_image_src( get_post_thumbnail_id( $post->id, 'full'));
		
		if ($t) {
			$icon_url = $t[0];
		} else {
			$icon_url = '';
		}
	
		
		/*
		 * Now we have a custom field for posters!
		 */

		$poster_url = "";
		if (isset($custom_fields['mb_poster_attachment_id']) && $custom_fields['mb_poster_attachment_id'][0]) {
			$args = array(
				'post_type' => 'attachment',
				'p'			=> $custom_fields['mb_poster_attachment_id'][0],
				'numberposts' => 1
			); 
			$poster_attachment = get_posts($args);
			if ($poster_attachment && $poster_attachment[0]) {
				$poster_attachment = $poster_attachment[0];
				$poster_url = $poster_attachment->guid;
			}
		}
			
			
			
		/*
		// Get the poster from the post text itself.
		$attr = $this->get_embedded_element_attributes($post, $element_type="img");
		if ($attr) {
			$firstpic = array_pop($attr);
			$firstpic_id = $firstpic['id'];
			$poster = wp_get_attachment_image_src($firstpic_id, 'full');
			$poster_url = $poster[0];
		} else {
			$poster_url = "";
		}
		*/
		
		
		$category_id = $post->categories[0]->id;
		
		$result = array (
			'id'		=> $book_id, 
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
			);
		
		return $result;
	}
	

	/*
	 * get_book
	 * Given an id or slug for a Wordpress category, fetch a MB book object containing
	 * the posts whose categories match, or are children.
	 * Return an array of chapters of the book.
	 *		$book = array ( $info, $chapters )
	 * where
	 *		$info = array ($id, $title, $author, $publisher_id)
	 *		$chapters = array ($chapter1, $chapter2, ...)
	 */
	protected function get_book($id = null, $book_cat_id = null) {
		global $mb_api;
		
		$book_info = $this->get_book_info_from_post($id, $book_cat_id);
		$book_cat_id = $book_info['category_id'];
		
		
		//list($book_id, $title, $author, $publisher_id) = $info;
		$book_chapters = $this->get_book_chapters($book_cat_id);
		$book = array (
			"info"		=> $book_info,
			"chapters"	=> $book_chapters
		);
		return ($book);
	}
	
	
	
	/*
	 * get_book_chapters ( $category_id )
	 * The posts are in category with id = $category_id,
	 * and in sub-categories. 
	 */
	/*
	$category->term_id
	$category->name
	$category->slug
	$category->term_group
	$category->term_taxonomy_id
	$category->taxonomy
	$category->description
	$category->parent
	$category->count
	$category->cat_ID
	$category->category_count
	$category->category_description
	$category->cat_name
	$category->category_nicename
	$category->category_parent
	*/
	protected function get_book_chapters($category_id) {
		global $mb_api;
		
		$chapters = array();
		
		$book_cat = $mb_api->introspector->get_category_by_id($category_id);
		$args = array(
			'type'			=> 'category',
			'child_of'		=> $book_cat->id,
			'orderby'		=> 'name',
			'order'			=> 'asc',
			'hide_empty'	=> 1,
			'hierarchical'	=> 1,
			'exclude'		=> '',
			'include'		=> '',
			'number'		=> '',
			'taxonomy'		=> 'category',
			'pad_counts'	=> 1
		);
		
		$chapter_categories = get_categories($args);
		
		foreach($chapter_categories as $chapter_category) {
			$posts = array();
			$posts = $mb_api->introspector->get_posts(array( 'cat' => $chapter_category->term_id ));
			if ($posts) {
				$chapter = array (
						"pages"		=> $posts,
						"id"		=> $chapter_category->term_id,
						"title"		=> $chapter_category->name,
						"category"	=> $chapter_category
					);
				$chapters[] = $chapter;
			}
		}

		return $chapters;
	}
	
	
	// --------
	// Gets all posts in one or more categories using all possible selectors
	public function build() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		extract($mb_api->query->get(array('id', 'slug', 'post_id', 'post_slug', 'category_id', 'category_slug', 'category_id_and', 'category_slug_and')));
		if ($id || $post_id) {
			if (!$id) {
				$id = $post_id;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'p' => $id,
				'posts_per_page' => -1 
			), true);
		} else if ($slug || $post_slug) {
			if (!$slug) {
				$slug = $post_slug;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'name' => $slug,
				'posts_per_page' => -1 
			), true);
		} else if ($category_id) {
			$posts = $mb_api->introspector->get_posts(array(
				'cat' => $category_id,
				'posts_per_page' => -1 
			), true);
		} else if ($category_slug) {
			$posts = $mb_api->introspector->get_posts(array(
				'category_name' => $category_slug,
				'posts_per_page' => -1 
			), true);
		} else if ($category_id_and) {
			$posts = $mb_api->introspector->get_posts(array(
				'category__and' => $category_and,
				'posts_per_page' => -1 
			), true);
		} else if ($category_slug_and) {
			$cat_index = $this->get_category_index("slug");
			$cat_slugs = split(",", $category_slug_and);
			$category_id = array ();
			foreach ($cat_slugs as $slug) {
				$category_id[] = $cat_index["categories"][$slug]->id;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'category__and' => $category_id,
				'posts_per_page' => -1 
			), true);
		} else {
			$mb_api->error("Include 'id' or 'slug' var in your request.");
		}

		
		
				
		return $this->posts_result($posts);
	}		
	

	
	
	
	// ------- the JSON stuff...
	
	
	
	public function info() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$php = '';
		if (!empty($mb_api->query->controller)) {
			return $mb_api->controller_info($mb_api->query->controller);
		} else {
			$dir = mb_api_dir();
			if (file_exists("$dir/mb-api.php")) {
				$php = file_get_contents("$dir/mb-api.php");
			} else {
				// Check one directory up, in case mb-api.php was moved
				$dir = dirname($dir);
				if (file_exists("$dir/mb-api.php")) {
					$php = file_get_contents("$dir/mb-api.php");
				}
			}
			if (preg_match('/^\s*Version:\s*(.+)$/m', $php, $matches)) {
				$version = $matches[1];
			} else {
				$version = '(Unknown)';
			}
			$active_controllers = explode(',', get_option('mb_api_controllers', 'core'));
			$controllers = array_intersect($mb_api->get_controllers(), $active_controllers);
			return array(
				'mb_api_version' => $version,
				'controllers' => array_values($controllers)
			);
		}
	}
	
	public function get_recent_posts() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$posts = $mb_api->introspector->get_posts();
		return $this->posts_result($posts);
	}
	
	public function get_post() {
		global $mb_api, $post;

		if (! $this->confirm_auth() ) {
			return false;
		}

		extract($mb_api->query->get(array('id', 'slug', 'post_id', 'post_slug')));
		if ($id || $post_id) {
			if (!$id) {
				$id = $post_id;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'p' => $id
			), true);
		} else if ($slug || $post_slug) {
			if (!$slug) {
				$slug = $post_slug;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'name' => $slug
			), true);
		} else {
			$mb_api->error("Include 'id' or 'slug' var in your request.");
		}
		if (count($posts) == 1) {
			$post = $posts[0];
			$previous = get_adjacent_post(false, '', true);
			$next = get_adjacent_post(false, '', false);
			$post = new MB_API_Post($post);
			$response = array(
				'post' => $post
			);
			if ($previous) {
				$response['previous_url'] = get_permalink($previous->ID);
			}
			if ($next) {
				$response['next_url'] = get_permalink($next->ID);
			}
			return $response;
		} else {
			$mb_api->error("Not found.");
		}
	}

	public function get_page() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		extract($mb_api->query->get(array('id', 'slug', 'page_id', 'page_slug', 'children')));
		if ($id || $page_id) {
			if (!$id) {
				$id = $page_id;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'page_id' => $id
			));
		} else if ($slug || $page_slug) {
			if (!$slug) {
				$slug = $page_slug;
			}
			$posts = $mb_api->introspector->get_posts(array(
				'pagename' => $slug
			));
		} else {
			$mb_api->error("Include 'id' or 'slug' var in your request.");
		}
		
		// Workaround for https://core.trac.wordpress.org/ticket/12647
		if (empty($posts)) {
			$url = $_SERVER['REQUEST_URI'];
			$parsed_url = parse_url($url);
			$path = $parsed_url['path'];
			if (preg_match('#^http://[^/]+(/.+)$#', get_bloginfo('url'), $matches)) {
				$blog_root = $matches[1];
				$path = preg_replace("#^$blog_root#", '', $path);
			}
			if (substr($path, 0, 1) == '/') {
				$path = substr($path, 1);
			}
			$posts = $mb_api->introspector->get_posts(array('pagename' => $path));
		}
		
		if (count($posts) == 1) {
			if (!empty($children)) {
				$mb_api->introspector->attach_child_posts($posts[0]);
			}
			return array(
				'page' => $posts[0]
			);
		} else {
			$mb_api->error("Not found.");
		}
	}
	
	public function get_date_posts() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		if ($mb_api->query->date) {
			$date = preg_replace('/\D/', '', $mb_api->query->date);
			if (!preg_match('/^\d{4}(\d{2})?(\d{2})?$/', $date)) {
				$mb_api->error("Specify a date var in one of 'YYYY' or 'YYYY-MM' or 'YYYY-MM-DD' formats.");
			}
			$request = array('year' => substr($date, 0, 4));
			if (strlen($date) > 4) {
				$request['monthnum'] = (int) substr($date, 4, 2);
			}
			if (strlen($date) > 6) {
				$request['day'] = (int) substr($date, 6, 2);
			}
			$posts = $mb_api->introspector->get_posts($request);
		} else {
			$mb_api->error("Include 'date' var in your request.");
		}
		return $this->posts_result($posts);
	}
	
	public function get_category_posts() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$category = $mb_api->introspector->get_current_category();
		if (!$category) {
			$mb_api->error("Not found.");
		}
		$posts = $mb_api->introspector->get_posts(array(
			'cat' => $category->id
		));
		return $this->posts_object_result($posts, $category);
	}
	
	public function get_tag_posts() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$tag = $mb_api->introspector->get_current_tag();
		if (!$tag) {
			$mb_api->error("Not found.");
		}
		$posts = $mb_api->introspector->get_posts(array(
			'tag' => $tag->slug
		));
		return $this->posts_object_result($posts, $tag);
	}
	
	public function get_author_posts() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$author = $mb_api->introspector->get_current_author();
		if (!$author) {
			$mb_api->error("Not found.");
		}
		$posts = $mb_api->introspector->get_posts(array(
			'author' => $author->id
		));
		return $this->posts_object_result($posts, $author);
	}
	
	public function get_search_results() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		if ($mb_api->query->search) {
			$posts = $mb_api->introspector->get_posts(array(
				's' => $mb_api->query->search
			));
		} else {
			$mb_api->error("Include 'search' var in your request.");
		}
		return $this->posts_result($posts);
	}
	
	public function get_date_index() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$permalinks = $mb_api->introspector->get_date_archive_permalinks();
		$tree = $mb_api->introspector->get_date_archive_tree($permalinks);
		return array(
			'permalinks' => $permalinks,
			'tree' => $tree
		);
	}
	
	public function get_category_index($key) {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$categories = $mb_api->introspector->get_categories($key);
		return array(
			'count' => count($categories),
			'categories' => $categories
		);
	}
	
	public function get_category_index_by_id() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$categories = $mb_api->introspector->get_categories("id");
		return array(
			'count' => count($categories),
			'categories' => $categories
		);
	}
	
	public function get_category_index_by_slug() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$categories = $mb_api->introspector->get_categories("slug");
		return array(
			'count' => count($categories),
			'categories' => $categories
		);
	}
	
	public function get_tag_index() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$tags = $mb_api->introspector->get_tags();
		return array(
			'count' => count($tags),
			'tags' => $tags
		);
	}
	
	public function get_author_index() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$authors = $mb_api->introspector->get_authors();
		return array(
			'count' => count($authors),
			'authors' => array_values($authors)
		);
	}
	
	public function get_page_index() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		$pages = array();
		// Thanks to blinder for the fix!
		$numberposts = empty($mb_api->query->count) ? -1 : $mb_api->query->count;
		$wp_posts = get_posts(array(
			'post_type' => 'page',
			'post_parent' => 0,
			'order' => 'ASC',
			'orderby' => 'menu_order',
			'numberposts' => $numberposts
		));
		foreach ($wp_posts as $wp_post) {
			$pages[] = new MB_API_Post($wp_post);
		}
		foreach ($pages as $page) {
			$mb_api->introspector->attach_child_posts($page);
		}
		return array(
			'pages' => $pages
		);
	}
	
	public function get_nonce() {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}

		extract($mb_api->query->get(array('controller', 'method')));
		if ($controller && $method) {
			$controller = strtolower($controller);
			if (!in_array($controller, $mb_api->get_controllers())) {
				$mb_api->error("Unknown controller '$controller'.");
			}
			require_once $mb_api->controller_path($controller);
			if (!method_exists($mb_api->controller_class($controller), $method)) {
				$mb_api->error("Unknown method '$method'.");
			}
			$nonce_id = $mb_api->get_nonce_id($controller, $method);
			return array(
				'controller' => $controller,
				'method' => $method,
				'nonce' => wp_create_nonce($nonce_id)
			);
		} else {
			$mb_api->error("Include 'controller' and 'method' vars in your request.");
		}
	}
	
	protected function get_object_posts($object, $id_var, $slug_var) {
		global $mb_api;
		$object_id = "{$type}_id";
		$object_slug = "{$type}_slug";
		extract($mb_api->query->get(array('id', 'slug', $object_id, $object_slug)));
		if ($id || $$object_id) {
			if (!$id) {
				$id = $$object_id;
			}
			$posts = $mb_api->introspector->get_posts(array(
				$id_var => $id
			));
		} else if ($slug || $$object_slug) {
			if (!$slug) {
				$slug = $$object_slug;
			}
			$posts = $mb_api->introspector->get_posts(array(
				$slug_var => $slug
			));
		} else {
			$mb_api->error("No $type specified. Include 'id' or 'slug' var in your request.");
		}
		return $posts;
	}
	
	protected function posts_result($posts) {
		global $wp_query;
		return array(
			'count' => count($posts),
			'count_total' => (int) $wp_query->found_posts,
			'pages' => $wp_query->max_num_pages,
			'posts' => $posts
		);
	}
	
	protected function posts_object_result($posts, $object) {
		global $wp_query;
		// Convert something like "MB_API_Category" into "category"
		$object_key = strtolower(substr(get_class($object), 9));
		return array(
			'count' => count($posts),
			'pages' => (int) $wp_query->max_num_pages,
			$object_key => $object,
			'posts' => $posts
		);
	}



	/*
	 * Read the HTML of the post text and figure out neat stuff from it about
	 * embedded elements, such as height/width of the embedded image.
	 * It seems that just getting the attachment info won't get us here.
	*/
	protected function get_embedded_element_attributes($wp_page, $element_type="img") {
		$text = $wp_page->content;
		
		// Use PHP XML/HTML extract functionality!
		$doc = new DOMDocument();
		$doc->loadHTML($text);
		
		$page_elements = array();
		
		// Get all elements in the HTML
		foreach ($doc->getElementsbytagname($element_type) as $node) {
			/*
			//$item = $doc->saveHTML($node);
			$element = array();
			$element['name'] = $node->nodeName;
			$element['value'] = $node->nodeValue;
			$element['type'] = $node->nodeType;
			$attributes = array();
			foreach ($node->attributes as $attr) {
				$attributes[$attr->name] = $attr->nodeValue;
			}
			$element['attributes'] = $attributes;
			$id = preg_replace("/.*?wp-image-/", "", $attributes['class']);
			$page_elements[$id] = $element;
			 */
			$attributes = array();
			foreach ($node->attributes as $attr) {
				$attributes[$attr->name] = $attr->nodeValue;
			}
			$id = preg_replace("/.*?wp-image-/", "", $attributes['class']);
			$attributes['id'] = $id;
			$page_elements[0+$id] = $attributes;
		}

		// Now, gather them into MB format 
		// img ---> picture
		
		//print_r($page_elements);
		return $page_elements;
	}



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
			$mb_api->error("You must include a 'cookie' authentication cookie. Use the `create_auth_cookie` Auth API method.");
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
