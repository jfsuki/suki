<?php
// framework/app/Core/Skills/ExpiryControlSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

use DateTime;

class ExpiryControlSkill
{
    /**
     * @param array{expiry_date: string, batch_number: string} $params
     */
    public function calculate(array $params): array
    {
        $expiryStr = (string) ($params['expiry_date'] ?? '');
        if ($expiryStr === '') {
            return ['ok' => false, 'error' => 'Fecha de vencimiento requerida.'];
        }

        try {
            $expiry = new DateTime($expiryStr);
            $now = new DateTime();
            $diff = $now->diff($expiry);
            $days = (int) $diff->days;
            if ($now > $expiry) {
                $days = -$days;
            }

            $urgency = 'VERDE';
            $code = 'OK';
            if ($days <= 0) {
                $urgency = 'VENCIDO';
                $code = 'CRITICAL';
            } elseif ($days <= 30) {
                $urgency = 'ROJO';
                $code = 'HIGH';
            } elseif ($days <= 90) {
                $urgency = 'AMARILLO';
                $code = 'MEDIUM';
            }

            return [
                'ok' => true,
                'days_remaining' => $days,
                'urgency_level' => $urgency,
                'status_code' => $code,
                'regulator' => 'INVIMA_CO',
                'batch' => $params['batch_number'] ?? 'unknown',
                'details' => sprintf(
                    "Control INVIMA: Lote %s vence en %s días. Nivel de urgencia: %s.",
                    $params['batch_number'] ?? 'unknown', $days, $urgency
                )
            ];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Formato de fecha inválido: ' . $expiryStr];
        }
    }
}
