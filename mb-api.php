<?php
/*
Plugin Name: Mimetic Books API
Plugin URI: http://wordpress.org/extend/plugins/mb-api/
Description: A RESTful API for WordPress eBook Publishing with Mimetic Books
Version: 0.0.1
Author: David Gross
Author URI: http://davidgross.org/
*/

$dir = mb_api_dir();

// @ suppresses error messages
@include_once "$dir/singletons/api.php";
@include_once "$dir/singletons/query.php";
@include_once "$dir/singletons/introspector.php";
@include_once "$dir/singletons/response.php";
@include_once "$dir/singletons/book.php";
@include_once "$dir/models/post.php";
@include_once "$dir/models/comment.php";
@include_once "$dir/models/category.php";
@include_once "$dir/models/tag.php";
@include_once "$dir/models/author.php";
@include_once "$dir/models/attachment.php";

// Themes
include_once "$dir/singletons/themes.php";

// Useful functions
@include_once "$dir/singletons/funx.php";

// Commerce functions
include_once "$dir/singletons/commerce.php";


function mb_api_init() {
	global $mb_api;
	if (phpversion() < 5) {
		add_action('admin_notices', 'mb_api_php_version_warning');
		return;
	}
	if (!class_exists('MB_API')) {
		add_action('admin_notices', 'mb_api_class_warning');
		return;
	}
	add_filter('rewrite_rules_array', 'mb_api_rewrites');
	$mb_api = new MB_API();
	
	
	// THIS SHOULD PROBABLY BE IN THE MB_API CLASS!
	mb_make_custom_post_type_init();
	
	// Add custom metaboxes
	mb_add_custom_metaboxes_to_pages();

	// Add custom metaboxes to posts
	mb_add_custom_metaboxes_to_posts();
	
	// Add custom column to the book posts listing
	add_filter('manage_book_posts_columns', 'mb_book_custom_columns_head');
	add_action('manage_posts_custom_column', 'mb_book_custom_columns_content', 10, 2);
	
	// Add cleanup actions to handle deleting of books and publishers.
	// Note, we do 'before' the delete so we still have access to the post info.
	add_action('before_delete_post', 'mb_delete_book_post');
	add_action('before_delete_post', 'mb_publisher_page_delete');
	
}

function mb_api_php_version_warning() {
	echo "<div id=\"mb-api-warning\" class=\"updated fade\"><p>Sorry, MB API requires PHP version 5.0 or greater.</p></div>";
}

function mb_api_class_warning() {
	echo "<div id=\"mb-api-warning\" class=\"updated fade\"><p>Oops, MB_API class not found. If you've defined a MB_API_DIR constant, double check that the path is correct.</p></div>";
}

function mb_api_activation() {
	// Add the rewrite rule on activation
	global $wp_rewrite;
	add_filter('rewrite_rules_array', 'mb_api_rewrites');
	$wp_rewrite->flush_rules();
}

function mb_api_deactivation() {
	// Remove the rewrite rule on deactivation
	global $wp_rewrite;
	$wp_rewrite->flush_rules();
}

function mb_api_rewrites($wp_rules) {
	$base = get_option('mb_api_base', 'mb');
	if (empty($base)) {
		return $wp_rules;
	}
	$mb_api_rules = array(
		"$base\$" => 'index.php?mb=info',
		"$base/(.+)\$" => 'index.php?mb=$matches[1]'
	);
	return array_merge($mb_api_rules, $wp_rules);
}

function mb_api_dir() {
	if (defined('MB_API_DIR') && file_exists(MB_API_DIR)) {
		return MB_API_DIR;
	} else {
		return dirname(__FILE__);
	}
}








/*
 * ----------------------------------------------------------------------
 * Add custom metaboxes to 'book' types.
 * This lets us add publisher ID's and other stuff to pages
 * ----------------------------------------------------------------------
 */
 
function mb_add_custom_metaboxes_to_pages() {
	 mb_book_page_meta_boxes_setup();
}


/* Meta box setup function. */
function mb_book_page_meta_boxes_setup() {
	/*
	wp_enqueue_script('thickbox');
	
	$jsURL = plugins_url( 'js/image_upload.js', __FILE__ );
	wp_register_script('my-upload', $jsURL, array('jquery'));
	wp_enqueue_script('my-upload');
	*/
	
	// Create the meta box
	add_action( 'add_meta_boxes', 'mb_book_add_page_meta_boxes' );
	
	// When any page is saved, run the processing for the metabox
	add_action( 'save_post', 'mb_book_page_meta_save_postdata');
	
}


/* Create one or more meta boxes to be displayed on the post editor screen. */
function mb_book_add_page_meta_boxes() {

	add_meta_box(
		'book-page-publisher_info',					// Unique ID
		esc_html__( 'Book Publisher ID' ),		// Title
		'mb_book_page_publisher_meta_box',				// Callback function
		'page',										// Admin page (or post type)
		'side',										// Context
		'high'										// Priority
	);
	
}

/* Display the post publish meta box. */
function mb_book_page_publisher_meta_box( $post) { 
	global $mb_api;
	
	// default is 1, the public library
	$mb_publisher_id = get_post_meta($post->ID, "mb_publisher_id", true);
	
	wp_nonce_field( basename( __FILE__ ), 'book_page_nonce' ); 
	
	?>
	<p>
		If this page represents a book publisher, you must enter the publisher ID code here. Otherwise, ignore this box.
		If you enter a code, the book publishing system will assume this is a publisher's information page.
	</p>
		<label for="mb_publisher_id">
			Publisher ID:
		</label>
		<input type="text" id="mb_publisher_id" name="mb_publisher_id" value="<?php print $mb_publisher_id;  ?>" />

	<?php 
}

