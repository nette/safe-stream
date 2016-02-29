<?php

/**
 * Test: Nette\Utils\SafeStream basic usage.
 */

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


// registration of stream should not produce unnecessary error
Assert::same(NULL, error_get_last());