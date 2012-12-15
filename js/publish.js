jQuery(document).ready(function($){

	jQuery('#publish').click(function() {
		id = jQuery('#mb_book_id').val();
		res = confirm ("Publish this book?");
		if (res) {
			//url = jQuery('#distribution_url').val().trim() + "mb/book/build_book_package/"
			url = jQuery('#distribution_url').val().trim();
			if (url == "") {
				url = jQuery('#base_url').val().trim() + "/";
			}
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			
			// Update progress on page:
			jQuery('#publishing_progress_message').html("Working...");
			
			//alert ("contacting :" + url);
			jQuery.get(
				url,
				function(data,textStatus, jqXHR) {
					//alert('page content: ' + data + "," + textStatus);
					if (data) {
						alert ("Issue publishing that book: " + data)
					} else {
						alert ("The book was published.");
					}
					jQuery('#publishing_progress_message').html("");
				});
		}
		return false;
	});

});