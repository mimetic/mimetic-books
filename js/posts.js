jQuery(document).ready(function($){
	
	// Show previews of design template pages
	jQuery('#mb_book_theme_page_menu').change(function() {
		n = jQuery('#mb_book_theme_page_menu').val();
		n = (n*1) + 1;
		fn = jQuery('#mb_book_theme_page_previews').val() + "/format_" + n + ".jpg";
		
		jQuery('#format_page_preview').attr("src", fn);

		return false;
	});

});