function mb_book_page_meta_save_postdata( $post_id) {
	global $mb_api;
	
	// verify if this is an auto save routine. 
	// If it is our form has not been submitted, so we dont want to do anything
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;

	// verify this came from the our screen and with proper authorization,
	// because save_post can be triggered at other times
	if (!isset($_POST['book_page_nonce']))
		return;
	
	if ( !wp_verify_nonce( $_POST['book_page_nonce'], basename( __FILE__ ) ) )
		return;

	// Check permissions
	if ( 'page' == $_POST['post_type'] ) 
	{
		if ( !current_user_can( 'edit_page', $post_id ) )
			return;
	}
	else
	{
		if ( !current_user_can( 'edit_post', $post_id ) )
			return;
	}

	// OK, we're authenticated: we need to find and save the data
	$pid = $_POST['mb_publisher_id'];
	$pid = trim($pid);
	update_post_meta( $post_id, 'mb_publisher_id', $_POST['mb_publisher_id'] );
	$mb_api->write_publishers_file();
		
}



/*
 * ----------------------------------------------------------------------
 * Add custom post type, 'book'
 * See: http://codex.wordpress.org/Function_Reference/register_post_type
 * ----------------------------------------------------------------------
 */
 

// Init to create the BOOK custom post type
function mb_make_custom_post_type_init() {
	global $dir;
	
	// Create a custom post type, "book"	
	$labels = array(
    'name' => _x('Books', 'post type general name', 'your_text_domain'),
    'singular_name' => _x('Book', 'post type singular name', 'your_text_domain'),
    'add_new' => _x('Add New', 'book', 'your_text_domain'),
    'add_new_item' => __('Add New Book', 'your_text_domain'),
    'edit_item' => __('Edit Book', 'your_text_domain'),
    'new_item' => __('New Book', 'your_text_domain'),
    'all_items' => __('All Books', 'your_text_domain'),
    'view_item' => __('View Book', 'your_text_domain'),
    'search_items' => __('Search Books', 'your_text_domain'),
    'not_found' =>  __('No books found', 'your_text_domain'),
    'not_found_in_trash' => __('No books found in Trash', 'your_text_domain'), 
    'parent_item_colon' => '',
    'menu_name' => __('Books', 'your_text_domain')

	);
	
	$icon_url = plugins_url('images/mb-book-icon.png', __FILE__);
	
	$args = array(
		'labels' => $labels,
		'public' => true,
		'publicly_queryable' => true,
		'show_ui' => true, 
		'show_in_menu' => true, 
		'query_var' => true,
		'rewrite' => array( 'slug' => _x( 'book', 'URL slug', 'your_text_domain' ) ),
		'capability_type' => 'post',
		'has_archive' => true, 
		'hierarchical' => false,
		'menu_position' => null,
		'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'custom-fields', 'revisions' ),
		'taxonomies'	=> array('category'),
		'register_meta_box_cb'	=> 'mb_book_post_meta_boxes_setup'
	); 
	register_post_type('book', $args);
	
	/* Fire our meta box setup function mb_on the post editor screen. */
	//add_action( 'load-post.php', 'mb_book_post_meta_boxes_setup' );
	//add_action( 'load-post-new.php', 'mb_book_post_meta_boxes_setup' );
	add_action('save_post', 'mb_book_post_meta_save_postdata');
	
	// Custom Icon for book type
	add_action( 'admin_head', 'mb_wpt_book_icons' );
	
}


// Styling for the custom post type icon
function mb_wpt_book_icons() {
	global $dir;
	$icon_url = plugins_url('images/mb-book-icon.png', __FILE__);
	$icon_32_url = plugins_url('images/book-32x32.png', __FILE__);
    ?>
    <style type="text/css" media="screen">
	#menu-posts-book .wp-menu-image {
            background: url(<?php echo ($icon_url); ?>) no-repeat 6px 6px !important;
        }
	#menu-posts-book:hover .wp-menu-image, #menu-posts-book.wp-has-current-submenu .wp-menu-image {
            background-position: 6px -18px !important;
        }
	#icon-edit.icon32-posts-book {
		background: url(<?php echo ($icon_32_url); ?>) no-repeat;
		}
    </style>
<?php 
}
 
// Add filter to ensure the text Book, or book, is displayed when user updates a book 
function mb_api_book_updated_messages( $messages ) {
	global $post, $post_ID;

	$messages['book'] = array(
		0 => '', // Unused. Messages start at index 1.
		1 => sprintf( __('Book updated. <a href="%s">View book</a>', 'your_text_domain'), esc_url( get_permalink($post_ID) ) ),
		2 => __('Custom field updated.', 'your_text_domain'),
		3 => __('Custom field deleted.', 'your_text_domain'),
		4 => __('Book updated.', 'your_text_domain'),
		/* translators: %s: date and time of the revision */
		5 => isset($_GET['revision']) ? sprintf( __('Book restored to revision from %s', 'your_text_domain'), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
		6 => sprintf( __('Book published. <a href="%s">View book</a>', 'your_text_domain'), esc_url( get_permalink($post_ID) ) ),
		7 => __('Book saved.', 'your_text_domain'),
		8 => sprintf( __('Book submitted. <a target="_blank" href="%s">Preview book</a>', 'your_text_domain'), esc_url( add_query_arg( 'preview', 'true', get_permalink($post_ID) ) ) ),
		9 => sprintf( __('Book scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview book</a>', 'your_text_domain'),
			// translators: Publish box date format, see http://php.net/date
			date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ), esc_url( get_permalink($post_ID) ) ),
		10 => sprintf( __('Book draft updated. <a target="_blank" href="%s">Preview book</a>', 'your_text_domain'), esc_url( add_query_arg( 'preview', 'true', get_permalink($post_ID) ) ) ),
	);

	return $messages;
}

// Display contextual help for Books

