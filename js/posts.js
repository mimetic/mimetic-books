
function active_theme_chooser() {

	// Popup menu technique:
	// Show previews of design template pages
	jQuery('#mb_book_theme_page_menu').change(function() {
		n = jQuery('#mb_book_theme_page_menu').val();
		n = (n*1) + 1;
		fn = jQuery('#mb_book_theme_page_previews').val() + "/format_" + n + ".jpg";
		
		jQuery('#format_page_preview').attr("src", fn);

		return false;
	});

	// Field to hold the theme design page ID
	pageIDFieldID = "mb_book_theme_page_menu";
	
	// jQuery UI Selectable grid technique:
	// Upon "stop" in selectable, update the result field
	jQuery( "#mb_book_themes_selector" ).selectable({
		stop: function(event, ui) {
			var result = jQuery( "#mb_book_theme_page_id" ).empty();
			var index = jQuery( ".ui-selected", this ).index( );
			
			var vals = jQuery("#mb_book_theme_page_id_values").val();
			vals = vals.split(",");
			//result.val( index );
			result.val( vals[index]);
			jQuery("#mb_book_theme_page_id_display").html( vals[index] );

			var n = index + 1;
			fn = jQuery('#mb_book_theme_page_previews').val() + "/format_" + n + ".jpg";
		
			jQuery('#format_page_preview').attr("src", fn);


		}
	});
	
	// Build the dialog window to contain the selectable choices
	var $info = jQuery("#mb-page-styles-dialog");
    $info.dialog({                   
        'dialogClass'   : 'wp-dialog',           
        'modal'         : true,
        'autoOpen'      : false, 
        'closeOnEscape' : true,      
		'width' 		: 830,
		'height'		: 550,
        'buttons'       : {
            "Close": function() {
                jQuery(this).dialog('close');
            }
        }
    });
    jQuery("*[name='show-styles']").click(function(event) {
        event.preventDefault();
        $info.dialog('open');
    });
    
}


jQuery(document).ready(function($){
	
	active_theme_chooser();
	
	



	// AJAX to show templates in a post

	// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
	jQuery('#mb_book_id').change(function(event) {
			var book_id = jQuery("#mb_book_id").val();
			var post_id = jQuery("#post_ID").val();
			var data = {
				action: 'page_design_chooser',
				book_id: book_id,
				post_id : post_id,
				chooser_element_id : 'mb-page-design-chooser'
			};
			
			if (!book_id) {
				jQuery("#mb-page-design-chooser").html("");
			} else {
				jQuery.post(ajaxurl, data, function(response) {
					//jQuery("#mb-page-styles-dialog-menu").html(response);
					jQuery("#mb-page-design-chooser").html(response);
					active_theme_chooser();
					//alert('Got this from the server: ' + response);
				});
			}
		});



});