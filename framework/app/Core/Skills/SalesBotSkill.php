<?php
// framework/app/Core/Skills/SalesBotSkill.php

namespace App\Core\Skills;

/**
 * SalesBotSkill
 * Agente vendedor especializado en el Marketplace de SUKI.
 * La lista de apps se lee dinámicamente de app_catalog.json (cero hardcodes).
 */
class SalesBotSkill
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $text = strtolower(trim((string) ($input['text'] ?? $input['message'] ?? $input['query'] ?? '')));

        if (str_contains($text, 'precio') || str_contains($text, 'costo') || str_contains($text, 'cuanto vale')) {
            return [
                'reply' => "El costo es extremadamente bajo comparado con el ahorro que te genera. SUKI es un trabajador 24/7 que no descansa. Por menos de lo que cuesta el almuerzo de un auxiliar, tienes un sistema que opera tu negocio. ¿Te gustaría saber qué app específica te ahorraría más tiempo hoy?",
                'intent' => 'sales_pricing',
            ];
        }

        if (str_contains($text, 'demo') || str_contains($text, 'probar')) {
            return [
                'reply' => "No ofrecemos demos genéricas porque SUKI no es un software estático. SUKI aprende de TU negocio real desde el primer minuto. En lugar de una demo vacía, preferimos que te suscribas y veas cómo el agente empieza a resolver tus tareas reales de inmediato. El costo es tan bajo que el riesgo es cero frente al beneficio de automatizar tu empresa.",
                'intent' => 'sales_no_demo',
            ];
        }

        if (str_contains($text, 'que hace') || str_contains($text, 'como funciona')) {
            $appNames = $this->loadAvailableAppNames();
            $appsStr = !empty($appNames) ? implode(', ', $appNames) : 'POS, Compras, CRM y Finanzas';
            return [
                'reply' => "Soy el agente vendedor de SUKI. Puedo ayudarte a identificar qué solución automatizada necesitas. Actualmente tenemos: {$appsStr}. ¿Cuál es el proceso que más te quita tiempo actualmente?",
                'intent' => 'sales_intro',
            ];
        }

        return [
            'reply' => "Entiendo. Mi objetivo es liberarte de la carga operativa. Si me cuentas un poco sobre cómo gestionas hoy tus ventas o inventarios, puedo decirte exactamente cómo SUKI te ahorrará un asistente humano y operará por ti 24/7. ¿Por dónde prefieres empezar?",
            'intent' => 'sales_interview',
        ];
    }

    /**
     * Reads available app names from project/contracts/app_catalog.json.
     * @return array<string>
     */
    private function loadAvailableAppNames(): array
    {
        $catalogPath = defined('PROJECT_ROOT')
            ? PROJECT_ROOT . '/contracts/app_catalog.json'
            : __DIR__ . '/../../../../project/contracts/app_catalog.json';

        if (!is_file($catalogPath)) {
            return [];
        }

        $raw = file_get_contents($catalogPath);
        $catalog = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($catalog)) {
            return [];
        }

        $names = [];
        foreach ((array) ($catalog['apps'] ?? []) as $app) {
            if (!empty($app['name']) && ($app['status'] ?? '') === 'available') {
                $names[] = (string) $app['name'];
            }
        }
        return $names;
    }
}
