<?php
declare(strict_types=1);
// app/Core/Agents/Tools/ToolCompressor.php

namespace App\Core\Agents\Tools;

use App\Core\Agents\Memory\TokenBudgeter;

/**
 * ToolCompressor
 * Autogen Principle: Nunca satures al LLM con outputs masivos de herramientas.
 * Si una query a la DB devuelve 150 ventas, este componente la resume
 * extrayendo solo el Top N relevante y agragando un mensaje de 'truncated'
 * para que el LLM sepa que hay más datos sin cobrar miles de tokens.
 */
class ToolCompressor
{
    private TokenBudgeter $budgeter;

    public function __construct(TokenBudgeter $budgeter)
    {
        $this->budgeter = $budgeter;
    }

    /**
     * Comprime el output de una herramienta (generalmente un listado de BD)
     * @param array $rawOutput El resultado bruto (ej: facturas, productos, clientes)
     * @param int $maxTokens Presupuesto estricto para el output de esta herramienta
     * @param int|null $hardLimit Límite duro de filas aunque el budget permita más
     * @return array
     */
    public function compress(array $rawOutput, int $maxTokens = 600, ?int $hardLimit = 10): array
    {
        // 1. Array vacío o datos muy pequeños: retornar íntegro
        if (empty($rawOutput)) {
            return $rawOutput;
        }

        $totalItems = count($rawOutput);
        
        // Capping inicial (nunca dejes pasar más de $hardLimit filas de golpe)
        $croppedItems = $rawOutput;
        $isTruncated = false;

        if ($hardLimit !== null && $totalItems > $hardLimit) {
            $croppedItems = array_slice($croppedItems, 0, $hardLimit);
            $isTruncated = true;
        }

        // 2. Comprimir por Tokens Iterativamente
        $croppedItems = $this->budgeter->cropJsonArray($croppedItems, $maxTokens);

        $finalCount = count($croppedItems);
        if ($finalCount < $totalItems) {
            $isTruncated = true;
        }

        if ($isTruncated) {
            // Añadir metadata al final del array para que LLM dimensione
            $croppedItems[] = [
                '_sys_notice' => sprintf(
                    'NOTA: Mostrando %d resultados de un total de %d. (Truncado por límites de ventana). Pide refinar la búsqueda si no encuentras lo que buscas.',
                    $finalCount,
                    $totalItems
                )
            ];
        }

        return $croppedItems;
    }
}
