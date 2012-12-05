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
@include_once "$dir/singletons/themes.php";

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
	$base = get_option('mb_api_base', 'api');
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
	'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'custom-fields', 'revisions' )
	); 
	register_post_type('book', $args);
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
