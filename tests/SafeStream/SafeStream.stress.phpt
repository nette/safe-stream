<?php

/**
 * Test: Nette\SafeStream stress test.
 * @multiple   5
 */

declare(strict_types=1);

use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


function randomStr(): string
{
	$s = str_repeat('LaTrine', rand(100, 20000));
	return md5($s, true) . $s;
}


function checkStr(string $s): bool
{
	return substr($s, 0, 16) === md5(substr($s, 16), true);
}


define('COUNT_FILES', 3);
set_time_limit(0);


// clear playground
for ($i = 0; $i <= COUNT_FILES; $i++) {
	@unlink(TEMP_DIR . '/testfile' . $i);
}

// test loop
$hits = ['ok' => 0, 'notfound' => 0, 'notsame' => 0, 'empty' => 0, 'cantwrite' => 0];

for ($counter = 0; $counter < 3000; $counter++) {
	// write
	$ok = @file_put_contents('nette.safe://' . TEMP_DIR . '/testfile' . rand(0, COUNT_FILES), randomStr());
	if ($ok === false) {
		$hits['cantwrite']++;
	}

	// delete
	@unlink('nette.safe://' . TEMP_DIR . '/testfile' . rand(0, COUNT_FILES));

	// read
	$res = @file_get_contents('nette.safe://' . TEMP_DIR . '/testfile' . rand(0, COUNT_FILES));

	// compare
	if ($res === false) {
		$hits['notfound']++;
	} elseif ($res === '') {
		$hits['empty']++;
	} elseif (checkStr($res)) {
		$hits['ok']++;
	} else {
		$hits['notsame']++;
	}
}

//var_export($hits);
Assert::same($counter, $hits['ok'] + $hits['notfound']);
Assert::same(0, $hits['notsame'], 'file contents is damaged');
Assert::same(0, $hits['empty'], 'file hasn\'t been written yet');
