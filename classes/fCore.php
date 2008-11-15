<?php
/**
 * Provides low-level debugging, error and exception functionality
 * 
 * @copyright  Copyright (c) 2007-2008 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fCore
 * 
 * @version    1.0.0b
 * @changes    1.0.0b  The initial implementation [wb, 2007-09-25]
 */
class fCore
{
	// The following constants allow for nice looking callbacks to static methods
	const backtrace               = 'fCore::backtrace';
	const debug                   = 'fCore::debug';
	const dump                    = 'fCore::dump';
	const enableDebugging         = 'fCore::enableDebugging';
	const enableDynamicConstants  = 'fCore::enableDynamicConstants';
	const enableErrorHandling     = 'fCore::enableErrorHandling';
	const enableExceptionHandling = 'fCore::enableExceptionHandling';
	const expose                  = 'fCore::expose';
	const getOS                   = 'fCore::getOS';
	const getPHPVersion           = 'fCore::getPHPVersion';
	const handleError             = 'fCore::handleError';
	const handleException         = 'fCore::handleException';
	const reset                   = 'fCore::reset';
	const sendMessagesOnShutdown  = 'fCore::sendMessagesOnShutdown';
	const stringlike              = 'fCore::stringlike';
	const trigger                 = 'fCore::trigger';
	
	
	/**
	 * If global debugging is enabled
	 * 
	 * @var boolean
	 */
	static private $debug = NULL;
	
	/**
	 * If dynamic constants should be created
	 * 
	 * @var boolean
	 */
	static private $dynamic_constants = FALSE;
	
	/**
	 * Error destination
	 * 
	 * @var string
	 */
	static private $error_destination = 'html';
	
	/**
	 * An array of errors to be send to the destination upon page completion
	 * 
	 * @var array
	 */
	static private $error_message_queue = array();
	
	/**
	 * Exception destination
	 * 
	 * @var string
	 */
	static private $exception_destination = 'html';
	
	/**
	 * Exception handler callback
	 * 
	 * @var mixed
	 */
	static private $exception_handler_callback = NULL;
	
	/**
	 * Exception handler callback parameters
	 * 
	 * @var array
	 */
	static private $exception_handler_parameters = array();
	
	/**
	 * The message generated by the uncaught exception
	 * 
	 * @var string
	 */
	static private $exception_message = NULL;
	
