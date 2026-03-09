<?php
// framework/scripts/training_dataset_vectorize.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\QdrantVectorStore;
use App\Core\SemanticChunkContract;
use App\Core\SemanticMemoryService;

const DEFAULT_CHUNK_MAX_CHARS = 600;

$inputPath = null;
$tenantId = '';
$appId = null;
$sourceType = 'training_dataset';
$memoryType = 'sector_knowledge';
$maxChunkChars = DEFAULT_CHUNK_MAX_CHARS;
$maxChunks = 0;
$dryRun = false;
$includeIntentsExpansion = false;
$publicationGateScript = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--include-intents-expansion') {
        $includeIntentsExpansion = true;
        continue;
    }
    if (str_starts_with($arg, '--in=')) {
        $inputPath = substr($arg, strlen('--in='));
        continue;
    }
    if (str_starts_with($arg, '--tenant-id=')) {
        $tenantId = trim(substr($arg, strlen('--tenant-id=')));
        continue;
    }
    if (str_starts_with($arg, '--app-id=')) {
        $value = trim(substr($arg, strlen('--app-id=')));
        $appId = $value !== '' ? $value : null;
        continue;
    }
    if (str_starts_with($arg, '--source-type=')) {
        $value = trim(substr($arg, strlen('--source-type=')));
        if ($value !== '') {
            $sourceType = $value;
        }
        continue;
    }
    if (str_starts_with($arg, '--max-chars=')) {
        $maxChunkChars = max(120, (int) substr($arg, strlen('--max-chars=')));
        continue;
    }
    if (str_starts_with($arg, '--max-chunks=')) {
        $maxChunks = max(0, (int) substr($arg, strlen('--max-chunks=')));
        continue;
    }
    if (str_starts_with($arg, '--publication-gate=')) {
        $publicationGateScript = substr($arg, strlen('--publication-gate='));
        continue;
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

if ($inputPath === null || trim($inputPath) === '') {
    $inputPath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';
}

if ($tenantId === '') {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'tenant_id requerido. Usa --tenant-id=<tenant>.',
    ], 2);
}

if (!is_file($inputPath)) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Dataset file not found: ' . $inputPath,
    ], 2);
}

if (!is_file($publicationGateScript)) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Publication gate script not found: ' . $publicationGateScript,
    ], 2);
}

$publicationCheck = runPublicationCheck($publicationGateScript, $inputPath);
if (($publicationCheck['ok'] ?? false) !== true) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Dataset no publicado. Vectorizacion bloqueada.',
        'input' => realpath($inputPath) ?: $inputPath,
        'publication_check' => $publicationCheck,
        'blocking_reasons' => ['dataset_not_published'],
    ], 1);
}

$dataset = readJson($inputPath);
if (!is_array($dataset)) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Dataset JSON invalido: ' . $inputPath,
    ], 2);
}

try {
    $datasetSourceType = trim((string) ($dataset['source_type'] ?? ''));
    if ($datasetSourceType !== '') {
        $sourceType = $datasetSourceType;
    }

    $datasetMemoryType = trim((string) ($dataset['memory_type'] ?? ''));
    if ($datasetMemoryType !== '') {
        $memoryType = QdrantVectorStore::assertMemoryType($datasetMemoryType);
    }
} catch (Throwable $e) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'error' => 'Metadata discovery/training invalida: ' . $e->getMessage(),
    ], 2);
}

$datasetSourceMetadata = is_array($dataset['source_metadata'] ?? null) ? $dataset['source_metadata'] : [];
$datasetSectorKey = trim((string) ($dataset['sector_key'] ?? $datasetSourceMetadata['sector_key'] ?? $dataset['sector'] ?? ''));
$datasetSectorLabel = trim((string) ($dataset['sector_label'] ?? $datasetSourceMetadata['sector_label'] ?? $datasetSectorKey));
$datasetCountryOrRegulation = trim((string) ($dataset['country_or_regulation'] ?? $datasetSourceMetadata['country_or_regulation'] ?? ''));
$datasetChannels = stringList($datasetSourceMetadata['channels'] ?? ($dataset['context_pack']['channels'] ?? []));
$datasetSkillsReferenced = stringList($datasetSourceMetadata['skills_referenced'] ?? []);
$commonChunkMetadata = array_filter([
    'batch_id' => (string) ($dataset['batch_id'] ?? ''),
    'source_type' => $sourceType,
    'sector_key' => $datasetSectorKey,
    'sector_label' => $datasetSectorLabel,
    'country_or_regulation' => $datasetCountryOrRegulation,
    'channels' => $datasetChannels !== [] ? $datasetChannels : null,
    'skills_referenced' => $datasetSkillsReferenced !== [] ? $datasetSkillsReferenced : null,
], static fn($value): bool => $value !== null && $value !== '' && $value !== []);

