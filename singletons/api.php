<?php

class MB_API {
  
	function __construct() {
		
		$dir = mb_api_dir();

		$this->settings = parse_ini_file ( $dir . DIRECTORY_SEPARATOR . "mb-settings.ini" );

		$this->query = new MB_API_Query();
		$this->introspector = new MB_API_Introspector();
		$this->response = new MB_API_Response();
		$this->funx = new MB_API_Funx();
		$this->commerce = new MB_API_Commerce($dir);
		$this->themes_dir_name = "themes";
		$this->themes_dir = $dir .DIRECTORY_SEPARATOR. $this->themes_dir_name;
		$this->themes = new MB_API_Themes($this->themes_dir);


		// Special "Do Not Use Me" marker for title or text blocks.
		// Anything beginning with this code will be ignored!
		$this->ignore_me_code = "###";

		// URL of this plugin
		$this->url = plugins_url() .DIRECTORY_SEPARATOR. basename($dir);

		$uploads = wp_upload_dir();

		$this->logfile = $dir . DIRECTORY_SEPARATOR . "mb-books-api.log";

		// Create the temp dir for building book packages
		$this->tempDir = $uploads['basedir'] . DIRECTORY_SEPARATOR . $this->settings['temp_dir_name'];
		if(! is_dir($this->tempDir))
			mkdir($this->tempDir);

		// Create the dir for holding book packages
		$this->package_dir = $uploads['basedir'] . DIRECTORY_SEPARATOR . $this->settings['packages_dir_name'];
		if(! is_dir($this->package_dir))
			mkdir($this->package_dir);

		// If missing, create the dir for holding book packages for distribution,
		// the "shelves" directory.
		$this->shelves_dir = $uploads['basedir'] . DIRECTORY_SEPARATOR . $this->settings['shelves_dir_name'];
		if(! is_dir($this->shelves_dir))
			mkdir($this->shelves_dir);

		// If missing, create the dir for holding publisher information,
		// the "publishers" directory.
		$this->publishers_dir = $uploads['basedir'] . DIRECTORY_SEPARATOR . $this->settings['publishers_dir_name'];
		if(! is_dir($this->publishers_dir))
			mkdir($this->publishers_dir);


		add_action('template_redirect', array(&$this, 'template_redirect'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		
		// My javascripts are loaded here
		add_action('admin_init', array(&$this, 'plugin_admin_init'));
		add_action('admin_enqueue_scripts', array(&$this, 'mb_javascript_scripts'));
		
		add_action('update_option_mb_api_base', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_info_post_id', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_title', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_author', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_id', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_theme_id', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_publisher_id', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_publisher_url', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_icon', array(&$this, 'flush_rewrite_rules'));
		add_action('update_option_mb_api_book_poster', array(&$this, 'flush_rewrite_rules'));
		add_action('pre_update_option_mb_api_controllers', array(&$this, 'update_controllers'));
		
		// Image uploading:
			//add_action('admin_print_scripts', array(&$this, 'mb_javascript_scripts'));
		add_action('admin_print_styles', array(&$this, 'image_uploader_styles'));

		// Book Custom Post: 
		// Delete all attached media from a custom 'book' post if the post is deleted.
		add_action('update_option_mb_api_base', array(&$this, 'flush_rewrite_rules'));
		
		
		
		// Initialize Theme options
		/*
		add_action( 'after_setup_theme', array(&$this, 'wp_plugin_image_options_init'));
		add_action( 'admin_init', array(&$this, 'wp_plugin_image_options_setup'));
		add_action( 'admin_enqueue_scripts', array(&$this, 'wp_plugin_image_options_enqueue_scripts'));
		add_action( 'admin_init', array(&$this, 'wp_plugin_image_options_settings_init'));
		*/
		
		// Do commerce-related actions if there is a commerce plugin installed that works.
		// This includes creating/updating an item for sale based on a book post.
		$this->commerce_is_installed = $this->commerce->commerce_is_installed();

		if ( $this->commerce_is_installed ) {
			$this->commerce->add_single_purchase_verification();
		}

		// Remove filters for excerpts which usually add a "read more" or something like that.
		//remove_filter( 'get_the_excerpt', 'twentyeleven_custom_excerpt_more' );
		remove_all_filters( 'get_the_excerpt' );
		
		add_filter('the_title', array(&$this, 'remove_private_prefix') ) ;
	}
  

	// Function to remove the "Private" and "Protected" from private and protected pages
	function remove_private_prefix($title) {

		$title = esc_attr($title);

		$findthese = array(
			'#Protected:#',
			'#Private:#'
		);

		$replacewith = array(
			'', // What to replace "Protected:" with
			'' // What to replace "Private:" with
		);

		$title = preg_replace($findthese, $replacewith, $title);
		return $title;
	}	
	
	
	
  function template_redirect() {
    // Check to see if there's an appropriate API controller + method    
    $controller = strtolower($this->query->get_controller());
    $available_controllers = $this->get_controllers();
    $enabled_controllers = explode(',', get_option('mb_api_controllers', 'core'));
    $active_controllers = array_intersect($available_controllers, $enabled_controllers);
    
    if ($controller) {
      
      if (!in_array($controller, $active_controllers)) {
        $this->error("Unknown controller '$controller'.");
      }
      
      $controller_path = $this->controller_path($controller);
      if (file_exists($controller_path)) {
        require_once $controller_path;
      }
      $controller_class = $this->controller_class($controller);
      
      if (!class_exists($controller_class)) {
        $this->error("Unknown controller '$controller_class'.");
      }
      
      $this->controller = new $controller_class();
      $method = $this->query->get_method($controller);
      
      if ($method) {
        
        $this->response->setup();
        
        // Run action hooks for method
        do_action("mb_api-{$controller}-$method");
        
        // Error out if nothing is found
        if ($method == '404') {
          $this->error('Not found');
        }
		// Run the method
        $result = $this->controller->$method();

        // Handle the result
        $this->response->respond($result);
        
        // Done!
        exit;
      }
    }
  }
  
  function admin_menu() {
    add_options_page('Mimetic Books API Settings', 'Mimetic Books API', 'manage_options', 'mb-api', array(&$this, 'admin_options'));
  }
  
  function admin_options() {
    if (!current_user_can('manage_options'))  {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }
    
	wp_enqueue_script('publish');
	
	// ---------- CONTROLLERS ------------
    $available_controllers = $this->get_controllers();
    $active_controllers = explode(',', get_option('mb_api_controllers', 'core'));
    
    if (count($active_controllers) == 1 && empty($active_controllers[0])) {
      $active_controllers = array();
    }
    
    if (!empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], "update-options")) {
      if ((!empty($_REQUEST['action']) || !empty($_REQUEST['action2'])) &&
          (!empty($_REQUEST['controller']) || !empty($_REQUEST['controllers']))) {
        if (!empty($_REQUEST['action'])) {
          $action = $_REQUEST['action'];
        } else {
          $action = $_REQUEST['action2'];
        }
        
        if (!empty($_REQUEST['controllers'])) {
          $controllers = $_REQUEST['controllers'];
        } else {
          $controllers = array($_REQUEST['controller']);
        }
        
        foreach ($controllers as $controller) {
          if (in_array($controller, $available_controllers)) {
            if ($action == 'activate' && !in_array($controller, $active_controllers)) {
              $active_controllers[] = $controller;
            } else if ($action == 'deactivate') {
              $index = array_search($controller, $active_controllers);
              if ($index !== false) {
                unset($active_controllers[$index]);
              }
            }
          }
        }
        $this->save_option('mb_api_controllers', implode(',', $active_controllers));
      }
      if (isset($_REQUEST['mb_api_base'])) {
        $this->save_option('mb_api_base', $_REQUEST['mb_api_base']);
      }
      if (isset($_REQUEST['mb_api_book_title'])) {
        $this->save_option('mb_api_book_title', $_REQUEST['mb_api_book_title']);
      }
      if (isset($_REQUEST['mb_api_book_author'])) {
        $this->save_option('mb_api_book_author', $_REQUEST['mb_api_book_author']);
      }
      if (isset($_REQUEST['mb_api_book_id'])) {
        $this->save_option('mb_api_book_id', $_REQUEST['mb_api_book_id']);
      }
      if (isset($_REQUEST['mb_api_book_theme_id'])) {
        $this->save_option('mb_api_book_theme_id', $_REQUEST['mb_api_book_theme_id']);
      }
      if (isset($_REQUEST['mb_api_book_publisher_id'])) {
        $this->save_option('mb_api_book_publisher_id', $_REQUEST['mb_api_book_publisher_id']);
      }
      if (isset($_REQUEST['mb_api_book_publisher_url'])) {
        $this->save_option('mb_api_book_publisher_url', $_REQUEST['mb_api_book_publisher_url']);
      }
      
      if (isset($_REQUEST['mb_api_book_info_post_id'])) {
		$this->save_option('mb_api_book_info_post_id', $_REQUEST['mb_api_book_info_post_id']);
      }
      
      if (isset($_REQUEST['mb_api_show_only_my_posts'])) {
		$this->save_option('mb_api_show_only_my_posts', true);
      } else {
	      $this->save_option('mb_api_show_only_my_posts', false);
	  }
      
   }
	// ---------- END CONTROLLERS ------------
    
    ?>
<div class="wrap">
  <div id="icon-options-general" class="icon32"><br /></div>
  <h2>Mimetic Books API Settings</h2>
  <form action="options-general.php?page=mb-api" method="post">
    <?php wp_nonce_field('update-options'); ?>

	<div style="padding:1em 3em 1em 3em; margin-top:1em; margin-bottom:1em; background-color:#f0f6f6;">
		<h3>Book</h3>
		<p>Choose which book you wish to publish. Maybe better if this lives on the book's post page, but I don't know how to do that right now.</p>
		<table class="form-table">
		 <tr valign="top">
			<th scope="row">Book:</th>
				<td>
					<?php
						$args = array (
							'post_type' => 'book',
							'posts_per_page' => -1
						);

						$my_query = null;
						$my_query = new WP_Query($args);

						$selected = get_option('mb_api_book_info_post_id');

						echo '<select id="mb_api_book_info_post_id" name="mb_api_book_info_post_id">';
						if( $my_query->have_posts() ) {
							while ( $my_query->have_posts() ) : $my_query->the_post();
								$id = get_the_ID();
								$title = get_the_title();
								if ($id == $selected) {
									echo '<option selected="selected" value="'. $id .'">'. $title . '</option>';
								} else {
									echo '<option value="'. $id.'">'. $title. '</option>';
								}
							endwhile;
						}
						echo '</select>';
						wp_reset_query();  // Restore global post data stomped by the_post().
					?>      

					<span style="margin-right:2em;">&nbsp;</span>
					<input type="button" id="publish_book_button" class="button-primary" value="<?php _e('Publish Book') ?>" />
					<span style="margin-left:20px;" id="publishing_progress_message" ></span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Layout Theme:</th>
				<td><?php echo $this->theme_popup_menu() ?> </td>
			</tr>
		</table>
		
					<input type="button" id="update_publishers" class="button-primary" value="<?php _e('Update Publishers') ?>" />

		<h3>Publisher</h3>
		<p>
			Enter the URL for your publisher's website <em>to which you wish to send published books</em>. Leave this empty if you are distributing books from this website. <i>Do not add "http://", we will do that for you.</i>
		</p>
	
		<table class="form-table">
			<tr valign="top">
				<th scope="row">Publish Books to Website:</th>
				<td>
					http://<input type="text" id="distribution_url" name="mb_api_book_publisher_url" size="64" value="<?php print get_option('mb_api_book_publisher_url', trim($this->settings['distribution_url']));  ?>" />
					<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />
				</td>
			</tr>
		</table>
		<p>
			Enter your Default Publisher ID, the unique code that identifies you as a publisher. You can choose your publisher ID for each book, as well, on the book information pages.
		</p>
		<table class="form-table">
			<tr valign="top">				
				<th scope="row">Default Publisher ID:</th>
				<td>
					<input type="text" name="mb_api_book_publisher_id" value="<?php echo get_option('mb_api_book_publisher_id', 'public'); ?>" size="32" />
				</td>
			</tr>
		</table>
		
		<h3>Misc. Settings</h3>
		<p>
			<input type="checkbox" name="mb_api_show_only_my_posts[]" value="<?php if ( get_option('mb_api_show_only_my_posts', '') ) { echo "1"; } ?>" <?php if ( get_option('mb_api_show_only_my_posts', '') ) { echo "checked"; } ?>/> Show Only My Posts. <i>Check this box so that users will only see their posts. They cannot share posts, but the don't have to see everyone else's, either.</i>
		</p>
		
		
		<h3>Hints</h3>
		<ul style="list-style-type:circle;">
			<li>Any title or text block that begins with "<?php echo $this->ignore_me_code; ?>" will be ignored. This is useful for design templates that don't use titles, for example.
			</li>
			<li>You can add returns to titles, which is cool if you want a multi-line title. Using our magic return code: <tt>[[[br]]]</tt> 
			</li>
			<li>For each publisher, you should make a new Page (not a post), and set the publisher ID on the page.
			</li>
		</ul>

		
		
		<!--
		<tr valign="top">
			<th scope="row">Title</th>
			<td><input type="text" name="mb_api_book_title" value="<?php echo get_option('mb_api_book_title', 'Untitled'); ?>" size="64" /></td>
			</tr>
			<tr valign="top">
			<th scope="row">Author(s)</th>
			<td><input type="text" name="mb_api_book_author" value="<?php echo get_option('mb_api_book_author', 'Anonymous'); ?>" size="64" /></td>
			</tr>
			<tr valign="top">
			<th scope="row">Book ID</th>
			<td><input type="text" name="mb_api_book_id" value="<?php echo get_option('mb_api_book_id', "mb_".uniqid()); ?>" size="64" /></td>
		</tr>
		-->
		<!--
		<tr valign="top">
			<th scope="row">Icon</th>
			<td>
				<label for="upload_image">
				<input id="upload_image" type="text" size="36" name="upload_image" value="" />
				<input id="upload_image_button" type="button" value="Upload Image" />
				<br />
				Enter an URL or upload an image for the banner.
				</label>
			</td>
		</tr>
		-->
	
		</table>
		<!--
		<h3>Address</h3>
		<p>Specify a base URL for MB API. For example, using <code>mb</code> as your API base URL would enable the following <code><?php bloginfo('url'); ?>/mb/get_recent_posts/</code>. If you assign a blank value the API will only be available by setting a <code>mb</code> query variable.</p>
		<table class="form-table">
		<tr valign="top">
			<th scope="row">API base</th>
			<td><code><?php bloginfo('url'); ?>/</code><input type="text" name="mb_api_base" value="<?php echo get_option('mb_api_base', 'mb'); ?>" size="15" /></td>
		</tr>
		</table>
		-->

		<?php if (!get_option('permalink_structure', '')) { ?>
		<br />
		<p><strong>Note:</strong> User-friendly permalinks are not currently enabled. The plugin will fail without proper warning! <a target="_blank" class="button" href="options-permalink.php">Change Permalinks</a>
		<?php } ?>
		<p class="submit">
		<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
		</p>
	</div>
    
      
        <h3>Controllers</h3>
    <?php $this->print_controller_actions(); ?>
    <table id="all-plugins-table" class="widefat">
      <thead>
        <tr>
          <th class="manage-column check-column" scope="col"><input type="checkbox" /></th>
          <th class="manage-column" scope="col">Controller</th>
          <th class="manage-column" scope="col">Description</th>
        </tr>
      </thead>
      <tfoot>
        <tr>
          <th class="manage-column check-column" scope="col"><input type="checkbox" /></th>
          <th class="manage-column" scope="col">Controller</th>
          <th class="manage-column" scope="col">Description</th>
        </tr>
      </tfoot>
      <tbody class="plugins">
        <?php
        
        foreach ($available_controllers as $controller) {
          
          $error = false;
          $active = in_array($controller, $active_controllers);
          $info = $this->controller_info($controller);
          
          if (is_string($info)) {
            $active = false;
            $error = true;
            $info = array(
              'name' => $controller,
              'description' => "<p><strong>Error</strong>: $info</p>",
              'methods' => array(),
              'url' => null
            );
          }
          
          ?>
          <tr class="<?php echo ($active ? 'active' : 'inactive'); ?>">
            <th class="check-column" scope="row">
              <input type="checkbox" name="controllers[]" value="<?php echo $controller; ?>" />
            </th>
            <td class="plugin-title">
              <strong><?php echo $info['name']; ?></strong>
              <div class="row-actions-visible">
                <?php
                
                if ($active) {
                  echo '<a href="' . wp_nonce_url('options-general.php?page=mb-api&amp;action=deactivate&amp;controller=' . $controller, 'update-options') . '" title="' . __('Deactivate this controller') . '" class="edit">' . __('Deactivate') . '</a>';
                } else if (!$error) {
                  echo '<a href="' . wp_nonce_url('options-general.php?page=mb-api&amp;action=activate&amp;controller=' . $controller, 'update-options') . '" title="' . __('Activate this controller') . '" class="edit">' . __('Activate') . '</a>';
                }
                  
                if (isset($info['url']) && $info['url']) {
                  echo ' | ';
                  echo '<a href="' . $info['url'] . '" target="_blank">Docs</a></div>';
                }
                
                ?>
            </td>
            <td class="desc">
              <p><?php echo $info['description']; ?></p>
              <p>
                <?php
                
                foreach($info['methods'] as $method) {
                  $url = $this->get_method_url($controller, $method, array('dev' => 1));
                  if ($active) {
                    echo "<code><a href=\"$url\">$method</a></code> ";
                  } else {
                    echo "<code>$method</code> ";
                  }
                }
                
                ?>
              </p>
            </td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    
    <?php $this->print_controller_actions('action2'); ?>

  
  </form>
</div>
<?php
  }	// END of admin_options()
  
  function print_controller_actions($name = 'action') {
    ?>
    <div class="tablenav">
      <div class="alignleft actions">
        <select name="<?php echo $name; ?>">
          <option selected="selected" value="-1">Bulk Actions</option>
          <option value="activate">Activate</option>
          <option value="deactivate">Deactivate</option>
        </select>
        <input type="submit" class="button-secondary action" id="doaction" name="doaction" value="Apply">
      </div>
      <div class="clear"></div>
    </div>
    <div class="clear"></div>
    <?php
  }
  
  function get_method_url($controller, $method, $options = '') {
    $url = get_bloginfo('url');
    $base = get_option('mb_api_base', 'mb');
    $permalink_structure = get_option('permalink_structure', '');
    if (!empty($options) && is_array($options)) {
      $args = array();
      foreach ($options as $key => $value) {
        $args[] = urlencode($key) . '=' . urlencode($value);
      }
      $args = implode('&', $args);
    } else {
      $args = $options;
    }
    if ($controller != 'core') {
      $method = "$controller/$method";
    }
    if (!empty($base) && !empty($permalink_structure)) {
      if (!empty($args)) {
        $args = "?$args";
      }
      return "$url/$base/$method/$args";
    } else {
      return "$url?mb=$method&$args";
    }
  }
  
  function save_option($id, $value) {
    $option_exists = (get_option($id, null) !== null);
    if ($option_exists) {
      update_option($id, $value);
    } else {
      add_option($id, $value);
    }
  }
  
  function get_controllers() {
    $controllers = array();
    $dir = mb_api_dir();
    $dh = opendir("$dir/controllers");
    while ($file = readdir($dh)) {
      if (preg_match('/(.+)\.php$/', $file, $matches)) {
        $controllers[] = $matches[1];
      }
    }
    $controllers = apply_filters('mb_api_controllers', $controllers);
    return array_map('strtolower', $controllers);
  }
  
  function controller_is_active($controller) {
    if (defined('MB_API_CONTROLLERS')) {
      $default = MB_API_CONTROLLERS;
    } else {
      $default = 'core';
    }
    $active_controllers = explode(',', get_option('mb_api_controllers', $default));
    return (in_array($controller, $active_controllers));
  }
  
  function update_controllers($controllers) {
    if (is_array($controllers)) {
      return implode(',', $controllers);
    } else {
      return $controllers;
    }
  }
  
  function controller_info($controller) {
    $path = $this->controller_path($controller);
    $class = $this->controller_class($controller);
    $response = array(
      'name' => $controller,
      'description' => '(No description available)',
      'methods' => array()
    );
    if (file_exists($path)) {
      $source = file_get_contents($path);
      if (preg_match('/^\s*Controller name:(.+)$/im', $source, $matches)) {
        $response['name'] = trim($matches[1]);
      }
      if (preg_match('/^\s*Controller description:(.+)$/im', $source, $matches)) {
        $response['description'] = trim($matches[1]);
      }
      if (preg_match('/^\s*Controller URI:(.+)$/im', $source, $matches)) {
        $response['docs'] = trim($matches[1]);
      }
      if (!class_exists($class)) {
        require_once($path);
      }
      $response['methods'] = get_class_methods($class);
      return $response;
    } else if (is_admin()) {
      return "Cannot find controller class '$class' (filtered path: $path).";
    } else {
      $this->error("Unknown controller '$controller'.");
    }
    return $response;
  }
  
  function controller_class($controller) {
    return "mb_api_{$controller}_controller";
  }
  
  function controller_path($controller) {
    $dir = mb_api_dir();
    $controller_class = $this->controller_class($controller);
    return apply_filters("{$controller_class}_path", "$dir/controllers/$controller.php");
  }
  
  function get_nonce_id($controller, $method) {
    $controller = strtolower($controller);
    $method = strtolower($method);
    return "mb_api-$controller-$method";
  }
  
  function flush_rewrite_rules() {
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
  }
  
  function error($message = 'Unknown error', $status = 'error') {
    $this->response->respond(array(
      'error' => $message
    ), $status);
  }
  
  function include_value($key) {
    return $this->response->is_value_included($key);
  }
  
  // For the whole site, used on the plugin settings page
  function theme_popup_menu() {
    $dir = mb_api_dir();
	
	// We want to minimize loading this...it can be slow.
	$this->load_themes();
	$values = $this->themes->themes_list;
	
	// Default theme is 1;
	$checked = get_option('mb_api_book_theme_id', '1');
	$listname = "mb_api_book_theme_id";
	$sort = true;
	$size = true;
	$extrahtml = "";
	$extraline = array();
	
	$menu = $this->funx->OptionListFromArray ($values, $listname, $checked, $sort, $size, $extrahtml, $extraline);

	return $menu;

  }
  
  
/*
 * FUNCTIONS MOVED FROM THE CONTROLLER, BOOK.PHP
 */
 
 

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
	

	public function get_book_info_from_post( $post_id = null, $category_id = null ) {
		global $mb_api;
	
		if ($post_id) {
			$post = $this->get_book_post($post_id);
			$category_id = $post->categories[0]->id;
		} elseif ($category_id) {
			$post = get_book_post_from_category_id($category_id);
		} else {
			extract($mb_api->query->get(array('id', 'post_id')));
			$post_id || $post_id = $id;
			$post = $this->get_book_post($post_id);
			$category_id = $post->categories[0]->id;
			
			$post || $mb_api->error(__FUNCTION__.": Invalid post id.");
			$post_id || $mb_api->error(__FUNCTION__.": Missing post id.");
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
		// Default theme is 1.
		if (isset($custom_fields['mb_book_theme_id']) && $custom_fields['mb_book_theme_id']) {
			// Get from book post
			$theme_id =  (string)$custom_fields['mb_book_theme_id'][0];
		} else {
			// get from settings page
			$theme_id = (string)get_option('mb_api_book_theme', 1);
		}
		// If not set, then the theme is the default theme, "1"
		$theme_id || $theme_id = "1";
		
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}

		if (!isset($mb_api->themes->themes[$theme_id])) {
			$this->write_log(__FUNCTION__.": The chosen theme ({$theme_id}) does not exist!");
			$theme_id = $theme_id || "NONE";
			//$this->error(__FUNCTION__.": The chosen theme ({$theme_id}) does not exist!");
		} else {
			$theme = $mb_api->themes->themes[$theme_id];
		}
		
		// Orientation: portrait/landscape, based on the theme
		$orientation = $theme->orientation;
		$orientation || $orientation = "landscape";
//($mb_api->themes->themes[$theme_id]->orientation)			

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
			$type = 'book';
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
				'posts_per_page' => 1,
				'post_status' => 'any'
			); 
			$poster_attachment = get_posts($args);
			if ($poster_attachment && $poster_attachment[0]) {
				$poster_attachment = $poster_attachment[0];
				$poster_url = $poster_attachment->guid;
			}
		}
		
