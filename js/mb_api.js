jQuery(document).ready(function($){
	


	function doPublishBook() {
		//alert ("Hi!");

		var id = jQuery('#mb_book_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		var url = jQuery('#base_url').val().trim() + "/";
		var progressURL = url;
		
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

			jQuery('#publish_book_button').prop("value","Working...");

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


/*
	function doPublishBookXX() {
		//alert ("Hi!");

		var id = jQuery('#mb_book_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		var url = jQuery('#base_url').val().trim() + "/";
		var progressURL = url;
		
		var thisButton = jQuery(this);
		
		var thisButtonOriginalTitle = thisButton.prop("value");
		
		if (distURL) {
			url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			//url = url + "mb/book/send_book_package/";
			//data = { book_id : id };
			//res = confirm ("Publish this book to "+distURL+"?");
		} else {
			url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			//url = url + "mb/book/publish_book_package/";
			//data = { book_id : id };
			//res = confirm ("Publish this book on this website?");
		}

		// Progress Bar
		// values are .max and .value
		var progressBar = document.getElementById("progress");
		jQuery(progressBar).show();
		progressBar.value = 0;

		if (res && id) {

			if (!window.XMLHttpRequest){
				alert("Your browser does not support the native XMLHttpRequest object.");
				return;
			}
			try{
				var xhr = new XMLHttpRequest();	 
				xhr.previous_text = '';
								 
				xhr.onerror = function() { alert("[XHR] Fatal Error."); };
				xhr.onreadystatechange = function() {
					try{
						if (xhr.readyState == 4){
							alert('[XHR] Done')
						} 
						else if (xhr.readyState > 2){
							var new_response = xhr.responseText.substring(xhr.previous_text.length);

console.log ("new_response: " + new_response);

							var result = JSON.parse( new_response );

console.log ("result: " + result);

							document.getElementById("progressMsg").innerHTML += result.message + ' ... ';
							progressBar.value = result.progress;
							
							xhr.previous_text = xhr.responseText;
						}	 
					}
					catch (e){
						alert("[XHR STATECHANGE] Exception: " + e);
					}						
				};

				xhr.open("GET", url, true);
				xhr.send();		 
			}
			catch (e){
				alert("[XHR REQUEST] Exception: " + e);
			}
		} // end if
		return false;
	}

	function doPublishBookX() {
		//alert ("Hi!");

		var id = jQuery('#mb_book_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		var url = jQuery('#base_url').val().trim() + "/";
		var progressURL = url;
		
		var thisButton = jQuery(this);
		
		var thisButtonOriginalTitle = thisButton.prop("value");
		
		if (distURL) {
			//url = url + "mb/book/send_book_package/?" + "book_id=" + id;
			url = url + "mb/book/send_book_package/";
			res = confirm ("Publish this book to "+distURL+"?");
			data = { book_id : id };
		} else {
			//url = url + "mb/book/publish_book_package/?" + "book_id=" + id;
			url = url + "mb/book/publish_book_package/";
			res = confirm ("Publish this book on this website?");
			data = { book_id : id };
		}

		// Progress Bar
		// values are .max and .value
		var progressBar = document.getElementById("progress");
		jQuery(progressBar).show();
		progressBar.value = 0;

console.log("URL used is: " + distURL);
console.log("distURL: "+distURL);
console.log("url: "+url);

		if (res && id) {

			jQuery.ajax({
					xhr: function(){
						var xhr = new window.XMLHttpRequest();

						xhr.addEventListener("progress", function(evt){
							if (evt.lengthComputable) {
console.log("EVENT:");
console.log(evt);
console.log("progress: " + evt.progress);
console.log("message: " + evt.message);


								progressBar.max = evt.total;
								progressBar.value = evt.loaded;

								var percentComplete = evt.loaded / evt.total;
console.log("percentComplete: " + percentComplete);

							//Do something with download progress
								progressBar.value = evt.loaded;
								//jQuery("#progressMsg").html("So far " + percentComplete + "%");
								jQuery("#progressMsg").html(evt.message);
								
								console.log("Percent Complete: " + percentComplete);
							}
						}, false);
						return xhr;
					},
					type: 'POST',
					// The URL for the request
					url: url,
					// The data to send (will be converted to a query string)
					data: data,
					// The type of data we expect back
					dataType : "json",
					success: function(data){
					
						//Do something success-ish
						console.log("Successful Result:");
						console.log(data);
					}
			 })
			// Code to run if the request succeeds (is done);
			// The response is passed to the function
			.done(function( json ) {
				 $( "<h1>" ).text( json.title ).appendTo( "body" );
				 $( "<div class=\"content\">").html( json.html ).appendTo( "body" );
				 progressBar.value = progressBar.max;
				 jQuery(progressBar).hide();
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
				//alert( "The request is complete!" );
			});
			
		} // end if
		return false;
	}
*/


	/* SINGLE BUTTON ON PAGE */
	jQuery('#publish_book_button').click( doPublishBook );



// ------------------------------------------------


	/* SINGLE BUTTON ON PAGE */
	/*
	jQuery('#publish_book_button').click(function() {
		var id = jQuery('#mb_book_id').val();
		var distURL = jQuery('#distribution_url').val().trim();
		url = jQuery('#base_url').val().trim() + "/";
		var thisButton = jQuery(this);
		
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
						//alert ("The book was published.");
					}
					//jQuery('#publishing_progress_message').html("");
					thisButton.prop("value", thisButtonOriginalTitle);
				});
		}
		return false;
	});
	*/

	/*
	VERSION FOR A LISTING
	*/
	
	jQuery('.publish_book_button').click(function() {
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


	jQuery('input[name=mb_book_available]').click( function() {
		curval = jQuery('input[name='+this.name+']').attr('checked');
		if (!curval) {
			res = confirm ("Are you sure? It is a bad idea to hide books that people have already sold or downloaded — the book will disappear from the reader's library!");
			
			return res;
		}
		return true;
	});



});