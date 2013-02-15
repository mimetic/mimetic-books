jQuery(document).ready(function($){
	
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
			var result = $( "#mb_book_theme_page_id" ).empty();
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
	var $info = $("#mb-page-styles-dialog");
    $info.dialog({                   
        'dialogClass'   : 'wp-dialog',           
        'modal'         : true,
        'autoOpen'      : false, 
        'closeOnEscape' : true,      
		'width' 		: 830,
		'height'		: 550,
        'buttons'       : {
            "Close": function() {
                $(this).dialog('close');
            }
        }
    });
    $("#show-styles").click(function(event) {
        event.preventDefault();
        $info.dialog('open');
    });

});