if ($sourceType === 'business_discovery') {
    $normalizedSector = safeTag((string) ($dataset['sector'] ?? ''));
    $normalizedSectorKey = safeTag($datasetSectorKey);
    if ($memoryType !== 'sector_knowledge') {
        writeAndExit([
            'ok' => false,
            'action' => 'blocked',
            'input' => realpath($inputPath) ?: $inputPath,
            'error' => 'Business discovery debe vectorizarse solo en sector_knowledge.',
            'blocking_reasons' => ['business_discovery_memory_type_invalid'],
            'dataset' => [
                'source_type' => $sourceType,
                'memory_type' => $memoryType,
            ],
        ], 1);
    }
    if ($datasetSectorKey === '' || $datasetSectorLabel === '' || $datasetCountryOrRegulation === '') {
        writeAndExit([
            'ok' => false,
            'action' => 'blocked',
            'input' => realpath($inputPath) ?: $inputPath,
            'error' => 'Business discovery requiere sector_key, sector_label y country_or_regulation.',
            'blocking_reasons' => ['business_discovery_metadata_incomplete'],
        ], 1);
    }
    if ($normalizedSector !== '' && $normalizedSectorKey !== '' && $normalizedSector !== $normalizedSectorKey) {
        writeAndExit([
            'ok' => false,
            'action' => 'blocked',
            'input' => realpath($inputPath) ?: $inputPath,
            'error' => 'Business discovery con sector inconsistente entre sector y sector_key.',
            'blocking_reasons' => ['business_discovery_sector_mismatch'],
        ], 1);
    }
}

$batchId = safeId((string) ($dataset['batch_id'] ?? 'batch_unknown'));
$datasetVersion = trim((string) ($dataset['dataset_version'] ?? '1.0.0'));
if ($datasetVersion === '') {
    $datasetVersion = '1.0.0';
}
$publishedAt = trim((string) ($dataset['publication']['published_at'] ?? date('c')));
if ($publishedAt === '') {
    $publishedAt = date('c');
}
$globalQuality = 1.0;
if (is_numeric($dataset['quality_score'] ?? null)) {
    $globalQuality = max(0.0, min(1.0, (float) $dataset['quality_score']));
}

$defaultLayers = ['knowledge_stable', 'support_faq'];
$enabledLayers = $defaultLayers;
if ($includeIntentsExpansion) {
    $enabledLayers[] = 'intents_expansion';
}
$excludedByDefault = ['intents_expansion', 'policy_constraints', 'ontology'];

$trace = [
    'records_scanned' => 0,
    'chunks_prepared' => 0,
    'chunks_omitted' => 0,
    'omit_reasons' => [],
    'layers' => [
        'knowledge_stable' => ['vectorize_enabled' => true, 'records' => 0, 'chunks' => 0, 'omitted' => 0],
        'support_faq' => ['vectorize_enabled' => true, 'records' => 0, 'chunks' => 0, 'omitted' => 0],
        'intents_expansion' => ['vectorize_enabled' => $includeIntentsExpansion, 'records' => 0, 'chunks' => 0, 'omitted' => 0],
    ],
];

$chunks = [];
$seenChunks = [];

$appendChunk = static function (array $chunk) use (&$chunks, &$seenChunks, &$trace, $maxChunks): bool {
    if ($maxChunks > 0 && count($chunks) >= $maxChunks) {
        addReason($trace, 'max_chunks_reached');
        return false;
    }
    $normalizedText = normalizeText((string) ($chunk['content'] ?? ''));
    if ($normalizedText === '') {
        addReason($trace, 'empty_text');
        return false;
    }
    $dedupeKey = sha1(
        implode('|', [
            (string) ($chunk['memory_type'] ?? ''),
            (string) ($chunk['source_id'] ?? ''),
            (string) ($chunk['type'] ?? ''),
            function_exists('mb_strtolower')
                ? mb_strtolower($normalizedText, 'UTF-8')
                : strtolower($normalizedText),
        ])
    );
    if (isset($seenChunks[$dedupeKey])) {
        addReason($trace, 'duplicate_chunk');
        return false;
    }
    $seenChunks[$dedupeKey] = true;
    $chunks[] = $chunk;
    $trace['chunks_prepared']++;
    return true;
};

