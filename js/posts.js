
function buildThemeChooserDialog() {

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
	var myDialogObject = jQuery("#mb-page-styles-dialog");
    myDialogObject.dialog({                   
       	'dialogClass'   : 'wp-dialog',           
        'modal'         : true,
        'autoOpen'      : false, 
        'closeOnEscape' : true,      
		'width' 		: 830,
		'height'		: 550,
        'buttons'       : {
            "Close": function() {
                jQuery(this).dialog('close').dialog('destroy');
            }
        }
    });
    
    return myDialogObject;
    
}

// Attach the show dialog to the button
function attachThemeChooserDialogToButton() {
	
	// test
	// jQuery("*[name='show-styles']").css( {"border":"5px solid green"});
	
	 jQuery("*[name='show-styles']").click(function(event) {
        event.preventDefault();
        myDialog = buildThemeChooserDialog();
        myDialog.dialog('open');
    });
}


jQuery(document).ready(function($){
	



	var x = jQuery("#test-dialog").dialog({                   
         'modal'         : true,
        'autoOpen'      : false, 
        'closeOnEscape' : true,      
		'width' 		: 830,
		'height'		: 550
    }).dialog("open");





	// Attach the dialog to buttons/objects	
	attachThemeChooserDialogToButton();
	
	// This is run when the "Book" popup menu value is changed, effectively
	// changing the book this post belongs to. When that happens, we need to
	// get a different set of page templates because the book (might) use a different
	// book template.

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
			var loadingPreviewURL = jQuery('#theme_page_preview_box_loading_url').val();

			// Remove popup functionality until the new info is loaded.
			//jQuery("#mb-page-design-chooser").html("<h2>Loading...</h2>");
			jQuery("#format_page_preview").attr("src", loadingPreviewURL);
			// Change the button name to Loading...
			jQuery("*[name='show-styles']").val("Loading...").off( "click" );
			
			if (!book_id) {
				jQuery("#mb-page-design-chooser").html("");
			} else {
				jQuery.post(ajaxurl, data, function(response) {
					//jQuery("#mb-page-styles-dialog-menu").html(response);
					jQuery("#mb-page-design-chooser").html(response);
					// Assume the dialog has already been built (it was, above).
					//myDialog.dialog('destroy');
					//myDialog = buildThemeChooserDialog();
					attachThemeChooserDialogToButton();
					//alert('Got this from the server: ' + response);
				});
			}
		});

});