	/**
	 * If this class is handling errors
	 * 
	 * @var boolean
	 */
	static private $handles_errors = FALSE;
	
	
	/**
	 * Creates a nicely formatted backtrace to the the point where this method is called
	 * 
	 * @param  integer $remove_lines  The number of trailing lines to remove from the backtrace
	 * @return string  The formatted backtrace
	 */
	static public function backtrace($remove_lines=0)
	{
		if ($remove_lines !== NULL && !is_numeric($remove_lines)) {
			$remove_lines = 0;
		}
		
		settype($remove_lines, 'integer');
		
		$doc_root  = realpath($_SERVER['DOCUMENT_ROOT']);
		$doc_root .= (substr($doc_root, -1) != '/' && substr($doc_root, -1) != '\\') ? '/' : '';
		
		$backtrace = debug_backtrace();
		
		while ($remove_lines > 0) {
			array_shift($backtrace);
			$remove_lines--;
		}
		
		$backtrace = array_reverse($backtrace);
		
		$bt_string = '';
		$i = 0;
		foreach ($backtrace as $call) {
			if ($i) {
				$bt_string .= "\n";
			}
			if (isset($call['file'])) {
				$bt_string .= str_replace($doc_root, '{doc_root}/', $call['file']) . '(' . $call['line'] . '): ';
			} else {
				$bt_string .= '[internal function]: ';
			}
			if (isset($call['class'])) {
				$bt_string .= $call['class'] . $call['type'];
			}
			if (isset($call['class']) || isset($call['function'])) {
				$bt_string .= $call['function'] . '(';
					$j = 0;
					if (!isset($call['args'])) {
						$call['args'] = array();
					}
					foreach ($call['args'] as $arg) {
						if ($j) {
							$bt_string .= ', ';
						}
						if (is_bool($arg)) {
							$bt_string .= ($arg) ? 'true' : 'false';
						} elseif (is_null($arg)) {
							$bt_string .= 'NULL';
						} elseif (is_array($arg)) {
							$bt_string .= 'Array';
						} elseif (is_object($arg)) {
							$bt_string .= 'Object(' . get_class($arg) . ')';
						} elseif (is_string($arg)) {
							// Shorten the UTF-8 string if it is too long
							if (strlen(utf8_decode($arg)) > 18) {
								preg_match('#^(.{0,15})#us', $arg, $short_arg);
								$arg = $short_arg[1] . '...';
							}
							$bt_string .= "'" . $arg . "'";
						} else {
							$bt_string .= (string) $arg;
						}
						$j++;
					}
				$bt_string .= ')';
			}
			$i++;
		}
		
		return $bt_string;
	}
	
	
	/**
	 * Performs a [http://php.net/call_user_func call_user_func()], while translating PHP 5.2 static callback syntax for PHP 5.1 and 5.0
	 * 
	 * Parameters can be passed either as a single array of parameters or as
	 * multiple parameters.
	 * 
	 * {{{
	 * #!php
	 * // Passing multiple parameters in a normal fashion
	 * fCore::call('Class::method', TRUE, 0, 'test');
	 * 
	 * // Passing multiple parameters in a parameters array
	 * fCore::call('Class::method', array(TRUE, 0, 'test'));
	 * }}}
	 * 
	 * To pass parameters by reference they must be assigned to an
	 * array by reference and the function/method being called must accept those
	 * parameters by reference. If either condition is not met, the parameter
	 * will be passed by value.
	 * 
	 * {{{
	 * #!php
	 * // Passing parameters by reference
	 * fCore::call('Class::method', array(&$var1, &$var2));
	 * }}}
	 * 
	 * @param  callback $callback    The function or method to call
	 * @param  array    $parameters  The parameters to pass to the function/method
	 * @return mixed  The return value of the called function/method
	 */
	static public function call($callback, $parameters=array())
	{
		// Fix PHP 5.0 and 5.1 static callback syntax
		if (is_string($callback) && self::getPHPVersion() < '5.2.0' && strpos($callback, '::') !== FALSE) {
			$callback = explode('::', $callback);
		}
		
		$parameters = array_slice(func_get_args(), 1);
		if (sizeof($parameters) == 1 && is_array($parameters[0])) {
			$parameters = $parameters[0];	
		}
		
		return call_user_func_array($callback, $parameters);
	}
	
	
	/**
	 * Translates a Class::method style static method callback to array style for compatibility with PHP 5.0 and 5.1 and built-in PHP functions
	 * 
	 * @param  callback $callback  The callback to translate
	 * @return array  The translated callback
	 */
	static public function callback($callback)
	{
		if (is_string($callback) || strpos($callback, '::') !== FALSE) {
			return explode('::', $callback);	
		}
		
		return $callback;
	}
	
	
	/**
	 * Checks an error/exception destination to make sure it is valid
	 * 
	 * @param  string $destination  The destination for the exception. An email, file or the string `'html'`.
	 * @return string|boolean  `'email'`, `'file'`, `'html'` or `FALSE`
	 */
	static private function checkDestination($destination)
	{
		if ($destination == 'html') {
			return 'html';
		}
		
		if (preg_match('#^[a-z0-9_.\-\']+@([a-z0-9\-]+\.){1,}([a-z]{2,})$#iD', $destination)) {
			return 'email';
		}
		
		$path_info     = pathinfo($destination);
		$dir_exists    = file_exists($path_info['dirname']);
		$dir_writable  = ($dir_exists) ? is_writable($path_info['dirnam']) : FALSE;
		$file_exists   = file_exists($destination);
		$file_writable = ($file_exists) ? is_writable($destination) : FALSE;
		
		if (!$dir_exists || ($dir_exists && ((!$file_exists && !$dir_writable) || ($file_exists && !$file_writable)))) {
			return FALSE;
		}
			
		return 'file';
	}
	
	
	/**
	 * Composes text using fText if loaded
	 * 
	 * @param  string  $message    The message to compose
	 * @param  mixed   $component  A string or number to insert into the message
	 * @param  mixed   ...
	 * @return string  The composed and possible translated message
	 */
	static private function compose($message)
	{
		$args = array_slice(func_get_args(), 1);
		
		if (class_exists('fText', FALSE)) {
			return call_user_func_array(
				array('fText', 'compose'),
				array($message, $args)
			);
		} else {
			return vsprintf($message, $args);
		}
	}
	
	
	/**
	 * Prints a debugging message if global or code-specific debugging is enabled
	 * 
	 * @param  string  $message  The debug message
	 * @param  boolean $force    If debugging should be forced even when global debugging is off
	 * @return void
	 */
	static public function debug($message, $force)
	{
		if ($force || self::$debug) {
			self::expose($message, FALSE);
		}
	}
	
	
	/**
	 * Creates a string representation of any variable using predefined strings for booleans, `NULL` and empty strings
	 * 
	 * The string output format of this method is very similar to the output of
	 * [http://php.net/print_r print_r()] except that the following values
	 * are represented as special strings:
	 *   
	 *  - `TRUE`: `'{true}'`
	 *  - `FALSE`: `'{false}'`
	 *  - `NULL`: `'{null}'`
	 *  - `''`: `'{empty_string}'`
	 * 
	 * @param  mixed $data  The value to dump
	 * @return string  The string representation of the value
	 */
	static public function dump($data)
	{
		if (is_bool($data)) {
			return ($data) ? '{true}' : '{false}';
		
		} elseif (is_null($data)) {
			return '{null}';
		
		} elseif ($data === '') {
			return '{empty_string}';
		
		} elseif (is_array($data) || is_object($data)) {
			
			ob_start();
			var_dump($data);
			$output = ob_get_contents();
			ob_end_clean();
			
			// Make the var dump more like a print_r
			$output = preg_replace('#=>\n(  )+(?=[a-zA-Z]|&)#m', ' => ', $output);
			$output = str_replace('string(0) ""', '{empty_string}', $output);
			$output = preg_replace('#=> (&)?NULL#', '=> \1{null}', $output);
			$output = preg_replace('#=> (&)?bool\((false|true)\)#', '=> \1{\2}', $output);
			$output = preg_replace('#string\(\d+\) "#', '', $output);
			$output = preg_replace('#"(\n(  )*)(?=\[|\})#', '\1', $output);
			$output = preg_replace('#(?:float|int)\((-?\d+(?:.\d+)?)\)#', '\1', $output);
			$output = preg_replace('#((?:  )+)\["(.*?)"\]#', '\1[\2]', $output);
			$output = preg_replace('#(?:&)?array\(\d+\) \{\n((?:  )*)((?:  )(?=\[)|(?=\}))#', "Array\n\\1(\n\\1\\2", $output);
			$output = preg_replace('/object\((\w+)\)#\d+ \(\d+\) {\n((?:  )*)((?:  )(?=\[)|(?=\}))/', "\\1 Object\n\\2(\n\\2\\3", $output);
			$output = preg_replace('#^((?:  )+)}(?=\n|$)#m', "\\1)\n", $output);
			$output = substr($output, 0, -2) . ')';
			
			// Fix indenting issues with the var dump output
			$output_lines = explode("\n", $output);
			$new_output = array();
			$stack = 0;
			foreach ($output_lines as $line) {
				if (preg_match('#^((?:  )*)([^ ])#', $line, $match)) {
					$spaces = strlen($match[1]);
					if ($spaces && $match[2] == '(') {
						$stack += 1;
					}
					$new_output[] = str_pad('', ($spaces)+(4*$stack)) . $line;
					if ($spaces && $match[2] == ')') {
						$stack -= 1;
					}
				} else {
					$new_output[] = str_pad('', ($spaces)+(4*$stack)) . $line;
				}
			}
			
			return join("\n", $new_output);
			
		} else {
			return (string) $data;
		}
	}
	
	
	/**
	 * Enables debug messages globally, i.e. they will be shown for any call to ::debug()
	 * 
	 * @param  boolean $flag  If debugging messages should be shown
	 * @return void
	 */
	static public function enableDebugging($flag)
	{
		self::$debug = (boolean) $flag;
	}
	
	
	/**
	 * Turns on a feature where undefined constants are automatically created with the string value equivalent to the name
	 * 
	 * This functionality only works if ::enableErrorHandling() has been
	 * called first. This functionality may have a very slight performance
	 * impact since a `E_STRICT` error message must be captured and then a
	 * call to [http://php.net/define define()] is made.
	 * 
	 * @return void
	 */
	static public function enableDynamicConstants()
	{
		if (!self::$handles_errors) {
			throw new fProgrammerException(
				'Dynamic constants can not be enabled unless error handling has been enabled via %s',
				__CLASS__ . '::enableErrorHandling()'
			);
		}
		self::$dynamic_constants = TRUE;
	}
	
	
	/**
	 * Turns on developer-friendly error handling that includes context information including a backtrace and superglobal dumps
	 * 
	 * All errors that match the current
	 * [http://php.net/error_reporting error_reporting()] level will be
	 * redirected to the destination and will include a full backtrace. In
	 * addition, dumps of the following superglobals will be made to aid in
	 * debugging:
	 * 
	 *  - `$_SERVER`
	 *  - `$_POST`
	 *  - `$_GET`
	 *  - `$_SESSION`
	 *  - `$_FILES`
	 *  - `$_COOKIE`
	 * 
	 * The superglobal dumps are only done once per page, however a backtrace
	 * in included for each error.
	 * 
	 * If an email address is specified for the destination, only one email
	 * will be sent per script execution. If both error and
	 * [enableExceptionHandling() exception handling] are set to the same
	 * email address, the email will contain both errors and exceptions.
	 * 
	 * @param  string $destination  The destination for the errors and context information - an email address, a file path or the string `'html'`
	 * @return void
	 */
	static public function enableErrorHandling($destination)
	{
		if (!self::checkDestination($destination)) {
			return;
		}
		self::$error_destination = $destination;
		self::$handles_errors    = TRUE;
		set_error_handler(self::callback(self::handleError));
	}
	
	
	/**
	 * Turns on developer-friendly uncaught exception handling that includes context information including a backtrace and superglobal dumps
	 * 
	 * Any uncaught exception will be redirected to the destination specified,
	 * and the page will execute the `$closing_code` callback before exiting.
	 * The destination will receive a message with the exception messaage, a
	 * full backtrace and dumps of the following superglobals to aid in
	 * debugging:
	 * 
	 *  - `$_SERVER`
	 *  - `$_POST`
	 *  - `$_GET`
	 *  - `$_SESSION`
	 *  - `$_FILES`
	 *  - `$_COOKIE`
	 * 
	 * The superglobal dumps are only done once per page, however a backtrace
	 * in included for each error.
	 * 
	 * If an email address is specified for the destination, only one email
	 * will be sent per script execution.
	 * 
	 * If an email address is specified for the destination, only one email
	 * will be sent per script execution. If both exception and
	 * [enableErrorHandling() error handling] are set to the same
	 * email address, the email will contain both exceptions and errors.
	 * 
	 * @param  string   $destination   The destination for the exception and context information - an email address, a file path or the string `'html'`
	 * @param  callback $closing_code  This callback will happen after the exception is handled and before page execution stops. Good for printing a footer.
	 * @param  array    $parameters    The parameters to send to `$closing_code`
	 * @return void
	 */
	static public function enableExceptionHandling($destination, $closing_code=NULL, $parameters=array())
	{
		if (!self::checkDestination($destination)) {
			return;
		}
		self::$exception_destination        = $destination;
		self::$exception_handler_callback   = $closing_code;
		if (!is_object($parameters)) {
			settype($parameters, 'array');
		} else {
			$parameters = array($parameters);	
		}
		self::$exception_handler_parameters = $parameters;
		set_exception_handler(self::callback(self::handleException));
	}
	
	
	/**
	 * Prints the ::dump() of a value in a pre tag with the class `exposed`
	 * 
	 * @param  mixed $data  The value to show
	 * @return void
	 */
	static public function expose($data)
	{
		echo '<pre class="exposed">' . htmlspecialchars((string) self::dump($data), ENT_QUOTES, 'UTF-8') . '</pre>';
	}
	
	
	/**
	 * Generates some information about the context of an error or exception
	 * 
	 * @return string  A string containing `$_SERVER`, `$_GET`, `$_POST`, `$_FILES`, `$_SESSION` and `$_COOKIE`
	 */
	static private function generateContext()
	{
		return self::compose('Context') . "\n-------" .
			"\n\n\$_SERVER\n"  . self::dump($_SERVER) .
			"\n\n\$_POST\n" . self::dump($_POST) .
			"\n\n\$_GET\n" . self::dump($_GET) .
			"\n\n\$_FILES\n"   . self::dump($_FILES) .
			"\n\n\$_SESSION\n" . self::dump((isset($_SESSION)) ? $_SESSION : NULL) .
			"\n\n\$_COOKIE\n" . self::dump($_COOKIE);
	}
	
	
	/**
	 * Returns the (generalized) operating system the code is currently running on
	 * 
	 * @return string  Either `'windows'`, `'solaris'` or `'linux/unix'` (linux, *BSD)
	 */
	static public function getOS()
	{
		$uname = php_uname('s');
		
		if (stripos($uname, 'linux') !== FALSE) {
			return 'linux/unix';
		}
		if (stripos($uname, 'bsd') !== FALSE) {
			return 'linux/unix';
		}
		if (stripos($uname, 'solaris') !== FALSE || stripos($uname, 'sunos') !== FALSE) {
			return 'solaris';
		}
		if (stripos($uname, 'windows') !== FALSE) {
			return 'windows';
		}
		
		self::trigger(
			'warning',
			self::compose(
				'Unable to reliably determine the server OS. Defaulting to %s.',
				"'linux/unix'"
			)
		);
		
		return 'linux/unix';
	}
	
	
	/**
	 * Returns the version of PHP running, ignoring any information about the OS
	 * 
	 * @return string  The PHP version in the format major.minor.version
	 */
	static public function getPHPVersion()
	{
		static $version = NULL;
		
		if ($version === NULL) {
			$version = phpversion();
			$version = preg_replace('#^(\d+\.\d+\.\d+).*$#D', '\1', $version);
		}
		
		return $version;
	}
	
	
	/**
	 * Handles an error, creating the necessary context information and sending it to the specified destination
	 * 
	 * @internal
	 * 
	 * @param  integer $error_number   The error type
	 * @param  string  $error_string   The message for the error
	 * @param  string  $error_file     The file the error occured in
	 * @param  integer $error_line     The line the error occured on
	 * @param  array   $error_context  A references to all variables in scope at the occurence of the error
	 * @return void
	 */
	static public function handleError($error_number, $error_string, $error_file=NULL, $error_line=NULL, $error_context=NULL)
	{
		if (self::$dynamic_constants && $error_number == E_NOTICE) {
			if (preg_match("#^Use of undefined constant (\w+) - assumed '\w+'\$#D", $error_string, $matches)) {
				define($matches[1], $matches[1]);
				return;
			}		
		}
		
		if ((error_reporting() & $error_number) == 0) {
			return;
		}
		
		$doc_root  = realpath($_SERVER['DOCUMENT_ROOT']);
		$doc_root .= (substr($doc_root, -1) != '/' && substr($doc_root, -1) != '\\') ? '/' : '';
		
		$error_file = str_replace($doc_root, '{doc_root}/', $error_file);
		
		$backtrace = self::backtrace(1);
		
		$error_string = preg_replace('# \[<a href=\'.*?</a>\]: #', ': ', $error_string);
		
		$error   = self::compose('Error') . "\n-----\n" . $backtrace . "\n" . $error_string;
		
		self::sendMessageToDestination('error', $error);
	}
	
	
	/**
	 * Handles an uncaught exception, creating the necessary context information, sending it to the specified destination and finally executing the closing callback
	 * 
	 * @internal
	 * 
	 * @param  object $exception  The uncaught exception to handle
	 * @return void
	 */
	static public function handleException($exception)
	{
		if ($exception instanceof fPrintableException) {
			$message = $exception->formatTrace() . "\n" . $exception->getMessage();
		} else {
			$message = $exception->getTraceAsString() . "\n" . $exception->getMessage();
		}
		$message = self::compose("Uncaught Exception") . "\n------------------\n" . trim($message);
		
		if (self::$exception_destination != 'html' && $exception instanceof fPrintableException) {
			$exception->printMessage();
		}
				
		self::sendMessageToDestination('exception', $message);
		
		if (self::$exception_handler_callback === NULL) {
			return;
		}
				
		try {
			self::call(self::$exception_handler_callback, self::$exception_handler_parameters);
		} catch (Exception $e) {
			self::trigger(
				'error',
				self::compose(
					'An exception was thrown in the %s closing code callback',
					'setExceptionHandling()'
				)
			);
		}
	}
	
	
	/**
	 * Resets the configuration of the class
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	static public function reset()
	{
		restore_error_handler();
		restore_exception_handler();
		
		self::$debug                        = NULL;
		self::$dynamic_constants            = FALSE;
		self::$error_destination            = 'html';
		self::$error_message_queue          = array();
		self::$exception_destination        = 'html';
		self::$exception_handler_callback   = NULL;
		self::$exception_handler_parameters = array();
		self::$exception_message            = NULL;
		self::$handles_errors               = FALSE;
		self::$toss_callbacks               = array();
	}
	
	
	/**
	 * Sends an email or writes a file with messages generated during the page execution
	 * 
	 * This method prevents multiple emails from being sent or a log file from
	 * being written multiple times for one script execution.
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	static public function sendMessagesOnShutdown()
	{
		$subject = self::compose(
			'[%1$s] One or more errors or exceptions occured at %2$s',
			$_SERVER['SERVER_NAME'],
			date('Y-m-d H:i:s')
		);
		
		$messages = array();
		
		if (self::$error_message_queue) {
			$message = join("\n\n", self::$error_message_queue);
			$messages[self::$error_destination] = $message;
		}
		
		if (self::$exception_message) {
			if (isset($messages[self::$exception_destination])) {
				$messages[self::$exception_destination] .= "\n\n";
			} else {
				$messages[self::$exception_destination] = '';
			}
			$messages[self::$exception_destination] .= self::$exception_message;
		}
		
		foreach ($messages as $destination => $message) {
			if (self::checkDestination($destination) == 'email') {
				mail($destination, $subject, $message . "\n\n" . self::generateContext());
			
			} else {
				$handle = fopen($destination, 'a');
				fwrite($handle, $subject . "\n\n");
				fwrite($handle, $message . "\n\n");
				fwrite($handle, self::generateContext() . "\n\n");
				fclose($handle);
			}
		}
	}
	
	
	/**
	 * Handles sending a message to a destination
	 * 
	 * If the destination is an email address or file, the messages will be
	 * spooled up until the end of the script execution to prevent multiple
	 * emails from being sent or a log file being written to multiple times.
	 * 
	 * @param  string $type     If the message is an error or an exception
	 * @param  string $message  The message to send to the destination
	 * @return void
	 */
	static private function sendMessageToDestination($type, $message)
	{
		$destination = ($type == 'exception') ? self::$exception_destination : self::$error_destination;
		
		if ($destination == 'html') {
			static $shown_context = FALSE;
			if (!$shown_context) {
				self::expose(self::generateContext());
				$shown_context = TRUE;
			}
			self::expose($message);
			return;
		}

		static $registered_function = FALSE;
		if (!$registered_function) {
			register_shutdown_function(self::callback(self::sendMessagesOnShutdown));
			$registered_function = TRUE;
		}
		
		if ($type == 'error') {
			self::$error_message_queue[] = $message;
		} else {
			self::$exception_message = $message;
		}
	}
	
	
	/**
	 * Returns `TRUE` for non-empty strings, numbers, objects, empty numbers and string-like numbers (such as `0`, `0.0`, `'0'`)
	 * 
	 * @param  mixed $value  The value to check
	 * @return boolean  If the value is string-like
	 */
	static public function stringlike($value)
	{
		if (!$value && !is_numeric($value)) {
			return FALSE;
		}
		
		if (is_resource($value) || is_array($value) || $value === TRUE) {
			return FALSE;
		}
		
		if (is_string($value) && !trim($value)) {
			return FALSE;	
		}
		
		return TRUE;
	}
	
	
	/**
	 * Triggers a user-level error
	 * 
	 * The default error handler in PHP will show the line number of this
	 * method as the triggering code. To get a full backtrace, use
	 * ::enableErrorHandling().
	 * 
	 * @param  string $error_type  The type of error to trigger: `'error'`, `'warning'`, `'notice'`
	 * @param  string $message     The error message
	 * @return void
	 */
	static public function trigger($error_type, $message)
	{
		$valid_error_types = array('error', 'warning', 'notice');
		if (!in_array($error_type, $valid_error_types)) {
			self::toss(
				'fProgrammerException',
				self::compose(
					'Invalid error type, %1$s, specified. Must be one of: %2$s.',
					self::dump($error_type),
					join(', ', $valid_error_types)
				)
			);
		}
		
		static $error_type_map = array(
			'error'   => E_USER_ERROR,
			'warning' => E_USER_WARNING,
			'notice'  => E_USER_NOTICE
		);
		
		trigger_error($message, $error_type_map[$error_type]);
	}
	
	
	/**
	 * Forces use as a static class
	 * 
	 * @return fCore
	 */
	private function __construct() { }
}



/**
 * Copyright (c) 2007-2008 William Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */