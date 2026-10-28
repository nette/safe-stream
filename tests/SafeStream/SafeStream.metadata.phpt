<?php declare(strict_types=1);

/**
 * Test: Nette\SafeStream metadata changes.
 */

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


$file = TEMP_DIR . '/metadata.txt';
@unlink($file); // @ file may not exist

Assert::true(touch('nette.safe://' . $file));
Assert::true(is_file($file));

Assert::true(touch('nette.safe://' . $file, 1_000_000_000, 1_100_000_000));
clearstatcache();
Assert::same(1_000_000_000, filemtime($file));
Assert::same(1_100_000_000, fileatime($file));

Assert::true(chmod('nette.safe://' . $file, 0444));
clearstatcache();
Assert::false(is_writable($file));
chmod($file, 0666);

unlink($file);
