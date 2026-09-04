<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter === '' || stripos(basename($file), $filter) !== false) {
        require $file;
    }
}

$passed = 0;
$failed = 0;

foreach ($GLOBALS['test_registry'] as $name => $callback) {
    try {
        $callback();
        $passed++;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}\n  {$error->getMessage()}\n");
    }
}

$total = $passed + $failed;
fwrite(STDOUT, "\n{$total} teste(s), {$passed} aprovado(s), {$failed} falha(s).\n");
exit($failed === 0 ? 0 : 1);