function mb_api_add_help_text( $contextual_help, $screen_id, $screen ) { 
	//$contextual_help .= __FUNCTION__. ":" . var_dump( $screen ); // use this to help determine $screen->id
	if ( 'book' == $screen->id ) {
		$contextual_help =
			'<p>' . __('Things to remember when adding or editing a book:', 'your_text_domain') . '</p>' .
			'<ul>' .
			'<li>' . __('Specify the correct genre such as Mystery, or Historic.', 'your_text_domain') . '</li>' .
			'<li>' . __('Specify the correct writer of the book.	Remember that the Author module refers to you, the author of this book review.', 'your_text_domain') . '</li>' .
			'</ul>' .
			'<p>' . __('If you want to schedule the book review to be published in the future:', 'your_text_domain') . '</p>' .
			'<ul>' .
			'<li>' . __('Under the Publish module, click on the Edit link next to Publish.', 'your_text_domain') . '</li>' .
			'<li>' . __('Change the date to the date to actual publish this article, then click on Ok.', 'your_text_domain') . '</li>' .
			'</ul>' .
			'<p><strong>' . __('For more information:', 'your_text_domain') . '</strong></p>' .
			'<p>' . __('<a href="http://codex.wordpress.org/Posts_Edit_SubPanel" target="_blank">Edit Posts Documentation</a>', 'your_text_domain') . '</p>' .
			'<p>' . __('<a href="http://wordpress.org/support/" target="_blank">Support Forums</a>', 'your_text_domain') . '</p>' ;
	} elseif ( 'edit-book' == $screen->id ) {
		$contextual_help = 
			'<p>' . __('This is the help screen displaying the table of books blah blah blah.', 'your_text_domain') . '</p>' ;
	}
	return $contextual_help;
}
add_action( 'contextual_help', 'mb_api_add_help_text', 10, 3 );



function mb_rewrite_flush() {
	mb_make_custom_post_type_init();
	flush_rewrite_rules();
}



/*
	* Delete all attachments to a post
	* $filesToKeep = string "file1.ext, file2.text, ...)
	*/
function mb_delete_all_attachments($post_id, $filesToKeep = "" )
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


	
function mb_delete_book_post($post_id)
{
	global $mb_api;
	
	$funx = new MB_API_Funx();
	
	$post = get_post($post_id);
	if ($post->post_type == 'book') {
		mb_delete_all_attachments($post_id);
		$book_id = get_post_meta($post_id, "mb_book_id", true);
		if ($book_id) {
			$dir = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . strtolower($book_id);
			$funx->rrmdir($dir);
			$mb_api->write_shelves_file();
			mb_write_log("Deleted book in shelves: $dir");
		} else {
			mb_write_log("Tried to delete a shelf item, but the book ID is missing or empty for post id=$post_id");
		}
	}
}




/* MODIFY BOOK LISTING */

// ADD NEW COLUMN  
function mb_book_custom_columns_head($defaults) {  
    $defaults['publish_book'] = 'Publish';  
    return $defaults;  
}  
  
// SHOW THE PUBLISH BUTTON
function mb_book_custom_columns_content($column_name, $post_ID) {  
	switch ( $column_name ) {
	case 'publish_book':

		$jsURL = plugins_url( 'js/mb_api.js', __FILE__ );
		wp_register_script('my-publish', $jsURL, array('jquery'));
		wp_enqueue_script('my-publish');

		$jsCSS = plugins_url( 'js/style.css', __FILE__ );
		wp_register_style( 'mb_api_style', $jsCSS);
		wp_enqueue_style('mb_api_style');

		mb_show_publish_button($post_ID) ;	  
		break;
    }  
}  

function mb_show_publish_button($post_ID) {
	global $mb_api;
	
	$mb_book_id = get_post_meta($post_ID, "mb_book_id", true);
	$mb_book_available = get_post_meta($post_ID, "mb_book_available", true);
	if ($mb_book_available && $mb_book_id) {
		?>
		<input type="hidden" id="distribution_url_<?php echo $mb_book_id; ?>" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
		<input type="hidden" id="base_url_<?php echo $mb_book_id; ?>" value="<?php print get_bloginfo('url');  ?>" />

		<input type="button" class="wp-core-ui button-primary publish_book_button" id="<?php echo "$mb_book_id"; ?>" value="Publish eBook" />
		<br>
		<div style="margin-top:0px;text-align:left;" class="publishing_progress_message" id="publishing_progress_message_<?php echo $mb_book_id; ?>" ></div>
		<?php 
	} else {
		?>
		This book is hidden. To show it, check the "Show on Shelves" box in the Book Settings pane.
		<?php
	}
}






/* META BOXES FOR BOOK POST */

/* Meta box setup function. */
function mb_book_post_meta_boxes_setup() {

	wp_enqueue_script('media-upload');
	wp_enqueue_script('thickbox');
	
	$jsURL = plugins_url( 'js/image_upload.js', __FILE__ );
	wp_register_script('my-upload', $jsURL, array('jquery'));
	wp_enqueue_script('my-upload');
	
	$jsURL = plugins_url( 'js/mb_api.js', __FILE__ );
	wp_register_script('my-publish', $jsURL, array('jquery'));
	wp_enqueue_script('my-publish');

	wp_enqueue_style('thickbox');

	$jsCSS = plugins_url( 'js/style.css', __FILE__ );
	wp_register_style( 'mb_api_style', $jsCSS);
	wp_enqueue_style('mb_api_style');
	
	// Create the meta box
	mb_book_add_post_meta_boxes();
	
}