$knowledgeStable = is_array($dataset['knowledge_stable'] ?? null) ? $dataset['knowledge_stable'] : [];
foreach ($knowledgeStable as $index => $record) {
    $trace['records_scanned']++;
    $trace['layers']['knowledge_stable']['records']++;
    if (!is_array($record)) {
        $trace['layers']['knowledge_stable']['omitted']++;
        addReason($trace, 'invalid_knowledge_record');
        continue;
    }
    if (isset($record['vectorize']) && $record['vectorize'] === false) {
        $trace['layers']['knowledge_stable']['omitted']++;
        addReason($trace, 'knowledge_vectorize_disabled');
        continue;
    }

    $recordId = safeId((string) ($record['id'] ?? 'knowledge_' . $index));
    $title = normalizeText((string) ($record['title'] ?? ''));
    $facts = stringList($record['facts'] ?? []);
    if ($facts === []) {
        $trace['layers']['knowledge_stable']['omitted']++;
        addReason($trace, 'knowledge_empty_facts');
        continue;
    }

    $tags = mergeTags(
        [
            'layer:knowledge_stable',
            'batch:' . $batchId,
            'sector:' . safeTag((string) ($record['sector'] ?? $datasetSectorKey ?? 'unknown')),
            'source_type:' . safeTag($sourceType),
            'sector_key:' . safeTag($datasetSectorKey !== '' ? $datasetSectorKey : (string) ($record['sector'] ?? 'unknown')),
        ],
        $record['tags'] ?? []
    );
    $sector = normalizeNullableField($record['sector'] ?? $datasetSectorKey);
    $version = trim((string) ($record['version'] ?? $datasetVersion));
    if ($version === '') {
        $version = $datasetVersion;
    }
    $quality = is_numeric($record['quality_score'] ?? null) ? (float) $record['quality_score'] : $globalQuality;
    $quality = max(0.0, min(1.0, $quality));

    foreach ($facts as $factIndex => $fact) {
        $baseText = trim($title !== '' ? ($title . '. ' . $fact) : $fact);
        $parts = splitText($baseText, $maxChunkChars);
        foreach ($parts as $partIndex => $part) {
            $sourceId = $batchId . ':knowledge_stable:' . $recordId;
            $chunk = [
                'memory_type' => $memoryType,
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'agent_role' => null,
                'sector' => $sector,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source' => $sourceId,
                'chunk_id' => $recordId . '_fact_' . $factIndex . '_p' . $partIndex,
                'type' => 'knowledge_stable',
                'tags' => $tags,
                'version' => $version,
                'quality_score' => $quality,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
                'metadata' => array_merge($commonChunkMetadata, [
                    'layer' => 'knowledge_stable',
                ]),
                'content' => $part,
            ];
            if ($appendChunk($chunk)) {
                $trace['layers']['knowledge_stable']['chunks']++;
            } else {
                $trace['layers']['knowledge_stable']['omitted']++;
            }
        }
    }
}

