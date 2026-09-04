<?php

declare(strict_types=1);

final class AnalysisNormalizer
{
    private const OUTCOMES = ['conversation','voicemail','no_answer','invalid_number','short_or_silent','other'];

    public function normalize(array $analysis, string $provider, string $model): array
    {
        $summary = trim((string) ($analysis['summary'] ?? ''));
        $transcript = trim((string) ($analysis['transcript'] ?? ''));
        if ($summary === '' || $transcript === '') {
            throw new InvalidArgumentException('A análise deve conter resumo e transcrição.');
        }

        $analysis['analysis_version'] = 3;
        $analysis['summary'] = $summary;
        $analysis['transcript'] = $transcript;
        $analysis['sentiment'] = $this->enum($analysis['sentiment'] ?? null, ['positivo','neutro','negativo','misto'], 'misto');
        $analysis['lead_temperature'] = $this->enum($analysis['lead_temperature'] ?? null, ['frio','morno','quente','indefinido'], 'indefinido');
        $analysis['sales_stage'] = $this->enum($analysis['sales_stage'] ?? null, ['contato_inicial','qualificacao','proposta','follow_up','fechamento','sem_avanco'], 'sem_avanco');
        $analysis['call_outcome'] = $this->enum($analysis['call_outcome'] ?? null, self::OUTCOMES, 'other');
        $analysis['scores'] = $this->scores($analysis['scores'] ?? []);
        foreach (['topics','key_points','customer_needs','buying_signals','strengths','improvements','objections','discovery_questions','next_steps','risks','compliance_alerts'] as $key) {
            $analysis[$key] = is_array($analysis[$key] ?? null) ? array_slice($analysis[$key], 0, 30) : [];
        }
        foreach (['recommended_approach'] as $key) {
            $analysis[$key] = trim((string) ($analysis[$key] ?? ''));
        }
        $analysis['sales_script'] = is_array($analysis['sales_script'] ?? null) ? $analysis['sales_script'] : [];
        $analysis['follow_up_plan'] = is_array($analysis['follow_up_plan'] ?? null) ? $analysis['follow_up_plan'] : [];
        $analysis['provider'] = $provider;
        $analysis['provider_model'] = $model;
        $analysis['analyzed_at'] = gmdate('c');
        return $analysis;
    }

    private function enum(mixed $value, array $allowed, string $default): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function scores(mixed $scores): array
    {
        $scores = is_array($scores) ? $scores : [];
        $result = [];
        foreach (['overall','opening','discovery','argumentation','value_proposition','objection_handling','closing','follow_up'] as $key) {
            $result[$key] = max(0, min(10, (int) round((float) ($scores[$key] ?? 0))));
        }
        return $result;
    }
}
