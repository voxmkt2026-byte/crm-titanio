<?php

declare(strict_types=1);

use App\AppException;
use App\Config;

test('configuração interpreta comentários, aspas e limites inteiros', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "# comentário\nNAME=Api4Com\nQUOTED=\"valor com espaço\"\nLIMIT=999\n");

    try {
        $config = Config::load($path);
    } finally {
        @unlink($path);
    }

    assertSameValue('Api4Com', $config->required('NAME'));
    assertSameValue('valor com espaço', $config->get('QUOTED'));
    assertSameValue(100, $config->int('LIMIT', 20, 1, 100));
});

test('configuração obrigatória rejeita valor vazio', function (): void {
    $config = Config::fromArray(['TOKEN' => '']);

    assertThrows(
        fn () => $config->required('TOKEN'),
        AppException::class,
        'CONFIG_MISSING'
    );
});

