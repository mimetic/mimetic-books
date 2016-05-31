jQuery(document).ready(function($){
	
	var progressBar = document.getElementById("progress"),
   xhr = new XMLHttpRequest();
	xhr.open("POST", "http://zinoui.com/demo/progress-bar/upload.php", true);
	xhr.upload.onprogress = function (e) {
		 if (e.lengthComputable) {
			  progressBar.max = e.total;
			  progressBar.value = e.loaded;
		 }
	}
	xhr.upload.onloadstart = function (e) {
		 progressBar.value = 0;
	}
	xhr.upload.onloadend = function (e) {
		 progressBar.value = e.loaded;
	}
	xhr.send(new FormData());




	function doPublishBook() {
		alert ("Hi1");
		var id = jQuery('#mb_api_book_info_post_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		var url = jQuery('#base_url').val().trim() + "/";
		var thisButton = jQuery(this);
		var thisButtonOriginalTitle = thisButton.prop("value");

		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "id=" + id;
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "id=" + id;
			res = confirm ("Publish this book on this website?");
		}

console.log("distURL"+distURL);
console.log("url"+url);
console.log("distURL"+distURL);

		if (res && id) {

			jQuery.ajax({
					xhr: function(){
						var xhr = new window.XMLHttpRequest();
						//Upload progress
						xhr.upload.addEventListener("progress", function(evt){
						if (evt.lengthComputable) {
							var percentComplete = evt.loaded / evt.total;
							//Do something with upload progress
							console.log(percentComplete);
							}
						}, false);
					//Download progress
						xhr.addEventListener("progress", function(evt){
							if (evt.lengthComputable) {
							 var percentComplete = evt.loaded / evt.total;
							//Do something with download progress
							 console.log(percentComplete);
							}
						}, false);
						return xhr;
					},
					type: 'POST',
					// The URL for the request
					url: distURL,
					// The data to send (will be converted to a query string)
					data: {},
					success: function(data){
					// The type of data we expect back
					dataType : "json",
					
					//Do something success-ish
					}
			 })
			// Code to run if the request succeeds (is done);
			// The response is passed to the function
			.done(function( json ) {
				 $( "<h1>" ).text( json.title ).appendTo( "body" );
				 $( "<div class=\"content\">").html( json.html ).appendTo( "body" );
			})
			// Code to run if the request fails; the raw request and
			// status codes are passed to the function
			.fail(function( xhr, status, errorThrown ) {
				alert( "Sorry, there was a problem!" );
				console.log( "Error: " + errorThrown );
				console.log( "Status: " + status );
				console.dir( xhr );
			})
			// Code to run regardless of success or failure;
			.always(function( xhr, status ) {
				alert( "The request is complete!" );
			});
			
		}	// end if
		return false;
	});



	/* SINGLE BUTTON ON PAGE */
	jQuery('#publish_book_button').click( doPublishBook );
	
	




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


	/* SINGLE BUTTON ON PAGE */
/*
	jQuery('#publish_book_button').click(function() {
		var id = jQuery('#mb_api_book_info_post_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		url = jQuery('#base_url').val().trim() + "/";
		var thisButton = jQuery(this);
		var thisButtonOriginalTitle = thisButton.prop("value");
		

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
					thisButton.prop("value",thisButtonOriginalTitle);
				});
		}
		return false;
	});

*/

});