jQuery(document).ready(function($){
	$('#upload_logo_button').click(function() {
		//type = image,audio,video,file. If we write it wrong, nothing will appear. type = file by default
		//tb_show(caption, url, imageGroup)
		// Google: 'ImageGroup tb_show thickbox':
		//The optional imageGroup parameter can also be used to pass in an array of images for a single or multiple image slide show gallery.
		// The problem is that inserting a gallery needs an associated post to work
		tb_show('Upload a logo', 'media-upload.php?referer=wptuts-settings&amp;type=image&amp;TB_iframe=true&amp;post_id=0', false);
		return false;
	});
	
	window.send_to_editor = function(html) {
		// html returns a link like this:
		// <a href="{server_uploaded_image_url}"><img src="{server_uploaded_image_url}" alt="" title="" width="" height"" class="alignzone size-full wp-image-125" /></a>
		var image_url = $('img',html).attr('src');
		//alert(html);
		$('#logo_url').val(image_url);
		tb_remove();
		$('#upload_logo_preview img').attr('src',image_url);
		
		$('#submit_options_form').trigger('click');
		// $('#uploaded_logo').val('uploaded');
		
	}
	
	
	
});