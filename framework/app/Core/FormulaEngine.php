<?php
declare(strict_types=1);

namespace App\Core;

/**
 * FormulaEngine
 *
 * Safe expression evaluator for computed columns in app-creator apps.
 * Uses a recursive descent parser — zero eval(), zero exec().
 *
 * Variable syntax:  {field_name}  → substituted from row values before parsing.
 *
 * Operators:  + - * / %   >  <  >=  <=  ==  !=   AND OR NOT  && ||
 * Functions:  ROUND(x,n)  ABS(x)  FLOOR(x)  CEIL(x)  SQRT(x)  POW(x,n)
 *             MAX(a,b,…)  MIN(a,b,…)  SUM(a,b,…)
 *             IF(cond, then, else)
 *             CONCAT(a,b,…)  UPPER(s)  LOWER(s)  LENGTH(s)  TRIM(s)
 *             COALESCE(a,b,…)  ISNULL(x)  NOT(x)
 *
 * Schema placement (per entity in app memory):
 *   "formulas": [
 *     {"name": "total_con_iva", "expression": "ROUND({subtotal} * 1.19, 2)", "label": "Total"}
 *   ]
 */
final class FormulaEngine
{
    private const DANGEROUS = [
        'eval', 'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open',
        'file_get_contents', 'file_put_contents', 'fopen', 'require', 'include',
        'base64_decode', 'base64_encode', 'chr', 'ord', 'hex2bin', 'bin2hex',
    ];

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Evaluates an expression against a data row.
     * Returns int|float|string|bool|null — callers must cast as needed.
     */
    public function evaluate(string $expression, array $row): mixed
    {
        $expr   = $this->substituteVars($expression, $row);
        $tokens = $this->tokenize($expr);
        $pos    = 0;
        try {
            return $this->parseExpression($tokens, $pos);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Applies formula definitions to a row, returning the row with added computed fields.
     * Each formula: ['name' => 'col', 'expression' => '...']
     */
    public function applyFormulas(array $formulas, array $row): array
    {
        foreach ($formulas as $formula) {
            $name = (string) ($formula['name']       ?? '');
            $expr = (string) ($formula['expression'] ?? '');
            if ($name === '' || $expr === '') {
                continue;
            }
            try {
                $row[$name] = $this->evaluate($expr, $row);
            } catch (\Throwable) {
                $row[$name] = null;
            }
        }
        return $row;
    }

    /**
     * Validates that an expression is safe and syntactically correct.
     * Returns ['valid' => bool, 'error' => string].
     */
    public function validate(string $expression): array
    {
        $lower = strtolower($expression);
        foreach (self::DANGEROUS as $d) {
            if (str_contains($lower, $d)) {
                return ['valid' => false, 'error' => "Función no permitida: '{$d}'"];
            }
        }
        if (str_contains($expression, '`')) {
            return ['valid' => false, 'error' => 'Carácter backtick no permitido'];
        }
        if (preg_match('/\$[a-zA-Z_]/', $expression)) {
            return ['valid' => false, 'error' => 'Variables PHP no permitidas — use {nombre_campo}'];
        }
        try {
            $resolved = (string) preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '0', $expression);
            $tokens   = $this->tokenize($resolved);
            $pos      = 0;
            $this->parseExpression($tokens, $pos);
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => 'Error de sintaxis: ' . $e->getMessage()];
        }
        return ['valid' => true, 'error' => ''];
    }

    // ─── Variable substitution ────────────────────────────────────────────────

