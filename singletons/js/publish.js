jQuery(document).ready(function($){

	jQuery('#publish').click(function() {
		id = jQuery('#mb_api_book_info_post_id').val();
		res = confirm ("Publish this book?");
		if (res) {
			//url = jQuery('#distribution_url').val().trim() + "mb/book/build_book_package/"
			url = jQuery('#distribution_url').val().trim();
			if (url == "") {
				url = jQuery('#base_url').val().trim() + "/";
			}
			url = url + "mb/book/publish_book_package/?" + "id=" + id;
			//alert ("contacting :" + url);
			jQuery.get(
				url,
				function(data,textStatus, jqXHR) {
					//alert('page content: ' + data + "," + textStatus);
					if (data) {
						alert ("Error publishing that book.")
					} else {
						alert ("The book was published.");
					}
				});
		}
		return false;
	});

});