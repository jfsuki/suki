<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetSupport;
use App\Core\ErpTrainingDatasetVectorizer;
use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

$inputPath = null;
$dryRun = false;
$strict = false;
$limit = 0;
$batchSize = 25;
$topK = 5;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--strict') {
        $strict = true;
        continue;
    }
    if (str_starts_with($arg, '--in=')) {
        $inputPath = substr($arg, strlen('--in='));
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, strlen('--limit=')));
        continue;
    }
    if (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, (int) substr($arg, strlen('--batch-size=')));
        continue;
    }
    if (str_starts_with($arg, '--top-k=')) {
        $topK = max(1, (int) substr($arg, strlen('--top-k=')));
        continue;
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

$defaultInput = ErpTrainingDatasetVectorizer::defaultInputPath();
if ($inputPath === null || trim($inputPath) === '') {
    $inputPath = $defaultInput;
}
$inputPath = ErpDatasetSupport::normalizeCliPath($inputPath);
$inputCheck = ErpDatasetSupport::validateCliInputPath($inputPath);
if (($inputCheck['ok'] ?? false) !== true) {
    writeAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => $inputCheck['error'] ?? 'Input ERP vectorization prep invalido.',
        'input' => $inputPath,
        'examples' => buildExamples($defaultInput),
        'suggested_json_candidates' => suggestPrepCandidates(),
        'how_to_fix' => 'Usa un erp_vectorization_prep.json valido y apunta siempre a la coleccion agent_training.',
    ], 2);
}

try {
    $vectorizer = new ErpTrainingDatasetVectorizer();
    $result = $vectorizer->vectorizeFromPath($inputPath, [
        'dry_run' => $dryRun,
        'strict' => $strict,
        'limit' => $limit,
        'batch_size' => $batchSize,
        'top_k' => $topK,
    ]);
} catch (Throwable $e) {
    writeAndExit([
        'ok' => false,
        'action' => 'failed',
        'error' => $e->getMessage(),
        'input' => realpath($inputPath) ?: $inputPath,
        'collection' => ErpTrainingDatasetVectorizer::TARGET_COLLECTION,
        'dry_run' => $dryRun,
        'strict' => $strict,
        'limit' => $limit,
        'batch_size' => $batchSize,
        'top_k' => $topK,
        'examples' => buildExamples($defaultInput),
        'suggested_json_candidates' => suggestPrepCandidates(),
        'runtime_diagnostics' => buildRuntimeDiagnostics(),
    ], 1);
}

writeAndExit($result, ($result['ok'] ?? false) ? 0 : 1);

/**
 * @return array<string,string>
 */
function buildExamples(string $defaultInput): array
{
    return [
        'dry_run' => 'php framework/scripts/vectorize_erp_training_dataset.php '
            . ErpDatasetSupport::relativeToRepo($defaultInput)
            . ' --dry-run --strict',
        'real_run' => 'php framework/scripts/vectorize_erp_training_dataset.php '
            . ErpDatasetSupport::relativeToRepo($defaultInput)
            . ' --limit=25 --batch-size=10 --top-k=5 --strict',
    ];
}

/**
 * @return array<int,string>
 */
function suggestPrepCandidates(): array
{
    $candidates = [];
    $roots = [
        FRAMEWORK_ROOT . '/training/output',
        FRAMEWORK_ROOT . '/training',
        PROJECT_ROOT . '/contracts/knowledge',
    ];

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'json') {
                continue;
            }
            $path = ErpDatasetSupport::relativeToRepo($fileInfo->getPathname());
            if (str_ends_with(strtolower($path), 'erp_vectorization_prep.json')) {
                $candidates[] = $path;
            }
        }
    }

    if ($candidates === [] && is_file(ErpTrainingDatasetVectorizer::defaultInputPath())) {
        $candidates[] = ErpDatasetSupport::relativeToRepo(ErpTrainingDatasetVectorizer::defaultInputPath());
    }

    return array_values(array_unique($candidates));
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
    $defaultInput = ErpTrainingDatasetVectorizer::defaultInputPath();
    echo "Usage:\n";
    echo "  php framework/scripts/vectorize_erp_training_dataset.php <erp_vectorization_prep.json> [--dry-run] [--limit=25] [--batch-size=10] [--top-k=5] [--strict]\n";
    echo "  php framework/scripts/vectorize_erp_training_dataset.php --in=<erp_vectorization_prep.json> [--dry-run] [--limit=25] [--batch-size=10] [--top-k=5] [--strict]\n\n";
    echo "Real repo example:\n";
    echo "  php framework/scripts/vectorize_erp_training_dataset.php "
        . ErpDatasetSupport::relativeToRepo($defaultInput)
        . " --dry-run --strict\n";
    echo "  php framework/scripts/vectorize_erp_training_dataset.php "
        . ErpDatasetSupport::relativeToRepo($defaultInput)
        . " --limit=25 --batch-size=10 --top-k=5 --strict\n\n";
    echo "Safety rules:\n";
    echo "  - Solo escribe en agent_training.\n";
    echo "  - Bloquea tenant_data_allowed=true y target_collection distinto de agent_training.\n";
    echo "  - Exige embedding real Gemini 768 y coleccion Qdrant canonicamente configurada.\n";
    echo "  - Dry-run ejecuta probe real de embeddings pero no hace upsert.\n";
}

/**
 * @return array<string,mixed>
 */
function buildRuntimeDiagnostics(): array
{
    $diagnostics = [
        'embedding' => ['ok' => false],
        'qdrant' => ['ok' => false],
    ];

    try {
        $embedding = new GeminiEmbeddingService();
        $diagnostics['embedding'] = [
            'ok' => true,
            'profile' => $embedding->profile(),
        ];
    } catch (Throwable $e) {
        $diagnostics['embedding'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $store = new QdrantVectorStore(null, null, null, null, null, null, null, 'agent_training');
        $diagnostics['qdrant'] = [
            'ok' => true,
            'profile' => $store->profile(),
            'inspection' => $store->inspectCollection(),
        ];
    } catch (Throwable $e) {
        $diagnostics['qdrant'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    return $diagnostics;
}