$supportFaq = is_array($dataset['support_faq'] ?? null) ? $dataset['support_faq'] : [];
foreach ($supportFaq as $index => $record) {
    $trace['records_scanned']++;
    $trace['layers']['support_faq']['records']++;
    if (!is_array($record)) {
        $trace['layers']['support_faq']['omitted']++;
        addReason($trace, 'invalid_faq_record');
        continue;
    }
    if (isset($record['vectorize']) && $record['vectorize'] === false) {
        $trace['layers']['support_faq']['omitted']++;
        addReason($trace, 'faq_vectorize_disabled');
        continue;
    }

    $recordId = safeId((string) ($record['id'] ?? 'faq_' . $index));
    $question = normalizeText((string) ($record['question'] ?? ''));
    $answer = normalizeText((string) ($record['answer'] ?? ''));
    $text = trim('Pregunta: ' . $question . "\nRespuesta: " . $answer);
    if ($question === '' || $answer === '') {
        $trace['layers']['support_faq']['omitted']++;
        addReason($trace, 'faq_missing_question_or_answer');
        continue;
    }

    $tags = mergeTags(
        [
            'layer:support_faq',
            'batch:' . $batchId,
            'source_type:' . safeTag($sourceType),
            'sector_key:' . safeTag($datasetSectorKey !== '' ? $datasetSectorKey : 'unknown'),
        ],
        $record['tags'] ?? []
    );
    $sector = normalizeNullableField($record['sector'] ?? $datasetSectorKey);
    $version = trim((string) ($record['version'] ?? $datasetVersion));
    if ($version === '') {
        $version = $datasetVersion;
    }
    $quality = is_numeric($record['quality_score'] ?? null) ? (float) $record['quality_score'] : $globalQuality;
    $quality = max(0.0, min(1.0, $quality));

    foreach (splitText($text, $maxChunkChars) as $partIndex => $part) {
        $sourceId = $batchId . ':support_faq:' . $recordId;
        $chunk = [
            'memory_type' => $memoryType,
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'agent_role' => null,
            'sector' => $sector,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source' => $sourceId,
            'chunk_id' => $recordId . '_p' . $partIndex,
            'type' => 'support_faq',
            'tags' => $tags,
            'version' => $version,
            'quality_score' => $quality,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
            'metadata' => array_merge($commonChunkMetadata, [
                'layer' => 'support_faq',
            ]),
            'content' => $part,
        ];
        if ($appendChunk($chunk)) {
            $trace['layers']['support_faq']['chunks']++;
        } else {
            $trace['layers']['support_faq']['omitted']++;
        }
    }
}

$intents = is_array($dataset['intents_expansion'] ?? null) ? $dataset['intents_expansion'] : [];
if ($includeIntentsExpansion) {
    foreach ($intents as $index => $intent) {
        $trace['records_scanned']++;
        $trace['layers']['intents_expansion']['records']++;
        if (!is_array($intent)) {
            $trace['layers']['intents_expansion']['omitted']++;
            addReason($trace, 'invalid_intent_record');
            continue;
        }
        if (isset($intent['vectorize']) && $intent['vectorize'] === false) {
            $trace['layers']['intents_expansion']['omitted']++;
            addReason($trace, 'intent_vectorize_disabled');
            continue;
        }

        $intentName = safeTag((string) ($intent['intent'] ?? 'intent_' . $index));
        $actionName = safeTag((string) ($intent['action'] ?? 'unknown_action'));
        $version = $datasetVersion;
        $groups = [
            'explicit' => stringList($intent['utterances_explicit'] ?? []),
            'implicit' => stringList($intent['utterances_implicit'] ?? []),
            'hard_negative' => stringList($intent['hard_negatives'] ?? []),
        ];

        foreach ($groups as $group => $utterances) {
            if ($utterances === []) {
                addReason($trace, 'intent_group_empty:' . $group);
                $trace['layers']['intents_expansion']['omitted']++;
                continue;
            }
            foreach ($utterances as $utteranceIndex => $utterance) {
                foreach (splitText($utterance, $maxChunkChars) as $partIndex => $part) {
                    $sourceId = $batchId . ':intent:' . $intentName;
                    $chunk = [
                        'memory_type' => $memoryType,
                        'tenant_id' => $tenantId,
                        'app_id' => $appId,
                        'agent_role' => null,
                        'sector' => normalizeNullableField($intent['sector'] ?? $datasetSectorKey),
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'source' => $sourceId,
                        'chunk_id' => $intentName . '_' . $group . '_' . $utteranceIndex . '_p' . $partIndex,
                        'type' => 'intent_utterance_' . $group,
                        'tags' => mergeTags(
                            [
                                'layer:intents_expansion',
                                'batch:' . $batchId,
                                'intent:' . $intentName,
                                'action:' . $actionName,
                                'group:' . $group,
                                'source_type:' . safeTag($sourceType),
                                'sector_key:' . safeTag($datasetSectorKey !== '' ? $datasetSectorKey : 'unknown'),
                            ],
                            []
                        ),
                        'version' => $version,
                        'quality_score' => $globalQuality,
                        'created_at' => $publishedAt,
                        'updated_at' => $publishedAt,
                        'metadata' => array_merge($commonChunkMetadata, [
                            'layer' => 'intents_expansion',
                            'intent' => $intentName,
                            'action' => $actionName,
                            'group' => $group,
                        ]),
                        'content' => $part,
                    ];
                    if ($appendChunk($chunk)) {
                        $trace['layers']['intents_expansion']['chunks']++;
                    } else {
                        $trace['layers']['intents_expansion']['omitted']++;
                    }
                }
            }
        }
    }
} else {
    $skipped = count($intents);
    $trace['records_scanned'] += $skipped;
    $trace['layers']['intents_expansion']['records'] = $skipped;
    $trace['layers']['intents_expansion']['omitted'] = $skipped;
    if ($skipped > 0) {
        addReason($trace, 'intents_expansion_skipped_by_default', $skipped);
    }
}

