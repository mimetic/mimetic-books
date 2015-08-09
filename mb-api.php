<?php
/*
* Plugin Name: Mimetic Books
* Plugin URI: http://mimetic.com/
* Description: This plugin allows WordPress bloggers to publish books using the Mimetic Books publishing system.
* Version: 0.2.3
* Author: David Gross
* Author URI: http://davidgross.org/
* License: GPL2
*/

/*  Copyright 2015  David Ian Gross  (email : info@mimetic.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Clear buffer, stop buffering output
// ob_start();
// ob_end_clean();

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
	
	// Add custom column to posts listings (for keywords, etc.)
// 	add_filter('manage_post_posts_columns', 'mb_book_post_custom_columns_head');
// 	add_action('manage_posts_custom_column', 'mb_book_post_custom_columns_content', 10, 2);
	
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
 * Add custom COLUMNS to the posts
 * NOT IN USE
 * ----------------------------------------------------------------------
 */





// ADD NEW COLUMN  
// function mb_book_post_custom_columns_head($defaults) {  
//     $defaults['keywords'] = 'Keywords';  
//     return $defaults;  
// }  
  
// SHOW THE PUBLISH BUTTON
// function mb_book_post_custom_columns_content($column_name, $post_ID) {  
// 	switch ( $column_name ) {
// 	case 'keywords':
// 
// 		$jsURL = plugins_url( 'js/mb_api.js', __FILE__ );
// 		wp_register_script('my-keywords', $jsURL, array('jquery'));
// 		wp_enqueue_script('my-keywords');
// 
// 		print ("KEYWORDS HERE");	  
// 		break;
//     }  
// }  





/*
 * ----------------------------------------------------------------------
 * Add custom metaboxes to 'mimeticbook' types.
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
	if (!isset($_REQUEST['book_page_nonce']))
		return;
	
	if ( !wp_verify_nonce( $_REQUEST['book_page_nonce'], basename( __FILE__ ) ) )
		return;

	// Check permissions
	if ( 'page' == $_REQUEST['post_type'] ) 
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
	
	// Update the publishers list, and to remove any publishers!
	$pid = $_REQUEST['mb_publisher_id'];
	$pid = trim($pid);
	update_post_meta( $post_id, 'mb_publisher_id', $pid );
	$mb_api->write_publishers_file();		
}



/*
 * ----------------------------------------------------------------------
 * Add custom post type, 'mimeticbook'
 * See: http://codex.wordpress.org/Function_Reference/register_post_type
 * ----------------------------------------------------------------------
 */


// Add a custom icon to the admin menu
function add_menu_icons_styles() { 
?>
	<style>
	#adminmenu .menu-icon-book div.wp-menu-image:before {
	  color: #F33;
	}
	</style>
<?php
}


// Init to create the BOOK custom post type
function mb_make_custom_post_type_init() {
	global $dir;
	
	// Create a custom post type, "book"	
	$labels = array(
    'name' => _x('Mimetic Books', 'post type general name', 'your_text_domain'),
    'singular_name' => _x('Mimetic Book', 'post type singular name', 'your_text_domain'),
    'add_new' => _x('Add New', 'mimeticbook', 'your_text_domain'),
    'add_new_item' => __('Add New Mimetic Book', 'your_text_domain'),
    'edit_item' => __('Edit Mimetic Book', 'your_text_domain'),
    'new_item' => __('New Mimetic Book', 'your_text_domain'),
    'all_items' => __('All Mimetic Books', 'your_text_domain'),
    'view_item' => __('View Mimetic Book', 'your_text_domain'),
    'search_items' => __('Search Mimetic Books', 'your_text_domain'),
    'not_found' =>  __('No Mimetic Books found', 'your_text_domain'),
    'not_found_in_trash' => __('No Mimetic Books found in Trash', 'your_text_domain'), 
    'parent_item_colon' => '',
    'menu_name' => __('Mimetic Books', 'your_text_domain')

	);
	
	$icon_url = plugins_url('images/mb-book-icon.png', __FILE__);
	
	$args = array(
		'labels' => $labels,
		'public' => true,
		'publicly_queryable' => true,
		'show_ui' => true, 
		'show_in_menu' => true, 
		'query_var' => true,
		'rewrite' => array( 'slug' => _x( 'mimeticbook', 'URL slug', 'your_text_domain' ) ),
		'capability_type' => 'post',
		'has_archive' => true, 
		'hierarchical' => false,
		'menu_position' => 5, // places menu item directly below Posts
		'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'custom-fields', 'revisions' ),
		'taxonomies'	=> array('category'),
		'register_meta_box_cb'	=> 'mb_book_post_meta_boxes_setup',
		'menu_icon' => 'dashicons-book-alt',
	); 
	register_post_type('mimeticbook', $args);
	
	/* Fire our meta box setup function mb_on the post editor screen. */
	//add_action( 'load-post.php', 'mb_book_post_meta_boxes_setup' );
	//add_action( 'load-post-new.php', 'mb_book_post_meta_boxes_setup' );
	add_action('save_post', 'mb_book_post_meta_save_postdata');
	
	// Now using the dash-icon icon. Simpler, faster.
	// Custom Icon for book type
	//add_action( 'admin_head', 'mb_wpt_book_icons' );
	
	// Style the icon
	//add_action( 'admin_head', 'add_menu_icons_styles' );

	
}

