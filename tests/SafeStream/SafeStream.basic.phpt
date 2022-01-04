<?php

/**
 * Test: Nette\SafeStream basic usage.
 */

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


// actually it creates temporary file
$handle = fopen('nette.safe://myfile.txt', 'x');
fwrite($handle, 'atomic and safe');
// and now rename it
fclose($handle);

Assert::true(is_file('nette.safe://myfile.txt'));
Assert::same('atomic and safe', file_get_contents('nette.safe://myfile.txt'));

// removes file thread-safe way
unlink('nette.safe://myfile.txt');

// this is not thread safe - don't relay on returned value
Assert::false(is_file('nette.safe://myfile.txt'));
