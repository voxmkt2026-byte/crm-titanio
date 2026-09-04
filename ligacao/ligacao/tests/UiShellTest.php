<?php

declare(strict_types=1);

test('página principal entrega busca, histórico, player e diálogo acessíveis', function (): void {
    ob_start();
    require dirname(__DIR__) . '/index.php';
    $html = (string) ob_get_clean();

    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    assertSameValue(1, $xpath->query('//*[@id="searchForm"]')->length);
    assertSameValue(1, $xpath->query('//*[@id="numberSearch"]')->length);
    assertSameValue(1, $xpath->query('//*[@id="calls"]')->length);
    assertSameValue(1, $xpath->query('//*[@id="audioPlayer"]')->length);
    assertSameValue(1, $xpath->query('//*[@id="analysisDialog"]')->length);
    assertSameValue(1, $xpath->query('//*[@id="status" and @aria-live="polite"]')->length);
    assertSameValue(1, $xpath->query('//script[contains(@src,"assets/app.js")]')->length);
    assertSameValue(1, $xpath->query('//link[contains(@href,"assets/app.css")]')->length);
    assertTrueValue(!str_contains($html, 'API4COM_TOKEN'));
    assertTrueValue(!str_contains($html, 'GEMINI_API_KEY'));
});
