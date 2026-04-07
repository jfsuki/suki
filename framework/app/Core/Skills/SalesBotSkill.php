<?php
// framework/app/Core/Skills/SalesBotSkill.php

namespace App\Core\Skills;

/**
 * SalesBotSkill
 * Agente vendedor especializado en el Marketplace de SUKI.
 */
class SalesBotSkill
{
    private array $apps = [
        'pos' => [
            'name' => 'SUKI POS & Retail',
            'benefit' => 'Automatiza tus ventas, control de caja e inventario en tiempo real.',
            'pain' => '¿Pierdes tiempo contando dinero al final del día o no sabes qué mercancía falta?'
        ],
        'purchases' => [
            'name' => 'SUKI Compras & Gastos',
            'benefit' => 'Controla tus proveedores y facturas de compra automáticamente.',
            'pain' => '¿Sientes que el dinero se va en gastos hormiga que no registras?'
        ],
        'crm' => [
            'name' => 'SUKI CRM & Cotizaciones',
            'benefit' => 'Convierte leads en clientes con seguimiento automático.',
            'pain' => '¿Se te olvidan las cotizaciones que envías y pierdes cierres de venta?'
        ]
    ];

    public function handle(string $text, array $state): array
    {
        $text = strtolower($text);

        // Lógica de entrevista / Captura de dolor
        if (str_contains($text, 'precio') || str_contains($text, 'costo') || str_contains($text, 'cuanto vale')) {
            return [
                'reply' => "El costo es extremadamente bajo comparado con el ahorro que te genera. SUKI es un trabajador 24/7 que no descansa. Por menos de lo que cuesta el almuerzo de un auxiliar, tienes un sistema que opera tu negocio. ¿Te gustaría saber qué app específica te ahorraría más tiempo hoy?",
                'intent' => 'sales_pricing'
            ];
        }

        if (str_contains($text, 'demo') || str_contains($text, 'probar')) {
            return [
                'reply' => "No ofrecemos demos genéricas porque SUKI no es un software estático. SUKI aprende de TU negocio real desde el primer minuto. En lugar de una demo vacía, preferimos que te suscribas y veas cómo el agente empieza a resolver tus tareas reales de inmediato. El costo es tan bajo que el riesgo es cero frente al beneficio de automatizar tu empresa.",
                'intent' => 'sales_no_demo'
            ];
        }

        if (str_contains($text, 'que hace') || str_contains($text, 'como funciona')) {
            return [
                'reply' => "Soy el agente vendedor de SUKI. Puedo ayudarte a identificar qué solución automatizada necesitas. Actualmente tenemos módulos de POS, Compras, CRM y Finanzas. ¿Cuál es el proceso que más te quita tiempo actualmente?",
                'intent' => 'sales_intro'
            ];
        }

        // Fallback: Entrevista proactiva
        return [
            'reply' => "Entiendo. Mi objetivo es liberarte de la carga operativa. Si me cuentas un poco sobre cómo gestionas hoy tus ventas o inventarios, puedo decirte exactamente cómo SUKI te ahorrará un asistente humano y operará por ti 24/7. ¿Por dónde prefieres empezar?",
            'intent' => 'sales_interview'
        ];
    }
}
