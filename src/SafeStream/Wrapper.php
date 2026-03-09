<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\SafeStream;


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
class Wrapper
{
	/** Name of stream protocol - nette.safe:// */
	public const Protocol = 'nette.safe';

	#[\Deprecated('use Wrapper::Protocol')]
	public const PROTOCOL = self::Protocol;

	/** @var ?resource */
	public $context;

	/** @var resource  orignal file handle */
	private $handle;

	/** @var int<0, max>  file position before writing started; used to roll back on write error */
	private int $startPos = 0;

	/** write error detected (e.g., disk full); triggers rollback on close */
	private bool $writeError = false;


	/**
	 * Registers protocol 'nette.safe://'.
	 */
	public static function register(): bool
	{
		if (in_array(self::Protocol, stream_get_wrappers(), true)) {
			stream_wrapper_unregister(self::Protocol);
		}

		return stream_wrapper_register(self::Protocol, self::class);
	}


	/**
	 * Opens the file and acquires the appropriate lock (LOCK_SH for reading, LOCK_EX for writing).
	 * Converts 'w' mode to 'c' to avoid truncation before the lock is held, then truncates manually.
	 * For reads, retries up to 100 times if the file is empty (another thread may be writing).
	 */
	public function stream_open(string $path, string $mode, int $options): bool
	{
		$path = substr($path, strlen(self::Protocol) + 3); // trim protocol nette.safe://
		$flag = trim($mode, 'crwax+');  // text | binary mode
		$resMode = rtrim($mode, 'tb');
		$lock = $resMode === 'r' ? LOCK_SH : LOCK_EX;
		$use_path = (bool) (STREAM_USE_PATH & $options);
		if ($resMode[0] === 'w') {
			$resMode[0] = 'c';
		}

		$handle = fopen($path, $resMode . $flag, $use_path);
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
			$this->startPos = max(0, fstat($handle)['size']);

		} elseif ($mode[0] === 'w') {
			ftruncate($handle, 0);
		}

		$this->handle = $handle;
		return true;
	}


	/**
	 * Releases the lock and closes the file. Truncates back to $startPos if a write error occurred.
	 */
	public function stream_close(): void
	{
		if ($this->writeError) {
			ftruncate($this->handle, $this->startPos);
		}

		flock($this->handle, LOCK_UN);
		fclose($this->handle);
	}


	/** @param int<1, max> $length */
	public function stream_read(int $length): string|false
	{
		return fread($this->handle, $length);
	}


	/**
	 * Writes data to the file. Sets the write-error flag if fewer bytes than expected were written.
	 */
	public function stream_write(string $data): int|false
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
	 * @param int<0, max> $size
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
	 * @return array<int>|false
	 */
	public function stream_stat(): array|false
	{
		return fstat($this->handle);
	}


	/**
	 * Returns file information for the given path. Not thread-safe.
	 * @return array<int>|false
	 */
	public function url_stat(string $path, int $flags): array|false
	{
		// This is not thread safe
		$path = substr($path, strlen(self::Protocol) + 3);
		return ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path); // intentionally @
	}


	/**
	 * Deletes a file. On Windows, fails if the file is currently open.
	 */
	public function unlink(string $path): bool
	{
		$path = substr($path, strlen(self::Protocol) + 3);
		return unlink($path);
	}


	/**
	 * Required since PHP 7.4 when the wrapper is used for includes; always returns false.
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2): bool
	{
		return false;
	}
}
