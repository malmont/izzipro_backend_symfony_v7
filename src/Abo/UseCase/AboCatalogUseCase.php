<?php

namespace App\Abo\UseCase;

use App\Abo\Services\AboCatalogService;
use App\Abo\Services\AboException;
use App\Abo\Services\BenefitService;

/**
 * Configuration des formules et des prestations par la gestion web (lot ABO-CONF, 19/11/2026) : le service commun d'EasyAdmin, des routes
 * et de l'assistant (`AboCatalogService`, `BenefitService`).
 */
class AboCatalogUseCase
{
    public function __construct(private readonly AboCatalogService $catalog, private readonly BenefitService $benefits)
    {
    }

    /** @return array<string, mixed> */
    public function preview(?int $id, array $body, string $locale): array
    {
        return $this->catalog->preview($id !== null ? $this->catalog->plan($id) : null, $body, $locale);
    }

    /** @return array<string, mixed> */
    public function plan(int $id, string $locale): array
    {
        return $this->catalog->view($this->catalog->plan($id), $locale);
    }

    /** @return array<string, mixed> */
    public function create(array $body, ?string $by, string $locale): array
    {
        return $this->catalog->view($this->catalog->savePlan(null, $body, $by), $locale);
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $body, ?string $by, string $locale): array
    {
        return $this->catalog->view($this->catalog->savePlan($this->catalog->plan($id), $body, $by), $locale);
    }

    /** @return array<string, mixed> */
    public function archive(int $id, bool $archived, string $locale): array
    {
        return $this->catalog->view($this->catalog->archive($this->catalog->plan($id), $archived), $locale);
    }

    public function delete(int $id): void
    {
        $this->catalog->delete($this->catalog->plan($id));
    }

    /** @return array<string, mixed> */
    public function setBenefits(int $id, array $body, string $locale): array
    {
        $plan = $this->catalog->plan($id);
        $rows = $body['benefits'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new AboException(422, 'BENEFITS_INVALID', 'benefits : liste [{ benefitId, quantity, … }] attendue.', 'benefits');
        }
        $this->catalog->setBenefits($plan, $rows);

        return $this->catalog->view($plan, $locale);
    }

    /** @return array{benefits: list<array<string, mixed>>} */
    public function benefits(string $locale): array
    {
        return ['benefits' => array_map(fn ($b) => $this->benefits->benefitView($b, $locale), $this->benefits->benefits()->findBy([], ['active' => 'DESC', 'position' => 'ASC', 'id' => 'ASC']))];
    }

    /** @return array<string, mixed> */
    public function saveBenefit(?int $id, array $body, string $locale): array
    {
        $benefit = $id !== null ? ($this->benefits->benefits()->find($id) ?? throw new AboException(404, 'BENEFIT_NOT_FOUND', 'Prestation introuvable.')) : null;

        return $this->benefits->benefitView($this->benefits->saveBenefit($benefit, $body), $locale);
    }

    public function deleteBenefit(int $id): void
    {
        $benefit = $this->benefits->benefits()->find($id) ?? throw new AboException(404, 'BENEFIT_NOT_FOUND', 'Prestation introuvable.');
        $this->benefits->deleteBenefit($benefit);
    }
}
