jQuery(document).ready(function($){

jQuery('#upload_image_button').click(function() {
	formfield = jQuery('#upload_image').attr('name');
	tb_show('', 'media-upload.php?referer=mb-api-settings&amp;type=image&amp;TB_iframe=true&amp;post_id=0', false);
	return false;
});

window.send_to_editor = function(html) {
	imgurl = jQuery('img',html).attr('src');
	jQuery('#upload_image').val(imgurl);
	tb_remove();
}

	
});