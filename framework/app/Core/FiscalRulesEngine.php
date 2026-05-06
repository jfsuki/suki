<?php
declare(strict_types=1);

namespace App\Core;

/**
 * FiscalRulesEngine — Motor fiscal config-driven.
 *
 * Lee fiscal_rules_<country>.json y aplica las reglas sin hardcode de tarifas en PHP.
 * Agregar un nuevo país = crear framework/data/fiscal_rules_<CC>.json.
 * Agregar un nuevo impuesto = nueva entrada en el JSON (si el tipo ya existe).
 *
 * Tipos de regla soportados:
 *   percentage_line          — Impuesto línea × tasa (IVA, impoconsumo). Solo informativo aquí.
 *   withholding              — Retención base × tasa con umbral UVT opcional y overrides.
 *   withholding_conditional_rate — Retención con tasa que varía según contexto (honorarios).
 *   locality_withholding     — Tasa viene de locality_table[municipality][activity] (ICA).
 *   withholding_on_tax       — Retención sobre el impuesto (ReteIVA).
 *   fixed_per_unit           — Monto fijo por unidad (bolsas plásticas).
 *
 * Uso:
 *   $engine  = new FiscalRulesEngine('CO');
 *   $result  = $engine->calculate($subtotal, $taxTotal, [
 *       'concept'          => 'compras',
 *       'document_type'    => 'support_document',
 *       'municipality'     => 'bogota',
 *       'activity_type'    => 'comercio',
 *       'issuer_type'      => 'juridica',
 *       'is_declarante'    => true,
 *       'agent_type'       => '',
 *       'sin_factura'      => false,
 *       'service_subtype'  => '',
 *   ]);
 *
 * El array retornado es compatible con los campos que escribe FiscalEngineService::calculateFiscalTotalsSummary().
 */
final class FiscalRulesEngine
{
    /** @var array<string, array<string,mixed>> */
    private static array $rulesCache = [];

    private string $country;
    /** @var array<string,mixed> */
    private array $rulesData;

    public function __construct(string $country = 'CO')
    {
        $this->country   = strtoupper(trim($country));
        $this->rulesData = self::loadRules($this->country);
    }

    // ─── Punto de entrada principal ──────────────────────────────────────────

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function calculate(float $subtotal, float $taxTotal, array $context = []): array
    {
        $documentType = (string) ($context['document_type'] ?? '');
        $concept      = $this->resolveConceptAlias((string) ($context['concept'] ?? $context['withholding_concept'] ?? ''));
        if ($concept === '') {
            $concept = in_array($documentType, ['support_document', 'purchase_fiscal_hook'], true)
                ? 'compras' : 'compras';
        }
        $context['concept'] = $concept;

        $uvt      = (float) ($this->rulesData['_meta']['uvt'] ?? 49799);
        $applied  = [];
        $notes    = [];

        foreach ((array) ($this->rulesData['rules'] ?? []) as $rule) {
            $rule = (array) $rule;
            if (empty($rule['enabled'])) {
                continue;
            }
            if (!$this->ruleApplies($rule, $context)) {
                continue;
            }

            $type   = (string) ($rule['type'] ?? '');
            $base   = $this->resolveBase($rule, $subtotal, $taxTotal);
            $result = $this->applyRule($type, $rule, $base, $uvt, $context);

            if ($result === null) {
                continue;
            }

            if (!empty($result['note'])) {
                $notes[] = $result['note'];
            }
            if (($result['amount'] ?? 0.0) > 0.0) {
                $applied[] = [
                    'rule_id'    => (string) ($rule['id'] ?? ''),
                    'name'       => (string) ($rule['name'] ?? ''),
                    'legal_ref'  => (string) ($rule['legal_ref'] ?? ''),
                    'type'       => $type,
                    'base'       => round($base, 2),
                    'rate'       => (float) ($result['rate'] ?? 0.0),
                    'amount'     => round((float) ($result['amount'] ?? 0.0), 2),
                ];
            }
        }

        // Aggregate by role
        $reteFuente      = 0.0;
        $reteFuenteRate  = 0.0;
        $reteIca         = 0.0;
        $reteIcaRate     = 0.0;
        $reteIva         = 0.0;
        $reteIvaRate     = 0.0;

        foreach ($applied as $item) {
            $id = (string) $item['rule_id'];
            if (str_starts_with($id, 'rete_fuente_')) {
                $reteFuente     += $item['amount'];
                $reteFuenteRate  = (float) $item['rate'];
            } elseif ($id === 'ica') {
                $reteIca     += $item['amount'];
                $reteIcaRate  = (float) $item['rate'];
            } elseif ($id === 'rete_iva') {
                $reteIva     += $item['amount'];
                $reteIvaRate  = (float) $item['rate'];
            }
        }

        $totalWithholding = $reteFuente + $reteIca + $reteIva;
        $total            = $subtotal + $taxTotal;
        $netPayable       = round($total - $totalWithholding, 2);

        return [
            'rete_fuente'        => round($reteFuente, 2),
            'rete_fuente_rate'   => $reteFuenteRate,
            'rete_ica'           => round($reteIca, 2),
            'rete_ica_rate'      => $reteIcaRate,
            'rete_iva'           => round($reteIva, 2),
            'rete_iva_rate'      => $reteIvaRate,
            'total_withholding'  => round($totalWithholding, 2),
            'net_payable'        => $netPayable,
            'applied'            => $applied,
            'notes'              => $notes,
        ];
    }

