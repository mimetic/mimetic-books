jQuery(document).ready(function($){
	


	function doPublishBook() {
		//alert ("/js/mb_api.js: doPublishBook()");

		var id = jQuery('#mb_book_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		var url = jQuery('#base_url').val().trim() + "/";
		
		var thisButton = jQuery(this);
		
		var thisButtonOriginalTitle = thisButton.prop("value");
		
		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			//url = url + "mb/book/send_book_package/";
			//data = { book_id : id };
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			//url = url + "mb/book/publish_book_package/";
			//data = { book_id : id };
			res = confirm ("Publish this book on this website?");
		}

		// Progress Bar
		// values are .max and .value
		var progressBar = document.getElementById("progress");
		jQuery(progressBar).show();
		progressBar.value = 0;

		if (res && id) {

			thisButton.prop("value","Working...");

			var source=url;
			 
			function start_task()
			{
					clear_log();
					
					source = new EventSource(url);
					 
					//a message is received
					source.addEventListener('message' , function(e) 
					{
					
							var result = JSON.parse( e.data );
							add_log(result.message);								 
							
							// Update progress
							if (result.progress) {
								progressBar.value = result.progress;
							}
							
							if(result.status == 'end') {
									//add_log('*** END ***');
									thisButton.prop("value", thisButtonOriginalTitle);
									source.close();
									
							} else if ('set_total' == result.status) {
								progressBar.max = result.params.total;
							}
					});
					 
					source.addEventListener('error' , function(e)
					{
						 add_log('Error occured');
						 console.log (e);
						//kill the object ?
						source.close();

						thisButton.prop("value", thisButtonOriginalTitle);

					});
			}
			 
			function add_log(message)
			{ 
				if (message) {							
					var r = document.getElementById('results');
					r.innerHTML += message + '<br>';
					r.scrollTop = r.scrollHeight;
				}
			}

		
			function clear_log()
			{
					var r = document.getElementById('results');
					r.innerHTML = '';
					r.scrollTop = r.scrollHeight;
			}

			
		} // end if
		
		start_task();
		
		return false;
	}


	/* SINGLE BUTTON ON PAGE */
	jQuery('#publish_book_button').click( doPublishBook );


	// ------------------------------------------------
	/*
	VERSION FOR A LISTING
	WITH AJAX feedback
	*/	
	function doPublishBookInRow() {
		//alert ("/js/mb_api.js: Publish Listing Version...");

		var id = jQuery(this).attr("id");
		var distURL = jQuery('#distribution_url_'+id).val().trim();
		var url = jQuery('#base_url_'+id).val().trim() + "/";
		var thisButton = jQuery(this);
		var thisButtonOriginalTitle = thisButton.prop("value");
		var thisButton = jQuery(this);

		
		var thisButtonOriginalTitle = thisButton.prop("value");
		
		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			//url = url + "mb/book/send_book_package/";
			//data = { book_id : id };
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			//url = url + "mb/book/publish_book_package/";
			//data = { book_id : id };
			res = confirm ("Publish this book on this website?");
		}

		// Progress Bar
		// values are .max and .value
		var progressBar = document.getElementById("progress"+id);
		jQuery(progressBar).show();
		progressBar.value = 0;

		if (res && id) {

			thisButton.prop("value","Working...");

			var source=url;
			 
			function start_task()
			{
					clear_log();
					
					source = new EventSource(url);
					 
					//a message is received
					source.addEventListener('message' , function(e) 
					{
					
							var result = JSON.parse( e.data );
							add_log(result.message);								 
							
							// Update progress
							if (result.progress) {
								progressBar.value = result.progress;
							}
							
							if(result.status == 'end') {
									//add_log('*** END ***');
									thisButton.prop("value", thisButtonOriginalTitle);
									source.close();
									
							} else if ('set_total' == result.status) {
								progressBar.max = result.params.total;
							}
					});
					 
					source.addEventListener('error' , function(e)
					{
						 add_log('Error occured');
						 console.log (e);
						//kill the object ?
						source.close();

						thisButton.prop("value", thisButtonOriginalTitle);

					});
			}
			 
			function add_log(message)
			{ 
				if (message) {							
					var r = document.getElementById('results'+id);
					r.innerHTML += message + '<br>';
					r.scrollTop = r.scrollHeight;
				}
			}

		
			function clear_log()
			{
					var r = document.getElementById('results'+id);
					r.innerHTML = '';
					r.scrollTop = r.scrollHeight;
			}

			
		} // end if
		
		start_task();
		
		return false;
	}


	/* SINGLE BUTTON ON PAGE */
	jQuery('.publish_book_button').click( doPublishBookInRow );

	// ------------------------------------------------
	/*
	VERSION FOR A LISTING
	No AJAX feedback
	*/
	/*
	jQuery('.publish_book_button').click(function() {
		alert ("/js/mb_api.js: Publish Listing Version...");

		var id = jQuery(this).attr("id");
		var distURL = jQuery('#distribution_url_'+id).val().trim();
		var url = jQuery('#base_url_'+id).val().trim() + "/";
		var thisButton = jQuery(this);
		//alert (id + "," + distURL + ", " + url);
		var thisButtonOriginalTitle = thisButton.prop("value");

		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			res = confirm ("Publish this book on this website?");
		}

		
		if (res && id) {
			// Update progress on page:
			//jQuery('#publishing_progress_message_'+id).html("Working...");
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
						//alert ("The book was published.");
					}
					//jQuery('#publishing_progress_message_'+id).html("");
					thisButton.prop("value",thisButtonOriginalTitle);
				});
		}
		return false;
	});
	*/


	// ------------------------------------------------

	jQuery('input[name=mb_book_available]').click( function() {
		curval = jQuery('input[name='+this.name+']').attr('checked');
		if (!curval) {
			res = confirm ("Are you sure? It is a bad idea to hide books that people have already sold or downloaded — the book will disappear from the reader's library!");
			
			return res;
		}
		return true;
	});



});