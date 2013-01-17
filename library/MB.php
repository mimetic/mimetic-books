<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * Converts to Mimetic Books format.
 *
 * Mimetic Books is a simple XML based format for a digital publication.
 *
 * This package provides a simple converter from Wordpress json structures
 * into a Mimetic Books XML structure. The idea is to be able to translate 
 * a series of WordPress posts or pages into an XML book, add multimedia
 * resources, package the whole thing into a file, and send it off somewhere.
 *
 * All strings should be in ASCII or UTF-8 format!
 *
 * LICENSE: Redistribution and use in source and binary forms, with or
 * without modification, are permitted provided that the following
 * conditions are met: Redistributions of source code must retain the
 * above copyright notice, this list of conditions and the following
 * disclaimer. Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN
 * NO EVENT SHALL CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
 * OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
 * TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
 * USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 * @category
 * @package     Services_MB
 * @author      David Gross <dgross@mimetic.com>
 * @copyright   2012 David Gross
 * @license     http://www.opensource.org/licenses/bsd-license.php
 */

/**
 * Marker constant for Services_MB::decode(), used to flag stack state
 */
define('SERVICES_MB_SLICE',   1);

/**
 * Marker constant for Services_MB::decode(), used to flag stack state
 */
define('SERVICES_MB_IN_STR',  2);

/**
 * Marker constant for Services_MB::decode(), used to flag stack state
 */
define('SERVICES_MB_IN_ARR',  3);

/**
 * Marker constant for Services_MB::decode(), used to flag stack state
 */
define('SERVICES_MB_IN_OBJ',  4);

/**
 * Marker constant for Services_MB::decode(), used to flag stack state
 */
define('SERVICES_MB_IN_CMT', 5);

/**
 * Behavior switch for Services_MB::decode()
 */
define('SERVICES_MB_SUPPRESS_ERRORS', 32);

/**
 * Converts to and from JSON format.
 *
 * Brief example of use:
 *
 * <code>
 * // create a new instance of Services_MB
 * $json = new Services_MB();
 *
 * // convert a complexe value to JSON notation, and send it to the browser
 * $value = array('foo', 'bar', array(1, 2, 'baz'), array(3, array(4)));
 * $output = $json->encode($value);
 *
 * print($output);
 * // prints: ["foo","bar",[1,2,"baz"],[3,[4]]]
 *
 * // accept incoming POST data, assumed to be in JSON notation
 * $input = file_get_contents('php://input', 1000000);
 * $value = $json->decode($input);
 * </code>
 */
class Mimetic_Book
{

	public $book, $id, $title, $author, $chapters;
	public $pictureFolder = "pictures/";
	public $audioFolder = "audio/";
	public $videoFolder = "video/";
	public $tempDir;
	
	protected $protected;	//http://www.php.net/manual/en/language.oop5.visibility.php
	private $private;
	
	
	
   /**
    * constructs a new MB instance
    *
    * @param    int     $use    object behavior flags; combine with boolean-OR
    *
    *                           possible values:
    *                           - SERVICES_MB_SUPPRESS_ERRORS:  error suppression.
    *                                   Values which can't be encoded (e.g. resources)
    *                                   appear as NULL instead of throwing errors.
    *                                   By default, a deeply-nested resource will
    *                                   bubble up with an error, so all return values
    *                                   from encode() should be checked with isError()
    */
    function Mimetic_Book($book_info, $options = array() )
    {

    	$this->id = ($book_info['id'] ? $book_info['id'] : "mb_".uniqid() );
    	$this->title = ($book_info['title'] ? $book_info['title'] : "Untitled");
    	$this->author = ($book_info['author'] ? $book_info['author'] : "Anonymous");
    	$this->publisher_id = $book_info['publisher_id'];
		$this->theme = $book_info['theme'];
		$this->theme_id = $this->theme->id;
		$this->icon = $book_info['icon_url'];
		$this->poster = $book_info['poster_url'];
		$this->type = $book_info['type'];
		$this->description = $book_info['description'];
		$this->short_description = $book_info['short_description'];
		$this->modified = $book_info['modified'];
		
		$this->date = substr($book_info['datetime'], 0, 10);
		$this->datetime = $book_info['datetime'];
		$this->modified = $book_info['modified'];
		
		$this->book = array();
		$this->book['title'] = $this->title;
    	$this->book['uniqueid'] = $this->id;
    	$this->book['author'] = $this->author;
    	$this->book['publisher_id'] = $this->publisher_id;
		$this->book['theme_id'] = $this->theme_id;
		
        $this->options = $options;
		
		$this->make_build_dir($options['tempDir']);
		
    }
	
	
	/*
	 * Get copies of the styling files
	 * These files are: settings.xml, templates.xml, textstyles.txt
	 * We should have multiple themes stored with this plugin, each of which has its styling files.
	 */
	public function get_theme_files()
	{
		$theme_id = $this->theme->id;
		$path = $this->theme->path;
		$this->dircopy($path, $this->build_files_dir);
	}
	