$trace['chunks_omitted'] = array_sum($trace['omit_reasons']);

if ($chunks === []) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'No se generaron chunks vectorizables desde dataset publicado.',
        'input' => realpath($inputPath) ?: $inputPath,
        'flow' => 'published_dataset->normalize_chunk->embedding(768)->upsert_qdrant',
        'trace' => $trace,
        'publication_check' => $publicationCheck,
    ], 1);
}

$baseReport = [
    'ok' => true,
    'action' => $dryRun ? 'dry_run' : 'vectorized',
    'input' => realpath($inputPath) ?: $inputPath,
    'flow' => 'published_dataset->normalize_chunk->embedding(768)->upsert_qdrant',
    'tenant_id' => $tenantId,
    'app_id' => $appId,
    'memory_type' => $memoryType,
    'vectorization_policy' => [
        'default_layers' => $defaultLayers,
        'enabled_layers' => $enabledLayers,
        'excluded_by_default' => $excludedByDefault,
    ],
    'dataset' => [
        'batch_id' => (string) ($dataset['batch_id'] ?? ''),
        'dataset_version' => $datasetVersion,
        'source_type' => $sourceType,
        'memory_type' => $memoryType,
        'sector_key' => $datasetSectorKey,
        'sector_label' => $datasetSectorLabel,
        'country_or_regulation' => $datasetCountryOrRegulation,
        'publication_status' => (string) ($dataset['publication']['status'] ?? ''),
        'published_at' => $publishedAt,
    ],
    'publication_check' => $publicationCheck,
    'trace' => $trace,
    'contract_sample' => $chunks !== [] ? SemanticChunkContract::buildPayload($chunks[0]) : null,
];

if ($dryRun) {
    $baseReport['chunks_vectorized'] = 0;
    writeAndExit($baseReport, 0);
}

try {
    $semantic = new SemanticMemoryService(
        null,
        new QdrantVectorStore(null, null, null, null, null, null, null, $memoryType)
    );
    $ingest = $semantic->ingest($chunks, ['wait' => true]);
} catch (Throwable $e) {
    $baseReport['ok'] = false;
    $baseReport['action'] = 'failed';
    $baseReport['error'] = 'Fallo vectorizacion: ' . $e->getMessage();
    writeAndExit($baseReport, 2);
}

$baseReport['semantic_memory'] = $ingest;
$baseReport['chunks_vectorized'] = (int) ($ingest['upserted'] ?? 0);

writeAndExit($baseReport, 0);

/**
 * @return array<string,mixed>|null
 */
function readJson(string $path): ?array
{
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string,mixed>
 */
function runPublicationCheck(string $gateScript, string $datasetPath): array
{
    $php = PHP_BINARY ?: 'php';
    $parts = [
        escapeshellarg($php),
        escapeshellarg($gateScript),
        escapeshellarg('--in=' . $datasetPath),
        escapeshellarg('--require-published'),
    ];
    $command = implode(' ', $parts);
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $raw = trim(implode("\n", $output));
    $decoded = json_decode($raw, true);
    $json = is_array($decoded) ? $decoded : parseLastJsonObject($raw);
    if (!is_array($json)) {
        $json = [
            'ok' => false,
            'error' => 'No se pudo interpretar salida del publication gate.',
            'raw_output' => $raw,
        ];
    }
    $json['exit_code'] = $code;
    if (!array_key_exists('ok', $json)) {
        $json['ok'] = ($code === 0);
    }
    return $json;
}

/**
 * @return array<string,mixed>|null
 */
function parseLastJsonObject(string $output): ?array
{
    $output = trim($output);
    if ($output === '') {
        return null;
    }
    $start = strrpos($output, '{');
    if ($start === false) {
        return null;
    }
    $candidate = substr($output, $start);
    $decoded = json_decode($candidate, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param mixed $value
 * @return array<int,string>
 */
function stringList($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $item) {
        $text = normalizeText((string) $item);
        if ($text !== '') {
            $result[] = $text;
        }
    }
    return $result;
}

function normalizeText(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text));
    if (!is_string($text)) {
        return '';
    }
    return trim($text);
}

