<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * Send books to Amazon S3.
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
 * @package s3
 * @author	 David Gross <dgross@mimetic.com>
 * @copyright	 2016 David Gross
 * @license http://www.opensource.org/licenses/bsd-license.php
 */


// Amazon S3 functions
//include_once mb_api_dir() . "/library/s3.php";

// Include the SDK using the Composer autoloader
require_once mb_api_dir() . "/library/vendor/autoload.php";

# SDK uses namespacing - requires PHP 5.3 (actually the SDK states its requirements as 5.3.3)
use Aws\S3;


class MB_S3
{
	private $s3 = null;
	
	// ACL flags
	const ACL_PRIVATE = 'private';
	const ACL_PUBLIC_READ = 'public-read';
	const ACL_PUBLIC_READ_WRITE = 'public-read-write';
	const ACL_AUTHENTICATED_READ = 'authenticated-read';

	const STORAGE_CLASS_STANDARD = 'STANDARD';
	const STORAGE_CLASS_RRS = 'REDUCED_REDUNDANCY';
	
	const LATEST_API_VERSION = "2006-03-01";

	private static $__accessKey = null; // AWS Access key
	private static $__secretKey = null; // AWS Secret key
	private static $__sslKey = null;

	public static $endpoint = 's3.amazonaws.com';
	public static $proxy = null;

	public static $bucket = null;

	private $region = 'us-west-2';

	// Added to cope with a particular situation where the user had no pernmission to check the bucket location, which necessitated using DNS-based endpoints.
	public static $use_dns_bucket_name = false;

	public static $useSSL = false;
	public static $useSSLValidation = true;
	public static $useExceptions = false;

	// SSL CURL SSL options - only needed if you are experiencing problems with your OpenSSL configuration
	public static $sslKey = null;
	public static $sslCert = null;
	public static $sslCACert = null;

	private static $__signingKeyPairId = null; // AWS Key Pair ID
	private static $__signingKeyResource = false; // Key resource, freeSigningKey() must be called to clear it from memory


	/**
	* Constructor - if you're not using the class statically
	*
	* @param string $accessKey Access key
	* @param string $secretKey Secret key
	* @param boolean $useSSL Enable SSL
	* @return void
	*/
	public function __construct($accessKey = null, $secretKey = null, $useSSL = true, $sslCACert = true, $endpoint = null)
	{
		global $mb_api;

		if (!function_exists('curl_init')) {
			global $mb_api;
			$mb_api->send_message('ERROR: The PHP cURL extension must be installed and enabled to use Amazon S3', 100, 'end');
			$mb_api->write_log('The PHP cURL extension must be installed and enabled to use Amazon S3');
			throw new Exception('The PHP cURL extension must be installed and enabled to use Amazon S3');
		}

		if ($accessKey !== null && $secretKey !== null)
			$this->setAuth($accessKey, $secretKey);

		self::$useSSL = $useSSL;
		self::$sslCACert = $sslCACert;

		$opts = [
			'scheme' => ($useSSL) ? 'https' : 'http',
			//'region' => $this->region,
			'version' => self::LATEST_API_VERSION,
			'credentials' => array(
				'key' => $accessKey,
				'secret' => $secretKey,
			)
			// Using signature v4 requires a region
			//'signature' => 'v4',
			//'region' => $this->region
			//'endpoint' => 'somethingorother.s3.amazonaws.com'
		];

		if ($endpoint) {
			// Can't specify signature v4, as that requires stating the region - which we don't necessarily yet know.
			$this->endpoint = $endpoint;
			$opts['endpoint'] = $endpoint;
		} else {
			// Using signature v4 requires a region. Also, some regions (EU Central 1, China) require signature v4 - and all support it, so we may as well use it if we can.
			$opts['signature'] = 'v4';
			$opts['region'] = $this->region;
		}

		if ($useSSL) $opts['ssl.certificate_authority'] = $sslCACert;

		$this->s3 = Aws\S3\S3Client::factory($opts);

	}




	/**
	* Set AWS access key and secret key
	*
	* @param string $accessKey Access key
	* @param string $secretKey Secret key
	* @return void
	*/
	public function setAuth($accessKey, $secretKey)
	{
		self::$__accessKey = $accessKey;
		self::$__secretKey = $secretKey;
	}

	// Example value: 'AES256'. See: https://docs.aws.amazon.com/AmazonS3/latest/dev/SSEUsingPHPSDK.html
	// Or, false to turn off.
	public function setServerSideEncryption($value)
	{
		$this->_serverSideEncryption = $value;
	}