/*
// Styling for the custom post type icon
function mb_wpt_book_icons() {
	global $dir;
	$icon_url = plugins_url('images/mb-book-icon.png', __FILE__);
	$icon_32_url = plugins_url('images/book-32x32.png', __FILE__);
	$bg1 = "url($icon_url)";
	$bg2 = "url($icon_32_url)";
    ?>
    <style type="text/css" media="screen">
	#menu-posts-book .wp-menu-image {
            background: <?php echo ($bg1); ?> no-repeat 6px 6px !important;
        }
	#menu-posts-book:hover .wp-menu-image, #menu-posts-book.wp-has-current-submenu .wp-menu-image {
            background-position: 6px -18px !important;
        }
	#icon-edit.icon32-posts-book {
		background: <?php echo ($bg2); ?> no-repeat;
		}
    </style>
<?php 
}
*/
 
// Add filter to ensure the text Book, or book, is displayed when user updates a book 
function mb_api_book_updated_messages( $messages ) {
	global $post, $post_ID;

	$messages['mimeticbook'] = array(
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
// __() is a function that indicates the text is translatable.
// See http://codex.wordpress.org/Function_Reference/_2
// $text = __('This text can be translated.', 'mytextdomain' );

function mb_api_add_help_text( $contextual_help, $screen_id, $screen ) { 
	//$contextual_help .= __FUNCTION__. ":" . var_dump( $screen ); // use this to help determine $screen->id
	if ( 'mimeticbook' == $screen->id ) {
			$contextual_help = <<<EOT
<p>
	Things to remember when adding or editing a book: 
</p>
<ul>
	<li> The Book ID must be unique. </li>
	<li> Choose the publisher you're working with.</li>
</ul>
<p>
	Book status and visibility are set in the Publish box and are similar to Wordpress usage: 
</p>
<ul>
	<li> Actually, status does nothing right now. </li>
	<li> Private books can be seen by all Editors in your Wordpress website.<br>
	http://codex.wordpress.org/Roles_and_Capabilities</li>
</ul>
<p>
	<strong> For more information: </strong> 
</p>
<p>
	<a href="http://codex.wordpress.org/Posts_Edit_SubPanel" target="_blank"> Edit Posts Documentation </a> 
</p>
<p>
	<a href="http://wordpress.org/support/" target="_blank"> Support Forums </a> 
</p>			
			
EOT;
			
			
	} elseif ( 'edit-book' == $screen->id ) {
		$contextual_help = <<<EOT
<p>
Tips for using Mimetic Books.

<ul>
	<li>Add pictures to a page using "Add Media." Add pictures at their full size, but no larger. 
	For example, if you want a picture to be seen at 1024x768 pixels (full screen), upload it that big.
	It doesn't matter how large it appears in WordPress, however. You can choose to show it at medium size in your post.
	</li>
</ul>

EOT;
		
		//$contextual_help = 
			//'<p>' . __('Tips for using Mimetic Books.', 'your_text_domain') . '</p>' ;
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
	if ($post->post_type == 'mimeticbook') {
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

	// Publish button text changes depending on whether we're publishing the real book or
	// a testing version
	$mb_updating_book = mb_checkbox_is_checked( get_post_meta($post_ID, "mb_updating_book", true) );
	
	$mb_pub_button_label = "Publish eBook";
	if ($mb_updating_book) {
		$mb_pub_button_label = "Publish Private Draft";
	}


	if ($mb_book_available && $mb_book_id) {
		?>
		<input type="hidden" id="distribution_url_<?php echo $mb_book_id; ?>" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
		<input type="hidden" id="base_url_<?php echo $mb_book_id; ?>" value="<?php print get_bloginfo('url');  ?>" />

		<input type="button" class="wp-core-ui button-primary publish_book_button" id="<?php echo "$mb_book_id"; ?>" value="<?php echo $mb_pub_button_label; ?>" />
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
		esc_html__( 'Book Publishing' ),		// Title
		'mb_book_post_publish_meta_box',		// Callback function
		'mimeticbook',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	
	/*
	add_meta_box(
		'book-post-theme',			// Unique ID
		esc_html__( 'Book Design Theme' ),		// Title
		'mb_book_post_theme_meta_box',		// Callback function
		'mimeticbook',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	*/

	add_meta_box(
		'book-post-settings',			// Unique ID
		esc_html__( 'Book Settings' ),		// Title
		'mb_book_post_settings_meta_box',		// Callback function
		'mimeticbook',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);

	/*
	add_meta_box(
		'book-post-poster',			// Unique ID
		esc_html__( 'Book Poster' ),		// Title
		'mb_book_post_poster_meta_box',		// Callback function
		'mimeticbook',					// Admin page (or post type)
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
	
	// Posts link
	$cats = get_the_category( $post->ID );
	if ($cats) {
		$book_cat_id = $cats[0]->term_id;
	} else {
		$book_cat_id = "";
	}
	$postlink = "edit.php?s&post_status=all&post_type=post&action=-1&m=0&cat={$book_cat_id}&paged=1&mode=list&action2=-1";
				
	// Publish button text changes depending on whether we're publishing the real book or
	// a testing version
	$mb_updating_book = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_updating_book", true) );
	
	$mb_pub_button_label = "Publish eBook";
	if ($mb_updating_book) {
		$mb_pub_button_label = "Publish Private Draft";
	}
				
				
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

	$mb_book_is_card_list = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_book_is_card_list", true) );


	?>
			<!-- defined on plugin settings page -->
			<input type="hidden" id="distribution_url" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
			<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />

			<div id="mb-settings">
				<div class="mb-settings-section ">
					<a href="<?php echo $postlink; ?>">Show posts</a> in this book.		
				</div>
				<div id="mb-misc-settings no-border">
					<div class="mb-settings-section">
				
					<?php
					if ($mb_book_available) {
					?>				
							<div class="submitbox" >
								<span style="margin-right:20px;" class="publishing_progress_message" id="publishing_progress_message" ></span>
								<div style="text-align:right;float:right;">
									<input type="button" id="publish_book_button" class="button-primary" value="<?php echo $mb_pub_button_label; ?>" />
								</div>
							</div>
							<div class="clear"></div>
							<br>
							<?php _e( "<i>* Be sure to publish the book again if you change any settings, authors, etc.</i>"); ?>
					
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
		
		<div id="mb-major-settings">
			<div class="mb-settings-section no-border">
	
				<!-- defined on plugin settings page -->
				<input type="hidden" id="distribution_url" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />

				<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />
			</div>
				
			<div class="mb-major-settings">
				<div class="mb-settings-section">
					<label for="mb_book_available">
						<input class="mb_verify_hide_book" default_value="1" type="checkbox" name="mb_book_available" value="true" <?php echo($mb_book_available); ?>/> 
						Show on Shelves<br>
						<i>Uncheck this box to remove your book from the shelves. You can still work on it. <b>It is a bad idea to hide books that people have already sold or downloaded — the book will disappear from the reader's library!</b></i>
					</label>
				</div>
			</div>
<!--
			<div class="mb-major-settings">
				<div class="mb-settings-section">
					
						<input default_value="1" type="checkbox" name="mb_book_private" value="true" <?php echo($mb_book_available); ?>/> 
						<label for="mb_book_available">Private Book</label>
						<br>
						<i>Check this box to hide your book from the public. Only people registered with this website, whose username is listed below, can see this book.</i>
					
				</div>
			</div>
-->
<!-- The 'draft' concept is NOT working, leave it out for now -->
<!--
			<div id="mb-major-settings">
				<div class="mb-settings-section">
					<label for="mb_updating_book">
						<input default_value="1" type="checkbox" name="mb_updating_book" value="true" <?php echo($mb_updating_book); ?>/> 
						Updating this Book<br>
						<i>Check this box while you are working on an update to your book. Uncheck it when you are ready to publish the update!</b></i>
					</label>
				</div>
			</div>
-->			
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
				
				<div class="mb-settings-section-break">
					<h3>Special Settings</h3>
				</div>
				
				<div class="mb-settings-section">		
					
					<h4>Use an Uploaded Book Package</h4>
					<i>To make a package, use the shell command, <code>tar cfo item.tar *</code> from within the directory of book files. Use the resulting item.tar file.</i>					
					<br>
					<br>
					<input type="checkbox" name="mb_use_local_book_file" value="true" <?php echo($mb_use_local_book_file); ?>/> <label for="mb_use_local_book_file">Use uploaded book package</label>
				</div>
				
				<div class="mb-settings-section mb-settings-section-last">		
					
					<h4>Custom URL for App:</h4>
					<i>If you want the app to download the book package from a different server, usually a cloud file delivery server (CDN), then you must enter that URL below.<br>
					<b>You will have to copy the book package we create here to that server!</b><br>
					Use the complete URL, including the file name, e.g.<br>
					<b>http://myserver.com/mypath/item.tar</b></i>
					
					<br>
					<br>
					<label for="mb_book_remote_url">Custom URL for App:</label>
					<input type="text" style="width:95%;" id="mb_book_remote_url" name="mb_book_remote_url" value="<?php print $mb_book_remote_url;  ?>" />
				</div>
				
		</div>
			
	<?php 
}




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

	$mb_book_is_card_list = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_book_is_card_list", true) );

	// Status of book, e.g. editing, testing, published, live, whatever
	
	/*
	Use the post status, built into WP:
	'publish' - A published post or page
	'pending' - post is pending review
	'draft' - a post in draft status
	'auto-draft' - a newly created post, with no content
	'future' - a post to publish in the future
	'private' - not visible to users who are not logged in
	'inherit' - a revision. see get_children.
	'trash' - post is in trashbin. added with Version 2.9.
	*/

	/*
	$values = array("x","y");
	$listname = "mb_book_status";
	$checked = 1;
	$sort = true;
	$size = true;
	$extrahtml = "";
	$extraline = array();
	$bookstatus = $mb_api->funx->OptionListFromArray ($values, $listname, $checked, $sort, $size, $extrahtml, $extraline);
	*/
	
	$mb_updating_book = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_updating_book", true) );
	

	?>
	<div id="mb-settings">
		<div id="mb-misc-settings">
		
			<!--
			<div id="mb-minor-settings">
				<div class="mb-settings-section no-border">
					<div class="mb-update-button">
						<?php
						$other_attributes = "";
						$wrap = false;
						$text = "Update Book Settings";
						$other_attributes = "class='mb-update-button'";
						echo get_submit_button( $text, "secondary", "submit", $wrap, $other_attributes );
					?>
					</div>
				</div>
				<div class="clear"></div>
			</div>
			-->
			
			<div id="mb-minor-settings">
	
				<div class="mb-settings-section">
					<label for="mb_book_theme_id">
						Choose a design theme for your book:<br/>
						<br/>
						<?php echo $mb_api->book_theme_chooser($post->ID) ?>
					</label>
				</div>
	
				<div class="mb-settings-section">
					<input class="mb_verify_hide_book" default_value="1" type="checkbox" name="mb_book_is_card_list" value="true" <?php echo($mb_book_is_card_list); ?>/> 
					<label for="mb_book_is_card_list">
						Book can be used as a card list<br>
						<i>Check the box to use this book as a pop-up book in an app, e.g. for games or reference.</b></i>
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
						<img class="poster_image" src="<?php echo $mb_poster_attachment_url;  ?>" alt="" />
						<input class="poster_url" type="hidden" name="mb_poster_attachment_url" value="<?php echo $mb_poster_attachment_url;  ?>">
						<input class="poster_id" type="hidden" name="mb_poster_attachment_id" value="<?php echo $mb_poster_attachment_id;  ?>">
					</label>
				</div>
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
			<img class="poster_image" src="<?php echo $mb_poster_attachment_url;  ?>" alt="" />
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
	if ( isset($_POST['post_type']) && 'mimeticbook' == $_POST['post_type'] ) {
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
		
// *** if user chooses a new book id (from the menu), then 
// remove the book from the previous book category (while leaving
// other category settings along!)

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


		// Now, assign this book to this category, but KEEP any chosen categories as well.
		wp_set_object_terms( $post_id, $book_id, "category", true );

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
		
		// Update mb book publishing update setting
		$tmp = isset($_POST['mb_updating_book']);
		update_post_meta( $post_id, 'mb_updating_book', $tmp );


		// Update mb book settings, e.g. no head on poster setting
		$tmp = isset($_POST['mb_book_is_card_list']);
		update_post_meta( $post_id, 'mb_book_is_card_list', $tmp );


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

	// Hook into the 'admin_enqueue_scripts' action
	// to set up the javascript/css
	add_action( 'admin_enqueue_scripts', 'mb_post_meta_boxes_scripts' );
	
	// Create the meta box
	add_action( 'add_meta_boxes', 'mb_post_add_page_meta_boxes' );
	add_action( 'save_post', 'mb_post_meta_save_postdata');
	
	// AJAX to display the page design chooser
	add_action('wp_ajax_page_design_chooser', 'mb_post_page_design_chooser_ajax');

}

// Set up the Javascript enqueue, etc.

// Register Script
// Run only on a post page, don't need elsewhere.
function mb_post_meta_boxes_scripts($hook) {
    if ( 'post.php' != $hook ) {
        return;
    }
    $jsURL = plugins_url( 'js/posts.js', __FILE__ );
	wp_register_script( 'mb-posts', $jsURL, array( 'jquery', 'jquery-ui-selectable', 'jquery-ui-dialog' ), false, false );
	//wp_register_script( 'mb-posts', $jsURL, array( 'jquery-ui-dialog' ), false, false );
	wp_enqueue_script( 'mb-posts' );


	$jsCSS = plugins_url( 'js/style.css', __FILE__ );
	wp_register_style( 'mb_api_style', $jsCSS, false, false );
	// MUST enqueue the built-in jquery dialog css or it fails!!!
	wp_enqueue_style("wp-jquery-ui-dialog", false, false );
	wp_enqueue_style('mb_api_style');

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
	
	add_meta_box(
		'book-post-page-custom-fields',					// Unique ID
		esc_html__( 'Mimetic Book Custom Fields' ),			// Title
		'mb_post_mb_page_fields_meta_box',				// Callback function
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
	isset($_REQUEST['post_type']) ? $pt = $_REQUEST['post_type'] : $pt = "";
	// Check permissions
	if ( 'page' == $pt ) 
	{
		if ( !current_user_can( 'edit_page', $post_id ) )
			return;
	}
	else
	{
		if ( !current_user_can( 'edit_post', $post_id ) )
			return;
	}

	// Different kinds of saving: inline, bulk, normal
	if (isset($_REQUEST['action']) ) {
		if ($_REQUEST['action'] != "inline-save") {

			// Verify this came from the our screen and with proper authorization,
			// because save_post can be triggered at other times
			if (!isset($_REQUEST['mb_post_nonce']) || !wp_verify_nonce( $_REQUEST['mb_post_nonce'], basename( __FILE__ ) ) )
					return;

			if (isset($_REQUEST['mb_theme_id']) && isset($_REQUEST['mb_book_theme_page_id'])) {
				// -----------------------
				// NORMAL SAVE




				// We want to minimize loading this...it can be slow.
				if (!$mb_api->themes->themes) {
					$mb_api->load_themes();
				}
				$theme_id = $_REQUEST['mb_theme_id'];
				//$format_ids = $mb_api->themes->themes[$theme_id]->details->format_ids;

				// Drop-down menu technique:
				// Given the index, e.g. 1, get the format ID, e.g. 'B'
				//$themePageID = $format_ids[$_REQUEST['mb_book_theme_page_id']];

				// If the user chose a book from our drop-down menu, set the corresponding category.
				// If the "uncategorized" category is checked, i.e. id=1, remove that.
				// If they have changed categories, we need to remove the old category.
				// However, we only want to remove the previous category...or we might end up
				// removing valid categories!


				$old_id = wp_is_post_revision( $post_id );

				if ($old_id) {
					$old_book_id = (Int)$mb_api->get_post_book_id($old_id);
					$cat = get_the_category( $old_id );
					if ($cat) {
						$old_category_id = $cat[0]->term_id;
					}

					//$mb_api->write_log(__FUNCTION__.": Category :".print_r($cat,true) );
				} else {

					$mb_book_id = $_REQUEST['mb_book_id'];

					if ($mb_book_id) {
						$new_cat = get_the_category( $mb_book_id );
						$new_cat = (String)$new_cat[0]->cat_ID;
					} else {
						$new_cat = false;
					}

					$prev_cat = $_REQUEST['mb_current_book_category'];


					// Is the prev category a book category? If not, ignore it.
					// If you want a post in TWO books, you'd better do that by hand, and 
					// you'll have trouble assigning a page design to it if the two books 
					// have different designs!
					// If the user sets the category to NO book, remove the previous book category.
					if ($prev_cat) {
						$is_book_cat = $mb_api->get_book_post_from_category_id( $prev_cat );
					} else {
						$is_book_cat = false;
					}

					// Currently selected categories
					$all_cats = $_REQUEST['post_category'];		

					// Remove the previous category if it is a book category
					if ($is_book_cat && $new_cat != $prev_cat) {
						// We depend on the mb_current_book_category being set 
						// Uncheck the prev category, and check the new one
						if(($key = array_search($prev_cat, $all_cats)) !== false) {
							unset($all_cats[$key]);
						}
					}

					$uncat_is_set = array_search("1", $all_cats);

					// Add new book category to the categories
					if ($new_cat != $prev_cat) {
						$all_cats[] = $new_cat;
						// Remove the "Uncategorized" category if there is a category
						if(($key = array_search("1", $all_cats)) !== false) {
								unset($all_cats[$key]);
						}
						$all_cats = array_map('intval', $all_cats);
						$all_cats = array_unique( $all_cats );
						wp_set_post_terms( $post_id, $all_cats, 'category' );
					} elseif ( $new_cat ) {
						// If the book has a category, and the Uncategorized is set, 
						// the unset the Uncategorized category
						if(($key = array_search("1", $all_cats)) !== false) {
								unset($all_cats[$key]);
						}
						wp_set_post_terms( $post_id, $all_cats, 'category' );
					}


					// jQuery selector technique:
					$themePageID = $_REQUEST['mb_book_theme_page_id'];
					
					// Update values
					update_post_meta( $post_id, 'mb_book_theme_page_id', $themePageID );
					update_post_meta( $post_id, 'mb_page_nav_label', $_REQUEST['mb_page_nav_label'] );
					if (isset($_REQUEST['mb_page_is_map']))
						update_post_meta( $post_id, 'mb_page_is_map', 1 );
					else
						update_post_meta( $post_id, 'mb_page_is_map', 0 );
						
					if (isset($_REQUEST['mb_show_page_in_contents']))
						update_post_meta( $post_id, 'mb_show_page_in_contents', 1 );
					else
						update_post_meta( $post_id, 'mb_show_page_in_contents', 0 );
						
					//$cat = get_the_category( $post_id );

					//$mb_api->write_log(__FUNCTION__.": POST :".print_r($cat,true) );

					//$mb_api->write_log(__FUNCTION__.": POST :".print_r($_REQUEST['post_category'],true)."\n--------\n" );
					
					
					//------
					// Save custom MB fields
					foreach (array_keys($_REQUEST) as $fieldname) {
						$matches = array();
						if (preg_match("/^(mb_custom_.*)/", $fieldname, $matches) ) {
							$f = $matches[1];
							update_post_meta( $post_id, $fieldname, $_REQUEST[$fieldname] );
//print ($f . "<BR>".$_REQUEST[$f]."<BR>");
						}
					} // for
					
				}	// else

			} 
		} 
	elseif (!wp_is_post_revision( $post_id )) 
		{

			$mb_book_id = $mb_api->get_post_book_id($post_id);

			// Categories:
			$cats = get_the_category( $mb_book_id );
			if ($cats) {
				$book_cat_id = $cats[0]->term_id;
			} else {
				$book_cat_id = null;
			}

			if ($book_cat_id) {
				// Currently selected categories
				$all_cats = $_REQUEST['post_category'];		
				if(($key = array_search("1", $all_cats)) !== false) {
					unset($all_cats[$key]);
					wp_set_post_terms( $post_id, $all_cats, 'category' );
				}
			}
		}
	}
}


/* Call the design chooser. Making this local function makes an "add_action" easier. */
function mb_post_page_design_chooser_ajax () {
	global $mb_api;
	return $mb_api->page_design_chooser_ajax ();
}


/* Display the post publish meta box. */
function mb_post_mb_page_theme_meta_box( $post) { 
	global $mb_api;
	
	wp_nonce_field( basename( __FILE__ ), 'mb_post_nonce' ); 

	// which book post does this post belong to?
	// Get the ID of the book post (not the published book's ID!!!)
	$book_id = $mb_api->get_post_book_id($post->ID);
	
	if (true || $book_id) {

		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		
		$book_id 
			? $theme_id = get_post_meta($book_id, "mb_book_theme_id", true)
			: $theme_id = null;
		
		// If the theme_id is not valid, reset to default theme.
		if (!isset($mb_api->themes->themes[$theme_id])) {
			$theme_id = 1;
		}
		
	
		// Link to edit the book linked to this post
		$bookpost = false;
		if ($book_id){
			$bookpost = get_post($book_id);
			$link = "<i>{$bookpost->post_title}</i>";
			$before = "Edit the book settings : ";
			$after = "";
			$editlink = edit_post_link( $link, $before, $after, $book_id );
			
			// Categories:
			$cats = get_the_category( $book_id );
			if ($cats) {
				$book_cat_id = $cats[0]->term_id;
			} else {
				$book_cat_id = null;
			}

			// Posts link
			$all_posts_link = "<a href=\"edit.php?s&amp;post_status=all&amp;post_type=post&amp;action=-1&amp;m=0&amp;cat={$book_cat_id}&amp;paged=1&amp;mode=list&amp;action2=-1\">List all posts</a> in this book.";


		} else {
			$editlink = "";
			$all_posts_link = "";
			$book_cat_id  = null;
		}
		
		
		
		
		$themePageID = get_post_meta($post->ID, "mb_book_theme_page_id", true);
		
		$bookmenu = $mb_api->book_id_popup_menu( "mb_book_id", "mb_book_id", $book_id );
		
		if (!$book_id) {
			echo ("To start a new book, select <i>Add New</i> from the <i>Books</i> menu on the left, or click <a href='post-new.php?post_type=book'>here</a>.");
		}
		
		
		// ----- drop-down menu technique is commented out, below. -------
		?>
		
			<input type="hidden" name="mb_book_theme_page_id" id="mb_book_theme_page_id" value="<?php echo($themePageID) ?>">
			<input type="hidden" name="mb_theme_id" value="<?php echo($theme_id) ?>">
			<input type="hidden" name="mb_current_book_category" value="<?php echo($book_cat_id) ?>">
			
			<div id="mb-minor-settings">
				<?php if ($editlink) { ?>
				<div class="mb-settings-section no-border">
					<?php echo $editlink; ?>
				</div>
				<?php } ?>
				<?php if ($all_posts_link) {
					echo $all_posts_link;
				}
				?>
				
				<div class="mb-settings-section">
					Book : <?php echo $bookmenu; ?>					
				</div>
				
			</div>
		
		
		<?php
		
		// ============= New Method for choosing page templates: POPUP GRID OF LAYOUTS ===============
		
		if ($book_id) {
			// Default theme is 1;
			empty($theme_id) && $theme_id = 1;
			$name = "mb_book_theme_page_id";
			$id = "mb_book_themes_selector";
			$sort = true;
			$chooser = $mb_api->page_design_chooser ($book_id, $post->ID);
		} else {
			$chooser = "";
		}
		
		$chooser = "<div id=\"mb-page-design-chooser\">$chooser</div>";
		echo $chooser;
		
		// Page Tag, shown in navigation
		$mb_page_nav_label = get_post_meta($post->ID, "mb_page_nav_label", true);
		?>
		<div class="mb-settings-section">
			<label for="mb_page_nav_label">
				Page Navbar Label :
			</label>
			<input type="text" style="width:10em;" id="mb_page_nav_label" name="mb_page_nav_label" value="<?php print $mb_page_nav_label;  ?>" />
		</div>
		<?
		
		// This is the book map page, linked to an icon in navigation bar
		$mb_page_is_map = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_page_is_map", true) );

		?>
		<div class="mb-settings-section">
			<input type="checkbox" name="mb_page_is_map" value="true" <?php echo($mb_page_is_map); ?> />
			<label for="mb_page_is_map">
				&nbsp;Page is book's map page?
			</label>
		</div>

		<?
		
		// Show this page in the table of contents?
		$mb_show_page_in_contents = mb_checkbox_is_checked( get_post_meta($post->ID, "mb_show_page_in_contents", true) );

		?>
		<div class="mb-settings-section">
			<input type="checkbox" name="mb_show_page_in_contents" value="true" <?php echo($mb_show_page_in_contents); ?> />
			<label for="mb_show_page_in_contents">
				&nbsp;List page in Table of Contents?
			</label>
		</div>

		<?
		
	}
}




/* ------------------------------------------------------------------------
	------------------------------------------------------------------------
	Display the post custom fields meta box.
	
	Shows a list of custom fields for use with the Mimetic Books viewer, 
	esp. for plugins such as the ecosystem-game.
	------------------------------------------------------------------------
	------------------------------------------------------------------------ */

function mb_post_mb_page_fields_meta_box( $post) { 
	global $mb_api;
	
	wp_nonce_field( basename( __FILE__ ), 'mb_post_nonce' ); 

	// which book post does this post belong to?
	// Get the ID of the book post (not the published book's ID!!!)
	$book_id = $mb_api->get_post_book_id($post->ID);
	
	if (true || $book_id) {

		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		
		$book_id 
			? $theme_id = get_post_meta($book_id, "mb_book_theme_id", true)
			: $theme_id = null;
		
		// If the theme_id is not valid, reset to default theme.
		if (!isset($mb_api->themes->themes[$theme_id])) {
			$theme_id = 1;
		}
		
		?>
		<div id="mb-settings">
			<div id="mb-misc-settings">
		<?php
		// --------
		// Show any custom fields listed in the theme
		$custom_fields = $mb_api->themes->themes[$theme_id]->details->custom_fields;

		$custom_fields_help = $mb_api->themes->themes[$theme_id]->details->custom_fields_help;

		$custom_fields_options = $mb_api->themes->themes[$theme_id]->details->custom_fields_options;

		//sort($custom_fields);
		
		if ($custom_fields) {
			$cfi = 0;
			foreach ($custom_fields as $fieldname) {
				$f = "mb_custom_".$fieldname;
				$curval = get_post_meta($post->ID, $f, true);
				if (isset($custom_fields_help[$cfi]) ) {
					$helptxt = "<br><i><smaller>".$custom_fields_help[$cfi]."</smaller></i>";
				} else {
					$helptxt = "";
				}
				
				// OPTION LIST
				$custom_fields_options ? $values = $custom_fields_options[$cfi] : $values = null;
				if ($values) {
					$values = explode("|", trim($values));
					$varray = array();
					foreach ($values as $v) {
						$varray[$v] = $v;
					}
					// Get current checked item -- pass the index in the list of options, not the value!
					$checked = array_search($curval, $varray);
					empty($checked) && $checked = 0;
					$sort = true;
					$size = true;
					$extrahtml = "";
					$extraline = array();
					$menu = $mb_api->funx->OptionListFromArray ($varray, $f, $checked, $sort, $size, $extrahtml, $extraline);
					
					
				} else {
					$menu = "";
				}
				
				
				
				
				?>
					<div class="mb-settings-section">
						<label for="<?php echo $f; ?>">
							<?php print "<b>$fieldname</b>$helptxt";?>&nbsp;: 
						</label><br>
						<?php if ($menu) {
							echo $menu;
						
						} else {
							?>
							<input type="text" style="width:90%;" id="<?php echo $f; ?>" name="<?php echo $f; ?>" value="<?php print $curval;  ?>" />
							<?php
						}
					?></div>
				<?php
				$cfi++;

			} // for
		}	// if custom fields
		?>
			</div>
		</div>
		<?php
		
	}	// if $book-id set or TRUE ==> always true
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
// Handle our custom post type, 'mimeticbook', in case of theme change
add_action( 'after_switch_theme', 'mb_rewrite_flush' );

	
?>
