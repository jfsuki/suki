<?php

declare(strict_types=1);

namespace App\Core;

/**
 * DocumentType — Constantes canónicas de tipos de documento interno.
 *
 * Centraliza los string literals que identifican tipos de documento
 * en AccountingService, AlanubePayloadBuilderCO y demás módulos fiscales.
 * NO duplicar estos valores en otros archivos.
 */
final class DocumentType
{
    const SALES_INVOICE = 'sales_invoice';
    const POS_SALE      = 'sale';
    const MANUAL        = 'manual';
    const CREDIT_NOTE   = 'credit_note';
    const DEBIT_NOTE    = 'debit_note';
    const POS_TICKET_FISCAL = 'pos_ticket_fiscal_hook';

    private function __construct() {}
}
