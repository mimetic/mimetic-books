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
	
	make_custom_post_type_init();
	
	// Add custom metaboxes
	add_custom_metaboxes_to_pages();

	// Add custom metaboxes to posts
	add_custom_metaboxes_to_posts();
	
	// Add custom column to the book posts listing
	add_filter('manage_book_posts_columns', 'book_custom_columns_head');
	add_action('manage_posts_custom_column', 'book_custom_columns_content', 10, 2);

	
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
function add_custom_metaboxes_to_pages() {
	 book_page_meta_boxes_setup();
}


/* Meta box setup function. */
function book_page_meta_boxes_setup() {
	/*
	wp_enqueue_script('thickbox');
	
	$jsURL = plugins_url( 'js/image_upload.js', __FILE__ );
	wp_register_script('my-upload', $jsURL, array('jquery'));
	wp_enqueue_script('my-upload');
	*/
	
	// Create the meta box
	add_action( 'add_meta_boxes', 'book_add_page_meta_boxes' );
	add_action( 'save_post', 'book_page_meta_save_postdata');
	
}


/* Create one or more meta boxes to be displayed on the post editor screen. */
function book_add_page_meta_boxes() {

	add_meta_box(
		'book-page-publisher_info',					// Unique ID
		esc_html__( 'Book Publisher Info' ),		// Title
		'book_page_publisher_meta_box',				// Callback function
		'page',										// Admin page (or post type)
		'side',										// Context
		'high'										// Priority
	);
	
}

/* Display the post publish meta box. */
function book_page_publisher_meta_box( $post) { 
	global $mb_api;
	
	// default is 1, the public library
	$mb_publisher_id = get_post_meta($post->ID, "mb_publisher_id", 1);
	
	wp_nonce_field( basename( __FILE__ ), 'book_page_nonce' ); 
	
	?>
	<p>
		If you enter an value here, the publishing system will assume this is a publisher's information page.
	</p>
		<label for="mb_publisher_id">
			Publisher ID:
		</label>
		<input type="text" id="mb_publisher_id" name="mb_publisher_id" value="<?php print $mb_publisher_id;  ?>" />

	<?php 
}


function book_page_meta_save_postdata( $post_id) {
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

	update_post_meta( $post_id, 'mb_publisher_id', $_POST['mb_publisher_id'] );
}

/*
 * ----------------------------------------------------------------------
 * Add custom post type, 'book'
 * See: http://codex.wordpress.org/Function_Reference/register_post_type
 * ----------------------------------------------------------------------
 */
 

// Init to create the custom post type
function make_custom_post_type_init() {
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
		'register_meta_box_cb'	=> 'book_post_meta_boxes_setup'
	); 
	register_post_type('book', $args);
	
		/* Fire our meta box setup function on the post editor screen. */
	//add_action( 'load-post.php', 'book_post_meta_boxes_setup' );
	//add_action( 'load-post-new.php', 'book_post_meta_boxes_setup' );
	add_action('save_post', 'book_post_meta_save_postdata');

	
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



function my_rewrite_flush() {
	make_custom_post_type_init();
	flush_rewrite_rules();
}



/*
	* Delete all attachments to a post
	* $filesToKeep = string "file1.ext, file2.text, ...)
	*/
function delete_all_attachments($post_id, $filesToKeep = "" )
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


	
function delete_book_post($post_id)
{
	global $mb_api;
	
	$funx = new MB_API_Funx();
	
	$post = get_post($post_id);
	if ($post->post_type == "book") {
		delete_all_attachments($post_id);
		$book_id = str_replace("item_", "", $post->post_name);
		$dir = $mb_api->shelves_dir . DIRECTORY_SEPARATOR . $book_id;
		$funx->rrmdir($dir);
	}
}




/* MODIFY BOOK LISTING */

// ADD NEW COLUMN  
function book_custom_columns_head($defaults) {  
    $defaults['publish_book'] = 'Publish';  
    return $defaults;  
}  
  
// SHOW THE PUBLISH BUTTON
function book_custom_columns_content($column_name, $post_ID) {  
	switch ( $column_name ) {
	case 'publish_book':

		$jsURL = plugins_url( 'js/publish.js', __FILE__ );
		wp_register_script('my-publish', $jsURL, array('jquery'));
		wp_enqueue_script('my-publish');

		$jsCSS = plugins_url( 'js/style.css', __FILE__ );
		wp_register_style( 'mb_api_style', $jsCSS);
		wp_enqueue_style('mb_api_style');

		show_publish_button($post_ID) ;		  
		break;
    }  
}  

