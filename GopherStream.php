<?php
/**
 * A stream wrapper implementing Gopher support in PHP. Useful, huh?
 *
 * @author Adam Harvey <aharvey@php.net>
 * @license http://www.opensource.org/licenses/mit-license.php
 * @package php-gopher
 * @version 0.1
 */

/**
 * Class implementing a Gopher stream wrapper.
 *
 * @package php-gopher
 */
class GopherStream {
	/**
	 * The context passed into the calling function. Presently ignored.
	 *
	 * @var resource
	 */
	public $context;

	/**
	 * A cache for the last directory opened by {@link dir_opendir
	 * dir_opendir()}.
	 *
	 * @var array
	 */
	protected $dir;

	/**
	 * The last Gopher TCP socket opened by a call to {@link stream_open
	 * stream_open()}.
	 *
	 * @var resource
	 */
	protected $socket;

	/**
	 * Constructs a new Gopher stream wrapper.
	 */
	public function __construct() {
		$this->dir = null;
		$this->socket = null;
	}

	/**
	 * Closes the currently open directory.
	 *
	 * Internally this just means we destroy the cache, since the socket
	 * isn't left open after {@link dir_opendir dir_opendir()} is called
	 * anyway.
	 *
	 * @return boolean
	 */
	public function dir_closedir() {
		$this->dir = null;
		return true;
	}

	/**
	 * Opens a directory, or in Gopher terms, an index.
	 *
	 * If a valid index isn't returned, this function will return false and
	 * trigger an E_USER_WARNING error.
	 *
	 * It's probably debatable how useful this actually is, since it only
	 * returns the relative URL as each directory item and doesn't provide
	 * any way of getting the other metadata provided in the index such as
	 * page title and type, but it's better than nothing. Probably.
	 *
	 * @param string $path The URL to open.
	 * @param integer $options The options passed by PHP. At present, this 
	 *                         only indicates safe mode, and we're going to
	 *                         ignore that completely.
	 * @return boolean
	 */
	public function dir_opendir($path, $options) {
		$parts = self::parseURL($path);

		$client = stream_socket_client($parts['host'].':'.$parts['port']);

		if (!$client) {
			return false;
		}

		if (isset($parts['path'])) {
			$path = self::normaliseRequest($parts['path']);
		}
		else {
			$path = '';
		}

		fwrite($client, "$path\r\n");

		/* The spec requires CRLF, but we'll split just on LF and trim
		 * later to cover any non-compliant implementations. */
		$dir = array();
		for ($line = fgets($client); !feof($client); $line = fgets($client)) {
			$line = trim($line);
			if (strlen($line) > 0) {
				$elements = explode("\t", $line);
				if (count($elements) < 2) {
					trigger_error('Bad index line in input', E_USER_WARNING);
					fclose($client);
					return false;
				}

				/* Construct a valid relative URL with file
				 * type included. */
				$dir[] = '/'.$elements[0][0].$elements[1];
			}
		}

		fclose($client);

		/* Since it's possible to have multiple identical entries, I
		 * thought about running array_unique over this, but really,
		 * let's leave it up to the calling code. */

		$this->dir = $dir;
		reset($this->dir);
		return true;
	}

	/**
	 * Reads the next directory item.
	 *
	 * @return string|boolean The relative URL to the next page, or FALSE
	 *                        if there isn't another directory item.
	 */
	public function dir_readdir() {
		$dir = current($this->dir);
		next($this->dir);
		return $dir;
	}

	/**
	 * Rewinds the directory stream back to the beginning.
	 *
	 * @return boolean
	 */
	public function dir_rewinddir() {
		reset($this->dir);
		return true;
	}

	/**
	 * Closes an existing stream.
	 *
	 * @return boolean
	 */
	public function stream_close() {
		return fclose($this->socket);
	}

	/**
	 * Checks if the stream has reached end-of-file.
	 *
	 * @return boolean
	 */
	public function stream_eof() {
		return feof($this->socket);
	}

