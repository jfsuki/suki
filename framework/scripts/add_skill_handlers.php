<?php
/**
 * One-time migration script: adds "handler" field to skills_catalog.json entries.
 * Run once, then delete (or keep for reference).
 * Safe to re-run — already-present handler fields are preserved.
 */

$catalogPath = __DIR__ . '/../../docs/contracts/skills_catalog.json';
$raw = file_get_contents($catalogPath);
$catalog = json_decode($raw, true);

if (!isset($catalog['catalog']) || !is_array($catalog['catalog'])) {
    echo "ERROR: catalog array not found\n";
    exit(1);
}

// Skill name → "Fully\Qualified\ClassName" or "ClassName@method"
// Based on: Skills/*.php public method audit
$handlerMap = [
    // Accounting
    'accounting_balance_sheet'           => 'App\\Core\\Skills\\AccountingSkill',
    'accounting_post'                    => 'App\\Core\\Skills\\AccountingSkill',
    'accounting_record_entry'            => 'App\\Core\\Skills\\AccountingSkill',
    'accounting_record_sale'             => 'App\\Core\\Skills\\AccountingSkill',
    'accounting_profit_loss'             => 'App\\Core\\Skills\\AccountingSkill',
    'accounting_cash_flow'               => 'App\\Core\\Skills\\AccountingSkill',
    'accounting_financial_statements'    => 'App\\Core\\Skills\\AccountingSkill',

    // Inventory
    'inventory_check'                    => 'App\\Core\\Skills\\InventorySkill',
    'inventory_check_stock'              => 'App\\Core\\Skills\\InventorySkill',
    'inventory_adjust_stock'             => 'App\\Core\\Skills\\InventorySkill',
    'inventory_create_product'           => 'App\\Core\\Skills\\InventorySkill',
    'inventory_list_products'            => 'App\\Core\\Skills\\InventorySkill',
    'inventory_reorder'                  => 'App\\Core\\Skills\\InventorySkill',
    'inventory_depletion_alert'          => 'App\\Core\\Skills\\InventorySkill',

    // CRM
    'customer_lookup'                    => 'App\\Core\\Skills\\CRMSkill',
    'crm_register_lead'                  => 'App\\Core\\Skills\\CRMSkill',
    'crm_update_customer'                => 'App\\Core\\Skills\\CRMSkill',
    'crm_search_customers'               => 'App\\Core\\Skills\\CRMSkill',
    'crm_stats'                          => 'App\\Core\\Skills\\CRMSkill',

    // App creator / CRUD / Reports
    'create_app'                         => 'App\\Core\\Skills\\CreateAppSkill',
    'propose_app_type'                   => 'App\\Core\\Skills\\CreateAppSkill',
    'app_crud'                           => 'App\\Core\\Skills\\AppCrudSkill@execute',
    'app_report'                         => 'App\\Core\\Skills\\AppReportSkill@execute',

    // Agent creation
    'create_agent'                       => 'App\\Core\\Skills\\CreateAgentSkill',

    // Business Automation
    'web_search'                         => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'automation_web_search'              => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'download_file'                      => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'automation_download_file'           => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'validate_payment'                   => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'automation_validate_payment'        => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'business_automation'                => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'automation_business_automation'     => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'create_tool'                        => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',
    'automation_create_tool'             => 'App\\Core\\Skills\\BusinessAutomationSkill@execute',

    // Calculator / conversions / fiscal tax
    'calculate'                          => 'App\\Core\\Skills\\CalculatorSkill',
    'calculator'                         => 'App\\Core\\Skills\\CalculatorSkill',
    'unit_conversion'                    => 'App\\Core\\Skills\\UnitConversionSkill@calculate',
    'fiscal_tax'                         => 'App\\Core\\Skills\\FiscalTaxSkill@calculate',
    'expiry_control'                     => 'App\\Core\\Skills\\ExpiryControlSkill@calculate',

    // Media
    'media_ingest'                       => 'App\\Core\\Skills\\MediaIngestionSkill',
    'media_upload'                       => 'App\\Core\\Skills\\MediaIngestionSkill',
    'media_process'                      => 'App\\Core\\Skills\\MediaIngestionSkill',
    'train_agent'                        => 'App\\Core\\Skills\\MediaIngestionSkill',
];

$updated = 0;
$skipped = 0;
foreach ($catalog['catalog'] as &$entry) {
    $name = $entry['name'] ?? '';
    if (isset($handlerMap[$name])) {
        if (!isset($entry['handler'])) {
            $entry['handler'] = $handlerMap[$name];
            $updated++;
        } else {
            $skipped++; // already has handler
        }
    }
}
unset($entry);

$written = file_put_contents(
    $catalogPath,
    json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

if ($written === false) {
    echo "ERROR: could not write catalog\n";
    exit(1);
}

echo "Done. Updated: {$updated} entries. Skipped (already had handler): {$skipped}\n";