	/*
	 * Get copies of the promotional artwork, i.e. icon, poster
	 * The URLs of these files is set by on the settings page or the book info page.
	 */
	public function get_book_promo_art($path)
	{
		$icon_url = $this->icon;
		$poster_url = $this->poster;
		if ($icon_url)
			copy($icon_url, $this->build_files_dir.DIRECTORY_SEPARATOR.basename("icon.png"));
		if ($poster_url)
			copy($poster_url, $this->build_files_dir.DIRECTORY_SEPARATOR.basename("poster.jpg"));
	}
	

	// Delete a directory and files in it
	private function delTree($dir) 
	{
		$files = array_diff(scandir($dir), array('.','..'));
		foreach ($files as $file) {
			(is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
		}
		return rmdir($dir);
	}
	
	private function make_build_dir($tempdir)
	{
		$tempdir || $tempdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mimetic-book-temp' . DIRECTORY_SEPARATOR;
		if(! is_dir($tempdir)) {
			mkdir($tempdir);
		}
		//make sure that if two users export different project from same site, they don't clobber each other
		$this->build_dir =  $tempdir . DIRECTORY_SEPARATOR . sha1(microtime()) . DIRECTORY_SEPARATOR ;
		if(! is_dir($this->build_dir)) {
			mkdir($this->build_dir);
		}
		$this->build_files_dir =  $this->build_dir . "files" . DIRECTORY_SEPARATOR;
		if(! is_dir($this->build_files_dir)) {
			mkdir($this->build_files_dir);
		}
   }
	
   private function remove_temp_dir()
   {
	   if (!$this->delTree($this->build_dir)) {
		   $this->isError($this->build_dir);
	   }
   }
	

   
    public function cleanup() 
	{
		$this->remove_temp_dir();
	}
   
   
   
	/*
	 * book_to_xml
	 * Convert the book array to XML.
	 */
    public function book_to_xml() 
    {
		require_once "Array2XML.php";
		//$Array2XML = new Array2XML();

		$this->xml = Array2XML::createXML('book', $this->book);
		return ($this->xml->saveXML() );
	}
	
	
	/**
    * Convert a chapter to MB format XML. 
    *
    * @param    array   $chapter    An array that holds a chapter extracted from Wordpress.
	*								The array looks like this:
	*	$chapter = array (
			id 			=> INTEGER: id of the WP category of posts in this chapter
			title		=> STRING: title of the WP category, e.g. "Chapter 1"
			category	=> OBJECT: a WP category object -- the category for this chapter
			pages		=> ARRAY: an array of posts. Each post is in MB_API_Post format,
							a fancier object than the basic WP post object.
    *
    * @return   mixed   JSON string representation of input var or an error if a problem occurs
    * @access   public
    
    
    <!ELEMENT chapter ( id? | page | title? )* >
	<!ATTLIST chapter hasCaptions
	<!ATTLIST chapter hasOverlays
	<!ATTLIST chapter navbarType
	<!ATTLIST chapter pickerLabelGroupBy


    */
    function convert_chapter($wp_chapter) 
    {
		// WP category object
        $category = $wp_chapter['category'];
		
		$attr = array(
				'id'					=> $category->term_id,
				'hasCaptions'			=> 'false',
				'hasOverlays'			=> 'false',
				'navbarType'			=> 'slider',
				'pickerLabelGroupBy'	=> 'text',
				'notInContents'			=> 'false',
				'stopAudioWhenLeaving'	=> 'true'
				);
		$chapter_id = $category->term_id;
		$chapter = array (
			'@attributes'	=> $attr,
			'title'			=> $category->name,
			'id'			=> $category->term_id,
			'altTitle'		=> $category->name,
			'index'			=> $category->term_order,
			'page'			=> $this->convert_pages($wp_chapter['pages'], $category)
			);

        $this->book['chapter'][] = $chapter;
	}

	
	
	private function convert_pages ($wp_posts, $category)
	{
		$pages = array();
		foreach ($wp_posts as $page) {
			$pages[] = $this->convert_page($page, $category);
		}
		return $pages;
	}
	
	/*
	DTD Page Definition:
	<!ELEMENT page ( aperture | audiofile | backgroundfile | buttons | cameraMake | cameraModel | caption | city | country | creator | date | dateTimeOriginal | exposureBias | exposureProgram | flash | focalLength | focalLength35mm | gps | gpsLatitudeDecimal | gpsLongitudeDecimal | grid | headline | imagefile | isoSpeedRating | label | order | overlay | pagedate | panoramas | pictures | publicfile | shutterSpeed | state | subjectDistance | sublocation | textblock | textblocks | texttitle | shapes | links )* >
	<!ATTLIST page backgroundcolor CDATA #IMPLIED >
	<!ATTLIST page contents ( false | true ) #IMPLIED >
	<!ATTLIST page contentstitle CDATA #IMPLIED >
	<!ATTLIST page id ID #IMPLIED >
	<!ATTLIST page skipinslideshow ( false | true ) #IMPLIED >
	<!ATTLIST page slideshowstart
	<!ATTLIST page template
	<!ATTLIST page order
	
	<video autoplay="true" filename="_user/video/hank-walking.mov" width="1024" height="576" x="center" y="center" absolute="true">
	</video>
				
	*/
	
	private function convert_page ($wp_page, $category)
	{
		global $mb_api;
		
		//print_r($wp_page);
		
		// Assign page attributes from the post
		$attr = array ();
		$attr_items = array ( "id", "caption", "city", "state", "country", "date", "headline", "imagefile", "label", "order", "pagedate", "modified");
		foreach ($attr_items as $item) {
			isset($wp_page->$item) && $attr[$item] = $wp_page->$item;
		}
		
		// Build the textblock
		$text = $wp_page->content;
		$title = $wp_page->title;

		// delete embedded stuff like img or a
		$text = str_replace( "\xC2\xA0", " ", $text);
		$text = $this->delete_embedded_media($text);
		
		// To make CDATA work with the XML generator we are using, we need to
		// wrap the text thus:
		$text = array('@cdata'=>$text);
		$title = array('@cdata'=>$title);
		
		$textblock = array (
			array ('title' => $title), 
			array('text' => $text )
		);
		
		$textblocks = array(
			"textblock" => $textblock
		);
		
		// This uses the built-in WP formats:
		//$wp_page->format_id ? $mb_template_id = $wp_page->format_id : $mb_template_id = "";
		
		// This uses page format ID's from the themes
		$themePageID = get_post_meta($wp_page->id, "mb_book_theme_page_id", true);
		
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		$themePageIDList = $mb_api->themes->themes[$this->theme_id]->format_ids;

		// If we don't have a theme template page assigned, use the first one in the list
		if (!$themePageID || !array_search($themePageID, $themePageIDList)) {
			$themePageID = $themePageIDList[0];
		}
		
		//Assign page values
		// Do NOT assign blank ones! Blank entries will overwrite template
		// entries, for backgrounds or whatever. Blank entries should only happen
		// when the author wants the entry blank!
		$page = array (
			'@attributes'		=> $attr,
			'title'				=> $wp_page->title,
			'altTitle'			=> $category->name,
			//'backgroundfile'	=> '',
			//'audiofile'		=> '',
			//'video'			=> '',
			'textblocks'		=> $textblocks,
			'template'			=> $themePageID
			);

		// Get ATTACHED MEDIA: sounds, video
		
		//if ($wp_page->attachments) {
		//$attached = $this->get_attachments($wp_page, false);
		//$page = array_merge($page, $attached);
		//}
		
		
		// GET EMBEDDED PICTURES (NOT ATTACHED)
		// Attached items are not necessarily embedded.
		// Some images may be attached but not really used on the page. 
		// Therefore, we should not include any images using this function,
		// but rather let the get_embedded function handle those.

		// Get EMBEDDED elements in the post's HTML,
		// Convert the embedded elements, e.g. img tags, to MB format arrays
		$embedded = $this->get_embedded_pictures($wp_page);
		$page = array_merge($page, $embedded);


		return $page;
	}


	
	
	/*
	 * Get all the attached media items for this post.
	 * $include_images : true means include attached images, false means exclude.
	 * Important: Not all attached images appear on the page! Some were uploaded to the page
	 * but eventually removed, yet they remain "attached".
	 * This does not have access to all the HTML settings, such as height/width,
	 * which the embedded HTML does. So, we should use this for audio, and probably
	 * nothing else.
	*/
	private function get_attachments ($wp_page, $include_images = false)
	{
		$args = array(
			'post_type' => 'attachment',
			'numberposts' => -1,
			'post_status' => null,
			'post_parent' => $wp_page->id
		);
		$attachments = get_posts( $args );
		
		
		// Image attachments embedded in the HTML. These may not show up as 
		// attachments, so we'll deal with them as their own group.
		
		
		// ------------------------
		// Read image attributes from the HTML,
		// giving us height/width and more.
		$image_attrs = $this->get_embedded_element_attributes($wp_page);
		
		// *** PROBLEM IS, not all embedded items are attachments belonging to the current page! ****
		// That only happens when the image was originally uploaded to the page.
		
print ("-----------\n");
print_r($image_attrs);
print ("Attachments\n");
print_r($attachments);
print ("-----------\n");

		$page_elements = array();
		if ( $attachments ) {
			foreach ( $attachments as $attachment ) {
				$element = array();
				$element['name'] = preg_replace('|/.*$|', '', $attachment->post_mime_type);
				$element['type'] = $attachment->post_mime_type;
				$element['modified'] = $attachment->post_modified;
				$element['modified_gmt'] = $attachment->post_modified_gmt;
				
				if ($include_images && wp_attachment_is_image( $attachment->ID)) {
					// Images
					$image_attributes = wp_get_attachment_image_src( $attachment->ID, "full"); // returns an array
					$attributes['src'] = $image_attributes[0];
					$attributes['width'] = $image_attributes[1];
					$attributes['height'] = $image_attributes[2];
					$attributes['originalWidth'] = $image_attributes[1];
					$attributes['originalHeight'] = $image_attributes[2];
					if ($image_attrs[$attachment->ID]) {
						$html_attrs = $image_attrs[$attachment->ID];
						$html_attrs['width'] && $attributes['width'] = $html_attrs['width']+0;
						$html_attrs['height'] && $attributes['height'] = $html_attrs['height']+0;
					} else {
						print ("MB:get_attachments: WTF? No attributes for attachment id=".$attachment->ID);
					}
				} else {
					// Other kinds of attachements
					$attributes['src'] = $attachment->guid;
				}

				$attributes['name'] = $attachment->post_title;
				$attributes['id'] = $attachment->ID;
				
				
				$element['attributes'] = $attributes;
				
				
				//print_r($attachment);
				// Handle MB's need to encapsulate, e.g. if we have an 'img', 
				// then we need to create a <pictures> element to hold
				// the <picture> which is the 'img'.
				list($mb_name, $mb_encaps_name) = $this->name_for_element($element['type']);


				if ($mb_encaps_name) {
					if (!isset($page_elements[$mb_encaps_name])) {
						$page_elements[$mb_encaps_name] = array ();
					}
					$page_elements[$mb_encaps_name][$mb_name][] = $this->element_to_mb($element);
				} else {
					$page_elements[$mb_name] = $this->element_to_mb($element);
				}
			}
		}

		// Now, gather them into MB format 
		// img ---> picture
		
		//print_r($page_elements);
		return $page_elements;
	}
	
	
	
	private function get_embedded_pictures($wp_page) {
		$elements = $this->get_embedded_elements($wp_page, "img");
		return $elements;
	}
	
	
	/*
	 * NOTE: this works great with images, but really not with other kinds of
	 * attachments! Audio for example, uses <a> as its tag, so we would
	 * have to check the linked file to know what we were dealing with.
	*/
	private function get_embedded_elements($wp_page, $element_type="img") {
		$text = $wp_page->content;
		
		// Use PHP XML/HTML extract functionality!
		$doc = new DOMDocument();
		$doc->loadHTML($text);
		
		$page_elements = array();
		
		// Get all elements in the HTML
		foreach ($doc->getElementsbytagname($element_type) as $node) {
			//$item = $doc->saveHTML($node);
			$element = array();
			$element['name'] = $node->nodeName;
			$element['value'] = $node->nodeValue;
			$element['type'] = $node->nodeType;
			$attributes = array();
			foreach ($node->attributes as $attr) {
				$attributes[$attr->name] = $attr->nodeValue;
			}
			$id = preg_replace("/.*?wp-image-/", "", $attributes['class']);
			$attributes['id'] = $id;

			$element['attributes'] = $attributes;
			
			// Add in all post info for the item
			$post = get_post( $id, ARRAY_A );
			
			// DON'T add the actual element to the page. Let's only return 
			// elements suitable for use by MB.php.
			//$page_elements[0+$id] = array_merge($post,$attributes);

			// Handle MB's need to encapsulate, e.g. if we have an 'img', 
			// then we need to create a <pictures> element to hold
			// the <picture> which is the 'img'.
			list($mb_name, $mb_encaps_name) = $this->name_for_element($element['name']);
			
			if ($mb_encaps_name) {
				if (!isset($page_elements[$mb_encaps_name])) {
					$page_elements[$mb_encaps_name] = array ();
				}
				$page_elements[$mb_encaps_name][$mb_name][] = $this->element_to_mb($element);
			} else {
				$page_elements[$mb_name] = $this->element_to_mb($element);
			}
		}

		
		//print_r($page_elements);
		return $page_elements;
	}
	

	/*
	 * Read the HTML of the post text and figure out neat stuff from it about
	 * embedded elements, such as height/width of the embedded image.
	 * It seems that just getting the attachment info won't get us here.
	*/
	private function get_embedded_element_attributes($wp_page, $element_type="img") {
		$text = $wp_page->content;
		
		// Use PHP XML/HTML extract functionality!
		$doc = new DOMDocument();
		$doc->loadHTML($text);
		
		$page_elements = array();
		// Get all elements in the HTML
		foreach ($doc->getElementsbytagname($element_type) as $node) {
			/*
			//$item = $doc->saveHTML($node);
			$element = array();
			$element['name'] = $node->nodeName;
			$element['value'] = $node->nodeValue;
			$element['type'] = $node->nodeType;
			$attributes = array();
			foreach ($node->attributes as $attr) {
				$attributes[$attr->name] = $attr->nodeValue;
			}
			$element['attributes'] = $attributes;
			$id = preg_replace("/.*?wp-image-/", "", $attributes['class']);
			$page_elements[$id] = $element;
			 */

			$attributes = array();
			foreach ($node->attributes as $attr) {
				$attributes[$attr->name] = $attr->nodeValue;
			}
			$id = preg_replace("/.*?wp-image-/", "", $attributes['class']);
			$attributes['id'] = $id;
			
			$post = get_post( $id, ARRAY_A );
			$page_elements[0+$id] = array_merge($post,$attributes);
		}

		// Now, gather them into MB format 
		// img ---> picture
		
		//print_r($page_elements);
		return $page_elements;
	}
	


	/*
	 * Convert an HTML img element to an MB XML element
	 * 
	 * Since these documents are not "default" to be found in the MB "_user" folder,
	 * all files referenced are in the * directory:
	 */	 // Written this way:    */pictures/mypic.jpg
	 /* 
	 * Picture element:
		addCorners
		alpha
		filename CDATA #REQUIRED >
		noShadow ( false | true ) #IMPLIED >
		noThumbnail
		scale
		width
		height
		x
		y
		rotation
		absolute
		time
		zoomedWidth
		zoomedHeight
		zoomedX
		zoomedY
		zoomedScale
		zoomedRotation

	*/
	private function element_to_mb($element) {
		$attr = $element['attributes'];
		$mb_element = array ();
		
		// Name could be an html name (img, audio) or it could be a MIME type (image/jpeg).
		// Modify the name to capture the right type for multiple formats, e.g. all images
		$name = preg_replace('|/.*$|', '', $element['name']);
		
		switch ($name) {
			case "image":
				
			case "img" :
				isset($attr['width']) ? $mb_element['width'] = $attr['width'] : $mb_element['width'] = null;
				isset($attr['height']) ? $mb_element['height'] = $attr['height'] : $mb_element['height'] = null;
				$mb_element['filename'] = "*" . DIRECTORY_SEPARATOR . $this->pictureFolder . basename($attr['src']);
				$mb_element['zoomedScale'] = "1";
				
				// haha, just for testing
				// $mb_element['addCorners'] = "true";
				
				// Convert the picture to one we can use, ready for packaging
				$this->convert_img($attr['src'], $mb_element['width'], $mb_element['height']);
				
				
				break;
			
			case "panorama" :
				break;
			
			case "link" :
				break;
			
			case "audio" :
				$mb_element['value'] = "*" . DIRECTORY_SEPARATOR . $this->audioFolder . basename($attr['src']);
				// Copy the audio file to the audio folder
				$this->copy_audio_file($attr['src']);
				break;
		}
		return $mb_element;
	}

	
	/*
	 * Convert an image to something we can use in the app.
	 * This basically just resizing and copying.
	 */
	private function copy_audio_file($src)
	{

		$dir = $this->build_files_dir . $this->audioFolder;
		if(! is_dir($dir)) {
			mkdir($dir);
		}

		$filename = basename($src);	
		$filepath = $dir.$filename;
		$name = basename ($src);
		
		$success = copy($src, $filepath);
		if (!$success) {
			$this->isError("Could not copy the audio file to {$mb_element['filename']}");
		}
		
		return $success;

	}

	
	
			
	/*
	 * Convert an image to something we can use in the app.
	 * This basically just resizing and copying.
	 */
	private function convert_img($src, $width, $height) {
		
		$dir = $this->build_files_dir . $this->pictureFolder;
		if(! is_dir($dir)) {
			mkdir($dir);
		}

		$filename = basename($src);	
		$filepath = $dir.$filename;
		$name = basename ($src);

		// Copy the file from the URL source to a real file we can work with
		file_put_contents($filepath ,file_get_contents($src));

		$output = array();
		$response = "";
		
		// ImageMagick converter
		if (true) {
			$FP_IM_CONVERT = "/opt/local/bin/convert";
		} else {
			$FP_IM_CONVERT = "/usr/local/bin/convert";
		}


		$IMAGE_TOOL = "im";
		$FP_IMAGEMAGICK_QUALITY = 80;
		$FP_PROFILE_SRGB = "sRGB.icm";
		$BASEDIR = dirname(__FILE__);

		if ($IMAGE_TOOL == "gd") {
			// SLIDES: RESIZE TO LARGE VIEWING SIZE (RESIZE ORIGINAL -> SLIDE)
			$success = ResizeImage($slide_size, $filepath, "$filepath", $default_border, $watermark);
			if (!$success) return false;

		}
		else	// using ImageMagick
		{
			$quality = " -quality " .$FP_IMAGEMAGICK_QUALITY;
			// unshapr mask radius=.5, sigma=.5, amount=1.2, threshhold=0.05
			$sharpen = " -unsharp .5x.5+1.2+0.05";
			$basics = " -units 'pixelsperinch' -density '72x72'";
			// let's see if we can not have our own profile lying around.
			//$profile = " -intent Perceptual -profile '$BASEDIR/$FP_PROFILE_SRGB'";
			$profile = "";
			$filter = "";

			$cmd = $FP_IM_CONVERT . " -colorspace LAB '{$filepath}' $basics $quality $sharpen $filter $profile -colorspace sRGB -strip ";
			if ($width && $height) {
				$cmd .= " -resize '{$width}x{$height}>' '{$filepath}'";
			} else {
				$cmd .= " -write '{$filepath}'";
			}

			exec ($cmd, $output, $response);
		}

		if ($response >= 300) {
			$this->isError("Imagemagick error: ".$response);
			return false;
		} else {
			return true;
		}

	}
	
	
	
	/*
	 * Name for Element
	 * return	: array(name, encapsulate tag name)
	 * e.g. name_for_element("img") => array ("picture", "pictures")
	 * 
	 * Get the MB name for an HTML element. For example, an 'img' element
	 * is called 'picture' in MB.
	 * Also, if the element requires encapsulation, then return true.
	 * 
	 * Not encapsulated MB items:
	 * imagefile, audiofile, backgroundfile, video, overlay, textblock
	 * 
	 * Encapsulated MB items:
	 * picture, panorama, button, link

	 */
	private function name_for_element($e) {
		switch ($e) {
			case 'img' : $mb_name = array ("picture", "pictures");
				break;
			case 'div' : $mb_name = array ("shape", "shapes");
				break;
			case 'panorama' : $mb_name = array ("panorama", "buttons");
				break;
			case 'a' : $mb_name = array ("link", "links");
				break;
			case 'button' : $mb_name = array ("button", "buttons");
				break;
			// mime types
			case 'image/jpeg' : $mb_name = array ("picture", "pictures");
				break;
			case 'image/png' : $mb_name = array ("picture", "pictures");
				break;
			case 'audio/mpeg' : $mb_name = array ("audiofile", null);
				break;
			
			
		}
		return $mb_name;
	}
	

	
	
	/**
	  * Delete embedded tags in a text:
	  * img, a
	 */
	private function delete_embedded_media($text ) {
		
		$p = array (
			"/< *img.*?>/",
			"|< *a.*?>.*?< */a *>|"
		);
		
		$text = preg_replace($p, "", $text);
		return $text;
	}
	
	
	
	
   /**
    * reduce a string by removing leading and trailing comments and whitespace
    *
    * @param    $str    string      string value to strip of comments and whitespace
    *
    * @return   string  string value stripped of comments and whitespace
    * @access   private
    */
    function reduce_string($str)
    {
        $str = preg_replace(array(

                // eliminate single line comments in '// ...' form
                '#^\s*//(.+)$#m',

                // eliminate multi-line comments in '/* ... */' form, at start of string
                '#^\s*/\*(.+)\*/#Us',

                // eliminate multi-line comments in '/* ... */' form, at end of string
                '#/\*(.+)\*/\s*$#Us'

            ), '', $str);

        // eliminate extraneous space
        return trim($str);
    }


    /**
     * @todo Ultimately, this should just call PEAR::isError()
     */
    function isError($data, $code = null)
    {
        if (class_exists('pear')) {
            return PEAR::isError($data, $code);
        } elseif (is_object($data) && (get_class($data) == 'services_mb_error' ||
                                 is_subclass_of($data, 'services_mb_error'))) {
            return true;
        }

        return false;
    }
	
	
		// removes files and non-empty directories
	private function rrmdir($dir) {
		if (is_dir($dir)) {
			$files = scandir($dir);
			foreach ($files as $file)
			if ($file != "." && $file != "..") 
				$this->rrmdir("$dir/$file");
			rmdir($dir);
		}
		else if (file_exists($dir)) unlink($dir);
	} 
	
	
	// copies contents of a directory into another directory
	private function dircopy($src, $dst) {
		if ( !(file_exists($src) && file_exists($dst)) ) {
			return false;
		}
		
		(substr($src, -1) == "/") || $src = $src."/";
		(substr($dst, -1) == "/") || $dst = $dst."/";
		$files = scandir($src);
		foreach ($files as $file) {
			if ($file != "." && $file != "..") 
				$this->rcopy($src.$file, $dst.$file);
		}
	}
	
	// copies files and non-empty directories
	private function rcopy($src, $dst) {
		if (file_exists($dst))
			$this->rrmdir($dst);
		if (is_dir($src)) {
			mkdir($dst);
			$files = scandir($src);
			foreach ($files as $file)
			if ($file != "." && $file != "..") 
				$this->rcopy("$src/$file", "$dst/$file"); 
		}
		else if (file_exists($src)) copy($src, $dst);
	}

	
	private function tar_dir($src) {
		$script = "tar -cf $src";
		$results = exec($script, $res);
		return $res;
	}

	
	
}


	/**
	 * Book object 
	 */

	 class book
	 {
		 public $chapters;
		 
		 function book() {
			$this->chapters = array();
		 }
		 
	 }

	 /*
	  * Errors
	  */

	
	
	
	
	 
	 
if (class_exists('PEAR_Error')) {

    class Services_MB_Error extends PEAR_Error
    {
        function Services_MB_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {
            parent::PEAR_Error($message, $code, $mode, $options, $userinfo);
        }
    }

} else {

    /**
     * @todo Ultimately, this class shall be descended from PEAR_Error
     */
    class Services_MB_Error
    {
        function Services_MB_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {

        }
    }


}
   
?>