/* Create one or more meta boxes to be displayed on the post editor screen. */
function mb_book_add_post_meta_boxes() {

	add_meta_box(
		'book-post-publish',			// Unique ID
		esc_html__( 'Publish Book' ),		// Title
		'mb_book_post_publish_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	
	/*
	add_meta_box(
		'book-post-theme',			// Unique ID
		esc_html__( 'Book Design Theme' ),		// Title
		'mb_book_post_theme_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	*/

	add_meta_box(
		'book-post-settings',			// Unique ID
		esc_html__( 'Book Settings' ),		// Title
		'mb_book_post_settings_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);

	/*
	add_meta_box(
		'book-post-poster',			// Unique ID
		esc_html__( 'Book Poster' ),		// Title
		'mb_book_post_poster_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	*/
	
}

/* ============== BOOK POST STUFF ============ */


/* Display the post publish meta box. */
function mb_book_post_publish_meta_box( $post) { 
	global $mb_api;
	
	$mb_book_id = get_post_meta($post->ID, "mb_book_id", true);
	$mb_book_publisher_id = get_post_meta($post->ID, "mb_publisher_id", true);
	if (!$mb_book_publisher_id)
		$mb_book_publisher_id = get_option('mb_publisher_id', '1');
	$mb_use_local_book_file = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_use_local_book_file", true) );
	$mb_book_available = get_post_meta($post->ID, "mb_book_available", true);

	?>
	<!-- defined on plugin settings page -->
	<input type="hidden" id="distribution_url" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
	<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />

	<div id="mb-settings">
		<div id="mb-misc-settings">
			<div id="mb-minor-settings">
				<div class="mb-settings-section no-border">
				
			<?php
		if ($mb_book_available) {
			?>
					<label for="book-post-publish">
						Be sure to update this page if you have made changes.<br/>
					</label>
				
					<div class="submitbox" >
						<span style="margin-right:20px;" class="publishing_progress_message" id="publishing_progress_message" ></span>
						<div style="text-align:right;float:right;">
							<input type="button" id="publish_book_button" class="button-primary" value="Publish eBook" />
						</div>
					</div>
					<div class="clear"></div>
					
					<?php
			} else {
					?>
					This book is hidden. To show it, check the "Show on Shelves" box in the Book Settings pane. 
					<?php
			}
					?>
					
				</div>
				
			</div>
		</div>
	</div>
	<?php 
}

/* Display the post theme meta box. */
/*
function mb_book_post_theme_meta_box( $post) { 
	global $mb_api;
	
	$mb_book_theme_id = get_post_meta($post->ID, "mb_book_theme_id", true);
	
	wp_nonce_field( basename( __FILE__ ), 'book_post_nonce' ); 

	?>
	<p>
		<label for="mb_book_theme_id">
			Choose a design theme for your book:<br/>
			<br/>
			<?php echo $mb_api->book_theme_popup_menu($post->ID) ?>
		</label>


	</p>
	<?php 
}
*/

/* Display the post settings meta box. */
function mb_book_post_settings_meta_box( $post) { 
	global $mb_api;
	
	wp_nonce_field( basename( __FILE__ ), 'book_post_nonce' ); 
	$mb_no_header_on_poster = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_no_header_on_poster", true) );
	
	$did = get_post_meta($post->ID, "mb_target_device", true);
	$values = array (
			"iphone" => "Apple iPhone/iPod",
			"iphone5" => "Apple iPhone5",
			"ipad" => "Apple iPad",
			"ipadretina" => "Apple iPad 2+",
			"iphone" => "Apple iPhone",
			"kindlefire" => "Kindle Fire 7&quot;"
		);
	$listname = "mb_target_device";
	$checked = $did;
	empty($checked) && $checked = "ipad";	// default to ipad
	$sort = false;
	$size = true;
	$extrahtml = "";
	$extraline = "";
	
	$devicemenu = $mb_api->funx->OptionListFromArray ($values, $listname, $checked, $sort, $size, $extrahtml, $extraline);

	$mb_poster_attachment_url = get_post_meta($post->ID, "mb_poster_attachment_url", true);
	$mb_poster_attachment_id = get_post_meta($post->ID, "mb_poster_attachment_id", true);

	//$mb_book_theme_id = get_post_meta($post->ID, "mb_book_theme_id", true);

	$mb_book_id = get_post_meta($post->ID, "mb_book_id", true);
	if (!$mb_book_id) {
		$current_user = wp_get_current_user();
		$mb_book_id = $current_user->last_name . "_" . $current_user->first_name . "_" . date("ymd_His");
		$mb_book_id = preg_replace('/\W/',"_",$mb_book_id);
		$mb_book_id = preg_replace('/__*/',"_",$mb_book_id);
	}

	$mb_book_publisher_id = get_post_meta($post->ID, "mb_publisher_id", true);
	if (!$mb_book_publisher_id)
		$mb_book_publisher_id = get_option('mb_publisher_id', '1');

	$values = $mb_api->get_publisher_ids();
	$listname = "";

	//$pid = get_post_meta($post->ID, "mb_publisher_id", true);
	isset ($mb_book_publisher_id) ? $checked = $mb_book_publisher_id : $checked = 1;
	$listname = "mb_publisher_id";
	$sort = true;
	$size = true;
	$extrahtml = "";
	$extraline = array();
	$pub_id_menu = $mb_api->funx->OptionListFromArray ($values, $listname, $checked, $sort, $size, $extrahtml, $extraline);
	
	$mb_book_remote_url = get_post_meta( $post->ID, 'mb_book_remote_url', true );
	
	$mb_use_local_book_file = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_use_local_book_file", true) );

	$mb_book_available = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_book_available", true) );

	?>
	<div id="mb-settings">
		<div id="mb-misc-settings">
			<div id="mb-minor-settings">
				<div class="mb-settings-section no-border">
					<div class="mb-update-button">
						<?php
						$other_attributes = "";
						$wrap = false;
						$text = "Update";
						$other_attributes = "class='mb-update-button'";
						echo get_submit_button( $text, "secondary", "submit", $wrap, $other_attributes );
					?>
					</div>
				</div>
				<div class="clear"></div>
			</div>
			<div id="mb-minor-settings">
				<div class="mb-settings-section">
					<label for="mb_book_available">
						<input class="mb_verify_hide_book" default_value="1" type="checkbox" name="mb_book_available" value="true" <?php echo($mb_book_available); ?>/> 
						Show on Shelves<br>
						<i>Uncheck this box to remove your book from the shelves. You can still work on it. <b>It is a bad idea to hide books that people have already sold or downloaded — the book will disappear from the reader's library!</b></i>
					</label>
				</div>
	
				<div class="mb-settings-section">
					<label for="mb_book_theme_id">
						Choose a design theme for your book:<br/>
						<br/>
						<?php echo $mb_api->book_theme_popup_menu($post->ID) ?>
					</label>
				</div>
	
				<div class="mb-settings-section">
					<label for="mb_target_device">
						Target Device : 
						<?php echo($devicemenu); ?>
					</label>
				</div>
				
				<div class="mb-settings-section">
					<label for="no_header_on_poster">
						<input type="checkbox" name="no_header_on_poster" value="true" <?php echo($mb_no_header_on_poster); ?>/> 
						Do not show title & author on store poster.
					</label>
				</div>
				
				<div class="mb-settings-section mb-settings-section-last">
					<label for="mb_poster_attachment_url">
						<div id="wp-content-media-buttons" class="wp-media-buttons" style="float:right;margin-bottom:8px;">
							<a href="#" class="button insert-media add_media poster_upload"><span class="wp-media-buttons-icon"></span> Add Poster</a>
						</div>
						<div class="clear"></div>
						<img class="poster_image" src="<?php echo $mb_poster_attachment_url;  ?>" />
						<input class="poster_url" type="hidden" name="mb_poster_attachment_url" value="<?php echo $mb_poster_attachment_url;  ?>">
						<input class="poster_id" type="hidden" name="mb_poster_attachment_id" value="<?php echo $mb_poster_attachment_id;  ?>">
					</label>
				</div>
			</div>
		</div>
		
				<div class="mb-settings-section-break">
					<h3>Book Publishing</h3>
				</div>
				
				
		<div id="mb-major-settings">
				<div class="mb-settings-section no-border">
					<label for="book-post-publish">
						<?php _e( "Be sure to update this page if you have made changes." ); ?>

						<br/>
			
						<!-- defined on plugin settings page -->
						<input type="hidden" id="distribution_url" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
						<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />
					</label>
				</div>
				
				<div class="mb-settings-section">		
					<label for="mb_book_id">
						Book ID:
					</label>
					<input type="text" id="mb_book_id" name="mb_book_id" value="<?php print $mb_book_id;  ?>" />

				</div>
				
				<div class="mb-settings-section">		
					<label for="mb_publisher_id">
						Publisher:
					</label>
					<?php print $pub_id_menu;  ?>

				</div>
				
				<div class="mb-settings-section">		
					<label for="no_header_on_poster">
						<input type="checkbox" name="mb_use_local_book_file" value="true" <?php echo($mb_use_local_book_file); ?>/> 
						Use an uploaded book package.<br>
						<i>To make a package, use the shell command, <code>tar cfo item.tar *</code> from within the directory of book files. Use the resulting item.tar file.</i>

					</label>
				</div>
				
				<div class="mb-settings-section mb-settings-section-last">		
					<label for="mb_book_remote_url">
						Remote URL for downloading:<br>
						<i>The remote URL is useful if you want to download a package from a remote server, such as a cloud file delivery server. The URL is not only the server folder, but it must include the file name, too. The URL must start with http://</i><br>
					</label>
					<input type="text" style="width:95%;" id="mb_book_remote_url" name="mb_book_remote_url" value="<?php print $mb_book_remote_url;  ?>" />
				</div>
				
		</div>

	</div>
		
	<?php 
}

/* Display the book post poster meta box. */
/*
function mb_book_post_poster_meta_box( $post) { 
	
	$mb_poster_attachment_url = get_post_meta($post->ID, "mb_poster_attachment_url", true);
	$mb_poster_attachment_id = get_post_meta($post->ID, "mb_poster_attachment_id", true);
	
	wp_nonce_field( basename( __FILE__ ), 'book_post_nonce' ); 
	
	?>
	<p>
		<label for="mb_poster_attachment_url">
			<?php _e( "Choose a JPG poster for your book." ); ?>
			<div id="wp-content-media-buttons" class="wp-media-buttons">
				<a href="#" class="button insert-media add_media poster_upload"><span class="wp-media-buttons-icon"></span> Add Poster</a>
			</div>
			<br/>
			<img class="poster_image" src="<?php echo $mb_poster_attachment_url;  ?>" />
			<input class="poster_url" type="hidden" name="mb_poster_attachment_url" value="<?php echo $mb_poster_attachment_url;  ?>">
			<input class="poster_id" type="hidden" name="mb_poster_attachment_id" value="<?php echo $mb_poster_attachment_id;  ?>">
		</label>

	</p>
	<?php 
}
*/

/*
 * Save a book post entry.
 * This is not applied if the save is a Bulk Edit.
 * If save is an inline-edit (Quick Edit), then only some of this is applied.
 */
function mb_book_post_meta_save_postdata( $post_id) {
	global $mb_api;
	
	// verify if this is an auto save routine. 
	// If it is our form has not been submitted, so we dont want to do anything
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;

	// Check post type and permissions
	// NOTE: A bulk edit won't have a post_type!
	if ( isset($_POST['post_type']) && 'book' == $_POST['post_type'] ) {
		if ( !current_user_can( 'edit_page', $post_id ) )
			return;
	} else {
		return;
	}
	
	// Different kinds of saving: inline, bulk, normal
	
	if ($_POST['action'] != "inline-save") {
		// -----------------------
		// NORMAL SAVE

		// Verify this came from the our screen and with proper authorization,
		// because save_post can be triggered at other times
		if (!isset($_POST['book_post_nonce']) || !wp_verify_nonce( $_POST['book_post_nonce'], basename( __FILE__ ) ) )
			return;

		// OK, we're authenticated: we need to find and save the data
		$book_id = null;
		if (isset($_POST['mb_book_id']))
			$book_id = strtolower(preg_replace("/[_\s]+/", "-", $_POST['mb_book_id']));


		// If there is no category for this book, make one.
		// The slug is the book id
		// The name is the title of the book
		if ($book_id) {

			$old_id = wp_is_post_revision( $post_id );
			if ($old_id) {
				$old_book_id = get_post_meta( $old_id, "mb_book_id", true);
				$cat = get_category_by_slug( $old_book_id );
			} else {
				$cat = get_category_by_slug( $book_id );
			}


			$cat_desc = "Pages in the book \"".$_POST['post_title']."\"";
			if (!$cat) {
				$mycat = array(
					'cat_name' => $_POST['post_title'],
					'category_description' => $cat_desc,
					'category_nicename' => $book_id,
					'category_parent' => '',
					'taxonomy' => 'category' 
					);
				$cat_id = wp_insert_category( $mycat );
			} else {
				wp_update_term($cat->cat_ID, 'category', array(
					'name' => $_POST['post_title'],
					'slug' => $book_id,
					'description' => $cat_desc
					));
			}		
		} else {
			// IF NO BOOK ID, TRY TO GET IT FROM THE FIRST CHOSEN CATEGORY!
			// Might be in use, then we get a duplicate, but I can't figure out how to check
			// here without screwing up the query stuff of WP.
			$pcats = $_POST['post_category'];
			// [0] is always 0
			if (count($pcats) > 1) {
				$cat = get_category($pcats[1]);
				$book_id = $cat->slug;
			}				
		}


		// Now, assign this book to this category
		wp_set_object_terms( $post_id, $book_id, "category" );

		// Now update the meta fields
		if (isset($_POST['mb_book_theme_id']))
			update_post_meta( $post_id, 'mb_book_theme_id', $_POST['mb_book_theme_id'] );
		if ($book_id)
			update_post_meta( $post_id, 'mb_book_id', $book_id);
		if (isset($_POST['mb_publisher_id']))
			update_post_meta( $post_id, 'mb_publisher_id', $_POST['mb_publisher_id'] );

		// Update mb book settings, e.g. no head on poster setting
		$tmp = isset($_POST['no_header_on_poster']);
		update_post_meta( $post_id, 'mb_no_header_on_poster', $tmp );

		// Update mb book settings, e.g. no head on poster setting
		$prev = get_post_meta( $post_id, 'mb_book_available', true );
		$tmp = isset($_POST['mb_book_available']);
		if ($prev != $tmp) {
			update_post_meta( $post_id, 'mb_book_available', $tmp );
			$mb_api->write_shelves_file();
		}
		

		// Update mb book settings, checkbox to not build the book but to use an uploaded book package
		$tmp = isset($_POST['mb_use_local_book_file']);
		update_post_meta( $post_id, 'mb_use_local_book_file', $tmp );

		// target device
		update_post_meta( $post_id, 'mb_target_device', $_POST['mb_target_device'] );

		// Remote download URL, e.g. from a cloud file server
		update_post_meta( $post_id, 'mb_book_remote_url', $_POST['mb_book_remote_url'] );
		//if ( is_int( wp_is_post_revision( $post_id ) ) )
		//	return;

		// Be sure the poster is a jpg file.
		$filetype = wp_check_filetype($_POST['mb_poster_attachment_url']);
		if ($filetype['ext'] == "jpg") {
			// Do something with $mydata 
			update_post_meta( $post_id, 'mb_poster_attachment_url', $_POST['mb_poster_attachment_url'] );
			update_post_meta( $post_id, 'mb_poster_attachment_id', $_POST['mb_poster_attachment_id'] );
		}

	
	} else {
		// -----------------------
		// INLINE SAVE (QUICK EDIT)
		check_ajax_referer( "inlineeditnonce", "_inline_edit" );
	}

	// Applied in both quick edit and normal save:

	// COMMERCE
	// If there is not a linked item for sale, create one.
	if ( ! wp_is_post_revision( $post_id ) ){
		// unhook this function so it doesn't loop infinitely
		remove_action('save_post', 'mb_book_post_meta_save_postdata');

		$mb_api->commerce->update_linked_item_for($post_id);

		// re-hook this function
		add_action('save_post', 'mb_book_post_meta_save_postdata');
	}

	
}



// -------------------
// Cleanup when deleting a publisher
// - delete any book files on the shelves
// - rebuild the shelves
function mb_publisher_page_delete($post_id) {
	global $mb_api, $post_type;  
	if ( $post_type != 'page' ) return;

	$post = get_post($post_id);
	if ($post->post_type == 'page' && get_post_meta($post_id, "mb_publisher_id", false) ) {
		$mb_api->write_publishers_file();
	}
}


/*
 * ----------------------------------------------------------------------
 * Book Post functions
 * ----------------------------------------------------------------------
 */
	



/*
	// Add boxes to Posts
	add_meta_box(
		'post-mb-page-theme',			// Unique ID
		esc_html__( 'Page Design' ),	// Title
		'mb_post_mb_page_theme_meta_box',	// Callback function
		'post',							// Admin page (or post type)
		'side',							// Context
		'high'							// Priority
	);					
	
*/
	
	
/*
 * ----------------------------------------------------------------------
 * Create a new category from a book's id
 * ----------------------------------------------------------------------
 */
function mb_book_create_category( $post_id ) {


}




// ------------------------------------------------------
/* META BOXES FOR POSTS */

/*
 * ----------------------------------------------------------------------
 * Add custom metaboxes to 'post' types.
 * This lets us add custom page stuff
 * ----------------------------------------------------------------------
 */

function mb_add_custom_metaboxes_to_posts() {
	 mb_post_meta_boxes_setup();
}


/* Meta box setup function. */
function mb_post_meta_boxes_setup() {

	//wp_enqueue_script('thickbox');
	//wp_enqueue_style('thickbox');

	$jsURL = plugins_url( 'js/posts.js', __FILE__ );
	wp_register_script('mb-posts', $jsURL, array('jquery', 'jquery-ui-core', 'jquery-ui-selectable', 'jquery-ui-dialog'));
	wp_enqueue_script('mb-posts');
	
	$jsCSS = plugins_url( 'js/style.css', __FILE__ );
	wp_register_style( 'mb_api_style', $jsCSS);
	wp_enqueue_style('mb_api_style');

	// Create the meta box
	add_action( 'add_meta_boxes', 'mb_post_add_page_meta_boxes' );
	add_action( 'save_post', 'mb_post_meta_save_postdata');
	
}


/* Create one or more meta boxes to be displayed on the post editor screen. */
function mb_post_add_page_meta_boxes() {

	add_meta_box(
		'book-post-page-format',					// Unique ID
		esc_html__( 'Mimetic Book Settings' ),			// Title
		'mb_post_mb_page_theme_meta_box',				// Callback function
		'post',										// Admin page (or post type)
		'side',										// Context
		'high'										// Priority
	);
	
}

function mb_post_meta_save_postdata( $post_id) {
	global $mb_api;
	
	// verify if this is an auto save routine. 
	// If it is our form has not been submitted, so we dont want to do anything
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;

	// verify this came from the our screen and with proper authorization,
	// because save_post can be triggered at other times
	if (!isset($_POST['mb_post_nonce']))
		return;
	
	if ( !wp_verify_nonce( $_POST['mb_post_nonce'], basename( __FILE__ ) ) )
		return;

	// Check permissions
	if ( 'page' == $_POST['post_type'] ) 
	{
		if ( !current_user_can( 'edit_page', $post_id ) )
			return;
	}
	else
	{
		if ( !current_user_can( 'edit_post', $post_id ) )
			return;
	}

	// OK, we're authenticated: we need to find and save the data
	
	if (isset($_POST['mb_theme_id']) && isset($_POST['mb_book_theme_page_id'])) {
	
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		$theme_id = $_POST['mb_theme_id'];
		$format_ids = $mb_api->themes->themes[$theme_id]->details->format_ids;
		
		// Drop-down menu technique:
		// Given the index, e.g. 1, get the format ID, e.g. 'B'
		//$themePageID = $format_ids[$_POST['mb_book_theme_page_id']];
		
		// jQuery selector technique:
		$themePageID = $_POST['mb_book_theme_page_id'];
		
		update_post_meta( $post_id, 'mb_book_theme_page_id', $themePageID );
	}
}


/* Display the post publish meta box. */
function mb_post_mb_page_theme_meta_box( $post) { 
	global $mb_api;
	
	wp_nonce_field( basename( __FILE__ ), 'mb_post_nonce' ); 

	// which book post does this post belong to?
	// Get the ID of the book post (not the published book's ID!!!)
	$book_id = $mb_api->get_post_book_id($post->ID);
	
	if ($book_id) {

		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
	
		$theme_id = get_post_meta($book_id, "mb_book_theme_id", true);
		
		// If the theme_id is not valid, reset to default theme.
		if (!isset($mb_api->themes->themes[$theme_id])) {
			$theme_id = 1;
		}
	
		// Get list of theme page id's for this theme
		$themePageIDList = $mb_api->themes->themes[$theme_id]->details->format_ids;
		$themePageID = get_post_meta($post->ID, "mb_book_theme_page_id", true);
	
		// If there is no assigned theme page id, we use the first in the list
		$themePageID || $themePageID = $themePageIDList[0];
	
		// If the themes have changed behind our back, the $theme_id could be invalid,
		// Choose '1', the default theme that must always be there.
		if (!$mb_api->themes->themes[$theme_id]) {
			$theme_id = 1;
			// Update the book to use theme 1!!!
			update_post_meta($book_id, 'mb_book_id', 1);
		}
	
		$f = $mb_api->themes->themes[$theme_id]->folder;
		$previewsFolder = $mb_api->url .DIRECTORY_SEPARATOR. $mb_api->themes_dir_name .DIRECTORY_SEPARATOR. $f .DIRECTORY_SEPARATOR."template_previews";
		// Get index of chosen page ID in the list of ID's
		$i = array_search($themePageID, $themePageIDList) + 1;
		// Use that index to choose the preview
		$previewFileName = $previewsFolder .DIRECTORY_SEPARATOR.  "format_" . $i . ".jpg";
		$pageFormatPopupMenu = mb_page_format_popup_menu($post->ID, $book_id);
		
		// portrait theme?
		$isPortraitTheme = "";
		if (isset($mb_api->themes->themes[$theme_id]->orientation) && $mb_api->themes->themes[$theme_id]->orientation == "portrait") {
			$isPortraitTheme = "portrait";
		}
			
	
		// Value list for each selection. That is, given select = 0, get $value[0], etc.
		// These are the template names, actually, e.g. "A" or "2-Column-page", that kind of thing.
		// Also, get the preview file names for each item, for the chooser grid, below.
		$graphics = array();
		$values = $themePageIDList;
		sort ($values);
		while (list($k, $name) = each ($values)) {
			$valueListArr[$k] = $name;
			$graphics[$k] = "$previewsFolder/format_" . (1+$k) . ".jpg";
		}
		$valueList = join(",",$valueListArr);
	
		// Link to edit the book linked to this post
		$bookpost = get_post($book_id);
		$link = "<i>{$bookpost->post_title}</i>";
		$before = "Go to book: ";
		$after = "";
		$editlink = edit_post_link( $link, $before, $after, $book_id )
		
		
		// ----- drop-down menu technique is commented out, below. -------
		?>
		<p>
			<input type="hidden" name="mb_book_theme_page_id" id="mb_book_theme_page_id" value="<?php echo($themePageID) ?>">
			<input type="hidden" id="mb_book_theme_page_id_values" value="<?php echo($valueList) ?>">
			<input type="hidden" id="mb_book_theme_page_previews" value="<?php echo($previewsFolder) ?>">
			<input type="hidden" name="mb_theme_id" value="<?php echo($theme_id) ?>">
			
			<?php echo $editlink; ?><hr/>
			
			

			<input type="button" style="float:right;" class="wp-core-ui button-secondary" id="show-styles" name="show-styles" value="Show Styles" />
			<br style="clear:all;"/>
			<br/>

			<!--
			<?php 
			echo ($pageFormatPopupMenu );
			?>
			-->
			<div class="theme_page_preview_box" name="show-styles">
				<label for="format_page_preview">
				</label>
				<div class="theme_page_preview <?php echo ($isPortraitTheme); ?>">
					<img id="format_page_preview" src="<?php echo ($previewFileName); ?>"/>
				</div>
			</div>
		</p>
		
			<label for="mb_book_theme_page_id">
				<div  style="text-align:center;">
				Current Page Style : &quot;<span id="mb_book_theme_page_id_display"><?php echo($themePageID) ?></span>&quot;
				</div>
			</label>
		
		
		<?php
		
		// ============= New Method for choosing page templates: POPUP GRID OF LAYOUTS ===============
		
		// Default theme is 1;
		$checked = $theme_id; //get_post_meta($book_id, "mb_book_theme_id", true);
		empty($checked) && $checked = 1;
		$name = "mb_book_theme_page_id";
		$id = "mb_book_themes_selector";
		$sort = true;

		$menu = $mb_api->funx->jQuerySelectableFromArray ($id, $graphics, $checked, $sort);

		?>
			<div id="mb-page-styles-dialog" style="display:none;" title="Page Styles">
				<div style="margin-left:auto;margin-right:auto;width:830px;"/>
				<?php echo $menu ?>
				</div>
			</div>
		<?php
		
	} else {
		
		_e( "To add this post to a book, choose a book's category from the Categories box." );
		
		//$current_user = wp_get_current_user();
		//$booklist = $mb_api->book_select_list($current_user);
		//print ($booklist);
		

	}
}


// For one post, used on a post page.
function mb_page_format_popup_menu($post_id, $book_id) {
	global $mb_api;


	if (!$book_id) {
		$book_id = $mb_api->get_post_book_id($post_id);
	}
	
	if ($book_id) {
		$book_post = get_post( array ('p' => $book_id) );
	} else {
		$mb_api->error(__FUNCTION__.": No book ID found.");
	}
	// We want to minimize loading this...it can be slow.
	if (!$mb_api->themes->themes) {
		$mb_api->load_themes();
	}
	
	//	get the theme ID
	$theme_id = get_post_meta($book_id, "mb_book_theme_id", true);
	
	// If the theme_id is not valid, reset to default theme.
	if (!isset($mb_api->themes->themes[$theme_id])) {
		$theme_id = 1;
	}
	
	$mytheme = $mb_api->themes->themes[$theme_id];	
	
	$values = $mytheme->details->format_ids;
	// Default theme is 1;
	// Get current checked item -- pass the index in the list of options, not the value!
	$pid = get_post_meta($post_id, "mb_book_theme_page_id", true);
	$checked = array_search($pid, $values);
	empty($checked) && $checked = 0;
	$listname = "mb_book_theme_page_id";
	$sort = true;
	$size = true;
	// Use some JS to make previews appear
	$extrahtml = "id=\"mb_book_theme_page_menu\"";
	//$extrahtml = "id=\"mb_book_theme_page_menu\" onChange=\"javascript:alert('hello');\"";
	$extraline = array();

	$menu = $mb_api->funx->OptionListFromArray ($values, $listname, $checked, $sort, $size, $extrahtml, $extraline);

	return $menu;

}


function mb_checkbox_is_checked($v) {
	if ($v && $v != "")
		return "CHECKED";
	else
		return "";
}

function mb_write_log($text) {
	global $mb_api;
	error_log (date('Y-m-d H:i:s') . ": {$text}\n", 3, $mb_api->logfile);
}

	
// DOES NOT WORK. DON'T KNOW WHY.
// ============================================================
// Modify the posts listing in the wp-admin.
// Only show the posts for the current author!
// However it blocks all query access to other items, which
// screws up publishing the shelves since that requires
// access to other users.
// ============================================================

function xmypo_parse_query_useronly( $wp_query ) {
	global $mb_api;
	$mb_api_show_only_my_posts = get_option('mb_api_show_only_my_posts', '');
	if ($mb_api_show_only_my_posts) {
		if ( strpos( $_SERVER[ 'REQUEST_URI' ], '/wp-admin/edit.php' ) !== false ) {
			if ( !current_user_can( 'level_10' ) ) {
				global $current_user;
				$wp_query->set( 'author', $current_user->ID );
			}
		}
	}
}
add_filter('parse_query', 'xmypo_parse_query_useronly');	
	


// ------------------------------------------------------

// Add initialization and activation hooks
add_action('init', 'mb_api_init');
register_activation_hook("$dir/mb-api.php", 'mb_api_activation');
register_deactivation_hook("$dir/mb-api.php", 'mb_api_deactivation');

// custom post: book
add_filter( 'post_updated_messages', 'mb_api_book_updated_messages' );
// Handle our custom post type, 'book', in case of theme change
add_action( 'after_switch_theme', 'mb_rewrite_flush' );

	
?>
