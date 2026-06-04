<?php

declare(strict_types=1);

namespace App\Core;

/**
 * DataQualityGuard — validates field values before any CRUD INSERT/UPDATE.
 *
 * Country-aware: rules are loaded from data_validation_policies.json under
 * countries.{countryCode} merged with common rules. PHP contains zero
 * business-specific regex — all formats live in the JSON config.
 *
 * To add a new country: add its section under "countries" in the JSON.
 * To change a phone regex for MX: edit countries.MX.field_types.phone.normalized_regex.
 * Zero PHP changes needed.
 *
 * Country code comes from tenant_business_config.country at runtime.
 */
final class DataQualityGuard
{
    private string $countryCode;
    private array  $fieldTypes;
    private array  $garbageRules;

    /** Maps algorithm names (JSON) to PHP methods. Add entries here when new algorithms are needed. */
    private const CHECK_DIGIT_ALGORITHMS = [
        'colombia_nit'    => 'verifyNitCheckDigit',
        'argentina_cuit'  => 'verifyCuitCheckDigit',
        'chile_rut'       => 'verifyRutCheckDigit',
    ];

    public function __construct(string $countryCode = 'CO')
    {
        $this->countryCode = strtoupper(trim($countryCode)) ?: 'CO';
        $this->loadRules();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validate all fields in a data array.
     *
     * @return array{reject: list<array>, warn: list<array>}
     *   reject — must block the operation; agent asks user to correct
     *   warn   — telemetry only, do not block
     *
     * Each violation: {field, value, type, reason, severity, ask}
     */
    public function validateFields(array $data): array
    {
        $reject = [];
        $warn   = [];

        foreach ($data as $fieldName => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $strValue  = (string) $value;
            $type      = $this->detectFieldType((string) $fieldName);
            $violation = $this->checkValue((string) $fieldName, $strValue, $type);
            if ($violation === null) {
                continue;
            }
            if ($violation['severity'] === 'reject') {
                $reject[] = $violation;
            } else {
                $warn[] = $violation;
            }
        }

        return ['reject' => $reject, 'warn' => $warn];
    }

    /**
     * Build a human-readable rejection message ready for the chat response.
     */
    public function formatRejectionMessage(array $rejectViolations): string
    {
        if (empty($rejectViolations)) {
            return '';
        }
        $messages = array_map(
            fn(array $v): string => $v['ask'] ?? "El campo «{$v['field']}» tiene un valor inválido: {$v['value']}.",
            $rejectViolations
        );
        if (count($messages) === 1) {
            return $messages[0];
        }
        return "Encontré datos que necesito verificar antes de guardar:\n- " . implode("\n- ", $messages);
    }

    /**
     * Detect semantic type from field name using JSON config patterns.
     * Exact match first, then substring fallback.
     */
    public function detectFieldType(string $fieldName): ?string
    {
        $normalized = strtolower(trim($fieldName));

        foreach ($this->fieldTypes as $type => $config) {
            if (in_array($normalized, (array) ($config['field_names'] ?? []), true)) {
                return (string) $type;
            }
        }
        // Substring fallback
        foreach ($this->fieldTypes as $type => $config) {
            foreach ((array) ($config['field_names'] ?? []) as $name) {
                if (str_contains($normalized, (string) $name)) {
                    return (string) $type;
                }
            }
        }
        return null;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function loadRules(): void
    {
        $common   = (array) PolicyLoader::get('data_validation_policies', 'common', []);
        $countries = (array) PolicyLoader::get('data_validation_policies', 'countries', []);

        // Country-specific overrides common (merge, country wins)
        $countryConfig = $countries[$this->countryCode] ?? $countries['CO'] ?? [];

        $this->fieldTypes = array_merge(
            (array) ($common['field_types']   ?? []),
            (array) ($countryConfig['field_types'] ?? [])
        );
        $this->garbageRules = (array) ($common['garbage_detection'] ?? []);
    }

    private function checkValue(string $field, string $value, ?string $type): ?array
    {
        // 1. Type-specific format + check digit
        if ($type !== null) {
            $config       = $this->fieldTypes[$type] ?? [];
            $formatReason = $this->validateByType($value, $config);
            if ($formatReason !== null) {
                $template = (string) ($config['reject_message'] ?? "El valor «{value}» no es válido para {$field}.");
                $ask      = (string) ($config['ask_if_missing'] ?? "Por favor ingresa un valor correcto para {$field}.");
                return [
                    'field'    => $field,
                    'value'    => $value,
                    'type'     => $type,
                    'reason'   => $formatReason,
                    'severity' => 'reject',
                    'ask'      => str_replace('{value}', $value, $template) . ' ' . $ask,
                ];
            }
        }

        // 2. Garbage detection (type-agnostic; skip for email — already validated by filter_var + domain check)
        if ($type === 'email') {
            return null;
        }
        $garbageReason = $this->detectGarbage($value, $type);
        if ($garbageReason !== null) {
            $config = $type !== null ? ($this->fieldTypes[$type] ?? []) : [];
            $ask    = (string) ($config['ask_if_missing'] ?? "Por favor ingresa un valor real para {$field}.");
            return [
                'field'    => $field,
                'value'    => $value,
                'type'     => $type,
                'reason'   => $garbageReason,
                'severity' => 'reject',
                'ask'      => $ask,
            ];
        }

        return null;
    }

    /**
     * Validate a value against its type config.
     * All validation is driven by JSON fields — zero hardcoded business regex.
     */
    private function validateByType(string $value, array $config): ?string
    {
        // Email: use PHP filter (more accurate than regex)
        if (!empty($config['validate_email'])) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return 'formato_invalido';
            }
            $atPos  = strpos($value, '@');
            $domain = $atPos !== false ? strtolower(substr($value, $atPos + 1)) : '';
            if (in_array($domain, (array) ($config['suspicious_domains'] ?? []), true)) {
                return 'dominio_sospechoso';
            }
            return null;
        }

        // Numeric types: normalize (strip spaces, dots, hyphens) then apply regex
        $rawRegex = (string) ($config['normalized_regex'] ?? '');
        if ($rawRegex !== '') {
            $clean = preg_replace('/[\s.\-]/', '', $value) ?? $value;
            // JSON stores raw regex without delimiters; wrap for preg_match
            $regex = '/' . str_replace('/', '\/', $rawRegex) . '/i';
            if (!preg_match($regex, $clean)) {
                return 'formato_invalido';
            }
            // Optional check digit algorithm
            $algo = (string) ($config['check_digit_algorithm'] ?? '');
            if ($algo !== '' && !$this->runCheckDigitAlgorithm($algo, $clean)) {
                return 'digito_verificador_invalido';
            }
            return null;
        }

        // Text fields: length validation
        $minLen = isset($config['min_length']) ? (int) $config['min_length'] : null;
        $maxLen = isset($config['max_length']) ? (int) $config['max_length'] : null;
        if ($minLen !== null && mb_strlen(trim($value)) < $minLen) {
            return 'demasiado_corto';
        }
        if ($maxLen !== null && mb_strlen($value) > $maxLen) {
            return 'demasiado_largo';
        }

        return null;
    }