	/**
	* Set the service region
	*
	* @param string $region Region
	* @return void
	*/
	public function setRegion($region)
	{
		$this->region = $region;
		if ('eu-central-1' == $region || 'cn-north-1' == $region) {
//				$this->config['signature'] =	new Aws\S3\S3SignatureV4('s3');
//				$this->s3->setConfig($this->config);
		}
		$this->s3->setRegion($region);
	}

	/**
	* Set the service endpoint
	*
	* @param string $host Hostname
	* @return void
	*/
	public function setEndpoint($host, $region)
	{
		$this->endpoint = $host;
		$this->region = $region;
		$this->config['endpoint_provider'] = $this->return_provider();
		$this->s3->setConfig($this->config);
	}

	public function return_provider() {
		$our_endpoints = array(
			'endpoint' => $this->endpoint
		);
		if ($this->region == 'eu-central-1' || $this->region == 'cn-north-1') $our_endpoints['signatureVersion'] = 'v4';
		$endpoints = array(
			'version' => 2,
			'endpoints' => array(
				"*/s3" => $our_endpoints
			)
		);
		return new Aws\Common\RulesEndpointProvider($endpoints);
	}

	/**
	* Set SSL on or off
	*
	* @param boolean $enabled SSL enabled
	* @param boolean $validate SSL certificate validation
	* @return void
	*/
	// This code relies upon the particular pattern of SSL options-setting in s3.php in UpdraftPlus
	public function setSSL($enabled, $validate = true)
	{
		$this->useSSL = $enabled;
		$this->useSSLValidation = $validate;
		// http://guzzle.readthedocs.org/en/latest/clients.html#verify
		if ($enabled) {

			// Do nothing - in UpdraftPlus, setSSLAuth will be called later, and we do the calls there

//				$verify_peer = ($validate) ? true : false;
//				$verify_host = ($validate) ? 2 : 0;
// 
//				$this->config['scheme'] = 'https';
//				$this->s3->setConfig($this->config);
// 
//				$this->s3->setSslVerification($validate, $verify_peer, $verify_host);


		} else {
			$this->config['scheme'] = 'http';
//				$this->s3->setConfig($this->config);
		}
		$this->s3->setConfig($this->config);
	}

	public function getuseSSL() 
	{
		return $this->useSSL;
	}

	/**
	* Set SSL client certificates (experimental)
	*
	* @param string $sslCert SSL client certificate
	* @param string $sslKey SSL client key
	* @param string $sslCACert SSL CA cert (only required if you are having problems with your system CA cert)
	* @return void
	*/
	public function setSSLAuth($sslCert = null, $sslKey = null, $sslCACert = null)
	{
		if (!$this->useSSL) return;

		if (!$this->useSSLValidation) {
			$this->s3->setSslVerification(false);
		} else {
			if (!$sslCACert) {
				$client = $this->s3;
				$this->config[$client::SSL_CERT_AUTHORITY] = false;
				$this->s3->setConfig($this->config);
			} else {
				$this->s3->setSslVerification(realpath($sslCACert), true, 2);
			}
		}

//			$this->s3->setSslVerification($sslCACert, $verify_peer, $verify_host);
//			$this->config['ssl.certificate_authority'] = $sslCACert;
//			$this->s3->setConfig($this->config);
	}

	/**
	* Set proxy information
	*
	* @param string $host Proxy hostname and port (localhost:1234)
	* @param string $user Proxy username
	* @param string $pass Proxy password
	* @param constant $type CURL proxy type
	* @return void
	*/
	public function setProxy($host, $user = null, $pass = null, $type = CURLPROXY_SOCKS5, $port = null)
	{
		global $mb_api;

		$this->proxy = array('host' => $host, 'type' => $type, 'user' => $user, 'pass' => $pass, 'port' => $port);

		if (!$host) return;

		$wp_proxy = new WP_HTTP_Proxy(); 
		if ($wp_proxy->send_through_proxy('https://s3.amazonaws.com'))
		{

			global $updraftplus;
			$mb_api->write_log("setProxy: host=$host, user=$user, port=$port");

			// N.B. Currently (02-Feb-15), only support for HTTP proxies has ever been requested for S3 in UpdraftPlus
			$proxy_url = 'http://';
			if ($user) {
				$proxy_url .= $user;
				if ($pass) $proxy_url .= ":$pass";
				$proxy_url .= "@";
			}

			$proxy_url .= $host;

			if ($port) $proxy_url .= ":$port";

			$this->s3->setDefaultOption('proxy', $proxy_url);
		}

	}

	/**
	* Set the error mode to exceptions
	*
	* @param boolean $enabled Enable exceptions
	* @return void
	*/
	public function setExceptions($enabled = true)
	{
		self::$useExceptions = $enabled;
	}

