<?php
// app/Core/LLM/LLMRouter.php

namespace App\Core\LLM;

use RuntimeException;

final class LLMRouter
{
    private array $config;
    private static array $circuit = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? $this->loadConfig();
    }

    public function chat(array $capsule, array $options = []): array
    {
        $policy = $capsule['policy'] ?? [];
        $providers = $this->providerOrder($policy, $options);

        $prompt = $this->buildPrompt($capsule);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($policy)],
            ['role' => 'user', 'content' => $prompt],
        ];

        $lastError = null;
        foreach ($providers as $providerName) {
            if ($this->isCircuitOpen($providerName)) {
                continue;
            }
            try {
                $provider = $this->makeProvider($providerName);
                $result = $provider->sendChat($messages, [
                    'max_tokens' => $policy['max_output_tokens'] ?? ($this->config['limits']['max_tokens'] ?? 600),
                    'temperature' => $options['temperature'] ?? 0.2,
                ]);
                $text = $result['text'] ?? '';
                $json = $this->extractJson($text);

                return [
                    'provider' => $providerName,
                    'text' => $text,
                    'json' => $json,
                    'usage' => $result['usage'] ?? [],
                    'raw' => $result['raw'] ?? null,
                ];
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                $this->tripCircuit($providerName);
                continue;
            }
        }

        throw new RuntimeException($lastError ?: 'No hay proveedores LLM disponibles.');
    }

    private function loadConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/llm.php';
        if (is_file($path)) {
            $cfg = require $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
        return [
            'providers' => [],
            'models' => [],
            'limits' => ['timeout' => 20, 'max_tokens' => 600],
        ];
    }

    private function providerOrder(array $policy, array $options): array
    {
        $mode = strtolower((string) ($options['mode'] ?? getenv('LLM_ROUTER_MODE') ?? 'auto'));
        $order = [];

        if ($mode === 'groq') {
            $order[] = 'groq';
        } elseif ($mode === 'gemini') {
            $order[] = 'gemini';
        } else {
            $latency = (int) ($policy['latency_budget_ms'] ?? 1200);
            if ($latency <= 1200) {
                $order[] = 'groq';
                $order[] = 'gemini';
            } else {
                $order[] = 'gemini';
                $order[] = 'groq';
            }
        }

        if (!empty($this->config['providers']['openrouter']['enabled'])) {
            $order[] = 'openrouter';
        }
        if (!empty($this->config['providers']['claude']['enabled'])) {
            $order[] = 'claude';
        }

        return array_values(array_unique($order));
    }

    private function makeProvider(string $name)
    {
        $provider = $this->config['providers'][$name] ?? null;
        if (!$provider || empty($provider['class'])) {
            throw new RuntimeException("Proveedor {$name} no configurado.");
        }
        $class = $provider['class'];
        if (!class_exists($class)) {
            throw new RuntimeException("Clase {$class} no encontrada.");
        }
        return new $class($this->config);
    }

    private function systemPrompt(array $policy): string
    {
        $strict = !empty($policy['requires_strict_json']);
        if ($strict) {
            return 'Responde solo con JSON valido. No uses markdown.';
        }
        return 'Responde breve y claro.';
    }

    private function buildPrompt(array $capsule): string
    {
        if (!empty($capsule['prompt_contract']) && is_array($capsule['prompt_contract'])) {
            $payload = json_encode(
                $capsule['prompt_contract'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if (is_string($payload) && $payload !== '') {
                return $payload;
            }
        }

        $parts = [];
        $parts[] = 'Usuario: ' . ($capsule['user_message'] ?? '');
        if (!empty($capsule['entity'])) {
            $parts[] = 'Entidad: ' . $capsule['entity'];
        }
        if (!empty($capsule['entity_contract_min']['required'])) {
            $parts[] = 'Campos requeridos: ' . implode(', ', $capsule['entity_contract_min']['required']);
        }
        if (!empty($capsule['state']['collected'])) {
            $parts[] = 'Datos ya recibidos: ' . json_encode($capsule['state']['collected'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($capsule['state']['missing'])) {
            $parts[] = 'Faltantes: ' . json_encode($capsule['state']['missing'], JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $parts);
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;

        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isCircuitOpen(string $provider): bool
    {
        if (!isset(self::$circuit[$provider])) {
            return false;
        }
        $entry = self::$circuit[$provider];
        if (time() - ($entry['ts'] ?? 0) > 120) {
            unset(self::$circuit[$provider]);
            return false;
        }
        return true;
    }

    private function tripCircuit(string $provider): void
    {
        self::$circuit[$provider] = ['ts' => time()];
    }
}
