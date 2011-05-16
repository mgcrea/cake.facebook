<?php
/**
 * Copyright 2011, Magenta Creations (http://mg-crea.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2011, Magenta Creations (http://mg-crea.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

class FacebookComponent extends Component {

	var $name = 'Facebook';
	var $components = array('Auth', 'Session');
	var $uses = array();

	var $config = array(
		'app_id' => null,
		'api_key' => null,
		'app_secret' => null,
		'certificate' => null,
		'load_legacy_api' => false,
		'scope' => array(),
		'curl' => true
	);

/**
 * Maps aliases to Facebook domains.
 */
	public static $DOMAIN_MAP = array(
		'graph'     => 'https://graph.facebook.com/',
		'www'       => 'https://www.facebook.com/'
	);

/**
 * Default options for curl.
 */
	public static $CURL_OPTS = array(
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_USERAGENT      => 'facebook-php-2.0',
	);

/***********************
 ** component methods **
 ***********************/

/**
 * graph() ~ Returns facebook graph information
 *
 * @param string $request
 * @param array $options to pass to the api (limit, offset, until, since)
 * @return array returned json from facebook
 * @access public
 */
	function graph($request = 'me', $options = array()) {

		// check for an access_token
		if(!$this->checkToken() && !$this->getToken()) {
			// reset the session
			//$this->setSession(null);
			trigger_error("Unable to get an access_token from facebook", E_USER_ERROR);
		}

		// check if we need a picture ~ we won't check certs for that
		if(preg_match('/\/picture$/', $request)) {
			self::$CURL_OPTS = array_merge_keys(self::$CURL_OPTS, array(
				CURLOPT_BINARYTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false
			));
		}

		$defaults = array(
			'access_token' => $this->getSession('access_token'),
			'metadata' => false,
			//'limit' => null,
			//'offset' => null,
			//'until' => null,
			//'since' => null,
		);
		$url = $this->getUrl('graph', $request, array_merge($defaults, $options)); //debug($url);
		//if(!empty($options['debug'])) debug($url); //exit;
		$response = $this->oauthRequest($url, array('check_token' => true));

		return $response;

	}

/**
 * picture() ~ Returns facebook picture
 *
 * @param string $request
 * @param array $options to pass to the api (limit, offset, until, since)
 * @return blob picture
 * @access public
 */
	function picture($request = 'me', $options = array()) {

		$defaults = array(
			'type' => 'square', // square (50x50), small (50x%), normal (100x%), large (200x%)
			//'return_ssl_resources' => true
		);

		return $this->graph($request . '/picture', array_merge($defaults, $options));

	}

	function pictureUrl($request) {
		return $this->getUrl('graph', $request . '/picture');
	}

/**
 * search() ~ Search information
 *
 * @param string $request
 * @param array $options to pass to the api (limit, offset, until, since)
 * @return blob picture
 * @access public
 */
	function search($request = null, $options = array()) {

		$defaults = array(
			'q' => $request
			//'type' => null,
		);

		return $this->graph('search', array_merge($defaults, $options));

	}

/**
 * login() ~ handle facebook login
 */
	function login($options = array()) {

		// generate random state to protect from crsf
		$state = md5(uniqid(rand(), true));
		$this->setSession('state', $state);

		$url = $this->getLoginUrl(compact('state')); //debug($url); exit;
		$this->controller->redirect($url);
	}
/**
 * getLoginUrl() ~ returns facebook adequate login url
 */
	protected function getLoginUrl($options = array()) {
		$defaults = array(
			'client_id' => $this->config['app_id'],
			'redirect_uri' => $this->url,
			'scope' => implode(',', $this->config['scope'])
		);
		return $this->getUrl('www', 'dialog/oauth', array_merge($defaults, $options));
	}

