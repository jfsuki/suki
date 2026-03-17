<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BegValidator;
use App\Core\Contracts\ContractRepository;

$failures = [];

$catalogReport = BegValidator::validateCatalog();
if (($catalogReport['ok'] ?? false) !== true) {
    $failures[] = 'El catalogo BEG base debe validar.';
}

$validPayload = [
    'event_id' => 'evt_sale_001',
    'event_type' => 'sale_event',
    'occurred_at' => date('c'),
    'tenant_id' => 'tenant_graph',
    'app_id' => 'app_graph',
    'related_entities' => [
        [
            'entity_type' => 'customer',
            'entity_id' => 'cust_001',
            'tenant_id' => 'tenant_graph',
            'app_id' => 'app_graph',
            'role' => 'buyer',
        ],
    ],
    'related_documents' => [
        [
            'document_type' => 'invoice',
            'document_id' => 'inv_001',
            'tenant_id' => 'tenant_graph',
            'app_id' => 'app_graph',
            'role' => 'source',
        ],
    ],
    'source_skill' => null,
    'source_module' => 'ontology_contracts',
    'status' => 'recorded',
    'causal_parent_ids' => ['evt_quote_001'],
    'metadata' => [
        'channel' => 'unit_test',
    ],
    'event_relationships' => [
        [
            'relationship_type' => 'causes',
            'target_event_id' => 'evt_collection_001',
            'description' => 'sale causes later collection',
        ],
    ],
];

$payloadReport = BegValidator::validateEventPayload($validPayload);
if (($payloadReport['ok'] ?? false) !== true) {
    $failures[] = 'Payload BEG valido debe pasar.';
}

$invalidEventType = $validPayload;
$invalidEventType['event_type'] = 'ghost_event';
$invalidEventReport = BegValidator::validateEventPayload($invalidEventType);
if (($invalidEventReport['ok'] ?? false) === true) {
    $failures[] = 'BEG debe bloquear event_type fuera de catalogo.';
}

$invalidRelationship = $validPayload;
$invalidRelationship['event_relationships'][0]['relationship_type'] = 'teleports_to';
$invalidRelationshipReport = BegValidator::validateEventPayload($invalidRelationship);
if (($invalidRelationshipReport['ok'] ?? false) === true) {
    $failures[] = 'BEG debe bloquear relationship_type invalido.';
}

$crossTenant = $validPayload;
$crossTenant['related_entities'][0]['tenant_id'] = 'tenant_other';
$crossTenantReport = BegValidator::validateEventPayload($crossTenant);
if (($crossTenantReport['ok'] ?? false) === true) {
    $failures[] = 'BEG debe bloquear referencias cross-tenant.';
}

try {
    $repo = new ContractRepository();
    $catalogSchema = $repo->getSchema('beg.schema');
    $payloadSchema = $repo->getSchema('beg_event_payload.schema');
    if (($catalogSchema['contract_id'] ?? '') !== 'business_event_graph') {
        $failures[] = 'ContractRepository debe resolver beg.schema.json.';
    }
    if (($payloadSchema['contract_id'] ?? '') !== 'beg_event_payload') {
        $failures[] = 'ContractRepository debe resolver beg_event_payload.schema.json.';
    }
} catch (Throwable $e) {
    $failures[] = 'Schema repository BEG fallo: ' . $e->getMessage();
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
