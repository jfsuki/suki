<?php
// framework/scripts/sanitize_training_dataset_channels.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$in = null;
$out = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--in=')) {
        $in = substr($arg, strlen('--in='));
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
}

if (!is_string($in) || $in === '') {
    fwrite(STDERR, "Missing --in=<dataset.json>\n");
    printHelp();
    exit(2);
}

if (!is_file($in)) {
    fwrite(STDERR, "Input file not found: {$in}\n");
    exit(2);
}

if (!is_string($out) || $out === '') {
    $out = $in;
}

$raw = file_get_contents($in);
if (!is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "Input file is empty: {$in}\n");
    exit(2);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON input: {$in}\n");
    exit(2);
}

$stats = [
    'strings_sanitized' => 0,
    'duplicates_removed' => 0,
];

sanitizeDataset($data, $stats);

$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    fwrite(STDERR, "Failed to encode sanitized JSON.\n");
    exit(2);
}

if (file_put_contents($out, $encoded . PHP_EOL) === false) {
    fwrite(STDERR, "Failed to write output file: {$out}\n");
    exit(2);
}

$report = [
    'ok' => true,
    'input' => realpath($in) ?: $in,
    'output' => realpath($out) ?: $out,
    'strings_sanitized' => $stats['strings_sanitized'],
    'duplicates_removed' => $stats['duplicates_removed'],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

function sanitizeDataset(array &$data, array &$stats): void
{
    if (isset($data['intents_expansion']) && is_array($data['intents_expansion'])) {
        foreach ($data['intents_expansion'] as &$intent) {
            if (!is_array($intent)) {
                continue;
            }
            sanitizeStringList($intent, 'utterances_explicit', $stats);
            sanitizeStringList($intent, 'utterances_implicit', $stats);
            sanitizeStringList($intent, 'hard_negatives', $stats);
        }
        unset($intent);
    }

    if (isset($data['multi_turn_dialogues']) && is_array($data['multi_turn_dialogues'])) {
        foreach ($data['multi_turn_dialogues'] as &$dialogue) {
            if (!is_array($dialogue) || !isset($dialogue['turns']) || !is_array($dialogue['turns'])) {
                continue;
            }
            foreach ($dialogue['turns'] as &$turn) {
                if (!is_array($turn)) {
                    continue;
                }
                if (isset($turn['user']) && is_string($turn['user'])) {
                    $turn['user'] = sanitizeChannelPrefix($turn['user'], $stats);
                }
            }
            unset($turn);
        }
        unset($dialogue);
    }

    if (isset($data['emotion_cases']) && is_array($data['emotion_cases'])) {
        foreach ($data['emotion_cases'] as &$emotion) {
            if (!is_array($emotion)) {
                continue;
            }
            sanitizeStringList($emotion, 'user_samples', $stats);
        }
        unset($emotion);
    }

    if (isset($data['qa_cases']) && is_array($data['qa_cases'])) {
        foreach ($data['qa_cases'] as &$qa) {
            if (!is_array($qa) || !isset($qa['text']) || !is_string($qa['text'])) {
                continue;
            }
            $qa['text'] = sanitizeChannelPrefix($qa['text'], $stats);
        }
        unset($qa);
    }
}

function sanitizeStringList(array &$container, string $key, array &$stats): void
{
    if (!isset($container[$key]) || !is_array($container[$key])) {
        return;
    }

    $result = [];
    $seen = [];
    foreach ($container[$key] as $value) {
        if (!is_string($value)) {
            continue;
        }
        $clean = sanitizeChannelPrefix($value, $stats);
        $fingerprint = normalizeForDedup($clean);
        if ($fingerprint === '') {
            continue;
        }
        if (isset($seen[$fingerprint])) {
            $stats['duplicates_removed']++;
            continue;
        }
        $seen[$fingerprint] = true;
        $result[] = $clean;
    }

    $container[$key] = $result;
}

function sanitizeChannelPrefix(string $text, array &$stats): string
{
    $clean = preg_replace('/^\s*(web|telegram|whatsapp)\s*:\s*/iu', '', trim($text));
    if (!is_string($clean)) {
        $clean = trim($text);
    }

    $clean = preg_replace('/\s+/u', ' ', $clean);
    if (!is_string($clean)) {
        $clean = trim($text);
    }
    $clean = trim($clean);

    if ($clean !== trim($text)) {
        $stats['strings_sanitized']++;
    }

    return $clean;
}

function normalizeForDedup(string $text): string
{
    $value = mb_strtolower(trim($text), 'UTF-8');
    $value = strtr(
        $value,
        [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]
    );
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? trim($value) : '';
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/sanitize_training_dataset_channels.php --in=<input.json> [--out=<output.json>]\n\n";
    echo "What it does:\n";
    echo "  - removes leading channel prefixes (Web:, Telegram:, WhatsApp:) from training texts\n";
    echo "  - deduplicates normalized utterances/user samples\n";
    echo "  - preserves channel metadata only in context_pack.channels\n";
}
