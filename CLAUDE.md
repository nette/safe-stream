# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SafeStream is a lightweight PHP library providing thread-safe file operations through a custom stream wrapper protocol (`nette.safe://`). The library ensures that file reads and writes are isolated, preventing race conditions in concurrent PHP execution contexts.

**Core mechanism:** PHP stream wrapper that implements file locking (LOCK_SH for reads, LOCK_EX for writes) to guarantee atomicity of file operations.

## Usage Examples

SafeStream works transparently with all standard PHP file functions by prefixing paths with `nette.safe://`:

```php
// Writing to a file
file_put_contents('nette.safe://file.txt', $content);

// Reading from a file
$content = file_get_contents('nette.safe://file.txt');

// Using file handles
$handle = fopen('nette.safe://file.txt', 'r');
fread($handle, 1024);
fclose($handle);

// Works with any PHP file function
$ini = parse_ini_file('nette.safe://config.ini');
unlink('nette.safe://file.txt');
```

**The problem SafeStream solves:** Without isolation, concurrent file operations lead to race conditions. Example:

```php
// Running this in multiple threads simultaneously will fail
$s = str_repeat('Long String', 10000);
while ($counter--) {
    file_put_contents('file', $s);        // Thread A writes
    $read = file_get_contents('file');     // Thread B reads incomplete data
    if ($s !== $read) {                    // Race condition occurs
        echo 'Data corruption detected!';
    }
}
```

With `nette.safe://` prefix, the above code becomes thread-safe: writers get exclusive locks, readers wait for writes to complete.

## Essential Commands

```bash
# Run all tests
composer run tester

# Run specific test file
vendor/bin/tester tests/SafeStream/SafeStream.basic.phpt -s

# Run tests in specific directory
vendor/bin/tester tests/SafeStream/ -s

# Run PHPStan static analysis
composer run phpstan
```

## Architecture

### Stream Wrapper Pattern

The library implements PHP's stream wrapper interface to intercept file operations:

1. **Protocol registration** (`src/loader.php`): Automatically registers `nette.safe://` protocol via `Wrapper::register()`
2. **Wrapper class** (`src/SafeStream/Wrapper.php`): Implements stream wrapper methods (`stream_open`, `stream_read`, `stream_write`, etc.)
3. **Backward compatibility** (`src/SafeStream/SafeStream.php`): Deprecated class for legacy `Nette\Utils\SafeStream` namespace

### Key Implementation Details

**File locking strategy:**
- Read operations: `LOCK_SH` (shared lock) - multiple readers allowed
- Write operations: `LOCK_EX` (exclusive lock) - single writer, blocks all readers
- Append mode tracking: `$startPos` records file size before writing to enable rollback on errors

**Write error handling:**
- `$writeError` flag tracks incomplete writes (e.g., disk full)
- On close, truncates file back to `$startPos` if write error detected
- Ensures files are never left in partial/corrupt state

**Race condition mitigation:**
- For reads: retries up to 100 times if file is empty (another thread may be writing)
- For writes using mode 'w': converts to mode 'c' (create without truncate) then manually truncates after acquiring lock

**Thread-safety boundaries:**
- `url_stat()` method is explicitly NOT thread-safe (documented in code)
- `unlink()` on Windows has limitations when file handles are open

### Testing Strategy

Uses Nette Tester with `.phpt` files:
- `SafeStream.basic.phpt`: Basic read/write/delete operations
- `SafeStream.stress.phpt`: Concurrent access simulation

Tests must use `require __DIR__ . '/../bootstrap.php'` for proper environment setup.

## PHP Version Support

Supports PHP 8.0 through 8.5 (as declared in composer.json). GitHub Actions tests against all supported versions.

## Deprecation Notes

- `Nette\Utils\SafeStream` class is deprecated; use `Nette\SafeStream\Wrapper`
- `Wrapper::PROTOCOL` constant deprecated in favor of `Wrapper::Protocol`
