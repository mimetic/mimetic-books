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
    function __construct($book_info, $options = array() )
    {
    
    	//increase max execution time of this script to 10 min:
		ini_set('max_execution_time', 600);
		//increase Allowed Memory Size of this script:
		//ini_set('memory_limit','960M');

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
		$this->meta_modified = $book_info['meta_modified'];
		$this->orientation = $book_info['orientation'];
		$this->is_card_list = $book_info['is_card_list'];
		
		$this->date = substr($book_info['datetime'], 0, 10);
		$this->datetime = $book_info['datetime'];
		
		$this->book = array();
		$this->book['title'] = $this->title;
    	$this->book['uniqueid'] = $this->id;
    	$this->book['author'] = $this->author;
    	$this->book['publisher_id'] = $this->publisher_id;
		$this->book['theme_id'] = $this->theme_id;
		
		if (!isset($options['dimensions']) ) { 
			$options['dimensions'] = array("width"=>1024,"height"=>768);
		}
		
        $this->options = $options;
		
		$this->make_build_dir($options['tempDir']);
		
		$this->attached_items_already_on_pages = array();
		
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
	public function get_book_promo_art()
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
		global $mb_api;

//error_log(basename(__FILE__).":".__FUNCTION__);
//error_log(print_r($this->book, true));

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
    
    * Note, the index is the number of the chapter. Hmm.
    
    <!ELEMENT chapter ( id? | page | title? )* >
	<!ATTLIST chapter hasCaptions
	<!ATTLIST chapter hasOverlays
	<!ATTLIST chapter navbarType
	<!ATTLIST chapter pickerLabelGroupBy


    */
    function convert_chapter($wp_chapter, $index, $not_in_contents = false) 
    {
		// WP category object
        $category = $wp_chapter['category'];
		
		$index || $index = "";
		
		$not_in_contents ? $not_in_contents = "true" : $not_in_contents = "false";
		
		$attr = array(
				'id'					=> $category->term_id,
				'hasCaptions'			=> 'false',	// this is reset by convert_pages()
				'hasOverlays'			=> 'true',
				'navbarType'			=> 'slider',
				'pickerLabelGroupBy'	=> 'text',
				'notInContents'			=> $not_in_contents,
				'stopAudioWhenLeaving'	=> 'true'
				);
		$chapter_id = $category->term_id;
		
		list($page, $settings) = $this->convert_pages($wp_chapter['pages'], $category);
		
		// Copy any attachments we didn't get from the pages
		// I'm worried this will get ALL attachments, not just this book's attachments.
		//$this->get_all_attachments ($this->attached_items_already_on_pages, true);
		
		$attr = array_merge($attr, $settings);
		
		$chapter = array (
			'@attributes'	=> $attr,
			'title'			=> $category->name,
			'id'			=> $category->term_id,
			'altTitle'		=> $category->name,
			'index'			=> $index,
			'page'			=> $page
			);

        $this->book['chapter'][] = $chapter;
	}

	
	/*
	Convert posts to book pages.
	Returns an array of pages and chapter attributes
	*/
	private function convert_pages ($wp_posts, $category)
	{
		global $mb_api;
		
		// Update the publishing status, and send an update via AJAX
		$mb_api->send_ajax_update ( $this->id, array (
			'message'	=> "Chapter has ".count($wp_posts)." pages.",
			'status' 	=> 'set_total',
			'total'		=> count($wp_posts)
		));

		
		$pages = array();
		$settings = array();
		$k = 1;
		$pagecount = count($wp_posts);
		foreach ($wp_posts as $page) {

			$p =  $this->convert_page($page, $k, $category);
			$pages[] = $p;
			if ($p['caption'])
				$settings['hasCaptions'] = "true";

			// Update the publishing status, and send an update via AJAX
			$mb_api->send_ajax_update ( $this->id, array (
				'progress'	=>	$k++
			));
		}

		return array($pages, $settings);
	}
	
	
	
	/*
	DTD Page Definition:
	<!ELEMENT page ( aperture | audiofile | videofile | backgroundfile | buttons | cameraMake | cameraModel | caption | city | country | creator | date | dateTimeOriginal | exposureBias | exposureProgram | flash | focalLength | focalLength35mm | gps | gpsLatitudeDecimal | gpsLongitudeDecimal | grid | headline | imagefile | isoSpeedRating | label | order | overlay | pagedate | panoramas | pictures | publicfile | shutterSpeed | state | subjectDistance | sublocation | textblock | textblocks | texttitle | shapes | links )* >
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
	
	private function convert_page ($wp_page, $pagenum, $category)
	{
		global $mb_api, $more;
		
		// --------------------
		// Build the textblock
		$text = $wp_page->content;
		$title = $wp_page->title;
		

		// USER FEEDBACK: Update the publishing status, and send an update via AJAX
		$mb_api->send_ajax_update ( $this->id, array (
			'message' 	=> "$pagenum : $title",
		));


//$mb_api->write_log(print_r($wp_page, true));
	
		// Get page attributes from the post
		$attr = array ();
		$attr_items = array ( "id", "caption", "city", "state", "country", "date", "headline", "imagefile", "label", "order", "pagedate", "modified");
		foreach ($attr_items as $item) {
			isset($wp_page->$item) && $attr[$item] = $wp_page->$item;
		}

		/*
		$attr_names = array ( "id", "caption", "city", "state", "country", "date", "headline", "imagefile", "label", "order", "pagedate", "modified", "caption");
		for ($i=0; $i<count($attr_names); $i++) {
			$item = $attr_items[$i];
			$itemName = $attr_names[$i];
			isset($wp_page->$item) && $attr[$itemName] = $wp_page->$item;
		}
		*/
		
		$mb_api->write_log(__FUNCTION__.": Building page $title");


		// "MORE" shows up at this point with a <span id=more-xxx></span>
		// So, let's find those, turn them back into <!--more--> so we can break with them.
//$mb_api->write_log(__FUNCTION__.": \r\r\r****************************");
		$text = preg_replace('/<span id\=\"more\-\d+"><\/span>/', '<!--more-->\1', $text);
//$mb_api->write_log(__FUNCTION__.": text: $text");
  


//$mb_api->write_log(__FUNCTION__.": \r\r\r%%%%%%%%%%%%%%%%%%%%%%");

		// --------------------
		// CAPTION
		// Do first, so we can strip it from the text itself
		
		/* 	**** WHEN WE USE THE WORDPRESS FILTERING OVER IN post.php, set_content_value()
			then the caption is converted from [caption... to <div class="caption"....
			5/25/13 : I have turned that off, to get the raw text from the content! 
			So, now we have to get our caption a little differently.
		*/

		
		// With FILTERING ON:
		/*
		$caption = "";
		// Caption starts with the excerpt.
		if (trim($wp_page->excerpt)) {
			$caption = '<div class="caption">' . $wp_page->excerpt . '</div>';
		}
		
		// Now add picture captions to the caption
		// Let's number, them, too...
		$matches = array();
		// Look for:
		//<p class="wp-caption-text">Caption for the icon thang.</p>
		$has_captions = preg_match_all('/\<p class="wp-caption-text">(.*?)<\/p\>/i', $wp_page->content, $matches);
		if ($has_captions) {
			if (count($matches[1])>1)
				$capnum = 1;
			else
				$capnum = false;
			
			foreach ($matches[1] as $c) {
				$capnum ? $capnumvalue = $capnum++ : $capnumvalue = "";
				if ($c) {
					$caption .= '<div class="caption">' . $capnumvalue . ".&nbsp;".$c . '</div>';
				}
				$x = 1;
			}
			$text = preg_replace('/\<p class="wp-caption-text">.*?<\/p\>/i', "", $text);
			// Strip these, too:
			//<div id="attachment_705" class="wp-caption alignleft" style="width: 159px"></div>
			$text = preg_replace('/\<div id=".*? class="wp-caption.*?\/div\>/i', "", $text);

		}
		*/


//$mb_api->write_log(__FUNCTION__.": content: ". $wp_page->content_raw."\r\r\r" );

		
		// WITH FILTERING OFF:
		$caption = "";
		// Caption starts with the excerpt.
		if (trim($wp_page->excerpt)) {
			$caption = '<div class="caption">' . $wp_page->excerpt . '</div>';
		}
		
		// Now add picture captions to the caption
		// Let's number, them, too...
		$matches = array();
		// Look for:
		//<p class="wp-caption-text">Caption for the icon thang.</p>
		$has_captions = preg_match_all('/\[caption.*?\](.*?)\[\/caption\]/i', $wp_page->content_raw, $matches);
//$mb_api->write_log(__FUNCTION__.": content: ". $wp_page->content."\r\r\r" );
//$mb_api->write_log(__FUNCTION__.": has captions? ".print_r($matches[1], true) );
		if ($has_captions) {
			if (count($matches[1])>1)
				$capnum = 1;
			else
				$capnum = false;
			
			foreach ($matches[1] as $c) {
				$capnum ? $capnumvalue = $capnum++ : $capnumvalue = "";
				if ($c) {
					// Strip the img from the caption...
					$c = preg_replace('/\<img.*?\/\>/i', "", $c);
					$caption .= '<div class="caption">' . $capnumvalue . ".&nbsp;".$c . '</div>';
				}
				$x = 1;
			}
			$text = preg_replace('/\[caption.*?\[\/caption\]/i', "", $text);
			// Strip these, too:
			//<div id="attachment_705" class="wp-caption alignleft" style="width: 159px"></div>
			$text = preg_replace('/\<div id=".*? class="wp-caption.*?\/div\>/i', "", $text);

		}
		
		// -------
		
		
		if ($caption) {
			$caption = array('@cdata'=>$caption);
		} else {
			$caption = "";
		}
		
//$mb_api->write_log(__FUNCTION__.": CAPTIONS: ".print_r($caption, true) );
		
		// --------------------
		// Do NOT include a title or text if it begins with the special 'do not include' marker!
		// The marker is ... $mb_api->ignore_me_code
		$ignore_me = '/^'.$mb_api->ignore_me_code.'/';
		
		$title = trim($title);

		if (preg_match($ignore_me, $title)) {
			$title = "";
		}

		if (preg_match($ignore_me, $text)) {
			$text = "";
		}
		
		// delete embedded stuff like img or a
		$text = str_replace( "\xC2\xA0", " ", $text);
		$text = $this->delete_embedded_media($text);
		$text = trim($text);
		
		// To make CDATA work with the XML generator we are using, we need to
		// wrap the text thus. Unless it is blank, of course.
		// Note, the order matters!
		$textblock = array ();
		
		// The first method only adds text/title when they have data.
		// That means a template page has to perfectly match, i.e. the template
		// cannot have a title if the page does, or text goes in the wrong places.
		
		// Method #1
		/*
			if ($title && $title != "")
				$textblock[] = array( 'title' => array('@cdata'=>$title) ) ;
			if ($text && $text != "")
			$textblock[] = array( 'text' => array('@cdata'=>$text) );
		
		*/
		
		
		// The second method always has text + title, meaning all template
		// pages must have both. The author can always 'blank out' one or the
		// other using the '###' mark at the beginning of the block.
		
		// We use the WordPress "MORE" break, which creates code <!--more--> to 
		// indicate that the text after the break goes into the next text block in the template.
		
		// The Title must be its own textblock because that's how InDesign templates end up
		// being created...with the title in its own block. No other reason it has to be this way.
		
		// Method #2

		if ($title || $text) {
		
			$text_chunks = explode("<!--more-->", $text);
			$textblocks = array ();
			$textblocks['textblock'] = array();

			if ($title != "" ) {
				$attr = array (
					"isHTML"	=> true
				);
			
				$textblocks['textblock'][] = array (
					'title' => array(
						'@attributes'		=> $attr,
						'@cdata'			=>$title
					)
				);
			} else {
				$textblocks['textblock'][] = array (
					'title' => ""
					);
			}

		
			foreach ($text_chunks as $t) {
				$t = trim($t);
				// This adds <p> and good stuff like that!!!! Woohoo!!
				$t = apply_filters('the_content', $t);
     // $content = str_replace(']]>', ']]&gt;', $content);
//$mb_api->write_log(__FUNCTION__.": t = {$t}");
				//$t = "<p>" . preg_replace('/[\r\n]/', "</p>\n<p>", $t) . "</p>";
				//$t = preg_replace('/\n?(.+?)(\n\n|\z)/s', "</p>\1<p>", $t);

				//$t = "<p>" . str_replace("\n", "</p><p>", $t) . "</p>";
				//$t = str_replace("\r", "", $t);


//$mb_api->write_log(__FUNCTION__.": t = ###{$t}###");

				$attr = array (
					"isHTML"	=> true
				);
			
				$textblocks['textblock'][] = array (
					'text' => array(
						'@attributes'		=> $attr,
						'@cdata' 			=> trim($t)
					)
				);
			}

		} else {
			$textblocks = array();
		}

//$mb_api->write_log(__FUNCTION__.": textblocks:".print_r($textblocks,true));

		/*
		$textblock = array (
				array ('title' => array('@cdata'=>$title) ), 
				array( 'text' => array('@cdata'=>$text) )
			);

		if ($textblock) {
			$textblocks = array(
				"textblock" => $textblock
			);
		} else {
			$textblocks = array();
		}
		*/	
		
		
		// This uses the built-in WP formats:
		//$wp_page->format_id ? $mb_template_id = $wp_page->format_id : $mb_template_id = "";
		
		// This uses page format ID's from the themes
		$themePageID = get_post_meta($wp_page->id, "mb_book_theme_page_id", true);
		
		// We want to minimize loading this...it can be slow.
		if (!$mb_api->themes->themes) {
			$mb_api->load_themes();
		}
		$themePageIDList = $mb_api->themes->themes[$this->theme_id]->details->format_ids;
		
		// If we don't have a theme template page assigned, use the first one in the list
		if (!$themePageID || !array_search($themePageID, $themePageIDList)) {
			$themePageID = $themePageIDList[0];
		}

		// True if this page uses a Table of Contents format, which makes it a contents page!
		//$themePageIsTOCList = $mb_api->themes->themes[$this->theme_id]->details->format_is_toc;
		
		
		// True if this page uses a Table of Contents format, which makes it a contents page!
		if ($mb_api->themes->themes[$this->theme_id]->details->format_is_toc[$themePageID])
			$attr["contents"] = "true";
		
		
		// IF this is a table of contents page, add that to the attributes
// 		if (isset($themePageIsTOCList[$themePageID])) {
// 			$attr["contents"] = $themePageIsTOCList[$themePageID];
// 		}
		
		
		// ----
		// Add custom field values
		$fields = array();
		foreach ($mb_api->themes->themes[$this->theme_id]->details->custom_fields as $fieldname) {

			// A corrupt or poorly made template (?) might have empty custom fields!
			// This will crash, so check for it.
			if ($fieldname) {
				$t = get_post_meta($wp_page->id, "mb_custom_".$fieldname, true);
				$t = trim($t);
				if ($t == "") {
					$fields[$fieldname] = "";
				} else {
					$fields[$fieldname] = array('@cdata'=>$t);
				}
			}
			
		}
		
		// Add the page's tags in a custom field called "tags"
		$tags = array();
		foreach ($wp_page->tags as $tag) {
		
			//$mb_api->write_log(print_r($tag,true));
		
			$tags[] = $tag->title;
		}
		$fields["tags"] = implode ( "," , $tags );

		
		// Post label for the navigation bar
		$nav_label = get_post_meta($wp_page->id, "mb_page_nav_label", true);
		
		// Use the slug as the ID or simply the post ID
		$wp_page->slug ? $attr['id'] = $wp_page->slug : $attr['id'] = $wp_page->id;
		
		// Book map page? Then set the page id to "map"
		if (get_post_meta($wp_page->id, "mb_page_is_map", true) ) {
			$attr['id'] = "map";
		}
				
		// Show page in the table of contents?
		if ($title && get_post_meta($wp_page->id, "mb_show_page_in_contents", true) ) {
			$attr['contentstitle'] = $title;
		}
		
		//Assign page values
		// Do NOT assign blank ones! Blank entries will overwrite template
		// entries, for backgrounds or whatever. Blank entries should only happen
		// when the author wants the entry blank!
		$page = array (
			'@attributes'		=> $attr,
			'title'				=> $title,
			'altTitle'			=> $category->name,
			//'backgroundfile'	=> '',
			//'audiofile'		=> '',
			//'video'			=> '',
			'textblocks'		=> $textblocks,
			'fields'				=> $fields,
			'template'			=> $themePageID,
			'caption'			=> $caption,
			'label'				=> $nav_label
			);
			

			
//$mb_api->write_log(print_r($attr,true));

		// Add a table of contents element to the page
// 		if (isset($themePageIsTOCList[$themePageID])) {
// 			$page['tableofcontents'] = "";
// 		}

		
		// GET EMBEDDED PICTURES (NOT ATTACHED)
		// Attached items are not necessarily embedded.
		// Some images may be attached but not really used on the page. 
		// Therefore, we should not include any images using this function,
		// but rather let the get_embedded function handle those.

		// Get EMBEDDED elements in the post's HTML,
		// Convert the embedded elements, e.g. img tags, to MB format arrays
		$embedded = $this->get_embedded_pictures($wp_page);
		$embedded && $page = array_merge($page, $embedded);

		// These are pictures actually embedded on pages
		$embedded_pictures = $embedded;

		$embedded = $this->get_embedded_audio($wp_page);
		$embedded && $page = array_merge($page, $embedded);

		$embedded = $this->get_embedded_video($wp_page);
		$embedded && $page = array_merge($page, $embedded);
		
		
	//$mb_api->write_log(__FUNCTION__.": EMBEDDED PICTURES:" . print_r($embedded_pictures['pictures']['picture'], true));
		

		// Get the base file names of the pictures we've got for this page
		if (isset($embedded_pictures['pictures'])) {
			if (isset( $embedded_pictures['pictures']['picture']) ) {
				$embedded_pictures = $embedded_pictures['pictures']['picture'];
			}
		}

		$pictures_to_exclude = array();
		for ($i=0; $i<count($embedded_pictures); $i++) {
			if (isset($embedded_pictures[$i]['filename'])) {
				$this->attached_items_already_on_pages[] = basename ($embedded_pictures[$i]['filename']) ;
			}
		}
		
//$mb_api->write_log(__FUNCTION__.": pictures_to_exclude:" . print_r($this->attached_items_already_on_pages, true));
		
		
		// Now, copy all other attachments to this page to the pictures folder
		// This method ain't great, but it should avoid getting ALL attachments to all pages
		// and not just the ones for this book.
		$this->get_attachments ($wp_page, $this->attached_items_already_on_pages, true);


		return $page;
	}


	
	/*
	 * This should process all attachments that are not in the exclude list.
	 * Note: we need to set $include_images to true to get images.
	 * This is for getting attached files that aren't embedded on pages, so
	 * we can add files to the "pictures" folder without putting them on a page.
	 * Instead, we would use the Attachments plugin (or perhaps simply upload them!)
	*/
	private function get_all_attachments ($exclude_image_list, $include_images = false)
	{
		global $mb_api;
			
		$args = array(
			'post_type' => 'attachment',
			'posts_per_page' => -1,
			'post_status' => null
		);
		$attachments = get_posts( $args );

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
					
					if (isset($image_attrs[$attachment->ID])) {
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
				


				// Exclude any file in the exclude list, by name.					
				if ( !in_array(basename($attributes['src']), $exclude_image_list) ) {
//$mb_api->write_log(__FUNCTION__.": Added attached picture not on page:" . basename($attributes['src']));
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
				} else {
//$mb_api->write_log(__FUNCTION__.": Ignored attached picture:" . basename($attributes['src']));
				}
			}
		}

		// Now, gather them into MB format 
		// img ---> picture
		
		//print_r($page_elements);
		return $page_elements;
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
	private function get_attachments ($wp_page, $exclude_image_list, $include_images = false)
	{
		global $mb_api;
			
		$args = array(
			'post_type' => 'attachment',
			'posts_per_page' => -1,
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

/*		
print ("-----------\n");
print_r($image_attrs);
print ("Attachments\n");
print_r($attachments);
print ("-----------\n");
*/
		$page_elements = array();
		if ( $attachments ) {
			foreach ( $attachments as $attachment ) {

				// Avoid time-out
				//ob_start();
			
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
					
					if (isset($image_attrs[$attachment->ID])) {
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
				


				// Exclude any file in the exclude list, by name.					
				if ( !in_array(basename($attributes['src']), $exclude_image_list) ) {
//$mb_api->write_log(__FUNCTION__.": Added attached picture not on page:" . basename($attributes['src']));
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
				} else {
//$mb_api->write_log(__FUNCTION__.": Ignored attached picture:" . basename($attributes['src']));
				}
				
				// Avoid time-out!
// 				echo ob_get_contents();
// 				ob_end_flush();
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
	
	private function get_embedded_audio($wp_page) {
		$elements = $this->get_embedded_elements($wp_page, "a", "audiofile");
		return $elements;
	}
	
	private function get_embedded_video($wp_page) {
		$elements = $this->get_embedded_elements($wp_page, "a", "videofile");
		return $elements;
	}
	
	
	private function display_xml_error($error, $xml)
	{
		$return  = $xml[$error->line - 1] . "\n";
		$return .= str_repeat('-', $error->column) . "^\n";

		switch ($error->level) {
			case LIBXML_ERR_WARNING:
				$return .= "Warning $error->code: ";
				break;
			 case LIBXML_ERR_ERROR:
				$return .= "Error $error->code: ";
				break;
			case LIBXML_ERR_FATAL:
				$return .= "Fatal Error $error->code: ";
				break;
		}

		$return .= trim($error->message) .
				   "\n  Line: $error->line" .
				   "\n  Column: $error->column";
				   
		$substr = substr($xml, $error->column-20, $error->column+20);
		$return .= "\n Sample: [ $substr ]";

		if ($error->file) {
			$return .= "\n  File: $error->file";
		}

		return "$return\n\n--------------------------------------------\n\n";
	}


	/*
	 * NOTE: this works great with images, but really not with other kinds of
	 * attachments! Audio for example, uses <a> as its tag, so we would
	 * have to check the linked file to know what we were dealing with.
	 *
	 * Worse, both audio and video could have the same extension. There's no way to
	 * tell whether myfile.mp4 is audio or video without checking the file itself!
	 *
	*/
	private function get_embedded_elements($wp_page, $element_type="", $subtype = "") {
		global $mb_api;
		
		
		
		$text = $wp_page->content;
		// This worked before...
		$text = apply_filters('the_content', $text);
				
		if (!$text) {
			return null;
		}
		
		// Use PHP XML/HTML extract functionality!
		libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$doc->loadHTML($text);
		$errors = libxml_get_errors();

		if (!$doc) {
			$errors = libxml_get_errors();
			foreach ($errors as $error) {
				$mb_api->write_log(  __FILE__ . ":" . __FUNCTION__.":". __LINE__ . ":" . $this->display_xml_error($error, $text) );
			}
			libxml_clear_errors();
		}
		
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
			if (isset($attributes['class'])) {
				if (preg_match("/wp-image-([0-9]*)/", $attributes['class'], $matches) === false) {
					$id = '';
					$mb_api->write_log (__FUNCTION__ . ": ERROR: No ID found for this element: ". $attributes['class']);
						$mb_api->send_ajax_update ( $this->id, array (
							'message' 	=> "<span style='color:red;'>Error: Missing picture, no ID found for HTML img element!</span>",
							'error' => true
						));
				} else {
					$id = $matches[1];
				}
				
			} else {
				$id = '';
			}
			$attributes['id'] = $id;
			
//$mb_api->write_log (__FUNCTION__ . ": element = " . print_r($node, true));

			// SUBTYPE? AUDIO/VIDEO, etc.
			// Check if this is a tag which links to audio, video, etc., which
			// means a subtype. Such tags are <a href="myfile.mp3"...> so we need to check
			// the content of the file it links to.
			switch ($subtype) {
				case "audiofile" :
					$pp = pathinfo($attributes["href"]);
					$ext = $pp['extension'];
					$basename = $pp['basename'];
					$element['name'] = "audiofile";
					$element['value'] = $basename;
					break;
				case "videofile" :
					$pp = pathinfo($attributes["href"]);
					$ext = $pp['extension'];
					$basename = $pp['basename'];
					$element['name'] = "videofile";
					$element['value'] = $basename;
					break;
				default :
					// Default element file extension is blank
					$ext = "";
			}
//$mb_api->write_log(__FUNCTION__.": element['name']: {$element['name']}");
//$mb_api->write_log(__FUNCTION__."----\n\n");
			
			
			// Strip text from the id
			// TO DO
			
			// SET IMAGE ALIGNMENT BASED ON THE CLASS.
			// This works PERFECTLY, however: 
			// PROBLEM: what works on the blog sucks in some templates.
			// Most likely, we want the template to take care of this.
			// This allows really cool things, like fitting into a centered 
			// area, but we'd better figure a different way to have a template 
			// center a picture.
			
			// Get the alignment from the class
			// aligncenter, alignnone, alignright, alignleft(?)
			/*
			$p = "/align(\w+)/i";
			if (preg_match($p, $attributes['class'], $matches)) {
				$alignment = $matches[1];
				if ($alignment != "none")
					$attributes['x'] = $alignment;
			} else {
				$alignment = "";
			}
			*/

			$element['attributes'] = $attributes;
			
			// Add in all post info for the item
			//$post = get_post( $id, ARRAY_A );
			
			// DON'T add the actual element to the page. Let's only return 
			// elements suitable for use by MB.php.
			//$page_elements[0+$id] = array_merge($post,$attributes);

			// Handle MB's need to encapsulate, e.g. if we have an 'img', 
			// then we need to create a <pictures> element to hold
			// the <picture> which is the 'img'.
			// Or, we might figure out what the thing is based on the file extension,
			// if it is an <a href="myfile.mp4", for example.
			list($mb_name, $mb_encaps_name)  = $this->name_for_element($element['name'], $ext);

			// If this isn't an element type we're searching for (e.g. audio, video) then
			// we should ignore it. It is common to have links to full-sized images surrounding
			// images, and we don't want those.
			if ( !$subtype || ($subtype == $mb_name) )  {			


				$e = $this->element_to_mb($element);
				if ($e && $mb_encaps_name) {
					if (!isset($page_elements[$mb_encaps_name])) {
						$page_elements[$mb_encaps_name] = array ();
					}
					$page_elements[$mb_encaps_name][$mb_name][] = $e;
				} else if ($e) {
					$page_elements[$mb_name] = $e;
				}
// 				} else if ($element) {
// 					$page_elements[$mb_name] = $this->element_to_mb($element);
// 				}
			}
		}
	

//$mb_api->write_log (__FUNCTION__ . ":Page Elements = " . print_r($page_elements, true) . "\n\n");
		return $page_elements;
	}
	

	/*
	 * Read the HTML of the post text and figure out neat stuff from it about
	 * embedded elements, such as height/width of the embedded image.
	 * It seems that just getting the attachment info won't get us here.
	*/
	private function get_embedded_element_attributes($wp_page, $element_type="img") {
		$text = $wp_page->content;
		if (!$text) {
			return array();
		}
		
		// Use PHP XML/HTML extract functionality!
		$doc = new DOMDocument();
		$doc->loadHTML($text);
		
		$page_elements = array();
		// Get all elements in the HTML
		foreach ($doc->getElementsbytagname($element_type) as $node) {

			$attributes = array();
			foreach ($node->attributes as $attr) {
				$attributes[$attr->name] = $attr->nodeValue;
			}
			$id = preg_replace("/.*?wp-image-/", "", $attributes['class']);
			$attributes['id'] = $id;
			
			$post = get_post( $id, ARRAY_A );
			//is_array($post) || $post = array();
			if (is_array($post)) {
				$page_elements[0+$id] = array_merge($post,$attributes);
			}
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
	private function element_to_mb($element = null) {
		global $mb_api;
		
		$testing = false;
		
		if (!$element)
			return null;

		$attr = $element['attributes'];
		$mb_element = array ();
		
		// Name could be an html name (img, audio) or it could be a MIME type (image/jpeg).
		// Modify the name to capture the right type for multiple formats, e.g. all images
		$name = preg_replace('|/.*$|', '', $element['name']);


//$mb_api->write_log(__FUNCTION__.": element name = $name" . print_r($element,true) );
		
		switch ($name) {
			case "image":
			
			/*
			Do NOT assign width, height, etc. These are specified by the template. 
			Why? Because it is unlikely the images in the blog are the right size for the
			template, so it would be a bad idea to force the template to use the blog images.
			That would only make sense if the only purpose of the blog was to build the book.
			
			Assign width,height,x if they are set and NOT empty.
			*/
			case "img" :
				
				/*
				if (isset($attr['width']) && $attr['width'] != "")
					$mb_element['width'] = $attr['width'];
					
				if (isset($attr['height']) && $attr['height'] != "")
					$mb_element['height'] = $attr['height'];
					
				if (isset($attr['x']) && $attr['x'] != "")
					$mb_element['x'] = $attr['x'];
				*/
				
//$mb_api->write_log(__FUNCTION__.": Look for post id : {$attr['id']}" );
				
				$post = get_post( $attr['id'], ARRAY_A );
				
				// If no post for this ID, let us look for the correct post
				// using the src URL we found in the embedded HTML element.
				// It can happen that an element is showing a valid image
				// because the URL is right, but the WP embedded ID (hidden as a class, wp-image-XXXX
				// where XXXX is the ID), is old and wrong, due to images being updated, replaced, etc.
				if (!$post) {
					$mb_api->write_log(__FUNCTION__.": WARNING: ID #".$attr['id'] . " is missing. Looking for the real post.");
//$mb_api->write_log(__FUNCTION__.": *** Element Attributes: " . print_r($attr, true) );
					
					$id = $this->fjarrett_get_attachment_id_by_url($attr['src']);
					if ($id)
						$post = get_post( $id, ARRAY_A );

					if (!$post) {
						$mb_api->write_log(__FUNCTION__.": ERROR: Could not find a post for the image: {$attr['src']}. This image is ignored." );
						$mb_api->send_ajax_update ( $this->id, array (
							'message' 	=> "<span style='color:red;'>ERROR: Picture in the HTML text (".$attr['src'].") is not part of this WordPress system. If you can see it, it must be located elsewhere or is not a WordPress media item.</span>",
							'error' => true
						));
						return;
					} else {
						$mb_api->write_log(__FUNCTION__.": WARNING: Fix this post! The media image in the HTML text has an old id. Better to insert the media again so the ID is current." );
						// USER FEEDBACK: Update the publishing status, and send an update via AJAX
						$mb_api->send_ajax_update ( $this->id, array (
							'message' 	=> "<span style='color:red;'>Warning: Picture in the HTML text (".basename($attr['src']).") has old ID hidden in the wp-image class! You should fix this by removing the image, then inserting again in your text. This is a weird effect of replacing, moving, or updating images in WordPress.</span>",
							'error' => true
						));

					
					}

				}
				
				// URL version
				// src URL of the original file, NOT a file name, not a resized file
				/*
				$src = $post['guid'];				
				$mb_element['filename'] = "*" . DIRECTORY_SEPARATOR . $this->pictureFolder . basename( $src );
				*/

				// Filename version
				// Path to the original file
				$attachment_id = $post['ID'];
				
				// WRONG image ID?
				// If the "wp-image-XXX" class that we use to discover the ID of the <img> tag in the
				// html of the page is wrong, the we don't know the correct ID of the image!
				// Weirdly enough, this can happen. In that case, it's really hard to figure out what to do.
				// Either the user has to remove the image (which will appear, since the URL is correct)
				// and insert it again, OR we have to figure out the right image!
				if (!$attachment_id) {


//$mb_api->write_log(__FUNCTION__.": Image Post fields:" . print_r($post, true) );


				}
				
				// Full path to file
				$src = get_attached_file( $attachment_id ); 
				
//$mb_api->write_log(__FUNCTION__.": Image file name (src): $src" );
//$mb_api->write_log(__FUNCTION__.": Image Post fields:" . print_r($post, true) );
				
				if (!$src) {
				
//$mb_api->write_log(__FUNCTION__.": Image file name : $src" );
//$mb_api->write_log(__FUNCTION__.": Image Post fields:" . print_r($post, true) );
//$mb_api->write_log(__FUNCTION__.": *** Element Attributes: " . print_r($attr, true) );
				
					$src = $post['guid'];
				}
				
				if ($src == "") {
					//$mb_api->write_log(__FUNCTION__.": Missing source for image!" . print_r($element, true) );
					$mb_api->write_log(__FUNCTION__.": *** The source might a remote URL, which we cannot use: " . $element['attributes']['src'] );
					
					//$mb_api->write_log(__FUNCTION__.": Image Post fields:" . print_r($post, true) );
						// USER FEEDBACK: Update the publishing status, and send an update via AJAX
						$mb_api->send_ajax_update ( $this->id, array (
							'message' 	=> "<span style='color:red;'>Error: Missing picture:<br \> ({$element['attributes']['src']})</span>",
							'error' => true
						));

					return "";
				}
				

				$mb_element['filename'] = "*" . DIRECTORY_SEPARATOR . $this->pictureFolder . basename( $src );

				
				// zoomedScale is set by the templates, now! We won't worry about it here.
				//$mb_element['zoomedScale'] = "1";
				
				// haha, just for testing
				// $mb_element['addCorners'] = "true";
				
				// This is the SIZE OF THE PIC IN THE POST (that is, resized).
				// We don't use this because we want a pic to fit the template,
				// so we take a screen-size pic knowing it won't be bigger than that,
				// but not knowing just how big it should be.
				// Resize and convert the picture to one we can use, ready for packaging,
				// and put the copy in the pictures folder.
				//$w = $attr['width'];
				//$h = $attr['height'];
				
				$metadata = wp_get_attachment_metadata( $attachment_id );
				$w = $metadata['width'];
				$h = $metadata['height'];

				//list($w,$h) = getimagesize($src);

// $mb_api->write_log(__FUNCTION__.": Image Filename and Size: $src, w=$w, h=$h");
// $mb_api->write_log(__FUNCTION__.": Filename for book: {$mb_element['filename']}");
// $mb_api->write_log(__FUNCTION__."---");

				
				$targetW = $this->options['dimensions']['width'];
				$targetH = $this->options['dimensions']['height'];
				
				/*
				// Don't need this, the resizer uses max height/width to fit 
				// the resize proportionately.
				if ($w > $h) {
					$newW = $targetW;
					$newH = round(($targetW / $w) * $h);
				} else {
					$newH = $targetH;
					$newW = round( ($targetH / $h) * $w);
				}
				 */
				
				$dir = $this->build_files_dir . $this->pictureFolder;
				if(! is_dir($dir)) {
					mkdir($dir);
				}

				$filename = basename($src);	
				$filepath = $dir.$filename;

				// Save normal sized image
				$image = wp_get_image_editor( $src );
				if (! is_wp_error($image) ) {

$testing && $time_start = microtime(true);
//$mb_api->write_log(__FUNCTION__."---BEGIN image resizing for: $filepath");

					// Add extra time for processing. This takes whereever we were in the 30 sec. default counter, 
					// and adds an addition 30 seconds from right now.
					set_time_limit ( 30 );
				
					// RESIZE: ENLARGE TO FIT TEMPLATE???
					// This resizes when it is smaller the target area.
					/*
					if ( $w < $targetW && $h < $targetH ) {
$mb_api->write_log(__FUNCTION__.": Try to resize $filename from $w x $h ---> $targetW x $targetH.");
						$image->resize( $targetW, $targetH, false );	// false = no-crop
$mb_api->write_log(__FUNCTION__.": (success)");
					}
					*/
					
					// RESIZE: REDUCE TO FIT TEMPLATE???
					// This resizes when it is smaller the target area, not when it is too large!
					if ($w > $targetW || $h > $targetH) {			
//$mb_api->write_log(__FUNCTION__.": Try to resize $filename from $w x $h ---> $targetW x $targetH.");
						$image->resize( $targetW, $targetH, false );	// false = no-crop
//$mb_api->write_log(__FUNCTION__.": (success)");
					}
					
					
					$image->set_quality( 80 );
					$image->save($filepath);
										
					// Make card-sized pictures if this book can be used as a cards list
					// Save thumbnail 300x300 image
					// If this is not a card picture (doesn't end in "-card") then rename it to ...-card
					if ($this->is_card_list) {
//$mb_api->write_log(__FUNCTION__.": Try to resize $filename to card size (300x300).");
						$image->resize( 300, 300, false );	// false = no-crop
						$image->set_quality( 80 );

						$info = pathinfo($src);
						$ext = '.' . $info['extension'];
						$name = $info['filename'];
						if (!preg_match("/-card$/", $name)) {
							$name = $name."-card";
						}
						$filepath = $dir.$name.$ext;
						$image->save($filepath);
					}
					

// $time_end = microtime(true);
// $time = $time_end - $time_start;
// $mb_api->write_log("--- time for normal sized image = $time sec");


					// Save double-sized image
					if ($this->options['save2x'] && ( ($w > ($targetW*2) || ($h > ($targetH*2)) ) ) ) {
						$image = wp_get_image_editor( $src);
						if (! is_wp_error($image) ) {
//$mb_api->write_log(__FUNCTION__.": Try to resize $filename to @2x size.");
							$image->resize( $targetW*2, $targetH*2, false );	// false = no-crop
							$image->set_quality( 80 );

							$info = pathinfo($src);
							$ext = '.' . $info['extension'];
							$name = $info['filename'];
							$filepath = $dir.$name."@2x{$ext}";
							$image->save($filepath);
						} else {
							return null;
						}
					}

					//-----
					if ($testing) {
						$time_end = microtime(true);
						$time = round($time_end - $time_start, 2);
						$mb_api->write_log("--- resize time (normal + 2x) = \t\t$time sec");
						$mb_api->write_log("---");
					}
					//-----
					
				} else {
					return null;
				}				
				break;
			
			case "panorama" :
				break;
			
			case "link" :
				break;
			
			case "audiofile" :
				$mb_element['value'] = "*" . DIRECTORY_SEPARATOR . $this->audioFolder . basename($attr['href']);
				// Copy the audio file to the audio folder
				$success = $this->copy_audio_file($attr['href']);
				if (!$success)
					return null;
					
				break;

			case "videofile" :
				$mb_element['value'] = "*" . DIRECTORY_SEPARATOR . $this->videoFolder . basename($attr['href']);
				// Copy the audio file to the audio folder
				$success = $this->copy_video_file($attr['href']);
				if (!$success)
					return null;
					
				break;
		}
		return $mb_element;
	}




	// retrieves the attachment ID from the file URL
	private function get_image_id_from_guid($guid) {
		global $wpdb;
		$attachment = null;
		if ($guid)
			$attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $guid ));
		return !empty( $attachment ) ? $attachment[0] : null;
	}
	/**
	 * Return an ID of an attachment by searching the database with the file URL.
	 *
	 * Thanks to: https://gist.github.com/fjarrett/5544469
	 * 
	 * First checks to see if the $url is pointing to a file that exists in
	 * the wp-content directory. If so, then we search the database for a
	 * partial match consisting of the remaining path AFTER the wp-content
	 * directory. Finally, if a match is found the attachment ID will be
	 * returned.
	 *
	 * @param string $url The URL of the image (ex: http://mysite.com/wp-content/uploads/2013/05/test-image.jpg)
	 * 
	 * @return int|null $attachment Returns an attachment ID, or null if no attachment is found
	 */
	function fjarrett_get_attachment_id_by_url( $url ) {
		// Split the $url into two parts with the wp-content directory as the separator
		$parsed_url  = explode( parse_url( WP_CONTENT_URL, PHP_URL_PATH ), $url );
		// Get the host of the current site and the host of the $url, ignoring www
		$this_host = str_ireplace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) );
		$file_host = str_ireplace( 'www.', '', parse_url( $url, PHP_URL_HOST ) );
		// Return nothing if there aren't any $url parts or if the current host and $url host do not match
		if ( ! isset( $parsed_url[1] ) || empty( $parsed_url[1] ) || ( $this_host != $file_host ) ) {
			return;
		}
		// Now we're going to quickly search the DB for any attachment GUID with a partial path match
		// Example: /uploads/2013/05/test-image.jpg
		global $wpdb;
		$attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}posts WHERE guid RLIKE %s;", $parsed_url[1] ) );
		// Returns null if no attachment is found
		return $attachment[0];
	}


	/*
	 * Copy an audio file to the proper folder
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
	 * Copy a video file to the proper folder
	 */
	private function copy_video_file($src)
	{

		$dir = $this->build_files_dir . $this->videoFolder;
		if(! is_dir($dir)) {
			mkdir($dir);
		}

		$filename = basename($src);	
		$filepath = $dir.$filename;
		$name = basename ($src);
		
		$success = copy($src, $filepath);
		if (!$success) {
			$this->isError("Could not copy the video file to {$mb_element['filename']}");
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
			//write_log(__FUNCTION__.": Resize using GD");
			$success = ResizeImage($slide_size, $filepath, "$filepath", $default_border, $watermark);
			if (!$success) return false;

		}
		else	// using ImageMagick
		{
			//write_log(__FUNCTION__.": Resize using ImageMagick");
			$quality = " -quality " .$FP_IMAGEMAGICK_QUALITY;
			// unshapr mask radius=.5, sigma=.5, amount=1.2, threshhold=0.05
			$sharpen = " -unsharp .5x.5+1.2+0.05";
			$basics = " -units 'pixelsperinch' -density '72x72'";
			// let's see if we can not have our own profile lying around.
			//$profile = " -intent Perceptual -profile '$BASEDIR/$FP_PROFILE_SRGB'";
			$profile = "";
			$filter = "";
			
			//$filepath = preg_replace("/ /","\ ", $filepath);

			$cmd = $FP_IM_CONVERT . " -colorspace LAB '{$filepath}' $basics $quality $sharpen $filter $profile -colorspace sRGB -strip ";
			if ($width && $height) {
				$cmd .= " -resize '{$width}x{$height}>' '{$filepath}'";
			} else {
				$cmd .= " -write '{$filepath}'";
			}

			exec ($cmd, $output, $response);
			//write_log(__FUNCTION__.":".print_r($cmd,true));
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
	 * Some elements are part of <a> tags, and we have to pass the file extension to figure out
	 * what the file is.
	 * 
	 * Not encapsulated MB items:
	 * imagefile, audiofile, backgroundfile, video, overlay, textblock
	 * 
	 * Encapsulated MB items:
	 * picture, panorama, button, link
	 * Supported video formats are platform- and version-dependent. 
	 * The iPhone video player supports playback of movie files with the .mov, .mp4, .m4v, and .3gp filename extensions.
	 * 

	 */
	private function name_for_element($e, $extension = "") {
		$mb_name = array ("","");
		switch ($e) {
			case 'img' : $mb_name = array ("picture", "pictures");
				break;
			case 'div' : $mb_name = array ("shape", "shapes");
				break;
			case 'panorama' : $mb_name = array ("panorama", "buttons");
				break;
			case 'audiofile' : 
				// AUDIO?
				if (in_array($extension, array("mp3","aac", "m4a", "wav"))) {
					$mb_name = array ("audiofile", null);
				}
				break;
			case 'videofile' : 
				// VIDEO?
				if (in_array($extension, array("mp4","mov", "m4v", "3gp"))) {
					$mb_name = array ("videofile", null);
				}
				break;
			case 'a' : 
				// AUDIO?
				if (in_array($extension, array("mp3","aac", "m4a", "wav"))) {
					$mb_name = array ("audiofile", null);
				// VIDEO?
				} else if (in_array($extension, array("mp4","mov", "m4v", "3gp"))) {
					$mb_name = array ("videofile", null);
				// LINKS
				} else {
					$mb_name = array ("link", "links");
				}
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
			
			case 'video/mp4' : $mb_name = array ("videofile", null);
				break;
			case 'video/mov' : $mb_name = array ("videofile", null);
				break;
			case 'video/m4v' : $mb_name = array ("videofile", null);
				break;
			case 'video/3gp' : $mb_name = array ("videofile", null);
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
		 
		 function __construct() {
			$this->chapters = array();
		 }
		 
	 }

	 /*
	  * Errors
	  */

	
	
	
	
	 
	 
if (class_exists('PEAR_Error')) {

    class Services_MB_Error extends PEAR_Error
    {
        function __construct($message = 'unknown error', $code = null,
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
        function __construct($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {

        }
    }


}
   
?>