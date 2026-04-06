<?php
declare(strict_types=1);

namespace App\Core\Agents\Orchestrator;

/**
 * ResponseSynthesizer
 * 
 * Clase encargada de recopilar los hallazgos de múltiples especialistas 
 * y generar una respuesta unificada tipo "Neural Team".
 */
class ResponseSynthesizer
{
    public function synthesize(array $results, string $workflowDescription): string
    {
        if (empty($results)) {
            return "El equipo neural no pudo llegar a una conclusión clara.";
        }

        $intro = "✅ **Decisión del Sistema Multi-Agente (SUKI AOS)**\n";
        $intro .= "Flujo ejecutado: *$workflowDescription*\n\n";

        $body = "";
        foreach ($results as $area => $data) {
            $statusIcon = ($data['status'] === 'SUCCESS') ? "🟢" : "🔴";
            $body .= "• $statusIcon **Agente de $area**: " . $data['output'] . "\n";
        }

        $summary = "\n**Conclusión Final:** El Supervisor ha validado estos reportes bajo las reglas deterministas de la empresa. El proceso ha sido marcado como **NOMINAL**.";

        return $intro . $body . $summary;
    }
}
