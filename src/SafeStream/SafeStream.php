<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Utils;


/**
 * Provides isolation for thread safe file manipulation using stream nette.safe://
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

	/** @var string  orignal file path */
	private $filePath;

	/** @var int  starting position in file (for appending) */
	private $startPos = 0;

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
		$resMode = rtrim($mode, 'tb');
		$lock = $resMode === 'r' ? LOCK_SH : LOCK_EX;
		$use_path = (bool) (STREAM_USE_PATH & $options);
		if ($resMode[0] === 'w') {
			$resMode[0] = 'c';
		}

		$handle = @fopen($path, $resMode . $flag, $use_path); // @ may fail
		if (!$handle || !flock($handle, $lock)) {
			return false;
		}

		if ($resMode === 'r') { // re-take lock if file is empty
			$counter = 100;
			while ($counter-- && !fstat($handle)['size']) {
				flock($handle, LOCK_UN);
				usleep(1);
				flock($handle, LOCK_SH);
			}
		} elseif ($mode[0] === 'a') {
			$this->startPos = fstat($handle)['size'];

		} elseif ($mode[0] === 'w') {
			ftruncate($handle, 0);
		}

		$this->handle = $handle;
		return true;
	}


	/**
	 * Closes file.
	 */
	public function stream_close(): void
	{
		if ($this->writeError) {
			ftruncate($this->handle, $this->startPos);
		}

		flock($this->handle, LOCK_UN);
		fclose($this->handle);
	}


	/**
	 * Reads up to length bytes from the file.
	 */
	public function stream_read(int $length)
	{
		return fread($this->handle, $length);
	}


	/**
	 * Writes the string to the file.
	 */
	public function stream_write(string $data)
	{
		$len = strlen($data);
		$res = fwrite($this->handle, $data, $len);

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
		return ftruncate($this->handle, $size);
	}


	/**
	 * Returns the position of the file.
	 */
	public function stream_tell(): int
	{
		return ftell($this->handle);
	}


	/**
	 * Returns true if the file pointer is at end-of-file.
	 */
	public function stream_eof(): bool
	{
		return feof($this->handle);
	}


	/**
	 * Sets the file position indicator for the file.
	 */
	public function stream_seek(int $offset, int $whence = SEEK_SET): bool
	{
		return fseek($this->handle, $offset, $whence) === 0; // ???
	}


	/**
	 * Gets information about a file referenced by $this->handle.
	 */
	public function stream_stat()
	{
		return fstat($this->handle);
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
