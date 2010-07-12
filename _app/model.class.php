<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU Public License
 */

if ( !isset($contrSetup) ) die('Direct access to this file is not allowed');

/**
 * Model
 * @abstract
 */
class model
{
	const
		version = '1.2.0'
		;

	public
		$configMissing = FALSE,
		$debugMode     = TRUE
		;

	/**
	 * Initialize
	 * @param object $contr
	 */
	function __construct($contr)
	{
		$app = $this;

		$this->contr = $contr;

		set_error_handler(array($this, 'error'), E_ALL);

		$this->timerStart = $this->timer_start();

		$this->debugOutput['version'] = model::version;

		/**
		 * Get the user's real IP address
		 */
		$this->userIp = !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : ( !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : ( !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '' ) );

		/*
		 * Load configuration
		 */
		if ( is_file($this->contr->rootPath . '_config.php') )
		{
			require($this->contr->rootPath . '_config.php');
		}
		else
		{
			if ( is_file($this->contr->rootPath . '_config.default.php') )
			{
				require($this->contr->rootPath . '_config.default.php');

				$this->configMissing = TRUE;
			}
			else
			{
				$this->error(FALSE, 'Missing configuration file.');
			}
		}

		if ( isset($config) )
		{
			foreach ( $config as $k => $v )
			{
				$this->{$k} = $v;
			}

			unset($config);
		}

		/**
		 * Authenticity token to secure forms
		 * @see http://en.wikipedia.org/wiki/Cross-site_request_forgery
		 */
		if ( !session_id() )
		{
			session_start();
		}

		$this->authToken = sha1(session_id() . phpversion() . $this->sysPassword . $this->userIp . ( !empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '' ));

		if ( ( !empty($_POST) && !isset($_POST['auth-token']) ) || ( isset($_POST['auth-token']) && $_POST['auth-token'] != $this->authToken ) )
		{
			$this->error(FALSE, 'The form has expired, please go back and try again (wrong or missing authenticity token).', __FILE__, __LINE__);
		}

		if ( isset($_POST['auth-token']) )
		{
			unset($_POST['auth-token']);
		}

		/*
		 * View
		 */
		if ( !class_exists('view') )
		{
			require($this->contr->rootPath . '_app/view.class.php');
		}

		$this->view = new view($this);

		/*
		 * Plugins
		 */
		if ( !class_exists('plugin') )
		{
			require($this->contr->rootPath . '_app/plugin.class.php');
		}

		if ( $handle = opendir($dir = $this->contr->pluginPath) )
		{
			while ( ( $file = readdir($handle) ) !== FALSE )
			{
				if ( is_file($dir . $file) && preg_match('/\.php$/', $file) )
				{
					$this->plugin_load($file);
				}
			}

			closedir($handle);
		}

		$this->hook('init');

		$this->input_sanitize();
	}

	/**
	 * Initialize plugin
	 * @param string plugin
	 */
	private function plugin_load($file)
	{
		$plugin = new plugin($this, $file);

		$this->pluginsLoaded[$plugin->info['name']] = $plugin;
	}

	/**
	 * Hook a plugin into the code
	 * @param string plugin
	 */
	function hook($hook, &$params = array())
	{
		if ( !empty($this->hooksRegistered[$hook]) )
		{
			/**
			 * Hook plugins in the right order
			 */
			usort($this->hooksRegistered[$hook], array($this, 'hook_sort'));

			foreach ( $this->hooksRegistered[$hook] as $plugin )
			{
				$this->pluginsLoaded[$plugin['name']]->hook($hook, $plugin['order'], $params);
			}
		}
	}

	/**
	 * Sort plugins by order of inclusion
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	private function hook_sort($a, $b)
	{
		return ( $a['order'] == $b['order'] ) ? 0 : $a['order'] > $b['order'] ? 1 : -1;
	}

	/**
	 * Register a hook
	 * @param string $name
	 * @param array $hooks
	 * @param array $params
	 */
	function hook_register($pluginName, $hooks)
	{
		foreach ( $hooks as $hook => $order )
		{
			$this->hooksRegistered[$hook][] = array('name' => $pluginName, 'order' => $order);
		}
	}