    private function detectGarbage(string $value, ?string $type): ?string
    {
        $lower  = strtolower(trim($value));
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        // Sequential digit run (algorithm-based — avoids false positives on real IDs)
        if ($digits !== '') {
            $seqReason = $this->detectSequentialRun($digits);
            if ($seqReason !== null) {
                return $seqReason;
            }
        }

        // Repeated digit run
        $minRun = (int) ($this->garbageRules['repeated_min_run'] ?? 4);
        if ($digits !== '' && preg_match('/([0-9])\1{' . ($minRun - 1) . ',}/', $digits)) {
            return 'digitos_repetidos';
        }

        // Keyboard mash
        foreach ((array) ($this->garbageRules['keyboard_mash'] ?? []) as $mash) {
            if (str_contains($lower, (string) $mash)) {
                return 'patron_teclado';
            }
        }

        // Test/placeholder words
        foreach ((array) ($this->garbageRules['test_words'] ?? []) as $word) {
            if ($lower === (string) $word || preg_match('/\b' . preg_quote((string) $word, '/') . '\b/', $lower)) {
                return 'palabra_relleno';
            }
        }

        // Repeated character for text fields
        $isTextField = !empty($this->fieldTypes[$type ?? '']['check_garbage_text'] ?? false)
            || in_array($type, ['nombre', 'ciudad', 'direccion'], true);
        if ($isTextField && preg_match('/(.)\1{3,}/u', $lower)) {
            return 'caracter_repetido';
        }

        return null;
    }

