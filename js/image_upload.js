jQuery(document).ready(function($){


jQuery('.poster_upload').click(function() {

    var send_attachment_bkp = wp.media.editor.send.attachment;
	
	//props = new array();
	//props.type = "media";

    wp.media.editor.send.attachment = function(props, attachment) {

        $('.poster_image').attr('src', attachment.url);
        $('.poster_url').val(attachment.url);
        $('.poster_id').val(attachment.id);

        wp.media.editor.send.attachment = send_attachment_bkp;
    }

    wp.media.editor.open();

    return false;       
});

// Old media manager
   /*
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
*/
	
});