    private function substituteVars(string $expression, array $row): string
    {
        $result = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use ($row): string {
                $val = $row[$m[1]] ?? null;
                if ($val === null)      return '0';
                if (is_bool($val))     return $val ? '1' : '0';
                if (is_numeric($val))  return (string) $val;
                return '"' . addslashes((string) $val) . '"';
            },
            $expression
        );
        return $result ?? $expression;
    }

    // ─── Tokenizer ────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{type: string, value: mixed}>
     */
    private function tokenize(string $expr): array
    {
        $tokens = [];
        $i      = 0;
        $len    = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];

            if (ctype_space($ch)) { $i++; continue; }

            // Number literal
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $num    = '';
                $hasDot = false;
                while ($i < $len && (ctype_digit($expr[$i]) || ($expr[$i] === '.' && !$hasDot))) {
                    if ($expr[$i] === '.') { $hasDot = true; }
                    $num .= $expr[$i++];
                }
                $tokens[] = ['type' => 'NUMBER', 'value' => (float) $num];
                continue;
            }

            // Identifier / keyword
            if (ctype_alpha($ch) || $ch === '_') {
                $id = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $id .= $expr[$i++];
                }
                $tokens[] = ['type' => 'IDENT', 'value' => strtoupper($id)];
                continue;
            }

            // Double-quoted string
            if ($ch === '"') {
                $str = ''; $i++;
                while ($i < $len && $expr[$i] !== '"') {
                    if ($expr[$i] === '\\' && $i + 1 < $len) {
                        $str .= $expr[$i + 1]; $i += 2;
                    } else {
                        $str .= $expr[$i++];
                    }
                }
                $i++; // skip closing "
                $tokens[] = ['type' => 'STRING', 'value' => $str];
                continue;
            }

            // Two-char operators
            $two = substr($expr, $i, 2);
            if (in_array($two, ['>=', '<=', '==', '!=', '&&', '||'], true)) {
                $tokens[] = ['type' => 'OP', 'value' => $two];
                $i += 2;
                continue;
            }

            // Single-char operators
            if (in_array($ch, ['+', '-', '*', '/', '%', '(', ')', ',', '>', '<', '!'], true)) {
                $tokens[] = ['type' => 'OP', 'value' => $ch];
                $i++;
                continue;
            }

            $i++; // skip unknown character
        }

        return $tokens;
    }

    // ─── Recursive descent parser ─────────────────────────────────────────────
    // Grammar (simplified):
    //   expression   → logical
    //   logical      → comparison ( ('AND'|'OR'|'&&'|'||') comparison )*
    //   comparison   → addition   ( ('>'|'<'|'>='|'<='|'=='|'!=') addition )?
    //   addition     → multiply   ( ('+'|'-') multiply )*
    //   multiply     → unary      ( ('*'|'/'|'%') unary )*
    //   unary        → '-' primary | ('!'|'NOT') primary | primary
    //   primary      → NUMBER | STRING | literal | IDENT '(' args ')' | '(' expression ')'

    /** @param array<int, array{type: string, value: mixed}> $tokens */
    private function parseExpression(array $tokens, int &$pos): mixed
    {
        $left  = $this->parseComparison($tokens, $pos);
        $count = count($tokens);

        while ($pos < $count) {
            $t = $tokens[$pos];
            $isAnd = ($t['type'] === 'IDENT' && $t['value'] === 'AND') || ($t['type'] === 'OP' && $t['value'] === '&&');
            $isOr  = ($t['type'] === 'IDENT' && $t['value'] === 'OR')  || ($t['type'] === 'OP' && $t['value'] === '||');

            if (!$isAnd && !$isOr) { break; }
            $pos++;
            $right = $this->parseComparison($tokens, $pos);
            $left  = $isAnd ? ($left && $right) : ($left || $right);
        }
        return $left;
    }

    /** @param array<int, array{type: string, value: mixed}> $tokens */
    private function parseComparison(array $tokens, int &$pos): mixed
    {
        $left = $this->parseAddition($tokens, $pos);

        if ($pos < count($tokens)) {
            $t  = $tokens[$pos];
            $op = $t['type'] === 'OP' ? (string) $t['value'] : '';
            if (in_array($op, ['>', '<', '>=', '<=', '==', '!='], true)) {
                $pos++;
                $right = $this->parseAddition($tokens, $pos);
                return match ($op) {
                    '>'  => $left > $right,
                    '<'  => $left < $right,
                    '>=' => $left >= $right,
                    '<=' => $left <= $right,
                    '==' => $left == $right,
                    '!=' => $left != $right,
                    default => false,
                };
            }
        }
        return $left;
    }

    /** @param array<int, array{type: string, value: mixed}> $tokens */
    private function parseAddition(array $tokens, int &$pos): mixed
    {
        $left  = $this->parseMultiplication($tokens, $pos);
        $count = count($tokens);

        while ($pos < $count) {
            $t  = $tokens[$pos];
            $op = $t['type'] === 'OP' ? (string) $t['value'] : '';
            if ($op !== '+' && $op !== '-') { break; }
            $pos++;
            $right = $this->parseMultiplication($tokens, $pos);
            $left  = $op === '+' ? ($left + $right) : ($left - $right);
        }
        return $left;
    }

    /** @param array<int, array{type: string, value: mixed}> $tokens */
    private function parseMultiplication(array $tokens, int &$pos): mixed
    {
        $left  = $this->parseUnary($tokens, $pos);
        $count = count($tokens);

        while ($pos < $count) {
            $t  = $tokens[$pos];
            $op = $t['type'] === 'OP' ? (string) $t['value'] : '';
            if (!in_array($op, ['*', '/', '%'], true)) { break; }
            $pos++;
            $right = $this->parseUnary($tokens, $pos);
            $right = (float) $right;
            $left  = match ($op) {
                '*' => (float) $left * $right,
                '/' => $right != 0.0 ? (float) $left / $right : 0,
                '%' => $right != 0.0 ? fmod((float) $left, $right) : 0,
                default => $left,
            };
        }
        return $left;
    }

    /** @param array<int, array{type: string, value: mixed}> $tokens */
    private function parseUnary(array $tokens, int &$pos): mixed
    {
        if ($pos >= count($tokens)) { return 0; }
        $t = $tokens[$pos];

        if ($t['type'] === 'OP' && $t['value'] === '-') {
            $pos++;
            return -(float) $this->parsePrimary($tokens, $pos);
        }
        if (($t['type'] === 'OP' && $t['value'] === '!') ||
            ($t['type'] === 'IDENT' && $t['value'] === 'NOT')) {
            $pos++;
            return !$this->parsePrimary($tokens, $pos);
        }
        return $this->parsePrimary($tokens, $pos);
    }

    /** @param array<int, array{type: string, value: mixed}> $tokens */
    private function parsePrimary(array $tokens, int &$pos): mixed
    {
        $count = count($tokens);
        if ($pos >= $count) { return 0; }
        $t = $tokens[$pos];

        if ($t['type'] === 'NUMBER') { $pos++; return $t['value']; }
        if ($t['type'] === 'STRING') { $pos++; return $t['value']; }

        if ($t['type'] === 'IDENT') {
            $name = (string) $t['value'];
            if ($name === 'TRUE')  { $pos++; return true;  }
            if ($name === 'FALSE') { $pos++; return false; }
            if ($name === 'NULL')  { $pos++; return null;  }

            // Function call: IDENT ( args )
            if ($pos + 1 < $count
                && $tokens[$pos + 1]['type'] === 'OP'
                && $tokens[$pos + 1]['value'] === '(') {
                $pos += 2; // skip name + '('
                $args = [];
                while ($pos < $count
                    && !($tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ')')) {
                    $args[] = $this->parseExpression($tokens, $pos);
                    if ($pos < $count && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ',') {
                        $pos++;
                    }
                }
                if ($pos < $count) { $pos++; } // skip ')'
                return $this->callFunction($name, $args);
            }

            $pos++;
            return 0; // unknown identifier
        }

        if ($t['type'] === 'OP' && $t['value'] === '(') {
            $pos++;
            $val = $this->parseExpression($tokens, $pos);
            if ($pos < $count && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ')') {
                $pos++;
            }
            return $val;
        }

        return 0;
    }

    // ─── Built-in function library ────────────────────────────────────────────

    /** @param array<mixed> $args */
    private function callFunction(string $name, array $args): mixed
    {
        return match ($name) {
            'ROUND'    => round((float) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
            'ABS'      => abs((float) ($args[0] ?? 0)),
            'FLOOR'    => floor((float) ($args[0] ?? 0)),
            'CEIL'     => ceil((float) ($args[0] ?? 0)),
            'SQRT'     => sqrt(abs((float) ($args[0] ?? 0))),
            'POW'      => (($args[1] ?? 0) >= 0)
                ? pow((float) ($args[0] ?? 0), (float) ($args[1] ?? 1))
                : 0,
            'MAX'      => !empty($args) ? max(array_map('floatval', $args)) : 0,
            'MIN'      => !empty($args) ? min(array_map('floatval', $args)) : 0,
            'SUM'      => array_sum(array_map('floatval', $args)),
            'IF'       => (bool) ($args[0] ?? false) ? ($args[1] ?? null) : ($args[2] ?? null),
            'CONCAT'   => implode('', array_map('strval', $args)),
            'UPPER'    => strtoupper((string) ($args[0] ?? '')),
            'LOWER'    => strtolower((string) ($args[0] ?? '')),
            'LENGTH'   => mb_strlen((string) ($args[0] ?? '')),
            'TRIM'     => trim((string) ($args[0] ?? '')),
            'NOT'      => !(bool) ($args[0] ?? false),
            'ISNULL'   => ($args[0] ?? null) === null || ($args[0] ?? '') === '',
            'COALESCE' => array_reduce(
                $args,
                static fn($carry, $item) => $carry ?? ($item !== null && $item !== '' ? $item : null),
                null
            ) ?? '',
            default    => null,
        };
    }
}
