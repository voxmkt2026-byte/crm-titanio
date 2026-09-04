<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$GLOBALS['test_registry'] = [];

function test(string $name, callable $callback): void
{
    $GLOBALS['test_registry'][$name] = $callback;
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Esperado ' . var_export($expected, true) . ', recebido ' . var_export($actual, true)
        );
    }
}

function assertTrueValue(bool $condition, string $message = 'Condição deveria ser verdadeira.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $class, ?string $publicCode = null): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw new RuntimeException("Esperada exceção {$class}, recebida " . $error::class, 0, $error);
        }

        if ($publicCode !== null) {
            if (!method_exists($error, 'publicCode')) {
                throw new RuntimeException('A exceção não possui publicCode().');
            }
            assertSameValue($publicCode, $error->publicCode());
        }
        return;
    }

    throw new RuntimeException("Esperada exceção {$class}, mas nenhuma foi lançada.");
}

function removeTestDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? removeTestDirectory($path) : @unlink($path);
    }
    @rmdir($directory);
}

