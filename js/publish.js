jQuery(document).ready(function($){
	
	/* SINGLE BUTTON ON PAGE */
	jQuery('#publish_book_button').click(function() {
		var id = jQuery('#mb_book_id').val();
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


	/*
	VERSION FOR A LISTING
	*/
	
	jQuery('.publish_book_button').click(function() {
		var id = jQuery(this).attr("id");
		var distURL = jQuery('#distribution_url_'+id).val().trim();
		var url = jQuery('#base_url_'+id).val().trim() + "/";

		//alert (id + "," + distURL + ", " + url);

		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			res = confirm ("Publish this book on this website?");
		}

		
		if (res && id) {
			// Update progress on page:
			jQuery('#publishing_progress_message_'+id).html("Working...");
			
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
					jQuery('#publishing_progress_message_'+id).html("");
				});
		}
		return false;
	});


});