function show_publish_button($post_ID) {
	global $mb_api;
	
	$mb_book_id = get_post_meta($post_ID, "mb_book_id", true);
	//$mb_book_publisher_id = get_post_meta($post_ID, "mb_publisher_id", get_option('mb_publisher_id', '1'));

	?>
	<input type="hidden" id="distribution_url_<?php echo $mb_book_id; ?>" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
	<input type="hidden" id="base_url_<?php echo $mb_book_id; ?>" value="<?php print get_bloginfo('url');  ?>" />

	<input type="button" class="wp-core-ui button-primary publish_book_button" id="<?php echo "$mb_book_id"; ?>" value="Publish eBook" />
	<br>
	<div style="margin-top:0px;text-align:left;" class="publishing_progress_message" id="publishing_progress_message_<?php echo $mb_book_id; ?>" ></div>
	<?php 
}






/* META BOXES FOR BOOK PAGE */

/* Meta box setup function. */
function book_post_meta_boxes_setup() {

	wp_enqueue_script('media-upload');
	wp_enqueue_script('thickbox');
	
	$jsURL = plugins_url( 'js/image_upload.js', __FILE__ );
	wp_register_script('my-upload', $jsURL, array('jquery'));
	wp_enqueue_script('my-upload');
	
	$jsURL = plugins_url( 'js/publish.js', __FILE__ );
	wp_register_script('my-publish', $jsURL, array('jquery'));
	wp_enqueue_script('my-publish');

	wp_enqueue_style('thickbox');

	$jsCSS = plugins_url( 'js/style.css', __FILE__ );
	wp_register_style( 'mb_api_style', $jsCSS);
	wp_enqueue_style('mb_api_style');
	
	// Create the meta box
	book_add_post_meta_boxes();
	
}


