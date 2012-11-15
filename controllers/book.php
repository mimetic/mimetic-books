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
	
	
	public function build_book() {
		global $mb_api;
		
		$dir = mb_api_dir();
		require_once "$dir/library/MB.php";
		
		/*
		$title = get_option('mb_api_book_title', 'Untitled');
		$author = get_option('mb_api_book_author', 'Anonymous');
		$book_id = get_option('mb_api_book_id', "mb_".uniqid() );
		$publisher_id = get_option('mb_api_book_publisher_id', '?');
		*/
		list($book_id, $title, $author, $publisher_id) = $this->get_book_info();

		
		// Create a new book object
		$mb = new Mimetic_Book($book_id, $title, $author, $publisher_id);
		
		
		extract($mb_api->query->get(array('category_id', 'category_slug' )));
		if ($category_id) {
			$book_category = $mb_api->introspector->get_category_by_id($category_id);
		} elseif ($category_slug) {
			$book_category = $mb_api->introspector->get_category_by_slug($category_slug);
		} else {
			$mb_api->error("Include 'category_id' or 'category_slug' var in your request.");
			return;
		}
	
		// get the array of wp chapters using id or slug of the book category
		$book = $this->get_book($book_category->id);
		
		// Add chapters to new $mb book object.
		// Chapters are arrays of posts/pages
		foreach($book['chapters'] as $chapter) {
			$mb->convert_chapter($chapter);
		}
		
		return $mb->book;
		

	}
	/*
	 * get_book_info
	 * Return an array of the book settings from the plugin
	 * settings page:
	 * $book_id, $title, $author, $publisher_id
	 */
	public function get_book_info() {
		$title = get_option('mb_api_book_title', 'Untitled');
		$author = get_option('mb_api_book_author', 'Anonymous');
		$book_id = get_option('mb_api_book_id', "mb_".uniqid() );
		$publisher_id = get_option('mb_api_book_publisher_id', '?');
		
		return array ($book_id, $title, $author, $publisher_id);
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
	public function get_book($book_cat_id) {
		global $mb_api;
		
		// Get the wp category object by id or slug
		$book_category = $mb_api->introspector->get_category_by_id($book_cat_id);
		
		
		$info = $this->get_book_info();
		//list($book_id, $title, $author, $publisher_id) = $info;
		$book_chapters = $this->get_book_chapters($book_category->id);
		$book = array (
			"info"		=> $info,
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
		$posts = $mb_api->introspector->get_posts();
		return $this->posts_result($posts);
	}
	
	public function get_post() {
		global $mb_api, $post;
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
		$permalinks = $mb_api->introspector->get_date_archive_permalinks();
		$tree = $mb_api->introspector->get_date_archive_tree($permalinks);
		return array(
			'permalinks' => $permalinks,
			'tree' => $tree
		);
	}
	
	public function get_category_index($key) {
		global $mb_api;
		$categories = $mb_api->introspector->get_categories($key);
		return array(
			'count' => count($categories),
			'categories' => $categories
		);
	}
	
	public function get_category_index_by_id() {
		global $mb_api;
		$categories = $mb_api->introspector->get_categories("id");
		return array(
			'count' => count($categories),
			'categories' => $categories
		);
	}
	
	public function get_category_index_by_slug() {
		global $mb_api;
		$categories = $mb_api->introspector->get_categories("slug");
		return array(
			'count' => count($categories),
			'categories' => $categories
		);
	}
	
	public function get_tag_index() {
		global $mb_api;
		$tags = $mb_api->introspector->get_tags();
		return array(
			'count' => count($tags),
			'tags' => $tags
		);
	}
	
	public function get_author_index() {
		global $mb_api;
		$authors = $mb_api->introspector->get_authors();
		return array(
			'count' => count($authors),
			'authors' => array_values($authors)
		);
	}
	
	public function get_page_index() {
		global $mb_api;
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
	
}

?>