/**
 * getToken() ~ requests an access_token using a session code from login
 */
	protected function getToken($options = array()) {

		// facebook login : http://developers.facebook.com/docs/authentication/#app-login

		$defaults = array(
			'client_id' => $this->config['app_id'],
			'client_secret' => $this->config['app_secret'],
			'redirect_uri' => $this->url,
			'code' => $this->getSession('code'), // user login
			//'grant_type' => "client_credentials", // app login

		);

		$url = $this->getUrl('graph', 'oauth/access_token', array_merge($defaults, $options)); //debug($url); exit;
		$response = $this->oauthRequest($url); //debug($response);

		if(!is_string($response)) return false;

		parse_str($response, $response);

		if(!empty($response['access_token'])) {
			// update session
			$this->setSession('access_token', $response['access_token']);
			$this->setSession('access_token_time', time());
			if(!empty($response['expires'])) $this->setSession('access_token_expires', $response['expires']);

			return true;
		}

		return false;

	}

/**
 * checkToken() ~ checks an access_token using expires timestamp
 */
	protected function checkToken($options = array()) {

		// handle expiration
		$expiration = $this->getSession('access_token_time') + $this->getSession('access_token_expires') - 1;
		if($expiration != -1 && time() >= $expiration) {
			$this->setSession(null);
			return $this->login();
		}

		return (boolean) $this->getSession('access_token');

	}

/**
 * logout()
 */
	function logout() {

		$url = $this->getLogoutUrl();
		$this->setSession(null); //debug($url); debug($this->getSession()); exit;
		return $this->controller->redirect($url);
	}

/**
 * getLogoutUrl()
 */
	protected function getLogoutUrl($options = array()) {
		$defaults = array(
			'api_key' => $this->config['api_key'],
			'session_key' => $this->getSession('code'),
			'next' => $this->url(array('controller' => $this->params['controller'], 'action' => 'index'))
		);
		return $this->getUrl('www', 'logout.php', array_merge($defaults, $options));
	}

/**
 * getSession()
 */
	function getSession($param = null) {
		return $this->Session->read('Auth.' . $this->name . ($param ? '.' . $param : null));
	}

/**
 * setSession()
 */
	function setSession($param, $value = null) {
		if(!is_array($param)) return $this->Session->write('Auth.' . $this->name . ($param ? '.' . $param : null), $value);
		foreach($param as $key => $value) $this->setSession($key, $value);
		return true;
	}

/**
 * isAuthorized()
 */
	function isAuthorized() {
		return (boolean) $this->getSession('code');
	}

/*********************
 ** utility methods **
 *********************/

/**
 * Build the URL for given domain alias, path and parameters.
 *
 * @param $name String the name of the domain
 * @param $path String optional path (without a leading slash)
 * @param $params Array optional query parameters
 * @return String the URL for the given parameters
 */
	protected function getUrl($name, $path = null, $params = array()) {
		if ($path && $path[0] === '/') $path = substr($path, 1);
		return self::$DOMAIN_MAP[$name] . $path . ($params ? '?' . http_build_query($params) : null);
	}

/**
 * url() ~ get app url using Cake's Router
 */
	protected function url($url = null) {
		$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://';
		return $protocol . $_SERVER['HTTP_HOST'] . Router::url($url);
	}

/**
 * oauthRequest() handle json responses from makeRequest
 */
	protected function oauthRequest($url, $options = array()) {

		$response = $this->makeRequest($url, $options); //debug($url);
		if(!empty($response[0]) && $response[0] == '{') $result = json_decode($response, true);
		$response = !empty($result) ? $result : $response;

		// results are returned, errors are thrown
		if (is_array($response) && !empty($response['error'])) {
			trigger_error($response['error']['type'] . ': ' . $response['error']['message'] . ' ~ ' . $url, E_USER_WARNING);
		}

		return $response;

	}