		// -----------
		// Get settings from the book post page
		
		// Hide header on the poster in the listing of books on the shelves in the app.
		if ( isset($custom_fields['mb_no_header_on_poster'][0]) ) {
			$hideHeaderOnPoster = $custom_fields['mb_no_header_on_poster'][0];
		} else {
			$hideHeaderOnPoster = false;
		}
		
		// Get dimensions of the target device, e.g. 1024x768 (iPad)
		if ( isset($custom_fields['mb_target_device'][0]) ) {
			$target_device = strtolower($custom_fields['mb_target_device'][0]);
		} else {
			$target_device = "ipad";
		}
		
		// Get dimensions of target device for the book.
		// Default is 1024x768 (iPad)
		$dimensions = $mb_api->getDimensionsForDevice($target_device);
		$save2x = $mb_api->getSave2XForDevice($target_device);
		
		
		/*
		 * Get modification date for book.
		 * Get the most recent of the post modifications OR
		 * the book post modification date.
		 * This means that updating info on the book page itself also
		 * sets the modification date.
		 */
 
 
		// Get most recent post
		$book_posts = get_posts(array(
			'category'		=> $category_id,
			'posts_per_page'	=> 1,
			'post_type'		=> 'post',
			'orderby'		=> 'modified',
			'order'			=> 'DESC',
			'post_status' 	=> 'any'
		));
		
