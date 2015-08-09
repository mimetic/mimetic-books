<?php

/*
	Convert a chapter array to a Mimetic Books chapter array
	that can be easily exported as XML.
	
	Array2XML from:	http://www.lalit.org/lab/convert-php-array-to-xml-with-attributes/
	
*/

class MB_API_Themes
{
	public $themes, $themes_list;
	
	
	
	/* 
	Constructor 
	*/
 	function MB_API_Themes($dir)
	{
		$this->themes = array();
		$this->themes_list = array();
		$this->themes_dir = $dir;
		
		define ("MB_SNIPPETSDIR", "snippets");
		define ("MB_PREVIEWDIR", "preview");
		define ("MB_THEME_VARIATION_DIR", "variations");
		
	}
	
	
	
	// Loads themes from disk files into arrays
	// This can be hundreds of files (mostly code snippets).
	// Set the themes directory if parameter given.
	function LoadAllThemes ($themes_dir = "") {
		global $mb_api;
		
		if ($themes_dir) {
			$this->themes_dir = $themes_dir;
		} else {
			$themes_dir = $this->themes_dir;
		}
		
		$themedirs = glob ($themes_dir."/*", GLOB_ONLYDIR);
		
		// Build array of theme objects which contain all theme info.
		foreach ( $themedirs as $themepath) {
			$this->LoadTheme($themepath);
		}
		
		//$mb_api->write_log(print_r($this->themes_list,true));
	}

	/*
	Load a theme from disk, using the path, e.g. mytheme1001
	It loads the theme into $this->themes, $this->themes_list

	Local over-rides:
	- FP_DIR_USER ("_user") directory may contain files to replace those in _themes
	- They must be in exactly the same path inside of FP_DIR_USER, 
		e.g. _user/_themes/default/_snippets/frameshop/frameshop_shipping_popup.txt
	- The user, site-wide vocabulary list is also in _user/vocabulary.txt. Entries in this are merged with
	entries in the _themes/vocabulary.txt lists, overriding them.
	*/
	function LoadTheme ($themepath) {
		global $mb_api;
		
		$infoFileName = "theme.json";
		if (file_exists("$themepath/$infoFileName")) {
			$f = file_get_contents ("$themepath/$infoFileName");
			//This will convert ASCII/ISO-8859-1 to UTF-8.
			//Be careful with the third parameter (encoding detect list), because
			//if set wrong, some input encodings will get garbled (including UTF-8!)
			$f = mb_convert_encoding($f, 'UTF-8', 'ASCII,UTF-8,ISO-8859-1');
			//Remove UTF-8 BOM if present, json_decode() does not like it.
			if(substr($f, 0, 3) == pack("CCC", 0xEF, 0xBB, 0xBF)) $f = substr($f, 3);
			
			$myTheme = json_decode ($f);
			if (isset($myTheme->disabled) && $myTheme->disabled == true)
				return;

			$myTheme->id = str_replace (":","_",$myTheme->id);	// just making sure there are no ":" in the ID!
			
			// Get details of the theme:
			// 'format_ids' : format ID's to build a list of page templates in a theme
			// 'format_is_toc_by_id'	: true if a given page template is a table of contents page
			$myTheme->details = $this->LoadFormatDetails ($themepath, $myTheme);	

			// Save the disk path of the theme
			$myTheme->path = $themepath;

			// Save the disk path of the theme
			$myTheme->folder = basename($themepath);

			// Load all code snippets in _snippets AND subdirectories (handy for organization)
			$snippets = array ();
			$snippetdirs = glob ($themepath . "/" . MB_SNIPPETSDIR . "/*", GLOB_ONLYDIR);
			$snippetdirs[] = ".";

			foreach ($snippetdirs as $dir) {
				$dir = basename ($dir);
				$files = glob ($themepath . "/" . MB_SNIPPETSDIR . "/$dir/*.txt");
				if ($files) {
					foreach ($files as $fn) {
						// trim 3 or 4 char extension to get snippet name
						$name = strtolower(preg_replace ("/(\.....?)$/","",basename($fn)));
						$snippets[$name] = file_get_contents ($fn);
					}
				}
			}

			$snippets['vocabulary_user'] = "";

			$myTheme->snippets = $snippets;

			// Add any variations as themes, also.
			// Built menus and lists for setting themes
			// A variation shows up as a theme, but it has the 'variation' flag set.
			$myTheme->is_variation = false; // default is false
			$previewpath = $themepath."/".MB_PREVIEWDIR;
			// add this theme to the menu listing
			// Add a space before 'default' to push it above others when sorting
			$this->themes_list[$myTheme->id] = $myTheme->name.": Default";
			// Get theme preview file
			$previewfile = $previewpath."/default.png";
			file_exists($previewfile) && $this->themes_previews[$myTheme->id] = $myTheme->id . ":" . $previewfile;
			file_exists($previewfile) && $this->themes_previews_for_js[$myTheme->id] = '_'.$myTheme->id . ':"' . $previewfile . '"';

			$vglob = $themepath."/".MB_THEME_VARIATION_DIR . "/*.css";
			$vlist = glob ($vglob);

			// load system variation files
			if ($vlist) {
					foreach ($vlist as $v) {
						$myVariation = array ();
						$v_filename = str_replace(".css", "", basename($v));
						$v_id = $myTheme->id . ":" . $v_filename;
						$v_id = preg_replace("/\s/", "_", $v_id);
						$v_name = $myTheme->name.":".mb_convert_case (basename ($v_filename), MB_CASE_TITLE);
						$v_name = str_replace("_", " ", $v_name);
						$myVariation['id'] =  $v_id;
						$myVariation['name'] = $v_name;
						$myVariation['theme_id'] = $myTheme->id;
						$myVariation['path'] = trim(basename($v));
						$myVariation['is_variation'] = true;
						$myVariation['userfile'] = false;
						$this->themes[$v_id] = $myVariation;
						$this->themes_list[$v_id] = $v_name;
	
						// Get theme preview file
						$previewfile = $previewpath."/{$v_filename}.png";
	
						file_exists($previewfile) && ($this->themes_previews[$v_id] = $v_id . ":" . $previewfile);
						// note : => __ (two underscores)
						$k = str_replace(":","__", $v_id);
						file_exists($previewfile) && $this->themes_previews_for_js[$v_id] = '_'.$k . ':"' . $previewfile . '"';
					}
				} // if $vlist
			
//$mb_api->write_log($myTheme);
			
		}
	
		// add theme to the list
		$this->themes[$myTheme->id] = $myTheme;

	} // end function


