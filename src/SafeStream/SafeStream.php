<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Utils;


/**
 * Provides atomicity and isolation for thread safe file manipulation using stream nette.safe://
 *
 * <code>
 * file_put_contents('nette.safe://myfile.txt', $content);
 *
 * $content = file_get_contents('nette.safe://myfile.txt');
 *
 * unlink('nette.safe://myfile.txt');
 * </code>
 * @internal
 */
class SafeStream
{
	/** Name of stream protocol - nette.safe:// */
	public const PROTOCOL = 'nette.safe';

	/** @var resource  orignal file handle */
	private $handle;

	/** @var resource|null  temporary file handle */
	private $tempHandle;

	/** @var string  orignal file path */
	private $file;

	/** @var string  temporary file path */
	private $tempFile;

	/** @var bool */
	private $deleteFile;

	/** @var bool  error detected? */
	private $writeError = false;


	/**
	 * Registers protocol 'nette.safe://'.
	 */
	public static function register(): bool
	{
		foreach (array_intersect(stream_get_wrappers(), ['safe', self::PROTOCOL]) as $name) {
			stream_wrapper_unregister($name);
		}
		stream_wrapper_register('safe', self::class); // old protocol
		return stream_wrapper_register(self::PROTOCOL, self::class);
	}


	/**
	 * Opens file.
	 */
	public function stream_open(string $path, string $mode, int $options): bool
	{
		$path = substr($path, strpos($path, ':') + 3);  // trim protocol nette.safe://

		$flag = trim($mode, 'crwax+');  // text | binary mode
		$mode = trim($mode, 'tb');     // mode
		$use_path = (bool) (STREAM_USE_PATH & $options); // use include_path?

		// open file
		if ($mode === 'r') { // provides only isolation
			return $this->checkAndLock($this->tempHandle = fopen($path, 'r' . $flag, $use_path), LOCK_SH);

		} elseif ($mode === 'r+') {
			if (!$this->checkAndLock($this->handle = fopen($path, 'r' . $flag, $use_path), LOCK_EX)) {
				return false;
			}

		} elseif ($mode[0] === 'x') {
			if (!$this->checkAndLock($this->handle = fopen($path, 'x' . $flag, $use_path), LOCK_EX)) {
				return false;
			}
			$this->deleteFile = true;

		} elseif ($mode[0] === 'w' || $mode[0] === 'a' || $mode[0] === 'c') {
			if ($this->checkAndLock($this->handle = @fopen($path, 'x+' . $flag, $use_path), LOCK_EX)) { // intentionally @
				$this->deleteFile = true;

			} elseif (!$this->checkAndLock($this->handle = fopen($path, 'a+' . $flag, $use_path), LOCK_EX)) {
				return false;
			}

		} else {
			trigger_error("Unknown mode $mode", E_USER_WARNING);
			return false;
		}

		// create temporary file in the same directory to provide atomicity
		$tmp = '~~' . lcg_value() . '.tmp';
		if (!$this->tempHandle = fopen($path . $tmp, (strpos($mode, '+') ? 'x+' : 'x') . $flag, $use_path)) {
			$this->clean();
			return false;
		}
		$this->tempFile = realpath($path . $tmp);
		$this->file = substr($this->tempFile, 0, -strlen($tmp));

		// copy to temporary file
		if ($mode === 'r+' || $mode[0] === 'a' || $mode[0] === 'c') {
			$stat = fstat($this->handle);
			fseek($this->handle, 0);
			if (stream_copy_to_stream($this->handle, $this->tempHandle) !== $stat['size']) {
				$this->clean();
				return false;
			}

			if ($mode[0] === 'a') { // emulate append mode
				fseek($this->tempHandle, 0, SEEK_END);
			}
		}

		return true;
	}


	/**
	 * Checks handle and locks file.
	 */
	private function checkAndLock($handle, int $lock): bool
	{
		if (!$handle) {
			return false;

		} elseif (!flock($handle, $lock)) {
			fclose($handle);
			return false;
		}

		return true;
	}


	/**
	 * Error destructor.
	 */
	private function clean(): void
	{
		flock($this->handle, LOCK_UN);
		fclose($this->handle);
		if ($this->deleteFile) {
			unlink($this->file);
		}
		if ($this->tempHandle) {
			fclose($this->tempHandle);
			unlink($this->tempFile);
		}
	}


	/**
	 * Closes file.
	 */
	public function stream_close(): void
	{
		if (!$this->tempFile) { // 'r' mode
			flock($this->tempHandle, LOCK_UN);
			fclose($this->tempHandle);
			return;
		}

		flock($this->handle, LOCK_UN);
		fclose($this->handle);
		fclose($this->tempHandle);

		if ($this->writeError || !rename($this->tempFile, $this->file)) { // try to rename temp file
			unlink($this->tempFile); // otherwise delete temp file
			if ($this->deleteFile) {
				unlink($this->file);
			}
		}
	}


	/**
	 * Reads up to length bytes from the file.
	 */
	public function stream_read(int $length)
	{
		return fread($this->tempHandle, $length);
	}


	/**
	 * Writes the string to the file.
	 */
	public function stream_write(string $data)
	{
		$len = strlen($data);
		$res = fwrite($this->tempHandle, $data, $len);

		if ($res !== $len) { // disk full?
			$this->writeError = true;
		}

		return $res;
	}


	/**
	 * Truncates a file to a given length.
	 */
	public function stream_truncate(int $size): bool
	{
		return ftruncate($this->tempHandle, $size);
	}


	/**
	 * Returns the position of the file.
	 */
	public function stream_tell(): int
	{
		return ftell($this->tempHandle);
	}


	/**
	 * Returns true if the file pointer is at end-of-file.
	 */
	public function stream_eof(): bool
	{
		return feof($this->tempHandle);
	}


	/**
	 * Sets the file position indicator for the file.
	 */
	public function stream_seek(int $offset, int $whence = SEEK_SET): bool
	{
		return fseek($this->tempHandle, $offset, $whence) === 0;
	}


	/**
	 * Gets information about a file referenced by $this->tempHandle.
	 */
	public function stream_stat()
	{
		return fstat($this->tempHandle);
	}


	/**
	 * Gets information about a file referenced by filename.
	 */
	public function url_stat(string $path, int $flags)
	{
		// This is not thread safe
		$path = substr($path, strpos($path, ':') + 3);
		return ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path); // intentionally @
	}


	/**
	 * Deletes a file.
	 * On Windows unlink is not allowed till file is opened
	 */
	public function unlink(string $path): bool
	{
		$path = substr($path, strpos($path, ':') + 3);
		return unlink($path);
	}


	/**
	 * Does nothing, but since PHP 7.4 needs to be implemented when using wrapper for includes
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2): bool
	{
		return false;
	}
}
