<?php
// framework/app/Core/ComputationalSkillBus.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

class ComputationalSkillBus
{
    private array $skillMap = [
        'UnitConversionSkill' => \App\Core\Skills\UnitConversionSkill::class,
        'FiscalTaxSkill'      => \App\Core\Skills\FiscalTaxSkill::class,
        'ExpiryControlSkill'  => \App\Core\Skills\ExpiryControlSkill::class,
    ];

    public function execute(string $skillName, array $params): array
    {
        if (!isset($this->skillMap[$skillName])) {
            throw new RuntimeException("Computational skill not registered: " . $skillName);
        }

        try {
            $class = $this->skillMap[$skillName];
            $skill = new $class();
            return $skill->calculate($params);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Skill execution failed: ' . $e->getMessage(),
            ];
        }
    }
}
