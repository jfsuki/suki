<?php
declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\AppDataService;
use App\Core\AppMemoryService;
use App\Core\ReportTokenService;

/**
 * AppReportSkill
 *
 * Called by SkillExecutor when the agent invokes 'query_app_data'.
 * Returns a data summary + a signed masked URL for the HTML/PDF report.
 *
 * URLs are HMAC-signed tokens — no raw app_id, tenant_id, entity exposed.
 * Short URL:  /r/{token}  →  web.config rewrites to  api/reports/view?token={token}
 *
 * Expected params:
 *   app_id    (string) — identifier of the created app
 *   entity    (string) — table/entity name to query (optional — defaults to first entity)
 *   format    (string) — 'json'|'html'|'pdf' (default: 'html')
 *   limit     (int)    — max rows (default: 50)
 *   filters   (array)  — key/value filter pairs
 */
final class AppReportSkill
{
    private AppDataService    $data;
    private AppMemoryService  $memory;
    private ReportTokenService $reportTokens;

    public function __construct(
        ?AppDataService    $data         = null,
        ?AppMemoryService  $memory       = null,
        ?ReportTokenService $reportTokens = null
    ) {
        $this->data         = $data         ?? new AppDataService();
        $this->memory       = $memory       ?? new AppMemoryService();
        $this->reportTokens = $reportTokens ?? new ReportTokenService();
    }

    public function execute(array $params, string $tenantId): array
    {
        $appId      = trim((string) ($params['app_id'] ?? ''));
        $entityName = trim((string) ($params['entity'] ?? ''));
        $format     = trim((string) ($params['format'] ?? 'html'));
        $limit      = max(1, min(500, (int) ($params['limit'] ?? 50)));
        $filters    = is_array($params['filters'] ?? null) ? (array) $params['filters'] : [];

        if ($appId === '') {
            return $this->error('app_id es requerido.');
        }

        if ($entityName === '') {
            $entities   = $this->data->getEntityNames($tenantId, $appId);
            $entityName = $entities[0]['table'] ?? '';
            if ($entityName === '') {
                return $this->error("La app '{$appId}' no tiene entidades definidas.");
            }
        }

        try {
            $columns = $this->data->getColumns($tenantId, $appId, $entityName);
            $rows    = $this->data->listRecords($tenantId, $appId, $entityName, $filters, $limit);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        $count       = count($rows);
        $entityLabel = $this->resolveEntityLabel($tenantId, $appId, $entityName);
        $colNames    = array_column($columns, 'label');
        $colList     = implode(', ', array_slice($colNames, 0, 6));

        // Build signed masked token — hides internal params from URL
        $tokenParams = [
            'app_id'   => $appId,
            'entity'   => $entityName,
            'tenant_id'=> $tenantId,
            'format'   => $format,
            'limit'    => $limit,
        ];
        if (!empty($filters)) {
            $tokenParams['filters'] = $filters;
        }
        $token     = $this->reportTokens->generate($tokenParams);
        $base      = $this->resolveBaseUrl();
        $reportUrl = $base . '/r/' . $token;

        // Build reply
        $reply = "**{$entityLabel}** — {$count} registro(s) encontrado(s)";
        if ($colList !== '') {
            $colsComputed = array_filter($columns, fn($c) => $c['computed'] ?? false);
            $reply .= "\nColumnas: {$colList}";
            if (!empty($colsComputed)) {
                $formulaNames = implode(', ', array_column($colsComputed, 'label'));
                $reply .= "\nCampos calculados: {$formulaNames}";
            }
        }
        if ($count > 0 && $format !== 'json') {
            $reply .= "\n[Ver reporte completo]({$reportUrl})";
        }

        return [
            'action'       => 'respond_local',
            'reply'        => $reply,
            'status'       => 'success',
            'report_url'   => $reportUrl,
            'entity'       => $entityName,
            'entity_label' => $entityLabel,
            'record_count' => $count,
            'columns'      => $columns,
            'rows'         => array_slice($rows, 0, 10),
        ];
    }

    private function resolveEntityLabel(string $tenantId, string $appId, string $entityName): string
    {
        foreach ($this->data->getEntityNames($tenantId, $appId) as $e) {
            if ($e['table'] === $entityName) {
                return $e['label'];
            }
        }
        return $entityName;
    }

    private function resolveBaseUrl(): string
    {
        $base = getenv('SUKI_BASE_URL') ?: '';
        if ($base !== '') {
            return rtrim($base, '/');
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return 'http://' . $host . '/suki';
    }

    /** DynamicSkillRegistry adapter — maps handle() contract to execute() */
    public function handle(array $input, array $context = []): array
    {
        return $this->execute($input, (string) ($context['tenant_id'] ?? 'default'));
    }

    private function error(string $msg): array
    {
        return [
            'action' => 'respond_local',
            'reply'  => "No pude obtener los datos: {$msg}",
            'status' => 'error',
            'error'  => $msg,
        ];
    }
}
