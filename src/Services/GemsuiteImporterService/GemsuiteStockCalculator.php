<?php

namespace App\Services\GemsuiteImporterService;

class GemsuiteStockCalculator
{
    /**
     * Calcule le stock TOTAL en additionnant la quantité par défaut
     * et les ajustements du tableau 'quantite'.
     */
    public function calculateTotalStock(array $data): int
    {
        $adjustments = 0.0;
        if (!empty($data['quantite']) && is_array($data['quantite'])) {
            foreach ($data['quantite'] as $q) {
                $adjustments += (float) ($q['quantite'] ?? 0);
            }
        }

        return (int) ($adjustments);
    }
}
