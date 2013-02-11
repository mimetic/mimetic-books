<?php

/*
	Commerce functions.
	These are mostly built on top of the Easy Digital Downloads plugin.
*/

class MB_API_Commerce
{
	public $x;
	
	
	
	/* 
	Constructor 
	*/
 	function MB_API_Commerce()
	{
	}
	
	
	// Verify that a compatible commerce plugin is installed
	// Requires Easy Digital Download commerce
	public function commerce_is_installed() {	
		global $mb_api;
		
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );		
		if (!is_plugin_active("easy-digital-downloads/easy-digital-downloads.php")) {
			$mb_api->error(__FUNCTION__.": The easy-digital-downloads plugin is not activated. You must install and activate the plugin for shelf functions to work.");
			$this->commerce_is_installed = false;			
			$this->commerce_plug_name = "";
		} else {
			$this->commerce_is_installed = true;
			$this->commerce_plug_name = "easy-digital-downloads";
		}
		
		return true;
	}

	// Requires Easy Digital Download commerce
	public function has_already_purchased($purchase_form, $args) {
		global $user_ID;
		if ( edd_has_user_purchased( $user_ID, $args['download_id'] ) ) {
			return '<p class="edd_has_purchased">' . __( '<i>(Purchased)</i>', 'edd' ) . '</p>';
		} else {
			return $purchase_form;
		}
	}

	
	// DO AS AN EDD EXTENSION! NOT HERE!!!
	// To-Do: lower priority!
	// Requires Easy Digital Download commerce
	// Show "FREE" instead of $0.00
	public function modify_free_purchase_template($purchase_form, $args) {
		$price = edd_get_download_price( $args['download_id'] );

		if ( $price == 0 ) {
			return '<p class="edd_has_purchased">' . __( '(Purchased.)', 'edd' ) . '</p>';
		} else {
			return $purchase_form;
		}
	}


	// Requires Easy Digital Download commerce
	public function add_single_purchase_verification() {	
		if ($this->commerce_is_installed && $this->commerce_plug_name == "easy-digital-downloads") {
			add_filter( 'edd_purchase_download_form', array(&$this, 'has_already_purchased'), 10, 2 );
			//add_filter( 'edd_purchase_download_form', array(&$this, 'modify_free_purchase_template'), 10, 2 );
		}
	}
	
	public function get_users_purchases($user_id) {
		
		$purchases = array();
	
		// testing:
		$user_id = 5;
		
		$users_purchases = edd_get_users_purchases( $user_id );

		if( $users_purchases ) {
			foreach( $users_purchases as $purchase ) {
				$purchase_meta = edd_get_payment_meta( $purchase->ID );
				$purchased_files = maybe_unserialize( $purchase_meta['downloads'] );
				if( is_array( $purchased_files ) ) {
					foreach( $purchased_files as $download ) {
						// Avoid listing an item twice, which could happen if there
						// was a double purchase, which we should block anyway.
						if (!in_array($download['id'], $purchases) ) {
							$purchases[] = $download['id'];
						}
					}
				}
			}
		}
		
		return $purchases;
	}
	
	// Requires Easy Digital Download commerce plugin:
	// Update or create a linked for-sale product for a given post, usually a book post.
	public function update_linked_item_for($post_id) {
		global $mb_api;
		
		$post = get_post($post_id);
		
		// The post type for a product when using Easy Digital Download commerce
		$post_type = "download";
		
		if ($post) {
			// Get the linked for sale item
			$item_id = get_post_meta($post_id, "mb_book_sale_item_id", true);
			if ($item_id) {
				// Update sale item with source post info
				$p = array();
				$p['ID'] = $item_id;
				$p['post_title'] = $post->post_title;
				$p['post_content'] = $post->post_content;
				$p['post_status'] = "publish";
				$p['comment_status'] = "closed";
				$p['ping_status'] = "closed";
				$p['post_type'] = $post_type;

 
				// update the post, which calls save_post again
				wp_update_post( $p );

			} else {
				// Update sale item with source post info
				$p = array();
				$p['post_type'] = $post_type;
				$p['post_title'] = $post->post_title;
				$p['post_content'] = $post->post_content;
				$p['post_status'] = "publish";
				$p['comment_status'] = "closed";
				$p['ping_status'] = "closed";

				$item_id = wp_insert_post( $p, false );
				update_post_meta($post_id, "mb_book_sale_item_id", $item_id);
				
			}
			$term_id = wp_set_object_terms( $item_id, "book", "download_category", true );
			$thumbnail_id = get_post_thumbnail_id($post_id);
			if ($thumbnail_id)
				$res = set_post_thumbnail( $item_id, $thumbnail_id );
			
			$x = $res;
		} else {
			// create a new item for sale
			$mb_api->write_log(__FUNCTION__.": Create item for sale for post $post_id");
		}
	
	}
		
		
}

?>