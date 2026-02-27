<?php
$f = 'c:\laragon\www\suki\framework\app\Core\LLM\LLMRouter.php';
$c = file_get_contents($f);

// 1. Add deepseek to default config
$search1 = <<<'EOD'
        return [
            'providers' => [],
EOD;

$replace1 = <<<'EOD'
        return [
            'providers' => [
                'deepseek' => [
                    'class' => \App\Core\LLM\Providers\DeepSeekProvider::class,
                    'enabled' => !empty(getenv('DEEPSEEK_API_KEY')),
                ],
                'gemini' => [
                    'class' => \App\Core\LLM\Providers\GeminiProvider::class,
                    'enabled' => !empty(getenv('GEMINI_API_KEY')),
                ],
            ],
EOD;

$c = str_replace($search1, $replace1, $c);

// 2. Add deepseek to in_array mode check
$search2 = "if (in_array($mode, ['groq', 'gemini', 'openrouter', 'claude'], true)) {";
$replace2 = "if (in_array($mode, ['groq', 'gemini', 'openrouter', 'claude', 'deepseek'], true)) {";

// Since str_replace might fail with $ variables if not escaped properly in the search string, 
// using a more literal approach or just fixing the array.
$c = str_replace("['groq', 'gemini', 'openrouter', 'claude']", "['groq', 'gemini', 'openrouter', 'claude', 'deepseek']", $c);

file_put_contents($f, $c);
echo "Registered DeepSeek in LLMRouter!";
