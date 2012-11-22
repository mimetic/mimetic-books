<?php
/*
Plugin Name: Mimetic Books API
Plugin URI: http://wordpress.org/extend/plugins/mb-api/
Description: A RESTful API for WordPress
Version: 1.0.7
Author: Dan Phiffer
Author URI: http://phiffer.org/
*/

$dir = mb_api_dir();

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

// Add initialization and activation hooks
add_action('init', 'mb_api_init');
register_activation_hook("$dir/mb-api.php", 'mb_api_activation');
register_deactivation_hook("$dir/mb-api.php", 'mb_api_deactivation');

?>