	/**
	 * Opens a Gopher stream.
	 *
	 * @param string $path The URL to open.
	 * @param string $mode The mode to open the stream in, per the modes
	 *                     accepted by {@link fopen fopen()}. Since Gopher
	 *                     doesn't support writing, this only allows
	 *                     read-only modes.
	 * @param integer $options The stream wrapper options passed by PHP.
	 * @param string $openedPath The actual URL opened, which, since there
	 *                           isn't any concept of URL rewriting in
	 *                           Gopher, will simply be $path.
	 * @return boolean
	 */
	public function stream_open($path, $mode, $options, &$openedPath) {
		/* Helper function to handle the fact that PHP doesn't want
		 * stream wrappers generating their own warnings except in
		 * specific circumstances. Saves copy-pasting code, anyway. */
		$warning = function ($message) use ($options) {
			if ($options & STREAM_REPORT_ERRORS) {
				trigger_error($message, E_USER_WARNING);
			}
		};

		if (!in_array($mode, array('r', 'rb', 'rt'))) {
			$warning('Gopher only supports read-only streams');
			return false;
		}

		$parts = self::parseURL($path);

		if ($this->socket) {
			fclose($this->socket);
		}

		$this->socket = stream_socket_client($parts['host'].':'.$parts['port']);

		if (!$this->socket) {
			$warning('Unable to open TCP socket client');
			return false;
		}

		if (isset($parts['path'])) {
			$path = self::normaliseRequest($parts['path']);
		}
		else {
			$path = '';
		}

		fwrite($this->socket, "$path\r\n");

		if ($options & STREAM_USE_PATH) {
			$openedPath = $path;
		}

		return true;
	}

	/**
	 * Reads a given number of bytes from the Gopher server.
	 *
	 * @param integer $count The number of bytes to read.
	 * @return string|boolean A string containing the bytes read, or FALSE
	 *                        on error.
	 */
	public function stream_read($count) {
		return fread($this->socket, $count);
	}

	/**
	 * Seeks to a given position within the response.
	 *
	 * @param integer $offset The offset to seek to, in bytes.
	 * @param integer $whence A valid whence value per
	 *                        {@link fseek fseek()}.
	 * @return boolean
	 */
	public function stream_seek($offset, $whence = SEEK_SET) {
		return fseek($this->socket, $offset, $whence);
	}

	/**
	 * Sets a given option within the stream wrapper.
	 *
	 * The parameters for this function are kind of inscrutable: best to
	 * refer to the documentation for {@link
	 * streamWrapper::stream_set_option stream_set_option} in the PHP
	 * manual to make sense of it.
	 *
	 * @param integer $option The option to set.
	 * @param mixed $arg1
	 * @param mixed $arg2
	 * @return boolean
	 */
	public function stream_set_option($option, $arg1, $arg2) {
		switch ($option) {
			case STREAM_OPTION_BLOCKING:
				return stream_set_blocking($this->socket, $arg1);

			case STREAM_OPTION_READ_TIMEOUT:
				return stream_set_timeout($this->socket, $arg1, $arg2);

			case STREAM_OPTION_WRITE_BUFFER:
				return stream_set_write_buffer($this->socket, $arg1, $arg2);
		}

		return false;
	}

	/**
	 * Performs a stat on the stream.
	 *
	 * @return array
	 */
	public function stream_stat() {
		return fstat($this->socket);
	}

	/**
	 * Gets the current read position within the stream.
	 *
	 * @return integer|boolean
	 */
	public function stream_tell() {
		return ftell($this->socket);
	}

	/**
	 * Normalises the path component of a Gopher URL (which might include a
	 * file type) to an actual request URL we can pass over the wire.
	 *
	 * @param string $request The path to normalise.
	 * @return string
	 */
	private static function normaliseRequest($request) {
		/* We need to remove any prefixed Gopher file type, since it's
		 * not sent as part of the actual request. */
		return preg_replace('#^/[a-zA-Z0-9]/#', '', $request);
	}

	/**
	 * Parses a Gopher URL.
	 *
	 * This is basically a thin wrapper around {@link parse_url
	 * parse_url()} which sets the port to the default Gopher port of 70 if
	 * it's not specified within the URL.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function parseURL($url) {
		$parts = parse_url($url);
		
		if (!isset($parts['port'])) {
			$parts['port'] = 70;
		}

		return $parts;
	}
}

stream_wrapper_register('gopher', 'GopherStream');

// vim: set cin ai ts=8 sw=8 noet:
