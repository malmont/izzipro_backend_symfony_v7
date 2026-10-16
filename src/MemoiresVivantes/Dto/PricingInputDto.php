<?php

namespace App\MemoiresVivantes\Dto;

use App\MemoiresVivantes\Entity\PricingSetting;

/**
 * Corps de PUT /api/memoires/admin/pricing : {"print": {"<devise>": {"firstCopy", "nextCopies"}}} ; remplacement
 * complet de la grille (une devise absente est retirée), au moins une devise, devises de la liste fermée, montants
 * décimaux ≥ 0 à deux décimales au plus ; chapterProduct ignoré (fixé dans les stores). Erreurs par chemin :
 * print, print.<devise>, print.<devise>.<champ>.
 */
class PricingInputDto
{
    /** @var array<string, array{firstCopy: float, nextCopies: float}> */
    public array $grid = [];
    /** @var list<array{path: string, message: string}> */
    public array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $print = $data['print'] ?? null;
        if (!is_array($print)) {
            $this->errors[] = ['path' => 'print', 'message' => 'grille attendue : {"<devise>": {"firstCopy", "nextCopies"}}'];

            return;
        }
        foreach ($print as $currency => $prices) {
            $code = strtolower(trim((string) $currency));
            if (!in_array($code, PricingSetting::ALLOWED_CURRENCIES, true)) {
                $this->errors[] = ['path' => 'print.' . $code, 'message' => 'devise non autorisée (' . implode(', ', PricingSetting::ALLOWED_CURRENCIES) . ')'];
                continue;
            }
            if (!is_array($prices)) {
                $this->errors[] = ['path' => 'print.' . $code, 'message' => 'firstCopy et nextCopies attendus'];
                continue;
            }
            $entry = [];
            foreach (['firstCopy', 'nextCopies'] as $field) {
                $value = $prices[$field] ?? null;
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100000 || round((float) $value, 2) != (float) $value) {
                    $this->errors[] = ['path' => "print.$code.$field", 'message' => 'montant décimal ≥ 0 attendu (deux décimales au plus)'];
                } else {
                    $entry[$field] = round((float) $value, 2);
                }
            }
            if (count($entry) === 2) {
                $this->grid[$code] = $entry;
            }
        }
        if ($this->errors === [] && $this->grid === []) {
            $this->errors[] = ['path' => 'print', 'message' => 'au moins une devise'];
        }
    }
}