	// A no-op in this compatibility layer (for now - not yet found a use)...
	public function useDNSBucketName($use = true, $bucket = '')
	{
		$this->use_dns_bucket_name = $use;
		if ($use && $bucket) {
			$this->setEndpoint($bucket.'.s3.amazonaws.com', $this->region);
		}
		return true;
	}



/* ======= */

// Upload a file to the bucket.
	public function uploadFile ($pathToFile, $key, $bucket) 
	{
		global $mb_api;
		
		if (!file_exists($pathToFile)) {
			$mb_api->write_log(__FUNCTION__.": File does not exist ($pathToFile)");
			return [];
		}			

		$s3 = $this->s3;

//$mb_api->send_message("——— AWS Upload Begin ———");

		/*
		Everything uploaded to Amazon S3 must belong to a bucket. These buckets are
		in the global namespace, and must have a unique name.

		For more information about bucket name restrictions, see:
		http://docs.aws.amazon.com/AmazonS3/latest/dev/BucketRestrictions.html
		*/
		$bucket || $bucket = self::$bucket;
		
		if (!$bucket) {
			$mb_api->write_log('No bucket specified for upload!');
			$mb_api->send_message("ERROR: Amazon S3 is not set up!");
			return false;
		}

		if (!$this->isValidBucketName( $bucket ) ) {
			$mb_api->write_log("*** Invalid bucket name {$bucket}\n");
			$mb_api->send_message("ERROR: Invalid Amazon S3 bucket name:  $bucket");
			return false;
		}
		//$mb_api->send_message(__FUNCTION__.": Does bucket ($bucket) exist?");

		$exists = $this->doesBucketExist($bucket);

		if (!$exists) {		
			$mb_api->write_log("Creating bucket named {$bucket}\n");
			$mb_api->send_message("Creating bucket named {$bucket}");
			
			try {
				$result = $s3->createBucket(['Bucket' => $bucket]);
				if (is_object($result) && method_exists($result, 'get') && '' != $result->get('RequestId')) {
					$s3->waitUntil('BucketExists', array('Bucket' => $bucket));
					return true;
				}
			} catch (Exception $e) {
				if (self::$useExceptions) {
					throw $e;
				} else {
					$this->log_exception($e);
					$mb_api->write_log("Failed to create bucket: {$bucket}\n");
					$mb_api->send_message("Failed to create bucket: {$bucket}");
					return $this->log_exception($e);
				}
			}
			
		}
		
		// Get the bucket location
		$result = $this->getBucketLocation($bucket);
		$this->region = $result['Location'];
		
		//$mb_api->write_log("Bucket region: {$this->region}");

		if (!$key) {
			$mb_api->write_log("ERROR: Missing key for S3!");
			$mb_api->send_message("ERROR: Missing key for S3!");

			return false;
		}
		
		//$mb_api->write_log("key = $key, pathToFile: {$pathToFile}");
		
		$file_size = filesize($pathToFile);
		// Chunks of 5MB
		$chunk_size = 5 * 1024 * 1024;
		$chunks = floor($file_size / $chunk_size );
		if ($file_size % $chunk_size > 0) $chunks++;
		
		$url = null;
		$result = null;
		
		if ($chunks < 2) {
			// UPLOAD SINGLE FILE IN ONE STEP
			// Upload an object by streaming the contents of a file
			// $pathToFile should be absolute path to a file on disk
			$result = $s3->putObject( [
				'Bucket' => $bucket,
				'Key'		=> $key,
				'SourceFile' => $pathToFile
				]);
		
			$url = $result['ObjectURL'];
			$location = $result['Location'];
	
			// We can poll the object until it is accessible
			$s3->waitUntil('ObjectExists', array(
				'Bucket' => $bucket,
				'Key' => $key
			));
			
			$mb_api->send_message(basename($pathToFile) . " : " . round($file_size/1024, PHP_ROUND_HALF_UP) . " kb");
			
		} else {
		
			// UPLOAD MULTIPART (PREFERRED FOR >100MB)
			$response = $s3->createMultipartUpload([
				'Bucket' => $bucket,
				'Key'		=> $key
			]);
			$uploadId = $response['UploadId'];

			// 3. Upload the file in parts.
			$file = fopen($pathToFile, 'r');
			$parts = array();
			$partNumber = 1;
			while (!feof($file)) {
				$body = fread($file, $chunk_size);
				
				$result = $s3->uploadPart(array(
					 'Bucket'	  => $bucket,
					 'Key'		  => $key,
					 'UploadId'	  => $uploadId,
					 'PartNumber' => $partNumber,
					 'Body'		  => $body
				));
				$parts[] = array(
					 'PartNumber' => $partNumber++,
					 'ETag'		  => $result['ETag'],
				);

// 				$mb_api->write_log("Uploaded: Part = $partNumber");
// 				$mb_api->write_log('Uploaded: ETag = '. $result['ETag']);
// 
// 				$mb_api->send_message('Uploaded: Part = $partNumber');
// 				$mb_api->send_message('Uploaded: ETag = '. $result['ETag']);

				$mb_api->send_message(basename($pathToFile) . " : " . round($partNumber * $chunk_size/1024, PHP_ROUND_HALF_UP) . " kb");
			}

			$mb_api->write_log("Parts: ". print_r($parts,true) );


			// 4. Complete multipart upload.
			$result = $s3->completeMultipartUpload([
				'Bucket'	  => $bucket,
				'Key'		  => $key,
				'MultipartUpload'	=> [
					'Parts'	  => $parts,
				],
				'UploadId' => $uploadId,
			]);
			fclose($file);

			$url = $result['ObjectURL'];
			$location = $result['Location'];
		}
		
		// 5. Get a signed URL for the object for download
		// URL:
		// Get a command object from the client and pass in any options
		// available in the GetObject command (e.g. ResponseContentDisposition)
		$command = $s3->getCommand('GetObject', array(
						'Bucket'      => $bucket,
						'Key'         => $key,
		//            'ResponseContentDisposition' => 'attachment; filename="'.$key.'"'
				  ));
		$request = $s3->createPresignedRequest($command, "+1 week");
		// Get the actual presigned-url
		$presignedUrl = (string) $request->getUri();

		//$mb_api->send_message("——— AWS Upload End ———");
		
		return [ 'url' => $url, 'presignedUrl' => $presignedUrl, 'location' => $location, 'result' => $result];
	}


