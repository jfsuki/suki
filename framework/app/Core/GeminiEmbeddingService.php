<?php
// app/Core/GeminiEmbeddingService.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class GeminiEmbeddingService
{
    private const CANONICAL_MODEL = 'gemini-embedding-001';
    private const CANONICAL_DIMENSION = 768;
    /**
     * @var array<int,string>
     */
    private const ALLOWED_TASK_TYPES = [
        'RETRIEVAL_DOCUMENT',
        'RETRIEVAL_QUERY',
    ];

    private string $apiKey;
    private string $model;
    private int $outputDimensionality;
    private string $baseUrl;
    private int $timeoutSec;

    /** @var callable|null */
    private $transport;

    /**
     * @param callable|null $transport function(string $method, string $url, array<string,string> $headers, array<string,mixed> $payload, int $timeoutSec): array{status:int,data:array<string,mixed>}
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?int $outputDimensionality = null,
        ?string $baseUrl = null,
        ?int $timeoutSec = null,
        ?callable $transport = null
    ) {
        $this->apiKey = trim((string) ($apiKey ?? getenv('GEMINI_API_KEY') ?? ''));
        if ($this->apiKey === '') {
            $cwd = getcwd();
            $envExists = file_exists($cwd . '/.env') ? 'encontrado' : 'no encontrado';
            throw new RuntimeException("GEMINI_API_KEY requerido para embeddings. CWD: {$cwd}, .env: {$envExists}.");
        }

        $resolvedModel = strtolower(trim((string) ($model ?? getenv('EMBEDDING_MODEL') ?: self::CANONICAL_MODEL)));
        if ($resolvedModel === '') {
            $resolvedModel = self::CANONICAL_MODEL;
        }
        if ($resolvedModel !== self::CANONICAL_MODEL) {
            throw new RuntimeException('Modelo de embeddings no canonico. Se requiere gemini-embedding-001.');
        }
        $this->model = $resolvedModel;

        $resolvedDim = (int) ($outputDimensionality ?? getenv('EMBEDDING_OUTPUT_DIMENSIONALITY') ?: self::CANONICAL_DIMENSION);
        if ($resolvedDim !== self::CANONICAL_DIMENSION) {
            throw new RuntimeException('Dimensionalidad no canonica. Se requiere output_dimensionality=768.');
        }
        $this->outputDimensionality = $resolvedDim;

        $this->baseUrl = rtrim((string) ($baseUrl ?? getenv('GEMINI_BASE_URL') ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->timeoutSec = max(5, (int) ($timeoutSec ?? getenv('EMBEDDING_TIMEOUT_SEC') ?: 20));
        $this->transport = $transport;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{vector:array<int,float>,model:string,dimensions:int}
     */
    public function embed(string $text, array $options = []): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('No se puede generar embedding de texto vacio.');
        }

        $payload = [
            'model' => 'models/' . $this->model,
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
            'outputDimensionality' => $this->outputDimensionality,
        ];

        $taskType = $this->normalizeTaskType((string) ($options['task_type'] ?? 'RETRIEVAL_DOCUMENT'));
        if ($taskType !== '') {
            $payload['taskType'] = $taskType;
        }

        $title = trim((string) ($options['title'] ?? ''));
        if ($title !== '') {
            $payload['title'] = $title;
        }

        $url = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':embedContent';
        $data = $this->request('POST', $url, $payload);
        $vector = $this->extractVector($data);

        return [
            'vector' => $vector,
            'model' => $this->model,
            'dimensions' => $this->outputDimensionality,
        ];
    }

    /**
     * @param array<int,string> $texts
     * @param array<string,mixed> $options
     * @return array<int,array{vector:array<int,float>,model:string,dimensions:int}>
     */
    public function batchEmbed(array $texts, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }

        $requests = [];
        $taskType = $this->normalizeTaskType((string) ($options['task_type'] ?? 'RETRIEVAL_DOCUMENT'));
        $title = trim((string) ($options['title'] ?? ''));

        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }

            $req = [
                'model' => 'models/' . $this->model,
                'content' => [
                    'parts' => [
                        ['text' => $text],
                    ],
                ],
                'outputDimensionality' => $this->outputDimensionality,
            ];
            if ($taskType !== '') {
                $req['taskType'] = $taskType;
            }
            if ($title !== '') {
                $req['title'] = $title;
            }
            $requests[] = $req;
        }

        if ($requests === []) {
            return [];
        }

        $url = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':batchEmbedContents';
        $data = $this->request('POST', $url, ['requests' => $requests]);

        if (!is_array($data['embeddings'] ?? null)) {
            throw new RuntimeException('Respuesta de batch embeddings Gemini invalida (sin array embeddings).');
        }

        $results = [];
        foreach ($data['embeddings'] as $index => $embData) {
            $results[] = [
                'vector' => $this->extractVector(['embedding' => $embData]),
                'model' => $this->model,
                'dimensions' => $this->outputDimensionality,
            ];
        }

        return $results;
    }

    /**
     * @param array<int,string> $texts
     * @param array<string,mixed> $options
     * @return array<int,array{vector:array<int,float>,model:string,dimensions:int}>
     */
    public function embedMany(array $texts, array $options = []): array
    {
        $batchSize = 100;
        $totalTexts = count($texts);
        if ($totalTexts <= $batchSize) {
            return $this->batchEmbed($texts, $options);
        }

        $allEmbeddings = [];
        $chunks = array_chunk($texts, $batchSize);
        foreach ($chunks as $chunk) {
            $batchResults = $this->batchEmbed($chunk, $options);
            foreach ($batchResults as $res) {
                $allEmbeddings[] = $res;
            }
        }
        return $allEmbeddings;
    }

    /**
     * @return array{provider:string,model:string,output_dimensionality:int,distance:string}
     */
    public function profile(): array
    {
        return [
            'provider' => 'google-gemini',
            'model' => $this->model,
            'output_dimensionality' => $this->outputDimensionality,
            'distance' => 'Cosine',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $payload): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey,
        ];

        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $headers, $payload, $this->timeoutSec);
            if (!is_array($response)) {
                throw new RuntimeException('Transporte de embeddings devolvio respuesta invalida.');
            }
            $status = (int) ($response['status'] ?? 0);
            $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException($this->extractErrorMessage($data, $status));
            }
            return $data;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar cliente HTTP para embeddings.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper(trim($method)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Fallo HTTP en embeddings Gemini: ' . $error);
        }

        $decoded = json_decode((string) $responseBody, true);
        $data = is_array($decoded) ? $decoded : ['raw' => (string) $responseBody];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractErrorMessage($data, $status));
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,float>
     */
    private function extractVector(array $data): array
    {
        $values = null;
        if (is_array($data['embedding']['values'] ?? null)) {
            $values = (array) $data['embedding']['values'];
        } elseif (is_array($data['embeddings'][0]['values'] ?? null)) {
            $values = (array) $data['embeddings'][0]['values'];
        }

        if (!is_array($values)) {
            throw new RuntimeException('Respuesta de embeddings Gemini sin vector.');
        }

        $vector = [];
        foreach ($values as $value) {
            if (!is_numeric($value)) {
                throw new RuntimeException('Vector de embeddings contiene valores no numericos.');
            }
            $vector[] = (float) $value;
        }

        if (count($vector) !== $this->outputDimensionality) {
            throw new RuntimeException(
                'Dimensionalidad inesperada en embeddings. Esperado '
                . $this->outputDimensionality
                . ', recibido '
                . count($vector)
                . '.'
            );
        }

        return $vector;
    }

    private function normalizeTaskType(string $taskType): string
    {
        $taskType = strtoupper(trim($taskType));
        if (!in_array($taskType, self::ALLOWED_TASK_TYPES, true)) {
            throw new RuntimeException('task_type invalido para embeddings. Usa RETRIEVAL_DOCUMENT o RETRIEVAL_QUERY.');
        }
        return $taskType;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractErrorMessage(array $data, int $status): string
    {
        $message = trim((string) ($data['error']['message'] ?? $data['message'] ?? ''));
        if ($message !== '') {
            return 'Gemini embeddings error: ' . $message;
        }
        return 'Gemini embeddings error HTTP ' . $status . '.';
    }
}
