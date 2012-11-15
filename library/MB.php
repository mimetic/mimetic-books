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
    function Mimetic_Book($id, $title, $author, $publisher_id, $use = 0)
    {
    	$this->id = ($id ? $id : "mb_".uniqid() );
    	$this->title = ($title ? $title : "Untitled");
    	$this->author = ($author ? $author : "Anonymous");
    	$this->publisher_id = $publisher_id;
		$this->book = array();
		
		// options left over from previous code I based this on...UNUSED.
        $this->use = $use;
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
	<!ATTLIST chapter hasCaptions NMTOKEN #IMPLIED >
	<!ATTLIST chapter hasOverlays NMTOKEN #IMPLIED >
	<!ATTLIST chapter navbarType NMTOKEN #IMPLIED >
	<!ATTLIST chapter pickerLabelGroupBy NMTOKEN #IMPLIED >


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
				'pickerLabelGroupBy'	=> 'text'
				);
		$chapter_id = $category->term_id;
		$chapter = array (
			'@attributes'	=> $attr,
			'title'			=> $category->name,
			'id'			=> $category->term_id,
			'altTitle'		=> $category->name,
			'index'			=> $category->term_order,
			'page'			=> $pages
			);

        $this->book->chapters[$chapter_id ] = $chapter;
	}

	private function convert_pages ($wp_posts)
	{
		$pages = array();
		foreach ($wp_posts as $page) {
			$pages[] = $this->convert_page($page);
		}
		return $pages;
	}
	
	private function convert_page ($wp_page)
	{
		return $wp_page;
	}


   /**
    * array-walking function for use in generating JSON-formatted name-value pairs
    *
    * @param    string  $name   name of key to use
    * @param    mixed   $value  reference to an array element to be encoded
    *
    * @return   string  JSON-formatted name-value pair, like '"name":value'
    * @access   private
    */
    function name_value($name, $value)
    {
        $encoded_value = $this->_encode($value);

        if(Services_MB::isError($encoded_value)) {
            return $encoded_value;
        }

        return $this->_encode(strval($name)) . ':' . $encoded_value;
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
}

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