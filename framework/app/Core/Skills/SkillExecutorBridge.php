<?php
declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\SkillExecutor;

/**
 * Template for future standalone skill classes that do NOT go through SkillExecutor.
 *
 * IMPORTANT: Do NOT register catalog entries that dispatch through SkillExecutor with this
 * bridge class — SkillExecutor calls DynamicSkillRegistry internally, which would create
 * an infinite dispatch loop.
 *
 * Legacy CommandBus skills (POS/Purchases/Fiscal/EntitySearch) are already verified:
 *   - POS skills → SkillExecutor::executePOSSkill() → POSCommandHandler
 *   - Purchases skills → SkillExecutor::executePurchaseSkill() → PurchasesCommandHandler
 *   - Fiscal skills → SkillExecutor::executeFiscalSkill() → FiscalEngineMessageParser
 *   - EntitySearch → SkillExecutor::executeEntitySearchSkill() → EntitySearchMessageParser
 *
 * NEW skills (not in SkillExecutor's if-chain) should use:
 *   "handler": "App\\Core\\Skills\\NewSkillClass" (direct class, not bridge)
 */
final class SkillExecutorBridge
{
    private SkillExecutor $executor;

    public function __construct()
    {
        $this->executor = new SkillExecutor();
    }

    /** Generic entry point — skill name passed via $context['_bridge_skill'] */
    public function handle(array $input, array $context = []): array
    {
        $skillName = (string) ($context['_bridge_skill'] ?? $input['skill_name'] ?? '');
        if ($skillName === '') {
            return ['status' => 'error', 'message' => 'SkillExecutorBridge: skill_name requerido'];
        }
        return $this->dispatch($skillName, $input, $context);
    }

    // ── POS ─────────────────────────────────────────────────────────────────

    public function pos_finalize_sale(array $input, array $context = []): array
    {
        return $this->dispatch('pos_finalize_sale', $input, $context);
    }

    public function pos_create_draft(array $input, array $context = []): array
    {
        return $this->dispatch('pos_create_draft', $input, $context);
    }

    public function pos_add_line(array $input, array $context = []): array
    {
        return $this->dispatch('pos_add_line', $input, $context);
    }

    // ── Purchases ────────────────────────────────────────────────────────────

    public function purchase_create(array $input, array $context = []): array
    {
        return $this->dispatch('purchase_create', $input, $context);
    }

    public function purchase_confirm(array $input, array $context = []): array
    {
        return $this->dispatch('purchase_confirm', $input, $context);
    }

    // ── Fiscal ──────────────────────────────────────────────────────────────

    public function fiscal_invoice(array $input, array $context = []): array
    {
        return $this->dispatch('fiscal_invoice', $input, $context);
    }

    public function fiscal_credit_note(array $input, array $context = []): array
    {
        return $this->dispatch('fiscal_credit_note', $input, $context);
    }

    // ── Entity Search ────────────────────────────────────────────────────────

    public function entity_search(array $input, array $context = []): array
    {
        return $this->dispatch('entity_search', $input, $context);
    }

    // ── Alerts ──────────────────────────────────────────────────────────────

    public function alerts_create(array $input, array $context = []): array
    {
        return $this->dispatch('alerts_create', $input, $context);
    }

    public function alerts_list(array $input, array $context = []): array
    {
        return $this->dispatch('alerts_list', $input, $context);
    }

    // ── Private ─────────────────────────────────────────────────────────────

    private function dispatch(string $skillName, array $input, array $context): array
    {
        try {
            return $this->executor->executeWithExplicitArgs($skillName, $input, $context);
        } catch (\Throwable $e) {
            error_log("[SkillExecutorBridge] {$skillName} error: " . $e->getMessage());
            return ['status' => 'error', 'message' => 'Error ejecutando ' . $skillName . ': ' . $e->getMessage()];
        }
    }
}
