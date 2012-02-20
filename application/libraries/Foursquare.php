<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

DEFINE("HTTP_GET","GET");
DEFINE("HTTP_POST","POST");

class Foursquare
{
	private $CI;

	function __construct($params = array())
	{
		$this->CI =& get_instance();
		$this->CI->config->load('foursquare');
	}

	/**
	* GetPublic
	* Performs a request for a public resource
	* @param String $endpoint A particular endpoint of the Foursquare API
	* @param Array $params A set of parameters to be appended to the request, defaults to false (none)
	*/
	public function get_public($endpoint, $params=false)
	{
		// Build the endpoint URL
		$url = $this->CI->config->item('baseUrl') . trim($endpoint, "/");
		// Append the client details
		$params['client_id'] = $this->CI->config->item('clientID');
		$params['client_secret'] = $this->CI->config->item('clientSecret');
		// Return the result;
		return $this->GET($url, $params);
	}

	/**
	* GetPrivate
	* Performs a request for a private resource
	* @param String $endpoint A particular endpoint of the Foursquare API
	* @param Array $params A set of parameters to be appended to the request, defaults to false (none)
	* @param bool $POST whether or not to use a POST request
	*/
	public function get_private($endpoint, $params=false, $POST=false)
	{
		$url = $this->CI->config->item('baseUrl') . trim($endpoint, "/");
		$params['oauth_token'] = $this->CI->session->userdata($this->CI->config->item('token_session'));

		if(!$POST) 
			return $this->GET($url, $params);
		else 
			return $this->POST($url, $params);
	}

	public function get_response_from_json_string($json)
	{
        $json = json_decode($json);

        if (!isset($json->response))
            throw new Exception('Foursquare: Invalid response');

        return $json->response;
    }

    /**
	* request
	* Performs a cUrl request with a url generated by make_url. The useragent of the request is hardcoded
	* as the Google Chrome Browser agent
	* @param String $url The base url to query
	* @param Array $params The parameters to pass to the request
	*/
	private function request($url, $params=false, $type=HTTP_GET)
	{
		// Populate data for the GET request
		if($type == HTTP_GET)
			$url = $this->make_url($url, $params);

		// borrowed from Andy Langton: http://andylangton.co.uk/
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		
		if (isset($_SERVER['HTTP_USER_AGENT']))
		{
			curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] );
		}
		else
		{
			// Handle the useragent like we are Google Chrome
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/525.13 (KHTML, like Gecko) Chrome/0.X.Y.Z Safari/525.13.');
		}
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

		// Populate the data for POST
		if($type == HTTP_POST)
		{
			curl_setopt($ch, CURLOPT_POST, 1);

			if($params)
				curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		}

		$result = curl_exec($ch);
		$info = curl_getinfo($ch);

		curl_close($ch);

		return $result;
	}

	/**
	* GET
	* Abstraction of the GET request
	*/
	private function GET($url, $params=false)
	{
		return $this->request($url, $params, HTTP_GET);
	}

	/**
	* POST
	* Abstraction of a POST request
	*/
	private function POST($url, $params=false)
	{
		return $this->request($url, $params, HTTP_POST);
	}

	/**
	* geo_locate
	* Leverages the google maps api to generate a lat/lng pair for a given address
	* packaged with FoursquareApi to facilitate locality searches.
	* @param String $addr An address string accepted by the google maps api
	* @return array(lat, lng) || NULL
	*/
	public function geo_locate($addr)
	{
		$geoapi = "http://maps.googleapis.com/maps/api/geocode/json";
		$params = array("address" => $addr, "sensor" => "false");
		$response = $this->GET($geoapi, $params);
		$json = json_decode($response);

		if ($json->status === "ZERO_RESULTS")
		{
			return NULL;
		}
		else
		{
			return array($json->results[0]->geometry->location->lat,$json->results[0]->geometry->location->lng);
		}
	}

	/**
	* make_url
	* Takes a base url and an array of parameters and sanitizes the data, then creates a complete
	* url with each parameter as a GET parameter in the URL
	* @param String $url The base URL to append the query string to (without any query data)
	* @param Array $params The parameters to pass to the URL
	*/
	private function make_url($url, $params)
	{
		if(!empty($params) && $params)
		{
			foreach($params as $k=>$v)
				$kv[] = "$k=$v";

			$url_params = str_replace(" ","+",implode('&',$kv));
			$url = trim($url) . '?' . $url_params;
		}

		return $url;
	}

	/**
	* authentication_link
	* Returns a link to the Foursquare web authentication page.
	* @param String $redirect The configured redirect_uri for the provided client credentials
	*/
	public function authentication_link($redirect='')
	{
        if (0 === strlen($redirect))
        {
            $redirect = $this->CI->config->item('redirectUri');
        }

		$params = array("client_id" => $this->CI->config->item('clientID'),
						"response_type" => "code",
						"redirect_uri" => $redirect);
		
		return $this->make_url($this->CI->config->item('authUrl'), $params);
	}

	/**
	* get_token
	* Performs a request to Foursquare for a user token, and returns the token, while also storing it
	* locally for use in private requests
	* @param $code The 'code' parameter provided by the Foursquare webauth callback redirect
	* @param $redirect The configured redirect_uri for the provided client credentials
	*/
	public function get_token($code, $redirect='')
	{
        if (0 === strlen($redirect))
        {
            // If we have to use the same URI to request a token as we did for
            // the authorization link, why are we not storing it internally?
            $redirect = $this->CI->config->item('redirectUri');
        }

		$params = array("client_id" => $this->CI->config->item('clientID'),
						"client_secret" => $this->CI->config->item('clientSecret'),
						"grant_type" => "authorization_code",
						"redirect_uri" => $redirect,
						"code" => $code);

		$result = $this->GET($this->CI->config->item('tokenUrl'), $params);

		$json = json_decode($result);
		$this->set_access_token($json->access_token);

		return $json->access_token;
	}

	public function set_access_token($token)
	{
		$this->CI->session->set_userdata($this->CI->config->item('token_session'), $token);
	}
}