    /**
     * Devuelve la tarifa IVA para un key dado (uso en generación de líneas).
     */
    public function ivaRate(string $rateKey = 'general'): float
    {
        $ivaRule = $this->findRule('iva');
        if ($ivaRule === null) {
            return 0.19;
        }
        foreach ((array) ($ivaRule['rates'] ?? []) as $entry) {
            $entry = (array) $entry;
            if (($entry['key'] ?? '') === $rateKey) {
                return (float) ($entry['rate'] ?? 0.0);
            }
        }
        $defaultKey = (string) ($ivaRule['default_rate_key'] ?? 'general');
        foreach ((array) ($ivaRule['rates'] ?? []) as $entry) {
            $entry = (array) $entry;
            if (($entry['key'] ?? '') === $defaultKey) {
                return (float) ($entry['rate'] ?? 0.19);
            }
        }
        return 0.19;
    }

    /**
     * Lista todas las reglas habilitadas para un tipo de documento.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listApplicableRules(string $documentType): array
    {
        $out = [];
        foreach ((array) ($this->rulesData['rules'] ?? []) as $rule) {
            $rule = (array) $rule;
            if (empty($rule['enabled'])) {
                continue;
            }
            $docTypes = is_array($rule['applies_to_document_types'] ?? null)
                ? (array) $rule['applies_to_document_types'] : [];
            if ($docTypes === [] || in_array($documentType, $docTypes, true)) {
                $out[] = $rule;
            }
        }
        return $out;
    }

    // ─── Aplicadores de regla ────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function applyRule(string $type, array $rule, float $base, float $uvt, array $context): ?array
    {
        return match ($type) {
            'withholding'                  => $this->applyWithholding($rule, $base, $uvt, $context),
            'withholding_conditional_rate' => $this->applyConditionalRateWithholding($rule, $base, $uvt, $context),
            'locality_withholding'         => $this->applyLocalityWithholding($rule, $base, $context),
            'withholding_on_tax'           => $this->applyWithholdingOnTax($rule, $base, $context),
            'fixed_per_unit'               => $this->applyFixedPerUnit($rule, $context),
            'percentage_line'              => null, // handled per-line by FiscalEngineService
            default                        => null,
        };
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function applyWithholding(array $rule, float $base, float $uvt, array $context): ?array
    {
        $thresholdUvt = (float) ($rule['threshold_uvt'] ?? 0);
        $threshold    = $thresholdUvt * $uvt;

        if ($base < $threshold) {
            return ['amount' => 0.0, 'rate' => 0.0, 'note' => sprintf(
                '%s: base $%.0f < umbral $%.0f (%.0f UVT) — no retiene',
                (string) ($rule['id'] ?? ''),
                $base,
                $threshold,
                $thresholdUvt
            )];
        }

        $rate = (float) ($rule['rate'] ?? 0.0);

        // Override conditions (e.g. sin_factura on compras)
        foreach ((array) ($rule['overrides'] ?? []) as $ov) {
            $ov       = (array) $ov;
            $condKey  = (string) ($ov['condition_key'] ?? '');
            $condVal  = $ov['condition_value'] ?? null;
            if ($condKey !== '' && ($context[$condKey] ?? null) == $condVal) {
                $rate = (float) ($ov['rate'] ?? $rate);
                break;
            }
        }

        // Sub-type override (e.g. servicios.aseo_vigilancia)
        $subtype = strtolower(trim((string) ($context['service_subtype'] ?? '')));
        if ($subtype !== '') {
            foreach ((array) ($rule['subtype_rates'] ?? []) as $sr) {
                $sr = (array) $sr;
                if (($sr['subtype'] ?? '') === $subtype) {
                    $srThreshold = ((float) ($sr['threshold_uvt'] ?? $thresholdUvt)) * $uvt;
                    if ($base >= $srThreshold) {
                        $rate = (float) ($sr['rate'] ?? $rate);
                    }
                    break;
                }
            }
        }

        return ['amount' => round($base * $rate, 2), 'rate' => $rate];
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function applyConditionalRateWithholding(array $rule, float $base, float $uvt, array $context): ?array
    {
        $thresholdUvt = (float) ($rule['threshold_uvt'] ?? 0);
        $threshold    = $thresholdUvt * $uvt;

        if ($base < $threshold) {
            return ['amount' => 0.0, 'rate' => 0.0];
        }

        $issuerType  = strtolower(trim((string) ($context['issuer_type'] ?? 'juridica')));
        $isDeclarante = (bool) ($context['is_declarante'] ?? true);
        $rate         = (float) ($rule['default_rate'] ?? 0.11);

        foreach ((array) ($rule['rate_conditions'] ?? []) as $cond) {
            $cond = (array) $cond;
            $matchType = !isset($cond['issuer_type']) || $cond['issuer_type'] === $issuerType;
            $matchDecl = !isset($cond['is_declarante']) || (bool) $cond['is_declarante'] === $isDeclarante;
            if ($matchType && $matchDecl) {
                $rate = (float) $cond['rate'];
                break;
            }
        }

        return ['amount' => round($base * $rate, 2), 'rate' => $rate];
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function applyLocalityWithholding(array $rule, float $base, array $context): ?array
    {
        $localityKey  = (string) ($rule['locality_key'] ?? 'municipality');
        $activityKey  = (string) ($rule['activity_key'] ?? 'activity_type');
        $municipality = strtolower(trim((string) ($context[$localityKey] ?? '')));
        $activity     = strtolower(trim((string) ($context[$activityKey] ?? 'comercio')));

        if (!in_array($activity, ['comercio', 'industria', 'servicios', 'financiero'], true)) {
            $activity = 'comercio';
        }

        $localityTable = is_array($rule['locality_table'] ?? null) ? (array) $rule['locality_table'] : [];
        $defaultRates  = is_array($rule['default_rates'] ?? null) ? (array) $rule['default_rates'] : [];

        $mRates = isset($localityTable[$municipality]) ? (array) $localityTable[$municipality] : null;
        $note   = null;

        if ($mRates === null) {
            if ($municipality !== '') {
                $note = sprintf("ica: municipio '%s' no encontrado — usando tarifa por defecto", $municipality);
            }
            $mRates = $defaultRates;
        }

        $rate   = (float) ($mRates[$activity] ?? $defaultRates[$activity] ?? 0.005);
        $amount = round($base * $rate, 2);

        return array_filter(['amount' => $amount, 'rate' => $rate, 'note' => $note]);
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function applyWithholdingOnTax(array $rule, float $taxBase, array $context): ?array
    {
        if ($taxBase <= 0.0) {
            return null;
        }
        $agentType   = strtolower(trim((string) ($context['agent_type'] ?? $context['issuer_agent_type'] ?? '')));
        $appliesWhen = is_array($rule['applies_when_agent'] ?? null) ? (array) $rule['applies_when_agent'] : [];

        if ($agentType === '' || !in_array($agentType, $appliesWhen, true)) {
            return null;
        }

        $rate   = (float) ($rule['rate'] ?? 0.15);
        $amount = round($taxBase * $rate, 2);

        return ['amount' => $amount, 'rate' => $rate];
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function applyFixedPerUnit(array $rule, array $context): ?array
    {
        $qty    = (float) ($context['qty'] ?? 0.0);
        $amount = $qty * (float) ($rule['amount_per_unit'] ?? 0.0);
        return $amount > 0.0
            ? ['amount' => round($amount, 2), 'rate' => 0.0]
            : null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context
     */
    private function ruleApplies(array $rule, array $context): bool
    {
        // document_type filter
        $docTypes = is_array($rule['applies_to_document_types'] ?? null)
            ? (array) $rule['applies_to_document_types'] : [];
        $docType  = (string) ($context['document_type'] ?? '');
        if ($docTypes !== [] && $docType !== '' && !in_array($docType, $docTypes, true)) {
            return false;
        }

        // concept filter (only for withholding rules that declare applies_to_concepts)
        $concepts = is_array($rule['applies_to_concepts'] ?? null)
            ? (array) $rule['applies_to_concepts'] : [];
        if ($concepts !== []) {
            $concept = (string) ($context['concept'] ?? '');
            if (!in_array($concept, $concepts, true)) {
                return false;
            }
        }

        return true;
    }