	/*
	 * Load Theme details for each page setting:
	 * 1) list of format pages for each theme
	 * Load list of theme format pages, for each theme.
	 * We get this list from the theme.json file, NOT from the actual XML
	 * files. This way, we don't have to parse the XML.
	 * This lets us choose the formatting page by ID, e.g. for a post
	*/
	function LoadFormatDetails ($themepath, $myTheme) {
		global $mb_api;
		$infoFileName = "theme.json";
		$fn = "$themepath/$infoFileName";
		
		/*
		 * This method does work to read the XML file and get the ID's of each page.
		 * However, what should we do with them? InDesign won't let us name pages,
		 * so we don't have a good way to change the ID of each page.
		 * Frankly, we shouldn't care about the name --- we should have a nice
		 * preview, and the user should choose visually, not by name. I think.
		 */

		$xmlFileName = "templates.xml";
		$fn = "$themepath/$xmlFileName";
		$theme_ids = array();
		$toc = array();
		
		if (file_exists($fn)) {
			$themeXML = file_get_contents($fn);
			$theme = new SimpleXMLElement($themeXML);

//$mb_api->write_log(__FUNCTION__.": custom fields? : {$theme->fields} : ".print_r($theme,true) );


			foreach ($theme->chapter[0]->page as $page) {
				$id = (string)$page->attributes()->id;
				$isContents = (boolean)$page->attributes()->contents;
				$theme_ids[] = $id;
				(isset($page->tableofcontents)) ? $toc[$id] = true : $toc[] = false;
				$toc[$id] = $isContents;


// error_log( print_r($page->attributes(),true));
// error_log( "Is contents? ". (string)$isContents);
			}
		} else {
			$mb_api->error(__FUNCTION__.": The theme at $themepath is missing the $xmlFileName file!");
		}
		
		$custom_fields = array ();
		if ($theme->fields) {
			$f = trim($theme->fields);
			$f = preg_replace ("/,\s*/",",",$f);
			$f = preg_replace ("/\s/","_",$f);
			$custom_fields = explode(",", trim($f));
//$mb_api->write_log(__FUNCTION__.": custom fields? : {$theme->fields} : ".print_r($custom_fields,true) );	
		}

		// HELP TEXT"
		$custom_fields_help = array ();
		if ($theme->fields_help) {
			$f = trim($theme->fields_help);
			$f = preg_replace ("/,\s*/",",",$f);
			$f = preg_replace ("/\s+/"," ",$f);
			$custom_fields_help = explode(",", trim($f));
//print_r($custom_fields_help);
		}


		// OPTION LIST VALUES
		$custom_fields_options = array ();
		if ($theme->fields_options) {
			$f = trim($theme->fields_options);
			$f = preg_replace ("/,\s*/",",",$f);
			$f = preg_replace ("/\s+/"," ",$f);
			$custom_fields_options = explode(",", trim($f));
//print_r($custom_fields_help);
		}



		$details = array (
			'format_ids' => $theme_ids,
			'format_is_toc' => $toc,
			'custom_fields'	=> $custom_fields,
			'custom_fields_help'	=> $custom_fields_help,
			'custom_fields_options'	=> $custom_fields_options
			);
		$details = (object) $details;
		
		return $details;
	}
	
}

?>