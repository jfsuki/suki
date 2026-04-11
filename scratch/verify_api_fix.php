<?php
// scratch/verify_api_fix.php
require_once __DIR__ . '/../framework/app/autoload.php';
require_once __DIR__ . '/../project/public/api.php';

use App\Controller\ChatController;

try {
    $controller = new ChatController();
    $result = $controller->list();
    
    echo "--- API VERIFICATION ---\n";
    echo $result . "\n";
    
    $data = json_decode($result, true);
    if (($data['status'] ?? '') === 'success') {
        echo "✅ API SUCCESS: " . count($data['data']['sessions'] ?? []) . " sessions found.\n";
    } else {
        echo "❌ API FAILED: " . ($data['message'] ?? 'Unknown error') . "\n";
    }

} catch (Exception $e) {
    echo "❌ CRASH: " . $e->getMessage() . "\n";
}