	/*		------------------------------ */
	// AWS functions


	 /**
	  * Determine if a string is a valid name for a DNS compatible Amazon S3
	  * bucket, meaning the bucket can be used as a subdomain in a URL (e.g.,
	  * "<bucket>.s3.amazonaws.com").
	  *
	  * @param string $bucket The name of the bucket to check.
	  *
	  * @return bool TRUE if the bucket name is valid or FALSE if it is invalid.
	  */
	 public static function isValidBucketName($bucket)
	 {
		  $bucketLen = strlen($bucket);
		  if ($bucketLen < 3 || $bucketLen > 63 ||
				// Cannot look like an IP address
				preg_match('/(\d+\.){3}\d+$/', $bucket) ||
				// Cannot include special characters, must start and end with lower alnum
				!preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $bucket)
		  ) {
				return false;
		  }

		  return true;
	 }


	private function log_exception($e) {
		global $mb_api;
		//trigger_error($e->getMessage()."\n\n (".get_class($e).") \n\n(line: ".$e->getLine().", \n\nfile: ".$e->getFile().")\n\n", E_USER_WARNING);
		$mb_api->write_log( "s3.php : AWS ERROR: " . $e->getMessage()."\n\n(".get_class($e).") \n\n(line: ".$e->getLine()."\n\nfile: ".$e->getFile().")\n\n", E_USER_WARNING);
		return false;
	}

	  /**
	  * Determines whether or not a bucket exists by name
	  *
	  * @param string $bucket	  The name of the bucket
	  * @param bool	$accept403 Set to true if 403s are acceptable
	  * @param array	$options	  Additional options to add to the executed command
	  *
	  * @return bool
	  */
	 public function doesBucketExist($bucket, $accept403 = true, array $options = array())
	 {
		global $mb_api;
		 try {
				//$mb_api->send_message("*** doesBucketExist ****");
				$this->s3->headBucket(['Bucket' => $bucket]);
				$exists = true;			 
			} catch (Aws\S3\Exception\S3Exception $e) {
				//$mb_api->send_message("*** \Aws\S3\Exception\S3Exception ****");
				$exists = false;
				//$this->log_exception($e);
//			} catch (Exception $e) {
			} catch (Aws\S3\Exception\NoSuchBucketException $e) {
				//$mb_api->send_message("*** Aws\S3\Exception\NoSuchBucketException ****");
				$exists = false;
				//$this->log_exception($e);
			} catch (Exception $e) {
				$exists = false;
				//$this->log_exception($e);
				//$mb_api->send_message("*** doesBucketExist(): S3Exception ****");
			}

		  return $exists;
	 }


	/**
	* Get a bucket's location
	*
	* @param string $bucket Bucket name
	* @return string | false
	*/
	public function getBucketLocation($bucket)
	{
		global $mb_api;
		try {
			$result = $this->s3->getBucketLocation(array('Bucket' => $bucket));
			$location = $result->get('Location');
			if ($location) return $location;
		} catch (Aws\S3\Exception\NoSuchBucketException $e) {
			return false;
		} catch (Exception $e) {
			if (self::$useExceptions) {
				throw $e;
			} else {
				return $this->log_exception($e);
			}
		}
	}



}
	
?>