<?php
// framework/app/Core/KanbanService.php

namespace App\Core;

use App\Core\QuotationRepository;
use App\Core\POSRepository;

/**
 * KanbanService
 * Provee la logica para agrupar entidades en columnas (board)
 * y manejar el movimiento de tarjetas.
 */
class KanbanService
{
    private QuotationRepository $quotationRepo;
    private POSRepository $posRepo;

    public function __construct(
        ?QuotationRepository $quotationRepo = null,
        ?POSRepository $posRepo = null
    ) {
        $this->quotationRepo = $quotationRepo ?? new QuotationRepository();
        $this->posRepo = $posRepo ?? new POSRepository();
    }

    /**
     * Obtiene el tablero de cotizaciones.
     */
    public function getQuotesBoard(string $tenantId): array
    {
        $quotes = $this->quotationRepo->listQuotations($tenantId, [], 50);
        
        $columns = [
            'draft'    => ['title' => 'Borrador', 'items' => []],
            'sent'     => ['title' => 'Enviada', 'items' => []],
            'approved' => ['title' => 'Aprobada', 'items' => []],
            'invoiced' => ['title' => 'Facturada', 'items' => []],
            'canceled' => ['title' => 'Cancelada', 'items' => []],
        ];

        foreach ($quotes as $q) {
            $status = $q['status'] ?? 'draft';
            if (isset($columns[$status])) {
                $columns[$status]['items'][] = [
                    'id' => $q['id'],
                    'title' => $q['quotation_number'],
                    'subtitle' => $q['customer_name'],
                    'amount' => $q['total'],
                    'date' => $q['created_at'],
                    'type' => 'quote'
                ];
            }
        }

        return [
            'board_type' => 'quotes',
            'columns' => $columns
        ];
    }

    /**
     * Mueve una tarjeta de estado.
     */
    public function moveCard(string $tenantId, string $type, string $id, string $newStatus): bool
    {
        if ($type === 'quote') {
            $updated = $this->quotationRepo->updateQuotation($tenantId, $id, ['status' => $newStatus]);
            return $updated !== null;
        }
        
        // Agregar mas tipos segun sea necesario (ej: ventas POS)
        return false;
    }
}
