<?php

/*
	Convert a chapter array to a Mimetic Books chapter array
	that can be easily exported as XML.
	
	Array2XML from:	http://www.lalit.org/lab/convert-php-array-to-xml-with-attributes/
	
*/

class MB_Chapter
{
	// $chapter : ARRAY of a chapter in mb-api format, converted from Wordpress.
	public $chapter;
	
	/* 
	Constructor 
	 * Convert a chapter from an mb-api array, a special format with all the 
	 * goodies from Wordpress, into a chapter array ready for XML exporting 
	 * in Mimetic Books format.
	 * 
	 * Here's the format of the $wp_chapter array.
		$wp_chapter = array (
			id 			=> INTEGER: id of the WP category of posts in this chapter
			title		=> STRING: title of the WP category, e.g. "Chapter 1"
			category	=> OBJECT: a WP category object -- the category for this chapter
			pages		=> ARRAY: an array of posts. Each post is in MB_API_Post format,
							a fancier object than the basic WP post object.
							
	DTD definition of a MB chapter :
		<!ELEMENT chapter ( id? | page | title? )* >
		<!ATTLIST chapter hasCaptions NMTOKEN #IMPLIED >
		<!ATTLIST chapter hasOverlays NMTOKEN #IMPLIED >
		<!ATTLIST chapter navbarType NMTOKEN #IMPLIED >  slider or timeline
		<!ATTLIST chapter pickerLabelGroupBy NMTOKEN #IMPLIED > (possible values: year, month, day, text)

	*/
 	function MB_Chapter($wp_chapter)
	{
		
		require_once "Array2XML.php";
		$Array2XML = new Array2XML();

		// WP category object
        $category = $wp_chapter['category'];
		
		$attr = array(
				'id'					=> $category->term_id,
				'hasCaptions'			=> 'false',
				'hasOverlays'			=> 'false',
				'navbarType'			=> 'slider',
				'pickerLabelGroupBy'	=> 'text'
				);
		
		$chapter = array (
			'@attributes'	=> $attr,
			'title'			=> $wp_chapter['title'],
			'id'			=> $wp_chapter['title'],
			'title'			=> $wp_chapter['title']
			);
								


		$xml = Array2XML::createXML('chapter', $chapter);
		echo $xml->saveXML();
	}

}

?>