/**
 * makeRequest() performs actual requests
 */
	protected function makeRequest($url, $options = array()) {

		if(!$this->config['curl']) {

			$timeout = self::$CURL_OPTS[CURLOPT_CONNECTTIMEOUT];
			$context = stream_context_create(array('http' => array('timeout' => $timeout)));
			if (IS_WIN) ini_set('default_socket_timeout', $timeout);
			$response = @file_get_contents($url, false, $context);

		} else {

			$ch = curl_init($url);
			$opts = self::$CURL_OPTS;

			// disable the 'Expect: 100-continue' behaviour. This causes CURL to wait
			// for 2 seconds if the server does not support this header.
			if (isset($opts[CURLOPT_HTTPHEADER])) {
				$existing_headers = $opts[CURLOPT_HTTPHEADER];
				$existing_headers[] = 'Expect:';
				$opts[CURLOPT_HTTPHEADER] = $existing_headers;
			} else {
				$opts[CURLOPT_HTTPHEADER] = array('Expect:');
			}

			curl_setopt_array($ch, $opts);
			$response = curl_exec($ch);

			if (curl_errno($ch) == 60) { // CURL_SSL_CACERT
				trigger_error('Invalid or no certificate authority found', E_USER_ERROR);
			}

			curl_close($ch);

		}

		return $response;
	}


/**********************
 ** callback methods **
 **********************/

	/*function __call($name, $arguments) {
		if(!empty($this->Facebook) && method_exists($this->Facebook, $name)) return call_user_func(array($this->Facebook, $name), $arguments);
		return false;
	}*/

/**
 * initialize() is fired before the controller's beforeFilter, but after models have been constructed.
 */
	function initialize(&$controller, $settings = array()) {

		$this->controller =& $controller;

		$this->data =& $this->controller->data;
		$this->params =& $this->controller->params;
		$this->passedArgs =& $this->controller->passedArgs;
		$this->action =& $this->controller->action;

		$this->config = array_merge($this->config, $settings);
		$this->url = $this->url(array('action' => 'index'));

		// load legacy api on demand
		if($this->config['load_legacy_api']) {
			App::import('Vendor', 'Facebook.facebook/src/facebook');
			$this->Facebook = new Facebook(array('appId' => $settings['app_id'], 'secret' => $settings['app_secret']));
		}

		// use bundled certificate
		if(!$this->config['certificate']) $this->config['certificate'] = dirname(__FILE__) . DS . '..' . DS . '..' . DS . 'vendors' . DS . 'facebook' . DS . 'src' . DS . 'fb_ca_chain_bundle.crt';
		self::$CURL_OPTS[CURLOPT_CAINFO] = $this->config['certificate'];

		// check for any login information from facebook
		if(!empty($this->params['url']['code'])) {
			//debug($this->params); exit;

			// check state from login
			$state = $this->params['url']['state'];
			if(empty($state) || $state != $this->getSession('state')) {
				trigger_error("The state does not match. You may be a victim of CSRF.");
				exit;
			}

			$this->setSession('code', $this->params['url']['code']);
			return $this->controller->redirect(array('action' => 'index'));
		}

	}

/**
 * startup() is fired after the controllers' beforeFilter, but before the controller action.
 */
	function startup(&$controller) {
	}

/**
 * beforeRender() is fired before a view is rendered.
 */
	function beforeRender(&$controller) {
		$this->controller->set('facebookConfig', array('app_id' => $this->config['app_id']));
	}

/**
 * beforeRedirect() is fired before a redirect is done from a controller. You can use the return of the callback to replace the url to be used for the redirect.
 */
	function beforeRedirect(&$controller, $url, $status = null, $exit = true) {

	}

/**
 * shutdown() is fired after the view is rendered and before the response is returned.
 */
	function shutdown(&$controller) {

	}

}

/**
 * array_merge_keys()
 */
if (!function_exists('array_merge_keys')) {
	function array_merge_keys(){
		$args = func_get_args();
		$result = array();
		foreach ($args as $array) {
			foreach ($array as $key => $value) {
				$result[$key] = $value;
			}
		}
		return $result;
	}
}

?>
