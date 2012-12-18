jQuery(document).ready(function($){

	jQuery('#publish').click(function() {
		var id = jQuery('#mb_api_book_info_post_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		url = jQuery('#base_url').val().trim() + "/";

		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			res = confirm ("Publish this book on this website?");
		}

		if (res && id) {
			// Update progress on page:
			jQuery('#publishing_progress_message').html("Working...");
			
			//alert ("contacting :" + url);
			jQuery.get(
				url,
				function(data, textStatus, jqXHR) {
					console.log("Publish.js results:", data, textStatus);
					if (data.error) {
						alert (data.status + ":" + data.error)
						console.log("Publish.js error:", data, textStatus);
					} else {
						alert ("The book was published.");
					}
					jQuery('#publishing_progress_message').html("");
				});
		}
		return false;
	});

});