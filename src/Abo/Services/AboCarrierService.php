<?php

namespace App\Abo\Services;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\SubscriptionPlan;
use App\Entity\VehicleProduct;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Lot ABO-CONF-b (20/11/2026, contrat validé par le chef) : les PRODUITS PORTEURS des formules d'abonnement. Un service commun à la
 * gestion web (`/api/site/abonnements/produits`), à EasyAdmin (case « Abonnement » de la fiche produit) et à l'assistant du site : mêmes
 * règles partout.
 *
 * - Activer l'abonnement sur un produit : refusé pour un véhicule ou un produit qui porte une formule de la plateforme ou d'une agence
 *   (formule branchée : 422 PRODUCT_NOT_ELIGIBLE).
 * - Le désactiver : refusé dès qu'une formule de ce produit a eu un abonné (409 PRODUCT_HAS_SUBSCRIBERS, même règle que le gel des
 *   réglages d'une formule) ; permis sinon, avec l'avertissement PLANS_HIDDEN qui NOMME les formules actives qui sortent de la vente
 *   (la grille ne montre que les formules d'un produit encore porteur).
 * - Le porteur créé avec sa formule (`newProduct`) : prix 0, vente directe coupée, abonnement activé, sans stock suivi ; jamais
 *   achetable seul (Product::isSubscriptionOnly). Visible dans la boutique (décision du propriétaire du 20/11/2026 : sa fiche est la
 *   page où l'on s'abonne) : le choix est isolé dans CARRIER_VISIBLE.
 */
class AboCarrierService
{
    /** Décision du propriétaire (20/11/2026) : le porteur créé par newProduct est visible dans la boutique (sa fiche mène à la grille) */
    public const CARRIER_VISIBLE = true;
    /** Catégorie du porteur créé par newProduct, créée au besoin */
    public const CARRIER_CATEGORY = 'Abonnements';
    public const FILTERS = ['oui', 'non', 'tous'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
    ) {
    }

    /**
     * La liste des produits pour la gestion web : porteurs d'abord, puis les autres, par nom.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(string $filter, ?string $q, int $page, int $pageSize): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'tous';
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $qb = $this->emProvider->getEntityManager()->getRepository(Product::class)->createQueryBuilder('p');
        if ($filter !== 'tous') {
            $qb->andWhere('p.subscriptionEnabled = :on')->setParameter('on', $filter === 'oui');
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :q')->setParameter('q', '%' . mb_strtolower(addcslashes($q, '%_\\')) . '%');
        }
        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $products = $qb->orderBy('p.subscriptionEnabled', 'DESC')->addOrderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)->getQuery()->getResult();
        $stats = $this->stats(array_map(static fn (Product $p) => (int) $p->getId(), $products));

        return ['items' => array_map(fn (Product $p) => $this->row($p, $stats[(int) $p->getId()] ?? null), $products), 'total' => $total, 'page' => $page, 'pageSize' => $pageSize];
    }

    /** @return array<string, mixed> la ligne d'un produit */
    public function view(Product $product): array
    {
        return $this->row($product, $this->stats([(int) $product->getId()])[(int) $product->getId()] ?? null);
    }

    /**
     * Active ou désactive l'abonnement sur un produit. Idempotent : même valeur, rien d'écrit.
     *
     * @return array{product: array<string, mixed>, warnings: list<array<string, mixed>>}
     * @throws AboException 422 PRODUCT_NOT_ELIGIBLE, 409 PRODUCT_HAS_SUBSCRIBERS
     */
    public function setSubscription(Product $product, bool $enabled, ?string $by): array
    {
        $warnings = $this->assertCanSet($product, $enabled);
        if ($product->isSubscriptionEnabled() !== $enabled) {
            $product->setSubscriptionEnabled($enabled);
            if ($enabled) {
                $this->restoreWebIfHidden($product);
            }
            $this->emProvider->getEntityManager()->flush();
            $this->recordToggle($product, $enabled);
        }

        return ['product' => $this->view($product), 'warnings' => $warnings];
    }

    /**
     * Lot ABO-WEB-2 (décision du chef, 20/11/2026) : après la suppression d'une formule. Un porteur au profil newProduct (vente coupée, prix 0
     * sans aucune variante payante) qui ne porte plus AUCUNE formule (active ou archivée) perd l'abonnement et sort de la boutique (isWeb
     * faux), avec le drapeau qui dit que c'est ce nettoyage qui l'a masqué. Tout autre produit : rien ne change. À appeler dans la
     * transaction de la suppression, après son écriture.
     */
    public function afterPlanDeleted(Product $product): void
    {
        $em = $this->emProvider->getEntityManager();
        if (!$product->isSubscriptionEnabled() || $product->isSaleEnabled() || !$product->isSubscriptionOnly() || (float) $product->getPrice() > 0.0
            || $em->getRepository(SubscriptionPlan::class)->count(['product' => $product]) > 0) {
            return;
        }
        $product->setSubscriptionEnabled(false);
        if ($product->isWeb() === true) {
            $product->setIsWeb(false)->setWebHiddenByAbo(true);
        }
        $em->flush();
        $this->recordToggle($product, false);
    }

    /** La réactivation de l'abonnement rend la fiche visible, seulement si c'est le nettoyage d'après suppression qui l'avait masquée */
    public function restoreWebIfHidden(Product $product): void
    {
        if ($product->isWebHiddenByAbo()) {
            $product->setIsWeb(true)->setWebHiddenByAbo(false);
        }
    }

    /** Le geste au journal (`product_subscription`) : API, assistant et EasyAdmin */
    public function recordToggle(Product $product, bool $enabled): void
    {
        $this->audit?->record('product_subscription', (int) $product->getId(), $enabled ? 'enable' : 'disable', ['subscriptionEnabled' => !$enabled], ['subscriptionEnabled' => $enabled]);
    }

    /**
     * Les règles du geste, sans rien écrire (aussi pour l'aperçu de l'assistant et EasyAdmin).
     *
     * @return list<array<string, mixed>> les avertissements (PLANS_HIDDEN avec les formules nommées)
     * @throws AboException
     */
    public function assertCanSet(Product $product, bool $enabled): array
    {
        $stats = $this->stats([(int) $product->getId()])[(int) $product->getId()] ?? null;
        if ($enabled) {
            if (($reason = $this->ineligibility($product, $stats)) !== null) {
                throw new AboException(422, 'PRODUCT_NOT_ELIGIBLE', $reason === 'vehicle' ? 'Un véhicule ne porte pas d\'abonnement.' : 'Ce produit porte une formule de la plateforme ou d\'une agence : il se règle dans EasyAdmin.', 'subscriptionEnabled');
            }

            return [];
        }
        if (($stats['withSubscribers'] ?? 0) > 0) {
            throw new AboException(409, 'PRODUCT_HAS_SUBSCRIBERS', 'Une formule de ce produit a eu des abonnés : il reste porteur (archivez plutôt la formule).', 'subscriptionEnabled');
        }
        if (($stats['branched'] ?? false) === true) {
            throw new AboException(422, 'PRODUCT_NOT_ELIGIBLE', 'Ce produit porte une formule de la plateforme ou d\'une agence : il se règle dans EasyAdmin.', 'subscriptionEnabled');
        }
        $hidden = $this->activePlanNames($product);

        return $hidden === [] || !$product->isSubscriptionEnabled() ? [] : [['code' => 'PLANS_HIDDEN', 'count' => count($hidden), 'plans' => $hidden]];
    }

    /**
     * Le porteur créé avec sa formule (`newProduct`), NON persisté et SANS catégorie : rien n'y touche une entité gérée, l'aperçu (web ou
     * assistant) peut le jeter sans risque. Une variante (prix 0, stock 0) : la commande née d'une période payée la demande. Sans
     * expédition : un abonnement ne se livre pas par défaut. `persistCarrier` le range et l'écrit, dans la transaction de la formule.
     *
     * @param array<string, mixed> $newProduct { names: { fr, en? } }
     * @throws AboException 422 PRODUCT_CHOICE_REQUIRED
     */
    public function draftCarrier(array $newProduct): Product
    {
        $names = is_array($newProduct['names'] ?? null) ? $newProduct['names'] : [];
        $name = mb_substr(trim(strip_tags((string) ($names['fr'] ?? reset($names) ?: ''))), 0, 255);
        if ($name === '') {
            throw new AboException(422, 'PRODUCT_CHOICE_REQUIRED', 'Donnez un nom au produit porteur.', 'newProduct');
        }
        $product = (new Product())->setName($name)->setDescription(null)->setPrice(0.0)->setQuantity(0)->setSlug($this->uniqueSlug($name))
            ->setCreatedAt(new \DateTimeImmutable())->setIsWeb(self::CARRIER_VISIBLE)->setIsPos(false)->setIsPreOrder(false)
            ->setSaleEnabled(false)->setSubscriptionEnabled(true)->setRequiresShipping(false);
        $product->addVariant((new ProductVariant())->setPrice(0)->setStockQuantity(0));

        return $product;
    }

    /** Range le porteur dans la catégorie « Abonnements » (créée au besoin) et le persiste : à appeler DANS la transaction de la formule */
    public function persistCarrier(Product $carrier): void
    {
        $em = $this->emProvider->getEntityManager();
        $category = $em->getRepository(Categories::class)->findOneBy(['name' => self::CARRIER_CATEGORY]);
        if ($category === null) {
            $category = (new Categories())->setName(self::CARRIER_CATEGORY);
            $em->persist($category);
        }
        $carrier->addCategory($category);
        $em->persist($carrier); // la variante suit (cascade)
    }

    /** @return list<string> les noms des formules actives d'un produit (celles qui sortiraient de la vente) */
    public function activePlanNames(Product $product): array
    {
        $plans = $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->findBy(['product' => $product, 'active' => true], ['id' => 'ASC']);

        return array_values(array_map(static fn (SubscriptionPlan $p) => $p->getName('fr'), $plans));
    }

    /** @return array<string, mixed> */
    private function row(Product $product, ?array $stats): array
    {
        $reasonOn = $this->ineligibility($product, $stats);
        $canDisable = $product->isSubscriptionEnabled() && ($stats['withSubscribers'] ?? 0) === 0 && ($stats['branched'] ?? false) !== true;

        return [
            'id' => $product->getId(), 'name' => $product->getName(), 'slug' => $product->getSlug(), 'imageUrl' => $product->getImage(),
            'subscriptionEnabled' => $product->isSubscriptionEnabled(), 'subscriptionOnly' => $product->isSubscriptionOnly(),
            'plans' => ['active' => $stats['active'] ?? 0, 'archived' => $stats['archived'] ?? 0, 'withSubscribers' => $stats['withSubscribers'] ?? 0,
                'activeNames' => $stats['activeNames'] ?? []], // lot ABO-WEB-2 : les formules qui sortiraient de la vente, nommées AVANT le geste (10 au plus)
            'canEnable' => !$product->isSubscriptionEnabled() && $reasonOn === null,
            'canDisable' => $canDisable,
            'reason' => match (true) {
                $reasonOn !== null => 'PRODUCT_NOT_ELIGIBLE',
                $product->isSubscriptionEnabled() && ($stats['withSubscribers'] ?? 0) > 0 => 'PRODUCT_HAS_SUBSCRIBERS',
                default => null,
            },
        ];
    }

    /** Raison de ne pas pouvoir activer : véhicule, ou porteur d'une formule branchée (plateforme, agence) */
    private function ineligibility(Product $product, ?array $stats): ?string
    {
        return match (true) {
            $product instanceof VehicleProduct => 'vehicle',
            ($stats['branched'] ?? false) === true => 'branched',
            default => null,
        };
    }

    /**
     * Formules par produit : actives, archivées, ayant eu un abonné, branchées.
     *
     * @param list<int> $ids
     * @return array<int, array{active: int, archived: int, withSubscribers: int, branched: bool, activeNames: list<string>}>
     */
    private function stats(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT p.product_id, COUNT(*) FILTER (WHERE p.active) AS active, COUNT(*) FILTER (WHERE NOT p.active) AS archived,
                COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM subscription s WHERE s.plan_id = p.id)) AS with_subscribers,
                BOOL_OR(COALESCE(p.fulfillment_type, 'none') <> 'none') AS branched,
                TO_JSON((ARRAY_AGG(COALESCE(NULLIF(p.names::json->>'fr', ''), (SELECT j.value FROM json_each_text(p.names::json) j LIMIT 1), '') ORDER BY p.id) FILTER (WHERE p.active))[1:10]) AS active_names
             FROM subscription_plan p WHERE p.product_id IN (?) GROUP BY p.product_id",
            [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']] = ['active' => (int) $r['active'], 'archived' => (int) $r['archived'], 'withSubscribers' => (int) $r['with_subscribers'], 'branched' => (bool) $r['branched'],
                'activeNames' => array_values(array_map('strval', (array) (json_decode((string) ($r['active_names'] ?? 'null'), true) ?? [])))];
        }

        return $out;
    }

    private function uniqueSlug(string $name): string
    {
        $base = (new AsciiSlugger('fr'))->slug($name)->lower()->toString() ?: 'abonnement';
        $repo = $this->emProvider->getEntityManager()->getRepository(Product::class);
        $slug = $base;
        for ($i = 2; $repo->findOneBy(['slug' => $slug]) !== null && $i < 1000; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
