<?php

App::import('Vendor', 'Facebook.facebook/src/facebook');

class FacebookComponent extends Component {

	var $name = 'Facebook';
	var $components = array('Auth', 'Session');
	var $uses = array();

	var $config = array(
		'app_id' => null,
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
		if(!$this->getSession('access_token')) {
			$this->getToken();
		}

		$defaults = array(
			'access_token' => $this->getSession('access_token')
		);
		$url = $this->getUrl('graph', $request, array_merge($defaults, $options));
		$response = $this->oauthRequest($url);

		return $response;

	}

/**
 * login() ~ handle facebook login
 */
	function login($options = array()) {

		// check for any login information from facebook
		if(!empty($this->params['url']['code'])) {

			// check state from login
			$state = $this->params['url']['state'];
			if(empty($state) || $state != $this->getSession('state')) {
				trigger_error("The state does not match. You may be a victim of CSRF.");
				exit;
			}

			$this->setSession('code', $this->params['url']['code']);
			return $this->controller->redirect(array('controller' => $this->params['controller'], 'action' => 'index'));
		}

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

		$defaults = array(
			'client_id' => $this->config['app_id'],
			'client_secret' => $this->config['app_secret'],
			'redirect_uri' => $this->url,
			'code' => $this->getSession('code')
		);

		$url = $this->getUrl('graph', 'oauth/access_token', array_merge($defaults, $options));
		$response = $this->oauthRequest($url); debug($response);
		parse_str($response, $response);

		if(!empty($response['access_token'])) {
			// update session
			$this->setSession('access_token', $response['access_token']);
			if(!empty($response['expires'])) $this->setSession('expires', $response['expires']);

			return true;
		}

		return false;

	}

/**
 * logout()
 */
	function logout() {
		$this->setSession(null);
		$url = $this->getLogoutUrl(); //debug($url); exit;
		return $this->controller->redirect($url);
	}

/**
 * getLogoutUrl()
 */
	protected function getLogoutUrl($options = array()) {
		$defaults = array(
			'client_id' => $this->config['app_id'],
			'access_token' => $this->getSession('access_token'),
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
	function setSession($param = null, $value = null) {
		return $this->Session->write('Auth.' . $this->name . ($param ? '.' . $param : null), $value);
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
 * oauthRequest()
 */
	protected function oauthRequest($url, $options = array()) {

		$response = $this->makeRequest($url, $options);
		$result = json_decode($response, true);
		$response = $result ?: $response;

		// results are returned, errors are thrown
		if (is_array($response) && !empty($response['error'])) {
			trigger_error($response['error']['type'] . ': ' . $response['error']['message'], E_USER_WARNING);
		}

		return $response;

	}

/**
 * makeRequest()
 */
	protected function makeRequest($url, $options = array()) {

		if(!$this->config['curl']) {

			$timeout = self::$CURL_OPTS[CURLOPT_CONNECTTIMEOUT];
			$context = stream_context_create(array('http' => array('timeout' => $timeout)));
			if (IS_WIN) ini_set('default_socket_timeout', $timeout);
			$response = @file_get_contents($url, false, $context);

		} else {

			$ch = curl_init($url);

			curl_setopt_array($ch, array_merge_keys(self::$CURL_OPTS, $options));
			$response = curl_exec($ch);

			if (curl_errno($ch) == 60) { // CURLE_SSL_CACERT
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
		$this->url = $this->url();

		// load legacy api on demand
		if($this->config['load_legacy_api']) {
			$this->Facebook = new Facebook(array('appId' => $settings['app_id'], 'secret' => $settings['app_secret']));
		}

		// use bundled certificate
		if(!$this->config['certificate']) $this->config['certificate'] = dirname(__FILE__) . DS . '..' . DS . '..' . DS . 'vendors' . DS . 'facebook' . DS . 'src' . DS . 'fb_ca_chain_bundle.crt';
		self::$CURL_OPTS[CURLOPT_CAINFO] = $this->config['certificate'];

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