    /**
     * Sequential digit run detection using ratio thresholds from config.
     * Flags only when a sequential run covers ≥ max(min_length, n*ratio) digits.
     */
    private function detectSequentialRun(string $digits): ?string
    {
        $n = strlen($digits);
        if ($n < 4) {
            return null;
        }

        $minLen   = (int)   ($this->garbageRules['sequential_run_min_length'] ?? 6);
        $minRatio = (float) ($this->garbageRules['sequential_run_min_ratio']  ?? 0.75);
        $minRun   = (int) max($minLen, (int) ceil($n * $minRatio));

        $maxAsc  = 1; $curAsc  = 1;
        $maxDesc = 1; $curDesc = 1;

        for ($i = 1; $i < $n; $i++) {
            $diff = (int) $digits[$i] - (int) $digits[$i - 1];
            if ($diff === 1) {
                $curAsc++;
                $maxAsc = max($maxAsc, $curAsc);
            } else {
                $curAsc = 1;
            }
            if ($diff === -1) {
                $curDesc++;
                $maxDesc = max($maxDesc, $curDesc);
            } else {
                $curDesc = 1;
            }
        }

        if ($maxAsc >= $minRun)  return 'digitos_secuenciales_ascendentes';
        if ($maxDesc >= $minRun) return 'digitos_secuenciales_descendentes';
        return null;
    }

    // -------------------------------------------------------------------------
    // Check digit algorithms — invoked by name from JSON config
    // -------------------------------------------------------------------------

    private function runCheckDigitAlgorithm(string $algo, string $cleanValue): bool
    {
        $method = self::CHECK_DIGIT_ALGORITHMS[$algo] ?? null;
        if ($method === null) {
            return true; // unknown algorithm — do not block
        }
        return $this->{$method}($cleanValue);
    }

    /**
     * DIAN Colombia NIT check digit.
     * Input: 9 digits → skip (can't verify). 10 digits → verify last digit.
     * Weights: [3,7,13,17,19,23,29,37,41] applied left to right on first 9 digits.
     */
    private function verifyNitCheckDigit(string $clean): bool
    {
        if (strlen($clean) !== 10) {
            return true; // 8 or 9 digits — no check digit to verify
        }
        if (!preg_match('/^[0-9]{10}$/', $clean)) {
            return false;
        }
        static $weights = [3, 7, 13, 17, 19, 23, 29, 37, 41];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $clean[$i] * $weights[$i];
        }
        $r     = $sum % 11;
        $check = ($r === 0 || $r === 1) ? $r : 11 - $r;
        return $check === (int) $clean[9];
    }

    /**
     * Argentina CUIT/CUIL check digit.
     * Input: 11 digits. Weights: [5,4,3,2,7,6,5,4,3,2] on digits 0-9, last is check.
     */
    private function verifyCuitCheckDigit(string $clean): bool
    {
        if (!preg_match('/^[0-9]{11}$/', $clean)) {
            return false;
        }
        static $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $clean[$i] * $weights[$i];
        }
        $r     = $sum % 11;
        $check = 11 - $r;
        if ($check === 11) $check = 0;
        if ($check === 10) return false; // invalid CUIT
        return $check === (int) $clean[10];
    }

    /**
     * Chile RUT check digit.
     * Input: 7-9 chars, last char is check digit (0-9 or K/k).
     * Weights: [2,3,4,5,6,7,2,3] applied right to left on numeric part.
     */
    private function verifyRutCheckDigit(string $clean): bool
    {
        $clean = strtoupper($clean);
        if (!preg_match('/^[0-9]{7,8}[0-9K]$/', $clean)) {
            return false;
        }
        $body  = substr($clean, 0, -1);
        $check = substr($clean, -1);
        static $weights = [2, 3, 4, 5, 6, 7, 2, 3];
        $reversed = strrev($body);
        $sum = 0;
        for ($i = 0; $i < strlen($reversed); $i++) {
            $sum += (int) $reversed[$i] * ($weights[$i] ?? 2);
        }
        $r        = $sum % 11;
        $expected = match (11 - $r) {
            11      => '0',
            10      => 'K',
            default => (string) (11 - $r),
        };
        return $check === $expected;
    }
}
