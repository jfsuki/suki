<?php
// app/Core/ExpressionEngine.php

namespace App\Core;

final class ExpressionEngine
{
    public function evaluate(string $expression, array $variables = []): float
    {
        $tokens = $this->tokenize($expression);
        if (empty($tokens)) {
            return 0.0;
        }
        $rpn = $this->toRpn($tokens);
        return $this->evalRpn($rpn, $variables);
    }

    private function tokenize(string $expression): array
    {
        $tokens = [];
        $len = strlen($expression);
        $number = '';
        $identifier = '';

        $flushNumber = function () use (&$tokens, &$number) {
            if ($number !== '') {
                $tokens[] = $number;
                $number = '';
            }
        };
        $flushIdentifier = function () use (&$tokens, &$identifier) {
            if ($identifier !== '') {
                $tokens[] = $identifier;
                $identifier = '';
            }
        };

        for ($i = 0; $i < $len; $i++) {
            $ch = $expression[$i];

            if (ctype_space($ch)) {
                $flushNumber();
                $flushIdentifier();
                continue;
            }

            if (ctype_digit($ch) || ($ch === '.' && $number !== '')) {
                $flushIdentifier();
                $number .= $ch;
                continue;
            }

            if ($ch === '-' && $number === '' && $identifier === '') {
                $prev = $this->previousNonSpace($expression, $i - 1);
                if ($prev === null || in_array($prev, ['+', '-', '*', '/', '('], true)) {
                    $number = '-';
                    continue;
                }
            }

            if (ctype_alpha($ch) || $ch === '_' ) {
                $flushNumber();
                $identifier .= $ch;
                continue;
            }

            if ($identifier !== '' && (ctype_digit($ch))) {
                $identifier .= $ch;
                continue;
            }

            $flushNumber();
            $flushIdentifier();

            if (in_array($ch, ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = $ch;
            }
        }

        $flushNumber();
        $flushIdentifier();

        return $tokens;
    }

    private function previousNonSpace(string $expression, int $index): ?string
    {
        for ($i = $index; $i >= 0; $i--) {
            if (!ctype_space($expression[$i])) {
                return $expression[$i];
            }
        }
        return null;
    }

    private function toRpn(array $tokens): array
    {
        $output = [];
        $stack = [];
        $precedence = [
            '+' => 1,
            '-' => 1,
            '*' => 2,
            '/' => 2,
        ];

        foreach ($tokens as $token) {
            if ($this->isNumber($token) || $this->isIdentifier($token)) {
                $output[] = $token;
                continue;
            }

            if (in_array($token, ['+', '-', '*', '/'], true)) {
                while (!empty($stack)) {
                    $top = end($stack);
                    if ($top === '(') {
                        break;
                    }
                    if ($precedence[$top] >= $precedence[$token]) {
                        $output[] = array_pop($stack);
                        continue;
                    }
                    break;
                }
                $stack[] = $token;
                continue;
            }

            if ($token === '(') {
                $stack[] = $token;
                continue;
            }

            if ($token === ')') {
                while (!empty($stack)) {
                    $top = array_pop($stack);
                    if ($top === '(') {
                        break;
                    }
                    $output[] = $top;
                }
            }
        }

        while (!empty($stack)) {
            $output[] = array_pop($stack);
        }

        return $output;
    }

    private function evalRpn(array $tokens, array $variables): float
    {
        $stack = [];
        foreach ($tokens as $token) {
            if ($this->isNumber($token)) {
                $stack[] = (float) $token;
                continue;
            }
            if ($this->isIdentifier($token)) {
                $stack[] = isset($variables[$token]) ? (float) $variables[$token] : 0.0;
                continue;
            }
            if (in_array($token, ['+', '-', '*', '/'], true)) {
                $b = array_pop($stack) ?? 0.0;
                $a = array_pop($stack) ?? 0.0;
                switch ($token) {
                    case '+': $stack[] = $a + $b; break;
                    case '-': $stack[] = $a - $b; break;
                    case '*': $stack[] = $a * $b; break;
                    case '/': $stack[] = $b == 0.0 ? 0.0 : $a / $b; break;
                }
            }
        }
        return (float) (array_pop($stack) ?? 0.0);
    }

    private function isNumber(string $token): bool
    {
        return is_numeric($token);
    }

    private function isIdentifier(string $token): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $token);
    }
}