/* Create one or more meta boxes to be displayed on the post editor screen. */
function book_add_post_meta_boxes() {

	add_meta_box(
		'book-post-publish',			// Unique ID
		esc_html__( 'Publish Book' ),		// Title
		'book_post_publish_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	
	add_meta_box(
		'book-post-theme',			// Unique ID
		esc_html__( 'Book Design Theme' ),		// Title
		'book_post_theme_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	
	add_meta_box(
		'book-post-settings',			// Unique ID
		esc_html__( 'Book Settings' ),		// Title
		'book_post_settings_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	
	add_meta_box(
		'book-post-poster',			// Unique ID
		esc_html__( 'Book Poster' ),		// Title
		'book_post_poster_meta_box',		// Callback function
		'book',					// Admin page (or post type)
		'side',					// Context
		'high'					// Priority
	);
	
	
}

/* ============== BOOK PAGE STUFF ============ */


/* Display the post publish meta box. */
function book_post_publish_meta_box( $post) { 
	global $mb_api;
	
	$mb_book_id = get_post_meta($post->ID, "mb_book_id", true);
	$mb_book_publisher_id = get_post_meta($post->ID, "mb_publisher_id", get_option('mb_publisher_id', '1'));
	?>
	<p>
		<label for="book-post-publish">
			<?php _e( "Be sure first to update this page if you have made changes." ); ?>

			<br/>
			
			<!-- defined on plugin settings page -->
			<input type="hidden" id="distribution_url" name="mb_api_book_publisher_url" size="" value="<?php print get_option('mb_api_book_publisher_url', trim($mb_api->settings['distribution_url']));  ?>" />
			<input type="hidden" id="base_url" value="<?php print get_bloginfo('url');  ?>" />
		</label>
		
		<label for="mb_book_id">
			Book ID:
		</label>
		<input type="text" id="mb_book_id" name="mb_book_id" value="<?php print $mb_book_id;  ?>" />

		<label for="mb_publisher_id">
			Publisher ID:
		</label>
		<input type="text" id="mb_publisher_id" name="mb_publisher_id" value="<?php print $mb_book_publisher_id;  ?>" />

		<div class="submitbox" >
			<span style="margin-right:20px;" class="publishing_progress_message" id="publishing_progress_message" ></span>
			<div style="text-align:right;float:right;">
				<input type="button" id="publish_book_button" class="button-primary" value="Publish eBook" />
			</div>
		</div>
		<div class="clear"></div>
	</p>
	<?php 
}

/* Display the post theme meta box. */
function book_post_theme_meta_box( $post) { 
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

/* Display the post settings meta box. */
function book_post_settings_meta_box( $post) { 
	global $mb_api;
	
	wp_nonce_field( basename( __FILE__ ), 'book_post_nonce' ); 
	
	?>
	<p>
	Something should go here. We can put some basic settings in. 
	</p>
	
		<label for="has_captions">
			Captions
		</label>
		<input type="checkbox" name="has_captions" /> 

	</p>
	<?php 
}

/* Display the book post poster meta box. */
function book_post_poster_meta_box( $post) { 
	
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

function book_post_meta_save_postdata( $post_id) {
	// verify if this is an auto save routine. 
	// If it is our form has not been submitted, so we dont want to do anything
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;

	// verify this came from the our screen and with proper authorization,
	// because save_post can be triggered at other times
	if (!isset($_POST['book_post_nonce']))
		return;
	
	if ( !wp_verify_nonce( $_POST['book_post_nonce'], basename( __FILE__ ) ) )
		return;


	// Check permissions
	if ( 'book' == $_POST['post_type'] ) 
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

	update_post_meta( $post_id, 'mb_book_theme_id', $_POST['mb_book_theme_id'] );
	update_post_meta( $post_id, 'mb_book_id', $_POST['mb_book_id'] );
	update_post_meta( $post_id, 'mb_publisher_id', $_POST['mb_publisher_id'] );
	

	//$is_rev = wp_is_post_revision( $post_id );
	
	//if ( is_int( wp_is_post_revision( $post_id ) ) )
	//	return;

	// Be sure the poster is a jpg file.
	$filetype = wp_check_filetype($_POST['mb_poster_attachment_url']);
	if ($filetype['ext'] == "jpg") {
		// Do something with $mydata 
		update_post_meta( $post_id, 'mb_poster_attachment_url', $_POST['mb_poster_attachment_url'] );
		update_post_meta( $post_id, 'mb_poster_attachment_id', $_POST['mb_poster_attachment_id'] );
	}
}


/*
	// Add boxes to Posts
	add_meta_box(
		'post-mb-page-theme',			// Unique ID
		esc_html__( 'Page Design' ),	// Title
		'post_mb_page_theme_meta_box',	// Callback function
		'post',							// Admin page (or post type)
		'side',							// Context
		'high'							// Priority
	);					
	
*/
	
	


// ------------------------------------------------------
/* META BOXES FOR POSTS */

/*
 * ----------------------------------------------------------------------
 * Add custom metaboxes to 'post' types.
 * This lets us add custom page stuff
 * ----------------------------------------------------------------------
 */

function add_custom_metaboxes_to_posts() {
	 post_meta_boxes_setup();
}


/* Meta box setup function. */
function post_meta_boxes_setup() {

	$jsURL = plugins_url( 'js/posts.js', __FILE__ );
	wp_register_script('mb-posts', $jsURL, array('jquery'));
	wp_enqueue_script('mb-posts');
	
	$jsCSS = plugins_url( 'js/style.css', __FILE__ );
	wp_register_style( 'mb_api_style', $jsCSS);
	wp_enqueue_style('mb_api_style');

	// Create the meta box
	add_action( 'add_meta_boxes', 'post_add_page_meta_boxes' );
	add_action( 'save_post', 'post_meta_save_postdata');
	
}


/* Create one or more meta boxes to be displayed on the post editor screen. */
function post_add_page_meta_boxes() {

	add_meta_box(
		'book-post-page-format',					// Unique ID
		esc_html__( 'Book Page Format' ),			// Title
		'post_mb_page_theme_meta_box',				// Callback function
		'post',										// Admin page (or post type)
		'side',										// Context
		'high'										// Priority
	);
	
}

function post_meta_save_postdata( $post_id) {
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

		$themePageID = $format_ids[$_POST['mb_book_theme_page_id']];
		update_post_meta( $post_id, 'mb_book_theme_page_id', $themePageID );
	}
}


/* Display the post publish meta box. */
function post_mb_page_theme_meta_box( $post) { 
	global $mb_api;
	
	wp_nonce_field( basename( __FILE__ ), 'mb_post_nonce' ); 

	// which book does this post belong to?
	// 
	// 
	// which theme is that book using?
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
		$fn = $previewsFolder .DIRECTORY_SEPARATOR.  "format_" . $i . ".jpg";
		$pageFormatPopupMenu = page_format_popup_menu($post->ID, $book_id);
	
		?>
		<p>
			<label for="mb_book_theme_page_id">
				<?php _e( "Page Format:" ); ?>
			</label>
			<input type="hidden" id="mb_book_theme_page_previews" value="<?php echo($previewsFolder) ?>">
			<input type="hidden" name="mb_theme_id" value="<?php echo($theme_id) ?>">
			<?php 
			echo ($pageFormatPopupMenu );
			?>
		
			<br/>
			<div class="theme_page_preview_box">
				<label for="format_page_preview">
				</label>
				<div class="theme_page_preview">
					<img id="format_page_preview" src="<?php echo ($fn); ?>"/>
				</div>
			</div>
		</p>
		<?php
	} else {
		
		_e( "Unknown Book &mdash; check your category setting?" );
		
	}
}


// For one book, used on a book post page
function page_format_popup_menu($post_id, $book_id) {
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



	
// ------------------------------------------------------

// Add initialization and activation hooks
add_action('init', 'mb_api_init');
register_activation_hook("$dir/mb-api.php", 'mb_api_activation');
register_deactivation_hook("$dir/mb-api.php", 'mb_api_deactivation');

// custom post: book
add_filter( 'post_updated_messages', 'mb_api_book_updated_messages' );
// Handle our custom post type, 'book', in case of theme change
add_action( 'after_switch_theme', 'my_rewrite_flush' );
add_action('before_delete_post', 'delete_book_post')

	
?>
