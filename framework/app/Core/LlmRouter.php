<?php
// app/Core/LlmRouter.php

namespace App\Core;

use RuntimeException;

final class LlmRouter
{
    public function interpret(string $text, array $context = [], array $payload = []): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $prompt = $this->buildPrompt($text, $context);
        $useGemini = $this->shouldUseGemini($text, $payload);

        try {
            if ($useGemini) {
                $client = new GeminiClient();
                $result = $client->generate($prompt, ['temperature' => 0.1, 'max_tokens' => 700]);
            } else {
                $client = new GroqClient();
                $messages = [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ];
                $result = $client->chat($messages, ['temperature' => 0.1, 'max_tokens' => 700]);
            }
        } catch (RuntimeException $e) {
            return null;
        }

        $json = $this->extractJson($result['content'] ?? '');
        if ($json === null) {
            return null;
        }

        return $this->normalizeIntent($json);
    }

    private function shouldUseGemini(string $text, array $payload): bool
    {
        $mode = strtolower((string) (getenv('LLM_ROUTER_MODE') ?: 'auto'));
        if ($mode === 'gemini') {
            return true;
        }
        if ($mode === 'groq') {
            return false;
        }

        if (!empty($payload['meta']['files']) || !empty($payload['meta']['media'])) {
            return true;
        }
        $lower = mb_strtolower($text);
        $keywords = ['imagen', 'foto', 'audio', 'pdf', 'ocr', 'voz', 'documento'];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return mb_strlen($text) > 280;
    }

    private function systemPrompt(): string
    {
        return <<<SYS
Eres un parser para un generador de apps. Devuelve solo JSON valido sin markdown.
Formato base:
{"actions":[{"type":"create_entity","entity":"clientes","label":"Clientes","fields":[{"name":"nombre","type":"string","label":"Nombre","required":true}]}]}
Acciones permitidas:
- create_entity (entity, label, fields)
- add_field (entity, fields)
- create_form (entity, form_name opcional)
- create_record (entity, data)
- query_records (entity, filters)
- update_record (entity, id, data)
- delete_record (entity, id)
- run_tests
Tipos de campo permitidos: string, text, number, decimal, int, bool, date, email.
Si no estas seguro, devuelve {"actions":[{"type":"help"}]}.
SYS;
    }

    private function buildPrompt(string $text, array $context): string
    {
        $entities = $context['entities'] ?? [];
        $forms = $context['forms'] ?? [];
        $memory = trim((string) ($context['memory'] ?? ''));
        $entityList = count($entities) ? implode(', ', $entities) : 'ninguna';
        $formList = count($forms) ? implode(', ', $forms) : 'ninguno';

        $prompt = "Mensaje: {$text}\nEntidades existentes: {$entityList}\nFormularios existentes: {$formList}";
        if ($memory !== '') {
            $prompt .= "\nContexto reciente: {$memory}";
        }
        $prompt .= "\nDevuelve JSON.";
        return $prompt;
    }

    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $content = preg_replace('/^```(?:json)?/i', '', $content);
        $content = preg_replace('/```$/', '', $content);
        $content = trim($content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    private function normalizeIntent(array $payload): array
    {
        if (isset($payload['actions']) && is_array($payload['actions'])) {
            return $payload;
        }
        if (isset($payload['action'])) {
            return ['actions' => [$payload['action']]];
        }
        if (isset($payload['intent'])) {
            return ['actions' => [['type' => $payload['intent']] + $payload]];
        }
        return ['actions' => [['type' => 'help']]];
    }
}
