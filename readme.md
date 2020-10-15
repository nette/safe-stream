Nette SafeStream: Atomic Operations
===================================

[![Downloads this Month](https://img.shields.io/packagist/dm/nette/safe-stream.svg)](https://packagist.org/packages/nette/safe-stream)
[![Tests](https://github.com/nette/safe-stream/workflows/Tests/badge.svg?branch=master)](https://github.com/nette/safe-stream/actions)
[![Coverage Status](https://coveralls.io/repos/github/nette/safe-stream/badge.svg?branch=master)](https://coveralls.io/github/nette/safe-stream?branch=master)
[![Latest Stable Version](https://poser.pugx.org/nette/safe-stream/v/stable)](https://github.com/nette/safe-stream/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/nette/safe-stream/blob/master/license.md)


Introduction
------------

The Nette SafeStream protocol for file manipulation guarantees atomicity and isolation of every file operation.

Documentation can be found on the [website](https://doc.nette.org/safestream). If you like it, **[please make a donation now](https://github.com/sponsors/dg)**. Thank you!

Installation:

```shell
composer require nette/safe-stream
```

It requires PHP version 7.1 and supports PHP up to 8.0.


What exactly are atomic operations good for? Let's start with a simple example that repeatedly writes the same string to a file and then reads it:

```php
$s = str_repeat('Long String', 10000);

$counter = 1000;
while ($counter--) {
	file_put_contents('file', $s);       // write it
	$readed = file_get_contents('file'); // read it
	if ($s !== $readed) {                  // check it
		echo 'Strings are different!';
	}
}
```

It may seem that the `echo 'Strings are different!'` command can't ever get executed. The opposite is true. Try to run this script in two browsers tabs simultaneously. The error occurs almost immediately.

One tab reads the file at the moment when the other has not yet finished writing it.

Therefore, the code is not safe when performed multiple at the same time (ie, in multiple threads). And that is nothing unusual on the Internet, where several people often connect to one website at the same time. Therefore, it's very important to ensure that your application can handle multiple threads at once - that it's *thread-safe* because native PHP functions are not. Otherwise, you can expect data loss and strange errors occurring.

The SafeStream offers solution: its secure protocol, through which we can atomically manipulate files through standard PHP functions. You just need prefix the filename with `nette.safe://`.

```php
file_put_contents('nette.safe://file', $s);
```

If we used the secure protocol in the first example, there would never be an error.

All known functions can be used with the protocol, for example:

```php
$handle = fopen('nette.safe://file.txt', 'x');

$ini = parse_ini_file('nette.safe://autoload.ini');
```

How does it Work?
-----------------

SafeStream guarantees:

- **Atomicity**: The file is written either as a whole or not written at all.
- **Isolation**: No one can start to read a file that is not yet fully written.

To ensure both it uses file locks and temporary files.

Writing is done to temporary files and they are renamed only after successful writing. If the write fails for any reason, such as a script error or insufficient disk space, it will be discarded and an incomplete file will not be created.

If you write to an existing file in `a` (append) mode, SafeStream will create a copy of it. Writing in this mode therefore has a higher overhead than writing in other modes.
