<?php
// config/env_loader.php

function loadEnv($path, $override = true)
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $entry = parseEnvLine((string) $line);
        if (!is_array($entry)) {
            continue;
        }

        $name = (string) ($entry['name'] ?? '');
        $value = (string) ($entry['value'] ?? '');
        if ($name === '') {
            continue;
        }

        if ($override || (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV))) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

/**
 * Parse .env line with support for:
 * - KEY=value
 * - export KEY=value
 * - quoted values (single/double quotes)
 * - inline comments on unquoted values (e.g. VALUE # comment)
 * - simple escapes (\n, \r, \t, \", \\, \#, \ )
 *
 * @return array{name:string,value:string}|null
 */
function parseEnvLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        return null;
    }

    if (str_starts_with($line, 'export ')) {
        $line = trim(substr($line, 7));
    }

    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
        return null;
    }

    $name = (string) ($matches[1] ?? '');
    $rawValue = (string) ($matches[2] ?? '');
    $value = parseEnvValue($rawValue);

    return [
        'name' => $name,
        'value' => $value,
    ];
}

function parseEnvValue(string $rawValue): string
{
    $rawValue = ltrim($rawValue);
    if ($rawValue === '') {
        return '';
    }

    $first = $rawValue[0];
    if ($first === '"' || $first === "'") {
        $quoted = extractQuotedValue($rawValue, $first);
        return decodeEnvEscapes($quoted, $first === '"');
    }

    // For unquoted values, strip trailing inline comments with whitespace prefix.
    $value = preg_replace('/\s+#.*$/', '', $rawValue);
    $value = trim((string) $value);
    return decodeEnvEscapes($value, false);
}

function extractQuotedValue(string $rawValue, string $quote): string
{
    $len = strlen($rawValue);
    $value = '';
    $escaped = false;

    for ($i = 1; $i < $len; $i++) {
        $ch = $rawValue[$i];
        if (!$escaped && $ch === $quote) {
            // Ignore any trailing comment/text after the closing quote.
            return $value;
        }
        if ($ch === '\\' && !$escaped) {
            $escaped = true;
            $value .= $ch;
            continue;
        }
        $escaped = false;
        $value .= $ch;
    }

    // Backward-compatible fallback for malformed line without closing quote.
    return substr($rawValue, 1);
}

function decodeEnvEscapes(string $value, bool $isDoubleQuoted): string
{
    if ($value === '') {
        return '';
    }

    $map = [
        '\\\\' => '\\',
        '\\#' => '#',
        '\\ ' => ' ',
    ];

    if ($isDoubleQuoted) {
        $map['\\n'] = "\n";
        $map['\\r'] = "\r";
        $map['\\t'] = "\t";
        $map['\\"'] = '"';
        $map['\\$'] = '$';
    } else {
        $map["\\'"] = "'";
    }

    return strtr($value, $map);
}

// Load project .env from parent folder of /config
loadEnv(__DIR__ . '/../.env', true);
