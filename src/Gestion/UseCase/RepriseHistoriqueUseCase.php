<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\RepriseHistoriqueService;
use App\Services\TenantEntityManagerProvider;

/**
 * Reprise d'historique (lot P3, 10/11/2026) : validation des entrées (422 `VALIDATION_FAILED` : `months` 1..24,
 * `estimateCoefficient` > 0, `costs[i].productId` connu, `costs[i].purchasePrice` cents entiers ≥ 0) puis délégation au service.
 */
class RepriseHistoriqueUseCase
{
    public function __construct(private readonly RepriseHistoriqueService $service, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @param array<string, mixed> $data */
    public function simulate(array $data): array
    {
        [$months, $coefficient] = $this->common($data, []);
        $page = isset($data['page']) && is_numeric($data['page']) ? max(1, (int) $data['page']) : 1;
        $limit = isset($data['limit']) && is_numeric($data['limit']) ? max(1, min(RepriseHistoriqueService::MAX_LIMIT, (int) $data['limit'])) : RepriseHistoriqueService::DEFAULT_LIMIT;

        return $this->service->simulate($months, $coefficient, $page, $limit);
    }

    /** @param array<string, mixed> $data */
    public function apply(array $data): array
    {
        $errors = [];
        $costs = [];
        $rawCosts = $data['costs'] ?? [];
        if (!is_array($rawCosts)) {
            $errors['costs'] = 'MUST_BE_LIST';
            $rawCosts = [];
        }
        $em = $this->emProvider->getEntityManager();
        foreach (array_values($rawCosts) as $i => $line) {
            $productId = is_array($line) && isset($line['productId']) && is_numeric($line['productId']) ? (int) $line['productId'] : null;
            $price = is_array($line) ? ($line['purchasePrice'] ?? null) : null;
            if ($productId === null || $productId <= 0) {
                $errors["costs[$i].productId"] = 'INVALID_ID';
            } elseif ($em->find(\App\Entity\Product::class, $productId) === null) {
                $errors["costs[$i].productId"] = 'PRODUCT_NOT_FOUND';
            }
            if (!(is_int($price) || (is_float($price) && floor($price) === $price) || (is_string($price) && ctype_digit($price))) || (int) $price < 0) {
                $errors["costs[$i].purchasePrice"] = 'MUST_BE_INTEGER_CENTS';
            }
            if ($productId !== null && !isset($errors["costs[$i].productId"]) && !isset($errors["costs[$i].purchasePrice"])) {
                $costs[$productId] = (int) $price;
            }
        }
        [$months, $coefficient] = $this->common($data, $errors);

        return $this->service->apply($months, $costs, $coefficient);
    }

    public function cancel(): array
    {
        return $this->service->cancel();
    }

    public function state(): array
    {
        return $this->service->state();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     * @return array{0: int, 1: ?float}
     */
    private function common(array $data, array $errors): array
    {
        $months = RepriseHistoriqueService::DEFAULT_MONTHS;
        if (array_key_exists('months', $data) && $data['months'] !== null) {
            if (!is_numeric($data['months']) || (int) $data['months'] != $data['months'] || (int) $data['months'] < 1 || (int) $data['months'] > RepriseHistoriqueService::MAX_MONTHS) {
                $errors['months'] = 'OUT_OF_RANGE';
            } else {
                $months = (int) $data['months'];
            }
        }
        $coefficient = null;
        if (array_key_exists('estimateCoefficient', $data) && $data['estimateCoefficient'] !== null && $data['estimateCoefficient'] !== '') {
            $raw = $data['estimateCoefficient'];
            if (!is_numeric($raw) || (float) $raw <= 0) {
                $errors['estimateCoefficient'] = 'MUST_BE_POSITIVE';
            } else {
                $coefficient = (float) $raw;
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Entrées de la reprise invalides');
        }

        return [$months, $coefficient];
    }
}
