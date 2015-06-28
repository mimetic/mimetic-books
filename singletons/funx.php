<?php

/*
	Convert a chapter array to a Mimetic Books chapter array
	that can be easily exported as XML.
	
	Array2XML from:	http://www.lalit.org/lab/convert-php-array-to-xml-with-attributes/
	
*/

class MB_API_Funx
{
	public $themes, $themes_list;
	
	
	
	/* 
	Constructor 
	*/
 	function MB_API_Funx()
	{
	}
	
	
	// Field value popup menu:
	// Build a <select> pop-up selector in HTML from an array
	// $values is the array of ($value, $name) used in <OPTION VALUE=$value>$NAME</OPTION>
	// $listname is the name for the selection in HTML
	// $checked is array of checked values (value matches value in $values)
	// $sort = true, sort $values by value
	// $extraline = array ('value'=>$value, 'checked'=>$checked, 'label'=>$label) where $checked should be text "CHECKED" or ""
	// Two fields are retrieved: $set and $fieldlabel
	// example: $ArtistIDList = OptionListFromArray ($values, "ID", array("1"), true, true, "", array("0"=>"empty"));
	
	public function OptionListFromArray ($values, $listname, $checked = array(), $sort = TRUE, $size = true, $extrahtml="", $extraline = array(), $defaultItemClass = "") {

		// internal use only for ease of reading
		$OPTION_LIST_IS_POPUP = true;
		$OPTION_LIST_IS_MULTI = true;

		is_array($values) || $values = array();
	
		if ($sort)
			asort ($values);
	
		if (!is_array($checked))
			$checked = array ($checked);
	
		$optionlist = "";
	
		$extraline && $optionlist .= "<OPTION VALUE=\"" . $extraline['value'] . "\" " . $extraline['checked'] . ">" . $extraline['label'] ."</OPTION>\n";

		if ($defaultItemClass) {
			$class = " class=\"$defaultItemClass\" ";
		} else {
			$class = "";
		}

	
		reset($values);
		$k = 1;
		while (list($ID, $name) = each ($values)) {
			$ID = trim($ID);
			$name = trim($name);
			if ($name && $name[0] != "/") {
				in_array($ID, $checked) ? $check = " selected" : $check = "";
				$optionlist .= "<OPTION $class VALUE=\"$ID\" $check>$name</OPTION>\n";
				$k++;
			}
		}
		if ($size === $OPTION_LIST_IS_POPUP) {
			$size = "";
		} elseif (!$size) {
			$k > 10 ? $size = $OPTION_LIST_MAXSIZE : $size = $k;
			$size = 'SIZE="' . $size . '" MULTIPLE';
		} else {
			$size = 'SIZE="' . $size . '" MULTIPLE';
		}
		$block = "\n<SELECT NAME=\"$listname\" $size $extrahtml>\n$optionlist</SELECT>\n";
	
		return $block;
	
	}
	
	
	// Used for jQuery UI popup lists
	public function jQuerySelectableFromArray ($fieldID, $values, $checked = "") {

		is_array($values) || $values = array();
	
		$optionlist = "";
	
		$class = " class=\"ui-state-default\" ";
	
		reset($values);
		$k = 1;
		while (list($ID, $name) = each ($values)) {
			$ID = trim($ID);
			$name = trim($name);
			if ($name[0] != "/") {
				($ID == $checked) ? $check = " selected" : $check = "";
				$optionlist .= "<li><img src=\"$name\" alt=\"\" /></li>\n";
				$k++;
			}
		}

		$block = "\n<ol class=\"selectable\" id=\"$fieldID\">\n$optionlist</ol>\n";
		return $block;
	
	}


// My error logging...just add a newline after each line. Dammit.
function mb_log ($text) {
	error_log (date('Y-m-d H:i:s') . ": {$text}\n", 3, "mb.log");
}	
	
	
	// removes files and non-empty directories
	public function rrmdir($dir) {
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
	public function dircopy($src, $dst) {
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
	public function rcopy($src, $dst) {
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

	
	public function tar_dir($src, $dst) {
		$src = str_replace(" ", "\ ", $src);
		$script = "tar -cf $dst $src";
		$results = exec($script, $res);
		return $res;
	}


	public function sendJSON($json)
	{
			$url = "http://localhost/api/v1/" . $this->getApiKey() . "/books/wordpress.json";
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_POST, 1);
			$result = curl_exec($ch);
			$info = curl_getinfo($ch);
			if (curl_errno($ch)) {
					print curl_error($ch);
			} else {
					curl_close($ch);
			}
			if ($result);
			if ($result && $info['http_code'] == '200')
					$this->set_message(true);
			else if ($result && $info['http_code'] == '404')
					$this->set_message(3);
			else if ($result && $info['http_code'] == '500')
					$this->set_message (0);
			return;
	}
	
	
	public function getNewAPIKey() {
		// Generates a random string of ten digits
		$salt = mt_rand();
		$key = sha1($salt);
		return $key;
	}

	public function getDigitalSignature() {
		$apiKey = "apikey";
		$secretKey = "secretkey";
		// Generates a random string of ten digits
		$salt = mt_rand();
		// Computes the signature by hashing the salt with the secret key as the key
		$signature = hash_hmac('sha256', $salt, $secretKey, true);
		// base64 encode...
		$encodedSignature = base64_encode($signature);
		// urlencode...
		$encodedSignature = urlencode($encodedSignature);
		return $encodedSignature;
	}
}

?>