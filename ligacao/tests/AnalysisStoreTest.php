<?php

declare(strict_types=1);

use App\AnalysisStore;

test('cache de análise usa nome hash e preserva os dados', function (): void {
    $directory = sys_get_temp_dir() . '/analysis-' . bin2hex(random_bytes(5));

    try {
        $store = new AnalysisStore($directory);
        $saved = $store->put('../call/1', ['summary' => 'Cliente pediu retorno.']);

        assertSameValue('Cliente pediu retorno.', $saved['summary']);
        assertSameValue('Cliente pediu retorno.', $store->get('../call/1')['summary']);
        $files = glob($directory . '/*.json') ?: [];
        assertSameValue(1, count($files));
        assertTrueValue(!str_contains(basename($files[0]), 'call'));
        assertSameValue(hash('sha256', '../call/1'), $saved['call_id_hash']);
        assertSameValue(1, $saved['schema_version']);
    } finally {
        removeTestDirectory($directory);
    }
});

test('cache inválido é ignorado e uma nova gravação substitui a anterior', function (): void {
    $directory = sys_get_temp_dir() . '/analysis-' . bin2hex(random_bytes(5));

    try {
        $store = new AnalysisStore($directory);
        $store->put('call-1', ['summary' => 'Primeira']);
        $store->put('call-1', ['summary' => 'Segunda']);
        assertSameValue('Segunda', $store->get('call-1')['summary']);

        $file = (glob($directory . '/*.json') ?: [null])[0];
        file_put_contents($file, '{inválido');
        assertSameValue(null, $store->get('call-1'));

        $store->delete('call-1');
        assertSameValue([], glob($directory . '/*.json') ?: []);
    } finally {
        removeTestDirectory($directory);
    }
});

