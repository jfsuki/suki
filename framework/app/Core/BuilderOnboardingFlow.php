<?php
// app/Core/BuilderOnboardingFlow.php

namespace App\Core;

final class BuilderOnboardingFlow
{
    /**
     * @param array<string, callable> $ops
     */
    public function handle(
        string $text,
        array $state,
        array $profile,
        string $tenantId,
        string $userId,
        array $ops,
        callable $coreHandler
    ): ?array {
        $active = (string) ($state['active_task'] ?? '');
        $isOnboarding = $active === 'builder_onboarding';
        $isUnknownDiscovery = $active === 'unknown_business_discovery';
        $trigger = (bool) ($ops['isBuilderOnboardingTrigger'])($text);
        $businessHint = ((string) ($ops['detectBusinessType'])($text)) !== '';

        $playbookInstall = (array) ($ops['parseInstallPlaybookRequest'])($text);
        if (!empty($playbookInstall['matched'])) {
            return null;
        }

        $playbookRoute = (array) ($ops['classifyWithPlaybookIntents'])($text, $profile);
        $playbookAction = (string) ($playbookRoute['action'] ?? '');
        $playbookConfidence = (float) ($playbookRoute['confidence'] ?? 0.0);
        if (
            str_starts_with($playbookAction, 'APPLY_PLAYBOOK_')
            && $playbookConfidence >= 0.72
            && !$trigger
            && !$isUnknownDiscovery
        ) {
            return null;
        }

        if ((bool) ($ops['isFormListQuestion'])($text)) {
            return ['action' => 'respond_local', 'reply' => (string) ($ops['buildFormList'])(), 'state' => $state];
        }
        if ((bool) ($ops['isEntityListQuestion'])($text)) {
            return ['action' => 'respond_local', 'reply' => (string) ($ops['buildEntityList'])(), 'state' => $state];
        }
        if (
            (bool) ($ops['isBuilderProgressQuestion'])($text)
            || str_contains($text, 'estado del proyecto')
            || str_contains($text, 'status del proyecto')
            || str_contains($text, 'estatus del proyecto')
            || str_contains($text, 'resumen del proyecto')
        ) {
            return ['action' => 'respond_local', 'reply' => (string) ($ops['buildProjectStatus'])(), 'state' => $state];
        }

        if (!$isOnboarding && !$isUnknownDiscovery && !$trigger && !$businessHint) {
            return null;
        }

        return $coreHandler(
            $text,
            $state,
            $profile,
            $tenantId,
            $userId,
            $isOnboarding || $isUnknownDiscovery,
            $trigger,
            $businessHint
        );
    }
}
