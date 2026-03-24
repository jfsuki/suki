<?php
// framework/tests/unit_onboarding_step_skipping.php
require_once __DIR__ . '/../vendor/autoload.php';

// Mocking environment to avoid DB errors
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('GEMINI_API_KEY=dummy');
if (!defined('FRAMEWORK_ROOT')) define('FRAMEWORK_ROOT', __DIR__ . '/../');

use App\Core\Agents\ConversationGateway;

// Use reflection to bypass constructor and initialize properties
$reflector = new ReflectionClass(ConversationGateway::class);
$gateway = $reflector->newInstanceWithoutConstructor();

// Initialize internal props used by normalizeBusinessType -> loadDomainPlaybook
$rootProp = $reflector->getProperty('projectRoot');
$rootProp->setAccessible(true);
$rootProp->setValue($gateway, realpath(__DIR__ . '/../'));

$method = $reflector->getMethod('resolveBuilderOnboardingStep');
$method->setAccessible(true);

echo "Case 1: Empty Profile\n";
$profile1 = [];
$state1 = [];
$step1 = $method->invoke($gateway, $profile1, $state1);
echo "Step: $step1 (Expected: business_type)\n";

echo "Case 2: Profile with business_type\n";
$profile2 = ['business_type' => 'ferreteria'];
$state2 = ['onboarding_step' => 'business_type']; // OLD state
$step2 = $method->invoke($gateway, $profile2, $state2);
echo "Step: $step2 (Expected: operation_model)\n";

if ($step2 === 'operation_model') {
    echo "SUCCESS: Onboarding skipped 'business_type' despite state['onboarding_step'].\n";
} else {
    echo "FAILURE: Onboarding stuck in '$step2'.\n";
    exit(1);
}

echo "Case 3: Profile with operation_model\n";
$profile3 = ['business_type' => 'ferreteria', 'operation_model' => 'contado'];
$step3 = $method->invoke($gateway, $profile3, $state2);
echo "Step: $step3 (Expected: needs_scope)\n";

if ($step3 === 'needs_scope') {
    echo "SUCCESS: Onboarding skipped to 'needs_scope'.\n";
} else {
    echo "FAILURE: Onboarding stuck in '$step3'.\n";
    exit(1);
}
