<?php

/**
 * Test: Nette\Utils\SafeStream basic usage.
 */

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


/* test for metadata */
file_put_contents('nette.safe://test.txt', 'hello word');
// create file 1
Assert::true(touch('nette.safe://test.txt'));
// is file?
/*Assert::true(is_file('nette.safe://test.txt'));
// change mod file
Assert::true(chmod('nette.safe://test1.txt', 0777));
// default permission
Assert::equal('100777', sprintf('%o', fileperms('nette.safe://test.txt')));
// removes file
unlink('nette.safe://test.txt');
// file is deleted?
Assert::false(is_file('nette.safe://test.txt'));

// create file 2
Assert::true(touch('nette.safe://test2.txt'));
// change owner file
Assert::true(chown('nette.safe://test2.txt', 'www-data'));
Assert::true(chown('nette.safe://test2.txt', 33));
// change group file
Assert::true(chgrp('nette.safe://test2.txt', 'www-data'));
Assert::true(chgrp('nette.safe://test2.txt', 33));
// removes file
unlink('nette.safe://test2.txt');
// file is deleted?
Assert::false(is_file('nette.safe://test2.txt'));
*/