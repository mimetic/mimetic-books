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
	 * $book_id = book unique id
	 * $id = the WordPress book post internal ID.
	 * 
	 * 
	 * LOCAL
	 * Get the file from the mb-book-packages directory.
	 * testing: http://localhost/photobook/wordpress/mb/book/publish_book_package/?dev=1&id=123456&DEBUG=true
	 * 
	 * 
	 * REMOTE
	 * If we are getting the file from a remote site, then this probably works best with a POST, not a GET!
	 * You can't really send a tar file in a GET, after all.
	 * Required params:
	 * 
	 * example : http://localhost/photobook/wordpress/mb/book/publish_book_package/?id=123456&u=test&p=pass&f=(filedata)
	 */
	public function publish_book_package() {
		global $mb_api;
		
			if (! $this->confirm_auth() ) {
				$this->write_log("Authorization not accepted.");
				return false;
			}

		$this->write_log("\n======================= ".__FUNCTION__.": Begin");

		extract($mb_api->query->get(array('remote')));
		
		$local = false;		
		$distribution_url = trim(get_option('mb_api_book_publisher_url'));
		$error = "";

		if ($remote) {
			// REMOTE PUBLISHING

			// Get book ID, username, password
			extract($mb_api->query->get(array('book_id', 'u', 'p', 'f')));

			
			// TESTING
			$u = "digross";
			$p = "nookie";

$this->write_log("Publish from remote site...getting file ");
$this->write_log("book_id = $book_id, username = $u, password = $p");

			if (!($book_id || $id) || !$u || !$p || !$f) {
				$mb_api->error(__FUNCTION__.": Missing book id (book_id), username (u), password (p), and/or file data (f) ($book_id, $u, $p).");
			}

			if (!$book_id) {
				$mb_api->error(__FUNCTION__.": Missing book_id.");
			}

			
			// Make a dir to hold the book package
			$dir = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . strtolower($book_id);
			if(! is_dir($dir))
				mkdir($dir);

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
			
			// Get book ID
			extract($mb_api->query->get(array('id', 'book_id')));
			
			if (isset($book_id)) {
				$book_post = $this->get_book_post_from_book_id($book_id);
				if ($book_post) {
					$id = $book_post->ID;
				} else {
					$mb_api->error(__FUNCTION__.": Invalid book id: $book_id");
				}
				$this->write_log("Publish book locally with Book ID={$book_id}.");
			} elseif (isset($id)) {
				$book_post = $this->get_book_post($id);
				$book_id = get_post_meta($id, "mb_book_id", true);
				$this->write_log("Publish book locally with book post id={$id}.");
			} else {
				$mb_api->error(__FUNCTION__.": Local publishing must include a book id (book_id) or a book post id (id).");
			}
			
			// Update the book post so the modification date for this book is Now.
			wp_update_post( array ('ID'=>$id ) );


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
			$this->write_log(__FUNCTION__.": Extract icon, poster, item info from the package.");
			$phar = new PharData($filename);
			if ($phar->offsetExists('icon.png'))
				$phar->extractTo($dir, array('icon.png'), true);
			if ($phar->offsetExists('poster.jpg'))
				$phar->extractTo($dir, array('poster.jpg'), true);
			if ($phar->offsetExists('item.json'))
				$phar->extractTo($dir, array('item.json'), true);	
		} catch (Exception $e) {
			// handle errors
			// This includes missing files, when the poster or icon files are missing.
			// DON'T quit here, it is probably just be a missing file.
			$error = "Error extracting icon or poster or item from the book package. Probably missing poster or icon.";
			$this->write_log(__FUNCTION__.": Error: $error");
			$mb_api->error(__FUNCTION__.": Failed to open the tar file to get the icon, poster, and item: " . $e);
		}

		// ------------------------------------------------------------
		// Create or Update a post entry in the Wordpress for this book!
		// First, look for an existing entry with this ID
		$book_post = $this->get_book_post_from_book_id($book_id);

		$user_id = $user->ID;

		$info = json_decode( file_get_contents($dir . DIRECTORY_SEPARATOR . "item.json") );

		if ($book_post && isset($book_post->ID)) {
			$post_id = $book_post->ID;
		} else {
			// If post does not exist, create it.
			// This must be a remote publish, since the book post does not exist,
			// meaning the book page posts exists on another website.		
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
			$this->mb_delete_all_attachments($post_id);
			// Do NOT delete the tar file.
			// $this->mb_delete_all_attachments($post_id, "item.tar");
			
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
					
			// Mark the book as published so it will appear in the shelves
			update_post_meta($post_id, 'mb_book_id', $book_id);
		}
		
		// Custom fields:
		
		// Mark the book as published so it will appear in the shelves
		update_post_meta($post_id, 'mb_published', true);
		
		
		// Nope...
		// The user's login is their publisher ID
		//update_post_meta($post_id, "mb_publisher_id", $user->data->user_login);
		update_post_meta($post_id, "mb_publisher_id", $info->publisherid);
		
		// Book author field
		update_post_meta($post_id, "mb_book_author", $info->author);


		// Must do AFTER writing it to the directory where it can be downloaded from
		// since we check that files are there before we really show a book as
		// published.
		// 
		// Update the shelves file with the new book
		$this->write_shelves_file();
		$this->write_log(__FUNCTION__.": Wrote the shelves file.");

		// Update the publishers file
		$mb_api->write_publishers_file();
		$this->write_log(__FUNCTION__.": Wrote the publishers file.");
		
		if ($error) {
			//data,textStatus
			$error['data'] = $error;
			$error = json_encode($error);
		}
		
		$this->write_log(__FUNCTION__.": End\n=======================\n");

		return $error;
	}
	
		
		
	/*
	 * Convert blog posts to a complete book package.
	 * $id = the WordPress book post internal ID, and not the book's unique id
	 *
	 * Writes a .tar file into the packages folder in the uploads dir.
	 * Clears out the build files in the build dir.
	 * Example: http://localhost/photobook/wordpress/mb/book/build_book_package/?dev=1&category_slug=book2
	 * 
	 * Choose which book to publish by provided one of these options, OR by passing the 
	 * values in the WordPress query:
	 * $id : ID of the book page we are publishing
	 * $category_id : category ID of the book's category
	 * $category_slug : slug of the book's category
	 */
	public function build_book_package($id = null, $category_id = null, $category_slug = null) {
		global $mb_api;
		
		if (! $this->confirm_auth() ) {
			$this->write_log("Authorization not accepted.");
			return false;
		}
		
		$this->write_log(__FUNCTION__.": Begin");

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
			$this->write_log(__FUNCTION__.": Unable to create tar file: $tarfilename");
			$mb_api->error("$e: Unable to create tar file: $tarfilename");
		}
		
		// Submit it to the library distribution site?
		
		
		// Delete the build files
		$mb->cleanup();
		
		// This will use the query to get the book post.
		$book_post = $this->get_book_post($id);
		
		$this->write_log(__FUNCTION__.": End");

		return true;
	}
	

	
	/*
	 *  ****** SELECTING USING CATEGORY ISN'T GOING TO WORK! ONLY ID IS WORKING! ****
	 * Build a book object from the posts
	 * $id = book post internal id (not book id)
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
		$this->write_log("Authorization not accepted.");
		return false;
	   }
    
		$this->write_log(__FUNCTION__.": Begin");

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
		$book_info = $mb_api->get_book_info_from_post($id);
		$book_category_id = $book_info['category_id'];
		$book_post_id = $id;
		
		if (!$book_category_id) {
			$this->write_log(__FUNCTION__.": The book page does not have a category assigned.");
			$mb_api->error("The book page does not have a category assigned.");
		}

		$options = array (
			'tempDir' => $mb_api->tempDir,
			'dimensions' => $book_info['dimensions'],
			'save2x'	=> $book_info['save2x']
			);

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


		// Add settings from the book post page
		$mb->hideHeaderOnPoster = $book_info['hideHeaderOnPoster'];
		
		if ($book) {
			// Add chapters to new $mb book object.
			// Chapters are arrays of posts/pages
			// $index is the number of the chapter
			$index = 1;
			foreach($book['chapters'] as $chapter) {
				$mb->convert_chapter($chapter, $index);
				$index++;
			}
		} else {
			$mb = false;
		}
		
		$this->write_log(__FUNCTION__.": End");

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
				'modified'				=> $book_obj->modified,
				'publisherid'			=> $book_obj->publisher_id,
				'hideHeaderOnPoster'	=> $book_obj->hideHeaderOnPoster
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

		$this->write_log(__FUNCTION__);

		
		$shelves = array (
			'path'		=> "shelves",
			'title'		=> "mylib",
			'maxsize'	=> 100,
			'id'		=> "shelves",
			'password'	=> "mypassword",
			'filename'	=> "shelves.json",
			'itemsByID'	=> array ()
		);
		
		// Get all books
		$posts = $mb_api->introspector->get_posts(array(
				'post_type' => 'book',
				'posts_per_page'	=> -1,
				'post_status' => 'any'
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
	private function mb_delete_all_attachments($post_id, $filesToKeep="")
	{
		$goodfiles = split(",", $filesToKeep);
		$args = array(
			'post_type' => 'attachment',
			'posts_per_page' => -1,
			'post_status' => null,
			'post_parent' => $post_id,
			'post_status' => 'any'
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
			'posts_per_page' => -1,
			'post_status' => null,
			'post_parent' => $post_id,
			'post_status' => 'any'
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
	
	/*
	 * UNUSED: BE CAREFUL, THE PUBLISHER ID IS NOT TESTED.
	 */
	public function send_book_package( ) {
		global $mb_api;
		
		$this->write_log(__FUNCTION__);

		// Get book ID, username, password
		extract($mb_api->query->get(array('id', 'book_id')));
		if ($id) {
			$info = $mb_api->get_book_info_from_post($id);
			$book_id = $info['id'];
		} elseif ($book_id) {
			$book_post = $this->get_book_post_from_book_id( $book_id );
			$info = $mb_api->get_book_info_from_post($book_post->ID);
			$id = $book_post->ID;
		} else {
			$mb_api->error(__FUNCTION__.": Missing id or book_id.");
		}
		

		isset($this->settings['distribution_url']) ? $d = trim($this->settings['distribution_url']) : $d = "";

		$url = get_option('mb_api_book_publisher_url', $d); 
		
		// be sure there's an ending slash
		$url = preg_replace("/(\/*)$/", '', $url) . "/";
		
		if (isset($url)) {
			$url .=  "mb/book/publish_book_package/";
		} else {
			$this->write_log("ERROR: Tried to send a book when no URL was provided.");
			$mb_api->error(__FUNCTION__.": Tried to send a book when no URL was provided.");
		}
		
		
		//build the book
		$this->build_book_package($id);
		
		$publisher_id = $info['publisher_id'];
		$p = "password";
		

		$localfile = $mb_api->package_dir . DIRECTORY_SEPARATOR . "{$book_id}.tar";
		$transFile = chunk_split(base64_encode(file_get_contents($localfile))); 

		$this->write_log("Prepare to send book id#{$book_id} to {$url}.");


		$ch = curl_init();
		$data = array (
				'remote'		=> 'remote',
				'book_id'		=> $book_id,
				'u'				=> $publisher_id,
				'p'				=> $p,
				'f'				=> $transFile
			);
		
		curl_setopt($ch, CURLOPT_URL, $url); 
		//curl_setopt($ch, CURLOPT_HEADER, 0); 
		curl_setopt($ch, CURLOPT_POST, 1); 
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $data);
		//curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

		$success = curl_exec($ch); 
		curl_close($ch); 
		
		// delete the book package
		unlink ($localfile);
		
		
		if ($success) {
			$output = "";
		} else {
			$mb_api->error(__FUNCTION__.": Error sending book to $url");
		}
		
		$this->write_log("Sent book id#{$book_id} to {$url}.");

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
				'post_type' => 'book',
				'post_status' => 'any'
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
		wp_reset_query();
		$posts = $mb_api->introspector->get_posts(array(
			'meta_key' => 'mb_book_id',
			'meta_value' => $id,
			'post_type' => 'book',
			'post_status' => 'any',
			'posts_per_page'	=> 1
		), true);

		if ($posts) {
			$book_post = $posts[0];
		} else {
			$book_post = array();
		}
		
		return $book_post;
	}
	
	private function get_book_post_from_category_id( $id ) {
		global $mb_api;
		$posts = $mb_api->introspector->get_posts(array( 'cat' => $id, 'post-type' => 'book', 'post_status' => 'any' ));	
		if ($posts) {
			$book_post = $posts[0];
		}
		return $book_post;
	}

	private function get_book_post_from_category_slug( $slug ) {
		global $mb_api;
		$posts = $mb_api->introspector->get_posts(array( 'category_name' => $slug, 'post-type' => 'book', 'post_status' => 'any' ));	
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
		return $mb_api->get_book_info_from_post( $post_id, $category_id );
	}
	


	/*
	 * get_book_category from a book post ID
	 */
	public function get_book_category( $post_id = null ) {
		global $mb_api;

		if (! $this->confirm_auth() ) {
			return false;
		}


		if ($post_id) {
			$post = $this->get_book_post($post_id);
			$category_id = $post->categories[0]->id;
		}
		return $category_id;
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
		
		$book_info = $mb_api->get_book_info_from_post($id, $book_cat_id);
		$book_cat_id = $book_info['category_id'];
		
		
		//list($book_id, $title, $author, $publisher_id) = $info;
		$book_chapters = $this->get_book_chapters($book_cat_id);
		
		if (count ($book_chapters) < 1) {
			$book = false;
			$mb_api->error(__FUNCTION__.": This book has no chapters with pages!");
		} else {
			$book = array (
				"info"		=> $book_info,
				"chapters"	=> $book_chapters
			);
		}
		return $book;
	}
	

	// Just return the ID part of a WP chapter object
	private function get_chapter_id($chapter_obj) {
		//write_log ("get_chapter_id!!! ".print_r($chapter_obj, true) );
		return $chapter_obj->cat_ID;
	}
	
	/*
	 * get_book_chapters ( $category_id )
	 * The posts are in category with id = $category_id,
	 * and in sub-categories. 
	 * Sub-chapters are categories items inside chapter category items,
	 * e.g. "Chapter 1" contains categories "sub-chapter A"
	 * Sub-chapter A will appear as a new chapter at the end of chapter 1.
	 * One wonders, should these appear as pages inside chapter 1?
	 * 
	 * Chapters are ordered either by an ordering plugin (recommended) 
	 * or alphabetically.
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

		// The order is alphabetical if the plugin allowing term_order is NOT used.
		// If term_order is installed, the chapters appear in that order.
		
		// Get children of the category, i.e. chapters
		// This does not get categories when the hide_empty is true, and the posts are private!!!
		$args = array(
			'type'			=> 'post',
			'child_of'		=> $category_id,
			'orderby'		=> 'name',
			'order'			=> 'asc',
			'hide_empty'	=> 0,
			'hierarchical'	=> 1,
			'exclude'		=> '',
			'include'		=> '',
			'number'		=> '',
			'taxonomy'		=> 'category',
			'pad_counts'	=> 1
		);
		$chapter_categories = get_categories($args);

		// Get posts ONLY with the book category tag, not with a child tag.
		// Some posts might have "My Book" category AND "Chapter 1" category.
		// These posts should only appear in "Chapter 1".
		
		// Get list of child categories to exclude when checking the main, book category
		$child_cat_ids = array_map(array($this, 'get_chapter_id'), $chapter_categories);

		$posts = array();
		$q = array (
			'post_type' => 'post',
			'post_status' => 'publish,private',
			'posts_per_page' => -1,
			//'orderby' => 'menu_order',	// ordering is taken care of by the ordering plugin
            //'order' => 'ASC',
			'tax_query' => array (	
								'relation' => 'AND',
								array (
									'taxonomy' => 'category', 
									'field' => 'id',
									'terms' => $category_id,
									'include_children' => false
								),
								array (
									'taxonomy' => 'category', 
									'field' => 'id',
									'terms' => $child_cat_ids,
									'include_children' => false,
									'operator' => 'NOT IN'
								)

							)
			);
		
		// This is the first chapter
		$posts = $mb_api->introspector->get_posts($q);
		$book_cat = get_category($category_id);

		if ($posts) {
			$chapter = array (
					"pages"		=> $posts,
					"id"		=> $book_cat->term_id,
					"title"		=> $book_cat->name,
					"category"	=> $book_cat
				);
			$chapters[] = $chapter;
		}

/*
$mb_api->write_log("*** There are ".count($posts)." pages found in category id=$category_id");
foreach ($posts as $p) {
	$mb_api->write_log ("   Page: ".$p->title);
}
*/

		// Get the book category add begin with it:
		// best to start list with book entries, typically cover, contents, etc.
		//array_unshift($chapter_categories, $book_cat );

		
		
		// OK, now add the chapters (sub-categories) to the first chapter
		// ONLY GET PUBLISH, PRIVATE POSTS, not drafts.
		foreach($chapter_categories as $chapter_category) {
			$posts = array();
			$q = array (
				//'cat' => $chapter_category->term_id, 
				'post_type' => 'post',
				'post_status' => 'publish,private',
				'posts_per_page' => -1,
				//'orderby' => 'menu_order',
				//'order' => 'ASC',
				'tax_query' => array (	
									array (
										'taxonomy' => 'category', 
										'field' => 'id',
										'terms' => $chapter_category->term_id,
										'include_children' => false
									)
								)
				);
			//$posts = get_posts($q);
			$posts = $mb_api->introspector->get_posts($q);

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
		$posts_per_page = empty($mb_api->query->count) ? -1 : $mb_api->query->count;
		$wp_posts = get_posts(array(
			'post_type' => 'page',
			'post_parent' => 0,
			'order' => 'ASC',
			'orderby' => 'menu_order',
			'posts_per_page' => $posts_per_page,
			'post_status' => 'any'
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



	public function write_publishers_file() {
		global $mb_api;
		$result = $mb_api->write_publishers_file();
		return $result;
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


	public function write_log($text) {
		global $mb_api;
		error_log (date('Y-m-d H:i:s') . ": {$text}\n", 3, $mb_api->logfile);
	}
	
	
	public function error($message = 'Unknown error', $status = 'error') {
		global $mb_api;
		$mb_api->error($message, $status);
	}

	
}

?>
