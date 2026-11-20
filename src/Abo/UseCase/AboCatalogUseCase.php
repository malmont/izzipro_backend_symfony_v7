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
    public function __construct(private readonly AboCatalogService $catalog, private readonly BenefitService $benefits,
        private readonly ?\App\Abo\Services\AboCarrierService $carriers = null, // lot ABO-CONF-b : produits porteurs
        private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null)
    {
    }

    /**
     * Lot ABO-CONF-b : les produits porteurs et ceux qui peuvent le devenir.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function products(string $filter, ?string $q, int $page, int $pageSize): array
    {
        return $this->carriers->list($filter, $q, $page, $pageSize);
    }

    /**
     * Lot ABO-CONF-b : activer ou désactiver l'abonnement sur un produit.
     *
     * @return array{product: array<string, mixed>, warnings: list<array<string, mixed>>}
     * @throws AboException
     */
    public function setProductSubscription(int $id, mixed $enabled, ?string $by): array
    {
        if (!is_bool($enabled)) {
            throw new AboException(422, 'MUST_BE_BOOLEAN', 'subscriptionEnabled : vrai ou faux.', 'subscriptionEnabled');
        }
        $product = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Product::class)->find($id);
        if ($product === null) {
            throw new AboException(404, 'PRODUCT_NOT_FOUND', 'Produit introuvable.', 'id');
        }

        return $this->carriers->setSubscription($product, $enabled, $by);
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