		if ($book_posts) {
			$book_posts = $book_posts[0];
			$modified = $book_posts->post_modified;
			//$mod = $post->post_modified_gmt;
		} else {
			// If no posts (?), then use now? Huh?
			$modified = date('Y-m-d H:i:s');
		}
		
		// If the book page itself was modified more recently, use it as the modification date.
		// This ensures that changing the poster will change the modification date.
		$book_post_modified = $post->modified;
		if (strtotime($book_post_modified) > strtotime($modified)) {
			$modified = $book_post_modified;
			
		}

		// We can't simply say the modified date is now, or else any time we
		// ask about a book, we get a new modified date. Then, the app will
		// think all books need updating all the time.
		//date("Y-m-d H:i:s");
		// Modified time is NOW!
		// $modified = date('Y-m-d H:i:s',current_time('timestamp',1));
				
		$category_id = $post->categories[0]->id;
		
		

		//$theme_id = (string)get_option('mb_api_book_theme', 1);
		
		
		$result = array (
			'id'		=> $book_id, 
			'title'			=> $title, 
			'author'		=> $author, 
//			'theme'			=> $theme, 
			'publisher_id'	=> $publisher_id,  
			'description'	=> $description, 
			'short_description'		=> $short_description, 
			'type'			=> $type, 
			'datetime'		=> $post->date, 
			'modified'		=> $modified, 
			'icon_url'		=> $icon_url, 
			'poster_url'	=> $poster_url,
			'category_id'	=> $category_id,
			'hideHeaderOnPoster' => $hideHeaderOnPoster,
			'dimensions'	=> $dimensions,
			'save2x'		=> $save2x,
			'orientation'	=> $orientation
			);
		
