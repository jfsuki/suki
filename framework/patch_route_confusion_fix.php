<?php
$f = 'c:\laragon\www\suki\framework\app\Core\Agents\ConversationGateway.php';
$c = file_get_contents($f);

// 1. Update the call site in handle()
$search1 = '$confusionRoute = $this->routeConfusion($normalizedBase, $mode, $state, $profile, $confusionBase);';
$replace1 = '$confusionRoute = $this->routeConfusion($normalizedBase, $mode, $state, $profile, $confusionBase, $tenantId, $userId);';
$c = str_replace($search1, $replace1, $c);

// 2. Update the method signature
$search2 = 'private function routeConfusion(string $text, string $mode, array $state, array $profile, array $confusionBase): array';
$replace2 = 'private function routeConfusion(string $text, string $mode, array $state, array $profile, array $confusionBase, string $tenantId = "default", string $userId = "anon"): array';
$c = str_replace($search2, $replace2, $c);

file_put_contents($f, $c);
echo "Fixed routeConfusion signature and call site!";