    private function resolveBase(array $rule, float $subtotal, float $taxTotal): float
    {
        return match ((string) ($rule['base_field'] ?? 'subtotal')) {
            'tax_total' => $taxTotal,
            'total'     => $subtotal + $taxTotal,
            default     => $subtotal,
        };
    }

    private function resolveConceptAlias(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $raw     = strtolower(trim($raw));
        $aliases = is_array($this->rulesData['concept_aliases'] ?? null)
            ? (array) $this->rulesData['concept_aliases']
            : [];
        return (string) ($aliases[$raw] ?? $raw);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRule(string $id): ?array
    {
        foreach ((array) ($this->rulesData['rules'] ?? []) as $rule) {
            $rule = (array) $rule;
            if (($rule['id'] ?? '') === $id) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadRules(string $country): array
    {
        $cacheKey = $country;
        if (isset(self::$rulesCache[$cacheKey])) {
            return self::$rulesCache[$cacheKey];
        }

        $path    = dirname(__DIR__, 2) . '/data/fiscal_rules_' . strtolower($country) . '.json';
        $decoded = file_exists($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        self::$rulesCache[$cacheKey] = is_array($decoded) ? $decoded : ['rules' => [], '_meta' => ['uvt' => 49799]];
        return self::$rulesCache[$cacheKey];
    }
}