		return $result;
	}
	

  
 /*
 * END: FUNCTIONS MOVED FROM THE CONTROLLER, BOOK.PHP
 */
 
  
  
  
  // For one book, used on a book post page
	function book_theme_popup_menu($book_id) {

		if ($book_id) {
			$book_post = get_post( array ('p' => $book_id) );
		} else {
			$this->error(__FUNCTION__.": No book ID passed to me.");
		}
		// We want to minimize loading this...it can be slow.
		$this->load_themes();
		$values = $this->themes->themes_list;
		// Default theme is 1;
		$checked = get_post_meta($book_id, "mb_book_theme_id", true);
		empty($checked) && $checked = 1;
		$listname = "mb_book_theme_id";
		$sort = true;
		$size = true;
		$extrahtml = "";
		$extraline = array();

		$menu = $this->funx->OptionListFromArray ($values, $listname, $checked, $sort, $size, $extrahtml, $extraline);

		return $menu;

	}

	function get_book_theme_id($book_id)
	{

	}

	/*
	* Get an array of book id's by category they use.
	* Handy do figure out which book a post belongs to.
	*/
	function get_books_by_category() {
		wp_reset_query();
		$books = get_posts(array(
			'posts_per_page'	=> -1,
			'post_type'		=> 'book',
			'post_status'	=> 'any'
		));
		
		$a = array();
		foreach ($books as $book) {
			$cats = wp_get_post_categories($book->ID);
			foreach ($cats as $cat) {
				$a["$cat"] = $book->ID;
			}
		}

		return $a;
	}

	
	
	// Get the book that a post belongs to.
	// We do this by checking the category of the post.
	// The first category that belongs to a book is this post's book!
	// Could a post belong to two books? In theory, yes, although the 
	// you would have problems if the books had two templates.
	function get_post_book_id($post_id)
	{
		$cats = wp_get_post_categories($post_id);
		if (!$cats)
			return null;

		if ($cats) {
			$book_id = null;
			$bbc = $this->get_books_by_category();
			if (!$bbc)
				return null;

			foreach ($cats as $cat) {
				//$cat = (Int) $cat;
				if (isset ($bbc[$cat]) ) {
					$book_id = $bbc[$cat];
					return $book_id;
				}
			}
		}
	}
	
	
	// Given a post ID, get the book theme id it uses.
	// This means first getting the book the post belongs to.
	function get_post_theme_id ($post_id)
	{
		$book_id = (Int)$this->get_post_book_id($post_id);
		$theme_id = get_post_meta($book_id, "mb_book_theme_id", true);
		return $theme_id;
	}
	
  

	function load_themes() {
		$dir = mb_api_dir();
		$themes_dir = "$dir/themes";

		$this->themes->LoadAllThemes ($themes_dir);
	}




	/*
	 * ============================================================
	 * Get an array of all publishers.
	 * This is done by getting all pages which are publisher pages,
	 * meaning they are pages with a publisher ID set.
	 * RETURN: array of publisher names and ID's:
	 *	$publisher_ids = ( id => name, ....)
	 * ============================================================
	 */

	function get_publisher_ids() {
		
		$publishers = array ();
		
		// Get all pages
		$posts = $this->introspector->get_posts(array(
				'post_type' => 'page',
				'posts_per_page'	=> -1,
				'post_status' => 'any'
			), false);
		
		// For all pages which have a publisher id meta data in them...
		foreach ($posts as $post) {
			$publisher_id = get_post_meta($post->id, "mb_publisher_id", true);
			if ($publisher_id) {
				$publishers[$publisher_id] = $post->title;
				//$publishers[$post->title] = $publisher_id;
			}
		}
		
		return $publishers;
	}


	/*
	 * ============================================================
	 * Write the Publishers file
	 * This is a json file, "publishers.json", used by the Mimetic Books app to know
	 * the info about publishers.
	 * ============================================================
	 */
	function write_publishers_file() {
		$this->write_log(__FUNCTION__);

		
		$publishers = array (
			'title'		=> "Publishers",
			'maxsize'	=> 100,
			'id'		=> "publishers",
			'password'	=> "mypassword",
			'filename'	=> "publishers.json",
			'itemsByID'	=> array ()
		);
		
		// Get all pages
		$posts = $this->introspector->get_posts(array(
				'post_type' => 'page',
				'posts_per_page'	=> -1,
				'post_status' => 'any'
			), false);
		
		// Delete all icons in the folder.
		// Create the icons folder if necessary.
		$dir = $this->publishers_dir . DIRECTORY_SEPARATOR . "icons";
		if (file_exists($dir)) {
			$files = array_diff(scandir($dir), array('.','..'));
			foreach ($files as $file) {
				(!is_dir($dir. DIRECTORY_SEPARATOR .$file)) && unlink($dir. DIRECTORY_SEPARATOR .$file);
			}
		} else {
			mkdir ($dir);
		}

		
		// For all pages which have a publisher id meta data in them...
		foreach ($posts as $post) {
			$publisher_id = get_post_meta($post->id, "mb_publisher_id", true);
			if ($publisher_id) {
				if (isset($post->thumbnail) && $post->thumbnail != "") {
					$icon = $post->thumbnail;
				
					$ext = strtolower(substr($icon, -4));
					if ($ext == ".png") {
						// Copy the local file to the publishers directory
						$filename = $this->publishers_dir . DIRECTORY_SEPARATOR . "icons" . DIRECTORY_SEPARATOR . "icon_{$publisher_id}.png";
						$success = copy($icon, $filename);
						if (!$success) {
							$this->error(__FUNCTION__.": Failed to copy $icon to $filename.");
						}
					} else {
						$this->write_log("Publisher icon must be a PNG file.");
					}

				} else {
					$icon = "";
				}
			
				$item = array (
					'id'				=> $publisher_id, 
					'title'				=> $post->title_plain, 
					'description'		=> $post->content, 
					'shortDescription'	=> $post->excerpt, 
					'datetime'			=> $post->date, 
					'modified'			=> $post->modified,
					'author'			=> join (" ", array ($post->author->first_name, $post->author->last_name)),
					'icon'				=> $icon
				);
				$publishers['itemsByID'][$publisher_id] = $item;
			}
		}
		
		$output = json_encode($publishers);
		$fn = $this->publishers_dir . DIRECTORY_SEPARATOR . "publishers.json";
		// Delete previous version of the shelves
		if (file_exists($fn))
			unlink ($fn);
		file_put_contents ($fn, $output, LOCK_EX);
		
		return $publishers;
	}
	
	
	// Returns width, height on the assumption 
	function getDimensionsForDevice($id) {
		$dim = array ('width'=>1024,'height'=>768);
		
		switch ($id) {
			case "ipad" :
				$dim = array ('width'=>1024,'height'=>768);
				break;
			case "ipadretina" :
				$dim = array ('width'=>1024,'height'=>768);
				break;
			case "kindlefire" :
				$dim = array ('width'=>1024,'height'=>600);
				break;
			case "iphone" :
				$dim = array ('width'=>480,'height'=>320);
				break;
			case "iphone5" :
				$dim = array ('width'=>1136,'height'=>640);
				break;
			default :
				$dim = array ('width'=>1024,'height'=>768);
				break;
		}
		
		return $dim;
	}
	
	
	// Returns whether to save a 2x version of the image, as for the iPad2 
	function getSave2XForDevice($id) {
		$r = false;
		
		switch ($id) {
			case "ipad" :
				$r = false;
				break;
			case "ipadretina" :
				$r = true;
				break;
			case "kindlefire" :
				$r = false;
				break;
			case "iphone" :
				$r = false;
				break;
			case "iphone5" :
				$r = false;
				break;
			default :
				$r = true;
				break;
		}
		
		return $r;
	}
	
	
	// ============================================================

	function plugin_admin_init() { 
	
		$dir = mb_api_dir();
	
		//wp_enqueue_script('media-upload');
		//wp_enqueue_script('thickbox');

		//$url = plugins_url( 'js/image_upload.js', __FILE__ );
		//wp_register_script('image_upload', $url, array('jquery','media-upload','thickbox'));
		//wp_enqueue_script('image_upload');

		$url = plugins_url( 'js/mb_api_settings.js', __FILE__ );
		wp_register_script('publish', $url, array('jquery'));
	}
	
	function mb_javascript_scripts() {
		wp_enqueue_style('publish');
	}
	
	
	function image_uploader_styles() { 
		wp_enqueue_style('thickbox');
	} 



	public function write_log($text) {
	
		if (gettype($text) != "string") {
			$text = print_r($text,true);
		}
	
		error_log (date('Y-m-d H:i:s') . ": {$text}\n", 3, $this->logfile);
	}



}

?>