	/**
	 * Check to see if required plugins are ready
	 * @param array $dependencies
	 */
	function check_dependencies($dependencies)
	{
		$missing = array();

		foreach ( $dependencies as $dependency )
		{
			if ( empty($this->{$dependency}->ready ) )
			{
				$missing[] = $dependency;
			}
		}

		if ( $missing )
		{
			$this->error(FALSE, 'Plugins required for this page: `' . implode('`, `', $missing) . '`.', __FILE__, __LINE__);
		}
	}

	/**
	 * Undo magic quotes
	 * @param mixed $v
	 * @return mixed $v
	 * @see http://php.net/magic_quotes
	 */
	private function undo_magic_quotes($v)
	{
		if ( is_array($v) )
		{
			return array_map(array($this, 'undo_magic_quotes'), $v);
		}
		else
		{
			return stripslashes($v);
		}
	}

	/**
	 * Convert special characters to HTML entities
	 * @param mixed $v
	 * @return mixed $v
	 */
	private function html_safe($v)
	{
		if ( is_array($v) )
		{
			return array_map(array($this, 'html_safe'), $v);
		}
		else
		{
			return htmlentities($v, ENT_QUOTES, 'UTF-8');
		}
	}

	/**
	 * Sanatize user input
	 */
	private function input_sanitize()
	{
    	/**
		 * Recursively remove magic quotes
		 */
		if ( function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc() )
	    {
			$_GET    = array_map(array($this, 'undo_magic_quotes'), $_GET);
			$_POST   = array_map(array($this, 'undo_magic_quotes'), $_POST);
			$_COOKIE = array_map(array($this, 'undo_magic_quotes'), $_COOKIE);
		}

		/*
		 * Check integrety of confirmed information (see $this->confirm())
		 */
		if ( isset($_POST['confirm']) && !empty($_POST['get-data']) && !empty($_GET) )
		{
			if ( unserialize($_POST['get-data']) != $_GET )
			{
				unset($_POST['confirm']);
			}
		}

		/*
		 * $_POST and $_GET values can't be trusted
		 * If neccesary, access them through $this->POST_raw and $this->GET_raw
		 */
		$this->POST_raw = isset($_POST) ? $_POST : array();
		$this->GET_raw  = isset($_GET)  ? $_GET  : array();

		unset($_POST, $_GET);

		foreach ( $this->POST_raw as $k => $v )
		{
			$this->POST_html_safe[$k] = $this->html_safe($v);
		}

		foreach ( $this->GET_raw as $k => $v )
		{
			$this->GET_html_safe[$k] = $this->html_safe($v);
		}

		$this->hook('input_sanitize');
	}

