<?php

class MB_API {
  
	function __construct() {
		
		$dir = mb_api_dir();

		$this->settings = parse_ini_file ( $dir . DIRECTORY_SEPARATOR . "mb-settings.ini" );

		$this->query = new MB_API_Query();
		$this->introspector = new MB_API_Introspector();
		$this->response = new MB_API_Response();
		$this->funx = new MB_API_Funx();
		$this->themes = new MB_API_Themes();

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
					<input type="button" id="publish" class="button-primary" value="<?php _e('Publish Book') ?>" />
					<span style="margin-left:20px;" id="publishing_progress_message" ></span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Layout Theme:</th>
				<td><?php echo $this->theme_popup_menu() ?> </td>
			</tr>
		</table>
		

		<h3>Publisher</h3>
		<p>
			Enter the URL for your publisher's website <em>to which you wish to send published books</em>. Leave this empty if you are distributing books from this website.
			<br/>
			Enter your Default Publisher ID, the unique code that identifies you as a publisher. You can choose your publisher ID for each book, as well, on the book information pages.
		</p>
	
		<table class="form-table">
			<tr valign="top">
				<th scope="row">Publish Books to Website:</th>
				<td>
					<input type="text" id="distribution_url" name="mb_api_book_publisher_url" size="64" value="<?php print get_option('mb_api_book_publisher_url', trim($this->settings['distribution_url']));  ?>" />
					<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />
				</td>
			</tr>
			<tr valign="top">
				
				<th scope="row">Default Publisher ID:</th>
				<td>
					<input type="text" name="mb_api_book_publisher_id" value="<?php echo get_option('mb_api_book_publisher_id', 'public'); ?>" size="32" /></td>
			</tr>
		</table>
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
  
  // For one book, used on a book post page
	function book_theme_popup_menu($book_id) {

		if ($book_id) {
			$book_post = get_post( array ('p' => $book_id) );
		} else {
			$mb_api->error(__FUNCTION__.": No book ID passed to me.");
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
  

	function load_themes() {
		$dir = mb_api_dir();
		$themes_dir = "$dir/themes";

		$this->themes->LoadAllThemes ($themes_dir);
	}


/*
 * ========== upload images to the plugin options ============
 */
 
 /*
	 function wp_plugin_image_get_default_options() {
		$options = array(
			'logo' => ''
		);
		return $options;
	}
	
	
	function wp_plugin_image_options_init() {
		 $wp_plugin_image_options = get_option( 'theme_wp_plugin_image_options' );
		 
		 // Are our options saved in the DB?
		 if ( false === $wp_plugin_image_options ) {
			// If not, we'll save our default options
			$wp_plugin_image_options = wp_plugin_image_get_default_options();
			add_option( 'theme_wp_plugin_image_options', $wp_plugin_image_options );
		 }
		 
		 // In other case we don't need to update the DB
	}

	function wp_plugin_image_options_setup() {
		global $pagenow;
		if ('media-upload.php' == $pagenow || 'async-upload.php' == $pagenow) {
			// Now we'll replace the 'Insert into Post Button inside Thickbox' 
			add_filter( 'gettext', 'replace_thickbox_text' , 1, 2 );
		}
	}
 

	function replace_thickbox_text($translated_text, $text ) {	
		if ( 'Insert into Post' == $text ) {
			$referer = strpos( wp_get_referer(), 'wp_plugin_image-settings' );
			if ( $referer != '' ) {
				return __('I want this to be my logo!', 'wp_plugin_image' );
			}
		}
	
		return $translated_text;
	}
	
	function wp_plugin_image_admin_options_page() {
		?>
			<!-- 'wrap','submit','icon32','button-primary' and 'button-secondary' are classes 
			for a good WP Admin Panel viewing and are predefined by WP CSS -->
			
			
			
			<div class="wrap">
				
				<div id="icon-themes" class="icon32"><br /></div>
			
				<h2><?php _e( 'WP-MBs Options', 'wp_plugin_image' ); ?></h2>
				
				<!-- If we have any error by submiting the form, they will appear here -->
				<?php settings_errors( 'wp_plugin_image-settings-errors' ); ?>
				
				<form id="form-wp_plugin_image-options" action="options.php" method="post" enctype="multipart/form-data">
				
					<?php
						settings_fields('theme_wp_plugin_image_options');
						do_settings_sections('wp_plugin_image');
					?>
				
					<p class="submit">
						<input name="theme_wp_plugin_image_options[submit]" id="submit_options_form" type="submit" class="button-primary" value="<?php esc_attr_e('Save Settings', 'wp_plugin_image'); ?>" />
						<input name="theme_wp_plugin_image_options[reset]" type="submit" class="button-secondary" value="<?php esc_attr_e('Reset Defaults', 'wp_plugin_image'); ?>" />		
					</p>
				
				</form>
				
			</div>
		<?php
	}
	
	function wp_plugin_image_options_validate( $input ) {
		$default_options = wp_plugin_image_get_default_options();
		$valid_input = $default_options;
		
		$wp_plugin_image_options = get_option('theme_wp_plugin_image_options');
		
		$submit = ! empty($input['submit']) ? true : false;
		$reset = ! empty($input['reset']) ? true : false;
		$delete_logo = ! empty($input['delete_logo']) ? true : false;
		
		if ( $submit ) {
			if ( $wp_plugin_image_options['logo'] != $input['logo']  && $wp_plugin_image_options['logo'] != '' )
				delete_image( $wp_plugin_image_options['logo'] );
			
			$valid_input['logo'] = $input['logo'];
		}
		elseif ( $reset ) {
			delete_image( $wp_plugin_image_options['logo'] );
			$valid_input['logo'] = $default_options['logo'];
		}
		elseif ( $delete_logo ) {
			delete_image( $wp_plugin_image_options['logo'] );
			$valid_input['logo'] = '';
		}
		
		return $valid_input;
	}
	
	function delete_image( $image_url ) {
		global $wpdb;
		
		// We need to get the image's meta ID..
		$query = "SELECT ID FROM wp_posts where guid = '" . esc_url($image_url) . "' AND post_type = 'attachment'";  
		$results = $wpdb -> get_results($query);
	
		// And delete them (if more than one attachment is in the Library
		foreach ( $results as $row ) {
			wp_delete_attachment( $row -> ID );
		}	
	}
	
	// --------------- JAVASCRIPT ------------------
	function wp_plugin_image_options_enqueue_scripts() {
		wp_register_script( 'image_upload', get_template_directory_uri() .'/js/image_upload.js', array('jquery','media-upload','thickbox') );	
	
		if ( 'appearance_page_wp_plugin_image-settings' == get_current_screen() -> id ) {
			wp_enqueue_script('jquery');
			
			wp_enqueue_script('thickbox');
			wp_enqueue_style('thickbox');
			
			wp_enqueue_script('media-upload');
			wp_enqueue_script('image_upload');
			
		}
		
	}


	 function wp_plugin_image_options_settings_init() {
		register_setting( 'theme_wp_plugin_image_options', 'theme_wp_plugin_image_options', 'wp_plugin_image_options_validate' );
		
		// Add a form section for the Logo
		add_settings_section('wp_plugin_image_settings_header', __( 'Logo Options', 'wp_plugin_image' ), 'wp_plugin_image_settings_header_text', 'wp_plugin_image');
		
		// Add Logo uploader
		add_settings_field('wp_plugin_image_setting_logo',  __( 'Logo', 'wp_plugin_image' ), 'wp_plugin_image_setting_logo', 'wp_plugin_image', 'wp_plugin_image_settings_header');
		
		// Add Current Image Preview 
		add_settings_field('wp_plugin_image_setting_logo_preview',  __( 'Logo Preview', 'wp_plugin_image' ), 'wp_plugin_image_setting_logo_preview', 'wp_plugin_image', 'wp_plugin_image_settings_header');
	}
 
	function wp_plugin_image_setting_logo_preview() {
		$wp_plugin_image_options = get_option( 'theme_wp_plugin_image_options' );  ?>
		<div id="upload_logo_preview" style="min-height: 100px;">
			<img style="max-width:100%;" src="<?php echo esc_url( $wp_plugin_image_options['logo'] ); ?>" />
		</div>
		<?php
	}
	
	function wp_plugin_image_settings_header_text() {
		?>
			<p><?php _e( 'Manage Logo Options for Wp-MBs Theme.', 'wp_plugin_image' ); ?></p>
		<?php
	}
	
	function wp_plugin_image_setting_logo() {
		$wp_plugin_image_options = get_option( 'theme_wp_plugin_image_options' );
		?>
			<input type="hidden" id="logo_url" name="theme_wp_plugin_image_options[logo]" value="<?php echo esc_url( $wp_plugin_image_options['logo'] ); ?>" />
			<input id="upload_logo_button" type="button" class="button" value="<?php _e( 'Upload Logo', 'wp_plugin_image' ); ?>" />
			<?php if ( '' != $wp_plugin_image_options['logo'] ): ?>
				<input id="delete_logo_button" name="theme_wp_plugin_image_options[delete_logo]" type="submit" class="button" value="<?php _e( 'Delete Logo', 'wp_plugin_image' ); ?>" />
			<?php endif; ?>
			<span class="description"><?php _e('Upload an image for the banner.', 'wp_plugin_image' ); ?></span>
		<?php
	}


	function wp_plugin_image_options_enqueue_scripts() {
		wp_register_script( 'image_upload', get_template_directory_uri() .'/js/image_upload.js', array('jquery','media-upload','thickbox') );	
	
		if ( 'appearance_page_wp_plugin_image-settings' == get_current_screen() -> id ) {
			wp_enqueue_script('jquery');
			
			wp_enqueue_script('thickbox');
			wp_enqueue_style('thickbox');
			
			wp_enqueue_script('media-upload');
			wp_enqueue_script('image_upload');
			
		}
		
	}
*/

	// ==================== easier uploader

	function plugin_admin_init() { 
	
		$dir = mb_api_dir();
	
		//wp_enqueue_script('media-upload');
		//wp_enqueue_script('thickbox');

		//$url = plugins_url( 'js/image_upload.js', __FILE__ );
		//wp_register_script('image_upload', $url, array('jquery','media-upload','thickbox'));
		//wp_enqueue_script('image_upload');

		$url = plugins_url( 'js/publish.js', __FILE__ );
		wp_register_script('publish', $url, array('jquery'));
	}
	
	function mb_javascript_scripts() {
		wp_enqueue_style('publish');
	}
	
	
	function image_uploader_styles() { 
		wp_enqueue_style('thickbox');
	} 



}

?>
