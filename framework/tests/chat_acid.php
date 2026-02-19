<?php
// framework/tests/chat_acid.php
// Acid test for ConversationGateway (local-first routing).

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\AcidChatRunner;

$runner = new AcidChatRunner('c:/laragon/www/suki/project');
$report = $runner->run('default', [
    'save' => true,
    'path' => __DIR__ . '/chat_acid_result.json',
]);

echo json_encode($report['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
