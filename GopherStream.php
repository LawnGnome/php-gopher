<?php

class GopherStream {
	public $context;

	protected $client;
	protected $dir;

	public function __construct() {
		$this->client = null;
		$this->dir = null;
	}

	public function dir_closedir() {
		$this->dir = null;
		return true;
	}

	public function dir_opendir($path, $options) {
		/* We'll ignore the options altogether, since safe mode doesn't
		 * make any sense for read-only streams. (Well, it doesn't
		 * really make any sense in general.) */

		$parts = self::parseURL($path);

		$client = stream_socket_client($parts['host'].':'.$parts['port']);

		if (!$client) {
			return false;
		}

		fwrite($client, self::normaliseRequest($parts['path'])."\r\n");

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
				$dir[] = $elements[1];
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

	public function dir_readdir() {
		$dir = current($this->dir);
		next($this->dir);
		return $dir;
	}

	public function dir_rewinddir() {
		reset($this->dir);
		return true;
	}

	public function stream_close() {
		return fclose($this->client);
	}

	public function stream_eof() {
		return feof($this->client);
	}

	public function stream_open($path, $mode, $options, &$openedPath) {
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

		if ($this->client) {
			fclose($this->client);
		}

		$this->client = stream_socket_client($parts['host'].':'.$parts['port']);

		if (!$this->client) {
			$warning('Unable to open TCP socket client');
			return false;
		}

		/* Send the actual request. We need to remove any prefixed
		 * Gopher file type, since it's not sent as part of the actual
		 * request. */
		fwrite($this->client, self::normaliseRequest($parts['path'])."\r\n");

		if ($options & STREAM_USE_PATH) {
			$openedPath = $path;
		}

		return true;
	}

	public function stream_read($count) {
		return fread($this->client, $count);
	}

	public function stream_seek($offset, $whence = SEEK_SET) {
		return fseek($this->client, $offset, $whence);
	}

	public function stream_set_option($option, $arg1, $arg2) {
		switch ($option) {
			case STREAM_OPTION_BLOCKING:
				return stream_set_blocking($this->client, $arg1);

			case STREAM_OPTION_READ_TIMEOUT:
				return stream_set_timeout($this->client, $arg1, $arg2);

			case STREAM_OPTION_WRITE_BUFFER:
				return stream_set_write_buffer($this->client, $arg1, $arg2);
		}

		return false;
	}

	public function stream_stat() {
		return fstat($this->client);
	}

	public function stream_tell() {
		return ftell($this->client);
	}

	private static function normaliseRequest($request) {
		/* We need to remove any prefixed Gopher file type, since it's
		 * not sent as part of the actual request. */
		return preg_replace('#^/[a-zA-Z0-9]/#', '', $request);
	}

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