/**
 * @param array<int,mixed> $fromRecord
 * @return array<int,string>
 */
function mergeTags(array $base, $fromRecord): array
{
    $tags = [];
    foreach ($base as $tag) {
        $safe = safeTag((string) $tag);
        if ($safe !== '' && !in_array($safe, $tags, true)) {
            $tags[] = $safe;
        }
    }
    if (is_array($fromRecord)) {
        foreach ($fromRecord as $tag) {
            $safe = safeTag((string) $tag);
            if ($safe !== '' && !in_array($safe, $tags, true)) {
                $tags[] = $safe;
            }
        }
    }
    return $tags;
}

function safeId(string $value): string
{
    $value = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($value));
    if (!is_string($value)) {
        return 'unknown';
    }
    $value = trim($value, '_');
    return $value !== '' ? $value : 'unknown';
}

function safeTag(string $value): string
{
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower(trim($value), 'UTF-8');
    } else {
        $value = strtolower(trim($value));
    }
    $value = preg_replace('/[^a-z0-9:_\-]/u', '_', $value);
    $value = preg_replace('/_+/', '_', (string) $value);
    if (!is_string($value)) {
        return '';
    }
    return trim($value, '_');
}

/**
 * @param mixed $value
 */
function normalizeNullableField($value): ?string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : null;
}

/**
 * @return array<int,string>
 */
function splitText(string $text, int $maxChars): array
{
    $text = normalizeText($text);
    if ($text === '') {
        return [];
    }

    if (vecStrLen($text) <= $maxChars) {
        return [$text];
    }

    $parts = preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: [];
    if ($parts === [] || count($parts) === 1) {
        return splitByWords($text, $maxChars);
    }

    $chunks = [];
    $current = '';
    foreach ($parts as $part) {
        $part = normalizeText((string) $part);
        if ($part === '') {
            continue;
        }
        $candidate = trim($current === '' ? $part : ($current . ' ' . $part));
        if (vecStrLen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
            $current = '';
        }
        if (vecStrLen($part) > $maxChars) {
            foreach (splitByWords($part, $maxChars) as $wordChunk) {
                $chunks[] = $wordChunk;
            }
        } else {
            $current = $part;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks !== [] ? $chunks : splitByWords($text, $maxChars);
}

/**
 * @return array<int,string>
 */
function splitByWords(string $text, int $maxChars): array
{
    $words = preg_split('/\s+/u', normalizeText($text)) ?: [];
    $chunks = [];
    $current = '';
    foreach ($words as $word) {
        $word = trim((string) $word);
        if ($word === '') {
            continue;
        }
        $candidate = trim($current === '' ? $word : ($current . ' ' . $word));
        if (vecStrLen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        if (vecStrLen($word) > $maxChars) {
            $chunks[] = vecStrSub($word, 0, $maxChars);
            $current = '';
        } else {
            $current = $word;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }
    return $chunks;
}

/**
 * @param array<string,mixed> $trace
 */
function addReason(array &$trace, string $reason, int $amount = 1): void
{
    if ($amount < 1) {
        return;
    }
    if (!isset($trace['omit_reasons'][$reason])) {
        $trace['omit_reasons'][$reason] = 0;
    }
    $trace['omit_reasons'][$reason] += $amount;
}

function vecStrLen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function vecStrSub(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        $slice = mb_substr($value, $start, $length, 'UTF-8');
        return is_string($slice) ? $slice : '';
    }
    return substr($value, $start, $length) ?: '';
}

/**
 * @param array<string,mixed> $payload
 */
function writeAndExit(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/training_dataset_vectorize.php --in=<dataset.json> --tenant-id=<tenant>\n";
    echo "      [--app-id=<app>] [--source-type=training_dataset]\n";
    echo "      [--max-chars=600] [--max-chunks=0]\n";
    echo "      [--include-intents-expansion] [--dry-run]\n\n";
    echo "Rules:\n";
    echo "  - Dataset must be published (publication gate check is mandatory).\n";
    echo "  - Default layers: knowledge_stable + support_faq.\n";
    echo "  - intents_expansion is excluded by default (enable explicitly by flag).\n";
    echo "  - Canonical flow: published -> normalize/chunk -> embedding(768) -> upsert Qdrant.\n";
}