	/**
	 * Make a string HTML safe
	 * @param string $v
	 * @return string
	 */
	static function h($v)
	{
		return htmlentities($v, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Translate a string
	 * @param string $v
	 * @return string
	 */
	function t($v, $args = '')
	{
		$params = array(
			'string' => $this->h($v)
			);

		$this->hook('translate', $params);

		return $args ? ( is_array($args) ? vsprintf($params['string'], $args) : sprintf($params['string'], $args) ) : $v;
	}

	/**
	 * Format a date
	 * @param string $v
	 * @return string
	 */
	function format_date($date, $type = 'datetime')
	{
		$params = array(
			'date' => $date,
			'type' => $type
			);

		$this->hook('format_date', $params);

		return $params['date'];
	}

	/**
	 * Send e-mail
	 * @param string $to
	 * @param string $subject
	 * @param string $body
	 * @param string $headers
	 * @return bool
	 */
	function email($to, $subject, $body, $headers = array())
	{
		$params = array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers
			);

		$this->hook('email', $params);

		return $params['success'];
	}

	/**
	 * Clear cache
	 */
	function clear_cache()
	{
		$this->hook('clear_cache');
	}

	/**
	 * Route URLs
	 * @param string $route
	 * @return string
	 */
	function route($route)
	{
		$route = $this->view->rootPath . ( $this->urlRewrite ? '' : '?q=' ) . $route;

		return $route;
	}

	/**
	 * Redirect to confirmation page
	 * @param string $notice
	 */
	function confirm($notice)
	{
		$this->view->notice  = $notice;
		$this->view->getData = $this->h(serialize($this->GET_raw));

		$this->view->load('confirm.html.php');

		$this->end();
	}

	/**
	 * Start a timer
	 * @return int
	 */
	function timer_start()
	{
		return array_sum(explode(' ', microtime()));
	}

	/**
	 * End a timer
	 * @param int $timerStart
	 * @return int
	 */
	function timer_end($timerStart)
	{
		return round($this->timer_start() - $timerStart, 3) . ' sec';
	}

	/**
	 * Wrap up and exit
	 */
	function end()
	{
		if ( empty($this->contr->standAlone) )
		{
			$this->hook('footer');
		}

		$this->view->output();

		$this->debugOutput['execution time']['all'] = $this->timer_end($this->timerStart);
		$this->debugOutput['peak memory usage']     = round(memory_get_peak_usage() / 1024 / 1024, 3) . ' MB';

		$this->hook('end');

		exit;
	}

	/**
	 * Display an error message
	 * @param string $errNo
	 * @param string $errStr
	 * @param string $errFile
	 * @param string $errLine
	 * @param array $errCont
	 */
	function error($errNo, $errStr, $errFile = '', $errLine = '', $errCont = array())
	{
		$this->hook('error');

		// If error has been supressed with @
		if ( !error_reporting() )
		{
			return;
		}

		if ( !headers_sent() )
		{
			header('HTTP/1.1 503 Service Temporarily Unavailable');
		}

		echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
			<html id="swiftlet_error">
				<head>
					<title>-</title>

					<style>
						#swiftlet_error body {
							color: #000;
							font-family: monospace;
							font-size: 12px;
						}

						#swiftlet_error div {
							background: #FFC;
							border: 1px solid #DD7;
							border-radius: 3px;
							-moz-border-radius: 3px;
							-webkit-border-radius: 3px;
							color: #300;
							padding: 1em 2em;
						}

						#swiftlet_error a:link, #swiftlet_error a:hover, #swiftlet_error a:active, #swiftlet_error a:visited {
							color: #AA4;
						}
					</style>
				</head>
				<body>
					<div>
						<p>
							Oops! An error occured.
						</p>

						' . ( $this->debugMode ? '
						<p>[
							<a href="javascript: void(0);" onclick="
								e = document.getElementById(\'error_debug_mode\');
								e.style.display = e.style.display == \'none\' ? \'block\' : \'none\';
								">
								DEBUG MODE</a> ]
						</p>

						<p id="error_debug_mode" style="display: block; margin-left: 2em;">
							' . ( $errStr   ? 'MESSAGE: <strong><br/><br/>' . $errStr  . '</strong><br/><br/>' : '' ) . '
							' . ( $errFile  ? 'FILE:    <strong>' . $errFile . '</strong><br/>' : '' ) . '
							' . ( $errLine  ? 'LINE:    <strong>' . $errLine . '</strong><br/>' : '' ) . '
							' . ( $errNo    ? 'NO:      <strong>' . $errNo   . '</strong><br/>' : '' ) . '
						</p>' : '' ) . '
			';

		if ( $this->debugMode )
		{
			if ( count(debug_backtrace(FALSE)) > 1 )
			{
				echo '
					<p>[
						<a href="javascript: void(0);" onclick="
							e = document.getElementById(\'error_backtrace\');
							e.style.display = e.style.display == \'none\' ? \'block\' : \'none\';
							">BACKTRACE</a> ]
					</p>

					<pre id="error_backtrace" style="display: none; margin-left: 2em;">';

				print_r(array_pop(debug_backtrace(FALSE)));

				echo '</pre>';
			}

			if ( empty($this->debugOutput) )
			{
				echo '
					<p>[
						<a href="javascript: void(0);" onclick="
							e = document.getElementById(\'error_debug_output\');
							e.style.display = e.style.display == \'none\' ? \'block\' : \'none\';
							">DEBUG OUTPUT</a> ]
					</p>

					<pre id="error_debug_output" style="display: none; margin-left: 2em;">';

				print_r($this->debugOutput);

				echo '</pre>';
			}
		}

		if ( $this->adminEmail )
		{
			echo '
						<p>
							<br/>
							Please contact us at <a href="mailto:' . $this->h($this->adminEmail) . '">' . $this->h($this->adminEmail) . '</a>.
						</p>
				';
		}

		echo '
					</div>
				</body>
			</html>
			';

		exit;
	}
}