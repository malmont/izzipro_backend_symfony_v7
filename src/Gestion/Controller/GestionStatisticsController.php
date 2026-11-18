<?php

namespace App\Gestion\Controller;

use App\Services\EmailConfigurationService\EmailLocale;
use App\Gestion\UseCase\GestionStatisticsUseCase;
use App\Gestion\UseCase\GestionYearUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques unifiées de la gestion (lot S1, 11/11/2026, `docs/statistiques-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`) :
 * ventes, marge, taux de marque, commandes, panier moyen, taux de retour, valeur du stock, autres revenus, avec plage précédente, an dernier et
 * séries ; règles du résultat de période. Les anciennes routes de statistiques restent intactes (la caisse en lit certaines). Écran : `statistiques`.
 */
class GestionStatisticsController extends AbstractController
{
    public function __construct(private readonly GestionStatisticsUseCase $useCase, private readonly GestionYearUseCase $years, private readonly ?EmailLocale $siteLocale = null,
        private readonly ?\App\Import\OrderArchive $archive = null, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null,
        private readonly ?\Symfony\Contracts\Translation\TranslatorInterface $translator = null,
        private readonly ?\App\Services\Group\AllShops\AllShopsReader $allShops = null)
    {
    }

    /**
     * Lot IMP-3 : la série « ventes d'avant la migration » (`?from=&to=`, jours Y-m-d, facultatifs), reprises de l'ancienne boutique du
     * commerçant. À PART des ventes du site : jamais additionnée à elles. Commandes payées seulement, hors taxes et hors port →
     * 200 { source: before_migration, label, currency, from, to, firstDay, lastDay, totals { revenue, orders, items, averageBasket },
     * months [{ month, revenue, orders, items }], states { paid, refunded, cancelled, unpaid } }
     */
    #[Route('/api/gestion/statistiques/avant-migration', name: 'gestion_statistiques_avant_migration', methods: ['GET'])]
    public function beforeMigration(Request $request): JsonResponse
    {
        $bounds = [];
        foreach (['from', 'to'] as $key) {
            $value = $request->query->get($key);
            if ($value !== null && $value !== '' && (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)))) {
                throw new \App\Gestion\Services\GestionValidationException([$key => 'INVALID_DATE']);
            }
            $bounds[$key] = is_string($value) && $value !== '' ? $value : null;
        }
        if ($bounds['from'] !== null && $bounds['to'] !== null && $bounds['from'] > $bounds['to']) {
            throw new \App\Gestion\Services\GestionValidationException(['to' => 'BEFORE_FROM']);
        }
        $locale = $this->locale($request);

        return $this->json(['source' => \App\Import\OrderArchive::SOURCE, 'label' => $this->translator?->trans('statistics.beforeMigrationTitle', [], 'gestion', $locale) ?? 'Ventes d\'avant la migration',
            'currency' => $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT] + ($this->archive?->series($bounds['from'], $bounds['to']) ?? []));
    }

    /** Lot AN : l'année de l'entreprise (séries mensuelles, cumul, an dernier, périodes, rentabilité, leviers, bilan d'une année terminée) */
    #[Route('/api/gestion/statistiques/annee', name: 'gestion_statistiques_annee', methods: ['GET'])]
    public function year(Request $request): JsonResponse
    {
        // lot G-3 : « Toutes les boutiques » : chaque boutique à son exercice, réunion par mois civil (`pricePercent` refusé par la garde)
        $yearQuery = array_intersect_key($request->query->all(), ['year' => 1]);
        $locale = $this->locale($request);
        $group = $this->allShops?->forRequest($request, 'gestion_statistiques_annee', ['query' => $yearQuery, 'locale' => $locale],
            fn (): array => ['data' => $this->years->overview($yearQuery, $locale)], \App\Services\Group\AllShops\AllShopsListMerger::year(...));
        if ($group !== null) {
            return $this->json($group);
        }

        return $this->json($this->years->overview($request->query->all(), $locale));
    }

    /** Lot AN : avis de l'IA sur une année terminée (409 YEAR_NOT_FINISHED sinon ; plafond partagé de 10 avis par jour) */
    #[Route('/api/gestion/statistiques/annee/avis', name: 'gestion_statistiques_annee_avis', methods: ['POST'])]
    public function yearAdvice(Request $request): JsonResponse
    {
        return $this->json($this->years->advise($request->query->all(), $this->locale($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/api/gestion/statistiques', name: 'gestion_statistiques', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $query = $request->query->all();
        $locale = $this->locale($request);
        // lot G-2 : « Toutes les boutiques » (en-tête X-Group-Scope, garde GroupScopeSubscriber) : chaque boutique, puis les totaux par devise
        // seuls les paramètres qui changent la lecture entrent dans la clé du cache (un paramètre de plus ne force pas une relecture)
        $groupQuery = array_intersect_key($query, array_flip(\App\Services\Group\AllShops\GroupScope::STATISTICS_PARAMS));
        ksort($groupQuery);
        $group = $this->allShops?->forRequest($request, 'gestion_statistiques', ['query' => $groupQuery, 'locale' => $locale],
            fn (): array => $this->useCase->overviewWithSums($groupQuery, $locale), \App\Services\Group\AllShops\AllShopsMerger::statistics(...));
        if ($group !== null) {
            return $this->json($group);
        }

        return $this->json($this->useCase->overview($query, $locale));
    }

    private function locale(Request $request): string
    {
        $asked = $request->query->get('locale');
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}
