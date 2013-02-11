jQuery(document).ready(function($){
	
	/* SINGLE BUTTON ON PAGE */
	jQuery('#publish_book_button').click(function() {
		var id = jQuery('#mb_api_book_info_post_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		url = jQuery('#base_url').val().trim() + "/";
		var thisButton = jQuery(this);

		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "id=" + id;
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "id=" + id;
			res = confirm ("Publish this book on this website?");
		}

		if (res && id) {
			// Update progress on page:
			//jQuery('#publishing_progress_message').html("Working...");
			jQuery(this).prop("value","Working...");
			
			//alert ("contacting :" + url);
			jQuery.get(
				url,
				function(data, textStatus, jqXHR) {
					console.log("mb_api.js results:", data, textStatus);
					if (data.error) {
						alert (data.status + ":" + data.error)
						console.log("mb_api.js error:", data, textStatus);
					} else {
						alert ("The book was published.");
					}
					//jQuery('#publishing_progress_message').html("");
					thisButton.prop("value","Publish eBook");
				});
		}
		return false;
	});



	/* UPDATE PUBLISHERS */
	jQuery('#update_publishers').click(function() {

		var thisButton = jQuery(this);

		url = jQuery('#base_url').val().trim() + "/";
		url = url + "mb/shelf/write_publishers_file/";

		jQuery(this).prop("value","Working...");
			

		jQuery.get(
			url,
			function(data, textStatus, jqXHR) {
				console.log("mb_api.js results:", data, textStatus);
				if (data.error) {
					alert (data.status + ":" + data.error)
					console.log("mb_api.js error:", data, textStatus);
				} else {
					//alert ("The book was published.");
				}
				//jQuery('#publishing_progress_message').html("");
				thisButton.prop("value","Update Publishers");
			});
		return false;
	});






});