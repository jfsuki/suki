<?php
// app/Core/Skills/CalculatorSkill.php

namespace App\Core\Skills;

final class CalculatorSkill
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $op = trim((string) ($input['op'] ?? 'evaluate'));
        
        return match ($op) {
            'margin_price' => $this->calculateMarginPrice($input),
            'round_multiple' => $this->calculateRoundMultiple($input),
            'tax_projection' => $this->calculateTaxProjection($input),
            default => $this->evaluateExpression($input),
        };
    }

    private function calculateMarginPrice(array $input): array
    {
        $cost = (float) ($input['cost'] ?? 0);
        $margin = (float) ($input['margin'] ?? 0.25);
        if ($margin >= 1) $margin /= 100; // handle percentage
        
        $price = $cost / (1 - $margin);
        
        if (!empty($input['round_to'])) {
            $price = $this->roundTo($price, (int)$input['round_to']);
        }

        return ['price' => $price, 'cost' => $cost, 'margin' => $margin];
    }

    private function calculateRoundMultiple(array $input): array
    {
        $value = (float) ($input['value'] ?? 0);
        $multiple = (int) ($input['multiple'] ?? 5000);
        return ['value' => $this->roundTo($value, $multiple), 'original' => $value, 'multiple' => $multiple];
    }

    private function calculateTaxProjection(array $input): array
    {
        $total = (float) ($input['total'] ?? 0);
        $iva_rate = (float) ($input['iva_rate'] ?? 0.19);
        $ica_rate = (float) ($input['ica_rate'] ?? 0.007); // 7 per thousand standard retail
        
        $iva = $total * $iva_rate;
        $ica = $total * $ica_rate;
        
        return [
            'total' => $total,
            'iva' => $iva,
            'ica' => $ica,
            'final_tax' => $iva + $ica
        ];
    }

    private function evaluateExpression(array $input): array
    {
        $expression = trim((string) ($input['expression'] ?? ''));
        if ($expression === '') {
            return ['result' => 0, 'error' => 'expression_empty'];
        }

        // Whitelist: only digits, decimal point, arithmetic operators, parentheses, spaces
        if (!preg_match('/^[\d\s\.\+\-\*\/\(\)]+$/', $expression)) {
            return ['result' => 0, 'error' => 'expression_contains_invalid_characters'];
        }

        try {
            $result = $this->safeEval($expression);
        } catch (\Throwable $e) {
            return ['result' => 0, 'error' => 'expression_invalid: ' . $e->getMessage()];
        }

        return ['result' => $result, 'expression' => $expression];
    }

    /**
     * Recursive descent parser: handles +, -, *, /, (, ), decimals, unary minus.
     * No eval(), no exec(), no arbitrary code — purely arithmetic.
     */
    private function safeEval(string $expr): float
    {
        $tokens = $this->tokenize($expr);
        $pos = 0;
        $result = $this->parseAddSub($tokens, $pos);
        if ($pos < count($tokens)) {
            throw new \InvalidArgumentException('Unexpected token at position ' . $pos);
        }
        return $result;
    }

    /** @param array<int,string> $tokens */
    private function parseAddSub(array $tokens, int &$pos): float
    {
        $left = $this->parseMulDiv($tokens, $pos);
        while ($pos < count($tokens) && in_array($tokens[$pos], ['+', '-'], true)) {
            $op = $tokens[$pos++];
            $right = $this->parseMulDiv($tokens, $pos);
            $left = $op === '+' ? $left + $right : $left - $right;
        }
        return $left;
    }

    /** @param array<int,string> $tokens */
    private function parseMulDiv(array $tokens, int &$pos): float
    {
        $left = $this->parseUnary($tokens, $pos);
        while ($pos < count($tokens) && in_array($tokens[$pos], ['*', '/'], true)) {
            $op = $tokens[$pos++];
            $right = $this->parseUnary($tokens, $pos);
            if ($op === '/' && $right == 0.0) {
                throw new \DivisionByZeroError('Division by zero');
            }
            $left = $op === '*' ? $left * $right : $left / $right;
        }
        return $left;
    }

    /** @param array<int,string> $tokens */
    private function parseUnary(array $tokens, int &$pos): float
    {
        if ($pos < count($tokens) && $tokens[$pos] === '-') {
            $pos++;
            return -$this->parsePrimary($tokens, $pos);
        }
        if ($pos < count($tokens) && $tokens[$pos] === '+') {
            $pos++;
        }
        return $this->parsePrimary($tokens, $pos);
    }

    /** @param array<int,string> $tokens */
    private function parsePrimary(array $tokens, int &$pos): float
    {
        if ($pos >= count($tokens)) {
            throw new \InvalidArgumentException('Unexpected end of expression');
        }
        if ($tokens[$pos] === '(') {
            $pos++;
            $val = $this->parseAddSub($tokens, $pos);
            if ($pos >= count($tokens) || $tokens[$pos] !== ')') {
                throw new \InvalidArgumentException('Missing closing parenthesis');
            }
            $pos++;
            return $val;
        }
        if (is_numeric($tokens[$pos])) {
            return (float) $tokens[$pos++];
        }
        throw new \InvalidArgumentException('Unexpected token: ' . $tokens[$pos]);
    }

    /**
     * Tokenizes an arithmetic string into numbers and operators.
     * @return array<int,string>
     */
    private function tokenize(string $expr): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($expr);
        while ($i < $len) {
            if ($expr[$i] === ' ') { $i++; continue; }
            if (in_array($expr[$i], ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = $expr[$i++];
                continue;
            }
            if (ctype_digit($expr[$i]) || $expr[$i] === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = $num;
                continue;
            }
            throw new \InvalidArgumentException('Unexpected character: ' . $expr[$i]);
        }
        return $tokens;
    }

    private function roundTo(float $value, int $multiple): float
    {
        if ($multiple <= 0) return $value;
        return ceil($value / $multiple) * $multiple;
    }
}
