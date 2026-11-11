<?php

namespace App\Gestion\Services;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\FraisDePort;
use App\Entity\NoteDeFrais;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\Product;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CommandeService\CommandeProductAttachmentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CommandeDashboardUseCase\FreezeCommandeMetricsUseCase;

/**
 * Jeu de données de l'application de gestion pour le site de démonstration (demande du propriétaire, 09/11/2026,
 * `docs/gestion-mise-a-niveau.md` § 8 ; enrichi le 10/11/2026 « le maximum de données ») : trois fournisseurs, deux
 * transporteurs d'achat, quatre périodes du calendrier sur l'année (les périodes passées sont figées par la date, lot P1), huit
 * commandes fournisseurs, les **produits existants du site** plus des produits de démonstration (`is_demo`, créés quand le
 * site compte moins de 32 produits ; au-delà, ceux que vendent les ventes de démonstration) rattachés aux commandes (coût d'achat = 55 % du prix de vente, coefficient 1,8 posé sur les
 * produits qui n'en ont pas : `CommandeProductAttachmentService`, prix de vente intacts), un frais de port par commande,
 * des notes de frais, le tout en cents. Les ventes (commandes clients) existantes alimentent déjà les statistiques.
 * Rejouable : chaque élément est reconnu par son nom, rien n'est effacé ni recréé ; le cache du site est invalidé à la fin
 * (un semis en console ne passe pas par l'invalidation des requêtes HTTP).
 */
class GestionDemoSeeder
{
    // Depuis le lot P1 (10/11/2026) : les quatre collections de démonstration sont les PÉRIODES du calendrier (trimestres), créées par PeriodCalendar
    public const COLLECTION_WINTER = 'Hiver 2025-2026';
    public const COLLECTION_CLOSED = 'Printemps 2026';
    public const COLLECTION_SUMMER = 'Été 2026';
    public const COLLECTION_OPEN = 'Automne 2026';
    private const PERIOD_KEYS = [self::COLLECTION_WINTER => '2026-Q1', self::COLLECTION_CLOSED => '2026-Q2', self::COLLECTION_SUMMER => '2026-Q3', self::COLLECTION_OPEN => '2026-Q4'];
    /** En dessous de ce nombre de produits dans le site, les produits de démonstration manquants sont créés (une seule fois : ils comptent ensuite) */
    public const MIN_PRODUCTS = 32;

    /** Produits de démonstration : [nom, prix de vente (cents), quantité, catégorie] */
    /** Produits de démonstration sans coût ni commande fournisseur (matière pour la reprise d'historique, lot P3) : jamais rattachés par le semis */
    public const UNCOSTED_PRODUCTS = [
        ['Trouvaille — foulard vintage', 3200, 6], ['Trouvaille — ceinture tressée', 2600, 8], ['Trouvaille — bonnet artisanal', 2200, 10], ['Trouvaille — gants tricotés', 1900, 9],
        ['Trouvaille — sac en toile', 4500, 5], ['Trouvaille — lunettes rétro', 3900, 6], ['Trouvaille — sandales en cuir', 5900, 4],
    ];

    /** Préfixe des références des ventes de démonstration (les commandes clients n'ont pas de marque `is_demo`) */
    public const SALE_REFERENCE_PREFIX = 'DEMO-GESTION-';

    /**
     * Ventes de démonstration réparties sur les périodes passées (demande de l'application, 10/11/2026) : par période, [n°, source
     * (1 web, 2 caisse, 3 mobile), jour depuis le début de la période, lignes [nom du produit, quantité]] ; type 2 = retour d'une vente d'Hiver
     */
    private const SALES = [
        self::COLLECTION_WINTER => [
            [1, 2, 9, [['Manteau de laine doublé', 1], ['Tuque en tricot torsadé', 2]]], [2, 1, 14, [['Parka isolée grand froid', 1]]],
            [3, 3, 22, [['Pull col roulé mérinos', 2], ['Gants doublés en cuir', 1]]], [4, 2, 30, [['Trouvaille — bonnet artisanal', 3], ['Trouvaille — gants tricotés', 2]]],
            [5, 1, 45, [['Chemise en flanelle à carreaux', 2]]], [6, 2, 60, [['Foulard en cachemire', 1], ['Trouvaille — foulard vintage', 1]]],
        ],
        self::COLLECTION_CLOSED => [
            [1, 2, 7, [['Jean coupe droite brut', 2], ['T-shirt coton biologique', 3]]], [2, 1, 12, [['Trouvaille — ceinture tressée', 2]]],
            [3, 3, 20, [['Espadrilles de toile', 1], ['Chaussettes en laine (lot de 3)', 2]]], [4, 2, 33, [['Bottes de pluie doublées', 1]]],
            [5, 1, 50, [['Trouvaille — sac en toile', 1], ['Sac à dos urbain 20 L', 1]]], [6, 2, 15, [['Tuque en tricot torsadé', 1]], 'return', self::COLLECTION_WINTER, 1],
        ],
        self::COLLECTION_SUMMER => [
            [1, 2, 5, [['Robe d\'été en lin', 2], ['Chapeau de paille à large bord', 1]]], [2, 1, 12, [['Maillot de bain une pièce', 1], ['Serviette de plage éponge', 2]]],
            [3, 3, 19, [['Lunettes de soleil polarisées', 1]]], [4, 2, 28, [['Trouvaille — lunettes rétro', 2], ['Short cargo léger', 2]]],
            [5, 1, 40, [['Sac fourre-tout en toile', 3]]], [6, 2, 55, [['Trouvaille — sandales en cuir', 1], ['Portefeuille compact', 1]]],
        ],
    ];

    private const PRODUCTS = [
        ['Manteau de laine doublé', 24900, 12, 'Vêtements'], ['Parka isolée grand froid', 32900, 8, 'Vêtements'], ['Pull col roulé mérinos', 11900, 20, 'Vêtements'],
        ['Chandail à capuchon molletonné', 7900, 30, 'Vêtements'], ['Tuque en tricot torsadé', 2999, 40, 'Accessoires'], ['Foulard en cachemire', 8900, 15, 'Accessoires'],
        ['Gants doublés en cuir', 6900, 18, 'Accessoires'], ['Chemise en flanelle à carreaux', 6500, 25, 'Vêtements'], ['Jean coupe droite brut', 9900, 24, 'Vêtements'],
        ['T-shirt coton biologique', 2900, 60, 'Vêtements'], ['Robe d\'été en lin', 10900, 16, 'Vêtements'], ['Short cargo léger', 5500, 22, 'Vêtements'],
        ['Maillot de bain une pièce', 7900, 14, 'Vêtements'], ['Serviette de plage éponge', 3500, 30, 'Accessoires'], ['Chapeau de paille à large bord', 4200, 20, 'Accessoires'],
        ['Lunettes de soleil polarisées', 12900, 12, 'Accessoires'], ['Sac fourre-tout en toile', 4900, 25, 'Accessoires'], ['Sac à dos urbain 20 L', 11900, 15, 'Accessoires'],
        ['Ceinture en cuir pleine fleur', 5900, 18, 'Accessoires'], ['Portefeuille compact', 4500, 20, 'Accessoires'], ['Bottes de pluie doublées', 9900, 10, 'Vêtements'],
        ['Espadrilles de toile', 6900, 18, 'Vêtements'], ['Chaussettes en laine (lot de 3)', 2400, 50, 'Accessoires'], ['Pyjama en coton peigné', 6900, 14, 'Vêtements'],
        ['Veste coupe-vent repliable', 8900, 16, 'Vêtements'], ['Pantalon de randonnée', 10900, 12, 'Vêtements'], ['Gourde isotherme 750 ml', 3900, 35, 'Accessoires'],
        ['Parapluie compact tempête', 3500, 30, 'Accessoires'], ['Montre bracelet cuir', 15900, 8, 'Accessoires'], ['Bonnet de bain et lunettes', 2500, 25, 'Accessoires'],
        ['Veste matelassée sans manches', 9900, 14, 'Vêtements'], ['Écharpe tricotée main', 5500, 16, 'Accessoires'], ['Cardigan long en maille', 8900, 12, 'Vêtements'],
        ['Blouson en denim', 11900, 10, 'Vêtements'], ['Collier de perles de verre', 4900, 20, 'Accessoires'], ['Boucles d\'oreilles argent', 6900, 18, 'Accessoires'],
    ];

    /** @var list<string> */
    private array $report = [];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CommandeProductAttachmentService $attachments,
        private readonly FreezeCommandeMetricsUseCase $freezeCommande,
        private readonly CacheInvalidationSubscriber $cacheInvalidation,
        private readonly \App\Gestion\Services\PeriodCalendar $calendar,
        private readonly ?\App\UseCase\CollectionDashboardUseCase\GetCollectionStatistiquesUseCase $collectionStatistiques = null,
        private readonly ?\App\Gestion\UseCase\GetPeriodResultUseCase $periodResult = null,
        private readonly ?\App\Services\CommandeService\CommandeLineService $lines = null, // lignes d'achat (lot L0)
    ) {
    }

    /** @return list<string> ce qui a été ajouté (vide si tout était déjà en place) */
    public function seed(): array
    {
        $this->report = [];
        $em = $this->emProvider->getEntityManager();

        $types = [];
        foreach (['Grossiste', 'Fabricant', 'Prestataire de services'] as $name) {
            $types[$name] = $this->ensure(TypeFournisseur::class, ['name' => $name], fn () => (new TypeFournisseur())->setName($name), "type de fournisseur « $name »");
        }
        $noteTypes = [];
        foreach (['Déplacement', 'Repas', 'Hébergement', 'Fournitures', 'Autre'] as $name) {
            $noteTypes[$name] = $this->ensure(TypeNoteDeFrais::class, ['name' => $name], fn () => (new TypeNoteDeFrais())->setName($name), "type de note de frais « $name »");
        }
        $suppliers = [
            'Atelier Nord Textile' => $this->ensure(Fournisseur::class, ['name' => 'Atelier Nord Textile'], fn () => (new Fournisseur())->setName('Atelier Nord Textile')->setTypeFournisseur($types['Fabricant'])->setAdresse('120, rue Saint-Zotique Est')->setVille('Montréal')->setPays('Canada')->setTel('514 555-0142'), 'fournisseur « Atelier Nord Textile »'),
            'Distribution Laurentides' => $this->ensure(Fournisseur::class, ['name' => 'Distribution Laurentides'], fn () => (new Fournisseur())->setName('Distribution Laurentides')->setTypeFournisseur($types['Grossiste'])->setAdresse('4500, boulevard Curé-Labelle')->setVille('Laval')->setPays('Canada')->setTel('450 555-0187'), 'fournisseur « Distribution Laurentides »'),
            'Logistique Saint-Laurent' => $this->ensure(Fournisseur::class, ['name' => 'Logistique Saint-Laurent'], fn () => (new Fournisseur())->setName('Logistique Saint-Laurent')->setTypeFournisseur($types['Prestataire de services'])->setAdresse('88, rue Dalhousie')->setVille('Québec')->setPays('Canada')->setTel('418 555-0119'), 'fournisseur « Logistique Saint-Laurent »'),
        ];
        $carriers = [
            'Purolator' => $this->ensure(Transporteur::class, ['name' => 'Purolator'], fn () => (new Transporteur())->setName('Purolator')->setContact('1 888 744-7123'), 'transporteur « Purolator »'),
            'Nationex' => $this->ensure(Transporteur::class, ['name' => 'Nationex'], fn () => (new Transporteur())->setName('Nationex')->setContact('1 866 648-7770'), 'transporteur « Nationex »'),
        ];
        // propriétaire des collections : premier administrateur du site (roles est une colonne JSON : comparaison en texte)
        $ownerId = $em->getConnection()->fetchOne('SELECT id FROM "user" WHERE roles::text LIKE :r ORDER BY id ASC LIMIT 1', ['r' => '%ROLE_ADMIN%']);
        $owner = $ownerId ? $em->find(User::class, (int) $ownerId) : null;

        // périodes du calendrier (créées sans trou depuis la plus ancienne commande de démonstration), budgets posés une fois
        $this->calendar->ensurePeriods(new \DateTimeImmutable('2025-12-05'));
        $collections = [];
        foreach ([self::COLLECTION_WINTER => 900000, self::COLLECTION_CLOSED => 1200000, self::COLLECTION_SUMMER => 1800000, self::COLLECTION_OPEN => 2500000] as $name => $budget) {
            $period = $em->getRepository(Collections::class)->findOneBy(['periodKey' => self::PERIOD_KEYS[$name], 'kind' => Collections::KIND_PERIOD]);
            if ($period === null) {
                throw new \RuntimeException("Période $name absente après création du calendrier");
            }
            if ((int) $period->getBudgetCollection() === 0) {
                $period->setBudgetCollection($budget);
                if ($owner && $period->getUserCollections() === null) {
                    $period->setUserCollections($owner);
                }
                $em->flush();
                $this->report[] = "période « $name » : budget posé";
            }
            $collections[$name] = $period;
        }
        // semis d'avant P1 (collections manuelles « Démo gestion — <nom> ») : fusionnées dans la période de même nom, une seule fois
        foreach ($collections as $name => $period) {
            $legacy = $em->getRepository(Collections::class)->findOneBy(['nomCollection' => 'Démo gestion — ' . $name, 'kind' => Collections::KIND_MANUAL]);
            if ($legacy === null) {
                continue;
            }
            foreach ($legacy->getCommandes() as $commande) {
                $commande->setCollections($period);
            }
            $em->flush();
            $em->createQuery('DELETE FROM App\\Entity\\NoteDeFrais n WHERE n.Collection = :c')->setParameter('c', $legacy)->execute(); // recréées sous la période ci-dessous
            $em->createQuery('DELETE FROM App\\Entity\\CollectionStatistiques s WHERE s.collection = :c')->setParameter('c', $legacy)->execute();
            $em->remove($legacy);
            $em->flush();
            $this->report[] = sprintf('ancienne collection « Démo gestion — %s » fusionnée dans la période « %s »', $name, $name);
        }

        $commandes = [];
        foreach ([
            ['Collection hiver — manteaux', self::COLLECTION_WINTER, 'Atelier Nord Textile', 450000, '2025-12-05 09:00:00'],
            ['Accessoires d\'hiver', self::COLLECTION_WINTER, 'Distribution Laurentides', 200000, '2026-01-12 14:30:00'],
            ['Commande textile printemps', self::COLLECTION_CLOSED, 'Atelier Nord Textile', 600000, '2026-03-10 10:00:00'],
            ['Chaussures et sacs', self::COLLECTION_CLOSED, 'Distribution Laurentides', 350000, '2026-04-08 11:00:00'],
            ['Maillots et serviettes', self::COLLECTION_SUMMER, 'Atelier Nord Textile', 500000, '2026-06-15 09:30:00'],
            ['Location nautique — matériel', self::COLLECTION_SUMMER, 'Logistique Saint-Laurent', 700000, '2026-07-02 15:00:00'],
            ['Réassort automne', self::COLLECTION_OPEN, 'Distribution Laurentides', 900000, '2026-10-05 09:30:00'],
            ['Accessoires des fêtes', self::COLLECTION_OPEN, 'Atelier Nord Textile', 400000, '2026-11-02 14:00:00'],
        ] as [$name, $collectionName, $supplier, $budget, $date]) {
            $commandes[$name] = $this->ensure(Commande::class, ['name' => $name], fn () => (new Commande())->setName($name)->setBudget($budget)->setDate(new \DateTime($date))
                ->setCollections($collections[$collectionName])->setFournisseur($suppliers[$supplier]), "commande « $name »");
        }

        // Produits : ceux du site sans commande, complétés par des produits de démonstration s'il en manque
        $this->ensureDemoProducts();
        $this->ensureUncostedProducts();
        $free = $em->getRepository(Product::class)->createQueryBuilder('p')->where('NOT EXISTS (SELECT 1 FROM App\\Entity\\CommandeLine cl WHERE cl.product = p)')->andWhere('p.name NOT IN (:uncosted)')
            ->setParameter('uncosted', array_column(self::UNCOSTED_PRODUCTS, 0))->orderBy('p.id', 'ASC')->getQuery()->getResult(); // les « Trouvailles » restent sans coût ni commande
        // reprise d'historique de démonstration : les périodes passées (fermées par la date) sont rouvertes le temps du rattachement, puis refermées
        $reopened = [];
        foreach ($collections as $collection) {
            if ($this->calendar->isClosed($collection) && $collection->getClosedAt() === null) {
                $collection->setReopenedAt(new \DateTime());
                $reopened[] = $collection;
            }
        }
        if ($reopened !== []) {
            $em->flush();
        }
        $targets = array_values(array_filter($commandes, fn (Commande $c) => !$c->getIsClosed()));
        if ($free !== [] && $targets !== []) {
            $perCommande = array_fill(0, count($targets), []);
            foreach ($free as $i => $product) {
                $perCommande[$i % count($targets)][] = $product;
            }
            foreach ($targets as $i => $commande) {
                $products = $perCommande[$i];
                if ($products === []) {
                    continue;
                }
                $lines = [];
                foreach ($products as $product) {
                    $price = (int) round((float) ($product->getPrice() ?? 0));
                    $lines[] = ['productId' => $product->getId(), 'purchasePrice' => max(100, (int) round($price * 0.55)), 'coefficientMultiplier' => 1.8, 'quantity' => $product->getQuantity() ?: 10];
                }
                $this->attachments->attach($commande, $lines, 'demo-seed');
                foreach ($products as $product) {
                    // le rattachement n'écrit pas le coefficient (prix de vente intact) : posé ici quand le produit n'en a pas, sans effet sur le prix (prix d'achat absent)
                    if ($product->getCoefficientMultiplier() === null && $product->getPurchasePrice() === null) {
                        $product->setCoefficientMultiplier(1.8);
                    }
                }
                $em->flush();
                $this->report[] = sprintf('%d produit(s) rattaché(s) à « %s » (coût d\'achat 55 %% du prix de vente, coefficient 1,8)', count($lines), $commande->getName());
            }
        }

        foreach ($reopened as $collection) {
            $collection->setReopenedAt(null); // refermée par la date
        }
        if ($reopened !== []) {
            $em->flush();
        }

        $shipping = [
            'Collection hiver — manteaux' => ['Expédition Purolator', 32500, 'PUR-2025-1205', 'PUR120500112CA', 'Purolator'],
            'Accessoires d\'hiver' => ['Expédition Nationex', 8900, 'NAT-2026-0112', 'NX00401122', 'Nationex'],
            'Commande textile printemps' => ['Expédition Purolator', 18500, 'PUR-2026-0312', 'PUR123456789CA', 'Purolator'],
            'Chaussures et sacs' => ['Expédition Nationex', 14200, 'NAT-2026-0408', 'NX00412208', 'Nationex'],
            'Maillots et serviettes' => ['Expédition Purolator', 11900, 'PUR-2026-0615', 'PUR061500334CA', 'Purolator'],
            'Location nautique — matériel' => ['Livraison palette Nationex', 45000, 'NAT-2026-0702', 'NX00470201', 'Nationex'],
            'Réassort automne' => ['Expédition Nationex', 24900, 'NAT-2026-1006', 'NX00458812', 'Nationex'],
            'Accessoires des fêtes' => ['Expédition Purolator', 9900, 'PUR-2026-1103', 'PUR987654321CA', 'Purolator'],
        ];
        foreach ($shipping as $commandeName => [$name, $price, $invoice, $tracking, $carrier]) {
            $commande = $commandes[$commandeName];
            if ($commande->getFraisDePort() === null) {
                $frais = (new FraisDePort())->setName($name)->setPrice($price)->setFacture($invoice)->setTracknumber($tracking)->setTransporteur($carriers[$carrier])->setCommande($commande);
                $em->persist($frais);
                $this->report[] = sprintf('frais de port « %s » (%s) sur « %s »', $name, self::money($price), $commandeName);
            }
        }
        $notes = [
            [self::COLLECTION_WINTER, 'Salon de la mode d\'hiver — hébergement', 41000, '2025-12-10', 'Hébergement'],
            [self::COLLECTION_WINTER, 'Repas d\'affaires — distributeur', 9600, '2026-01-13', 'Repas'],
            [self::COLLECTION_WINTER, 'Étiquettes et cintres', 7800, '2026-01-20', 'Fournitures'],
            [self::COLLECTION_CLOSED, 'Visite de l\'atelier — essence', 6500, '2026-03-12', 'Déplacement'],
            [self::COLLECTION_CLOSED, 'Repas avec le fournisseur', 8400, '2026-03-12', 'Repas'],
            [self::COLLECTION_CLOSED, 'Séance photo des nouveautés', 25000, '2026-04-22', 'Autre'],
            [self::COLLECTION_SUMMER, 'Déplacement au port — stationnement', 3200, '2026-07-02', 'Déplacement'],
            [self::COLLECTION_SUMMER, 'Housses et sangles', 11500, '2026-07-05', 'Fournitures'],
            [self::COLLECTION_OPEN, 'Salon des détaillants — hébergement', 32000, '2026-10-14', 'Hébergement'],
            [self::COLLECTION_OPEN, 'Fournitures d\'emballage', 12300, '2026-10-20', 'Fournitures'],
            [self::COLLECTION_OPEN, 'Repas d\'équipe — inventaire', 14600, '2026-11-03', 'Repas'],
        ];
        foreach ($notes as [$collectionName, $description, $amount, $date, $type]) {
            $collection = $collections[$collectionName];
            if (!$em->getRepository(NoteDeFrais::class)->findOneBy(['Collection' => $collection, 'Description' => $description])) {
                $note = (new NoteDeFrais())->setName($description)->setDescription($description)->setMontant($amount)->setDate(new \DateTime($date))->setTypeNoteDeFrais($noteTypes[$type])->setCollection($collection);
                $em->persist($note);
                $this->report[] = sprintf('note de frais « %s » (%s)', $description, self::money($amount));
            }
        }
        $em->flush();

        $this->ensureSales($collections, $owner);
        $this->alignPurchasesBeforeSales($commandes);

        // périodes passées : fermées par la date (fin + 15 jours) ; leurs commandes et la période sont (re)figées à chaque semis, statistiques
        // recalculées (tableaux de bord complets et à jour des règles de calcul dès la fin du semis)
        foreach ($collections as $name => $collection) {
            if (!$this->calendar->isClosed($collection)) {
                continue;
            }
            $em->createQuery('DELETE FROM App\\Entity\\CollectionStatistiques s WHERE s.collection = :c')->setParameter('c', $collection)->execute();
            $commandes = $em->getRepository(Commande::class)->createQueryBuilder('c')->where('c.date >= :from AND c.date < :to')->setParameter('from', $collection->getStartDateCollection()->format('Y-m-d 00:00:00'))->setParameter('to', \DateTimeImmutable::createFromInterface($collection->getEndDateCollection())->modify('+1 day')->format('Y-m-d 00:00:00'))->getQuery()->getResult();
            foreach ($commandes as $commande) {
                $em->createQuery('DELETE FROM App\\Entity\\CommandeStatistiques s WHERE s.commande = :c')->setParameter('c', $commande)->execute();
                $commande->setIsClosed(false);
                $this->freezeCommande->execute($commande);
            }
            $this->collectionStatistiques?->executeOrFreeze($collection);
            if ($em->getRepository(\App\Entity\PeriodResult::class)->findLatestByCollection($collection) === null) {
                $this->periodResult?->execute($collection); // résultat de période (P2) figé une fois ; jamais recalculé ensuite (chiffres figés intacts, 10/11/2026)
            }
            $this->report[] = sprintf('période « %s » figée avec ses %d commande(s) (tableaux de bord recalculés)', $name, count($commandes));
        }
        $this->cacheInvalidation->invalidateWrittenTenants(); // listes et tableaux de bord en cache : à jour dès la fin du semis

        return $this->report;
    }

    /** Produits de démonstration (`is_demo`) créés quand le site compte moins de MIN_PRODUCTS produits ; reconnus par leur nom, jamais recréés */
    private function ensureDemoProducts(): void
    {
        $em = $this->emProvider->getEntityManager();
        // au-delà de 32 articles, seuls ceux que vendent les ventes de démonstration sont garantis (sinon ces ventes resteraient sans lignes)
        $only = null;
        if ((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM product') >= self::MIN_PRODUCTS) {
            $only = [];
            foreach (self::SALES as $sales) {
                foreach ($sales as $sale) {
                    foreach ($sale[3] as [$productName]) {
                        $only[$productName] = true;
                    }
                }
            }
        }
        $categories = [];
        foreach ($em->getRepository(Categories::class)->findAll() as $category) {
            $categories[(string) $category->getName()] = $category;
        }
        $created = 0;
        foreach (self::PRODUCTS as $i => [$name, $price, $quantity, $categoryName]) {
            if (($only !== null && !isset($only[$name])) || $em->getRepository(Product::class)->findOneBy(['name' => $name])) {
                continue;
            }
            $product = (new Product())->setName($name)->setDescription('Produit de démonstration de la gestion (rattaché à une commande fournisseur).')
                ->setPrice($price)->setQuantity($quantity)->setSlug('demo-gestion-' . ($i + 1) . '-' . substr(md5($name), 0, 6))->setCreatedAt(new \DateTimeImmutable())
                ->setIsWeb(true)->setIsPos(true)->setIsPreOrder(false)->setIsDemo(true);
            if (isset($categories[$categoryName])) {
                $product->addCategory($categories[$categoryName]);
            }
            $em->persist($product);
            $created++;
        }
        $em->flush();
        if ($created > 0) {
            $this->report[] = sprintf('%d produit(s) de démonstration créé(s) (marqués contenu d\'exemple)', $created);
        }
    }

    /**
     * Achats avant ventes (retour de l'application, 10/11/2026) : sur un vrai site, un article est acheté avant d'être vendu. Les lignes d'achat
     * du semis sont datées de leur commande (historique saisi au fil de l'eau, donc avant la coupure des périodes fermées), et un article vendu
     * par une vente de démonstration avant toute commande qui le contient est déplacé vers la commande du semis la plus récente datée au plus
     * tard le jour de sa première vente. Sans cela, la démonstration affichait des « coûts inconnus » qu'aucun vrai client n'aurait. Rejouable.
     *
     * @param array<string, Commande> $commandes commandes du semis, par nom
     */
    private function alignPurchasesBeforeSales(array $commandes): void
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $ids = array_values(array_filter(array_map(fn (Commande $c) => $c->getId(), $commandes)));
        if ($ids === []) {
            return;
        }
        $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER];
        $dated = (int) $conn->executeStatement('UPDATE commande_line l SET attached_at = c.date FROM commande c WHERE c.id = l.commande_id AND l.commande_id IN (:ids) AND l.attached_at > c.date', ['ids' => $ids], $types);
        if ($dated > 0) {
            $this->report[] = sprintf('%d ligne(s) d\'achat du semis datée(s) de leur commande', $dated);
        }
        $dates = $conn->fetchAllKeyValue('SELECT id, CAST(date AS date) FROM commande WHERE id IN (:ids) ORDER BY date', ['ids' => $ids], $types);
        $firstSales = $conn->fetchAllKeyValue(
            'SELECT v.product_id, CAST(MIN(o.order_date) AS date) FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id JOIN product_variant v ON v.id = oi.product_variant_id'
            . ' WHERE o.reference LIKE :p AND o.order_type_id = 1 GROUP BY v.product_id',
            ['p' => self::SALE_REFERENCE_PREFIX . '%']
        );
        $moved = 0;
        foreach ($firstSales as $productId => $firstSale) {
            $bought = $conn->fetchOne('SELECT 1 FROM commande_line l JOIN commande c ON c.id = l.commande_id WHERE l.product_id = :p AND CAST(c.date AS date) <= :d LIMIT 1', ['p' => $productId, 'd' => $firstSale]);
            if ($bought !== false) {
                continue;
            }
            $target = null;
            foreach ($dates as $commandeId => $date) {
                if ($date <= $firstSale) {
                    $target = (int) $commandeId; // la plus récente datée au plus tard le jour de la première vente (dates croissantes)
                }
            }
            $lineId = $conn->fetchOne('SELECT id FROM commande_line WHERE product_id = :p AND commande_id IN (:ids) AND product_variant_id IS NULL ORDER BY id LIMIT 1', ['p' => $productId, 'ids' => $ids], $types);
            if ($target === null || $lineId === false || $conn->fetchOne('SELECT 1 FROM commande_line WHERE product_id = :p AND commande_id = :c AND product_variant_id IS NULL', ['p' => $productId, 'c' => $target]) !== false) {
                continue;
            }
            $conn->executeStatement('UPDATE commande_line l SET commande_id = :c, attached_at = c.date, updated_at = NOW() FROM commande c WHERE c.id = :c AND l.id = :l', ['c' => $target, 'l' => $lineId]);
            $product = $em->find(Product::class, (int) $productId);
            if ($product !== null) {
                $this->lines?->refreshShortcuts($product);
            }
            $moved++;
        }
        if ($moved > 0) {
            $this->report[] = sprintf('%d article(s) vendu(s) avant leur achat : ligne d\'achat déplacée vers une commande antérieure à la première vente', $moved);
        }
    }

    /** Produits « Trouvaille » (`is_demo`), sans prix d'achat ni commande : la reprise d'historique a de quoi simuler ; reconnus par leur nom */
    private function ensureUncostedProducts(): void
    {
        $em = $this->emProvider->getEntityManager();
        $category = $em->getRepository(Categories::class)->findOneBy(['name' => 'Accessoires']);
        $created = 0;
        foreach (self::UNCOSTED_PRODUCTS as $i => [$name, $price, $quantity]) {
            if ($em->getRepository(Product::class)->findOneBy(['name' => $name])) {
                continue;
            }
            $product = (new Product())->setName($name)->setDescription('Produit de démonstration sans coût connu (matière pour la reprise d\'historique).')
                ->setPrice($price)->setQuantity($quantity)->setSlug('demo-trouvaille-' . ($i + 1) . '-' . substr(md5($name), 0, 6))->setCreatedAt(new \DateTimeImmutable())
                ->setIsWeb(true)->setIsPos(true)->setIsPreOrder(false)->setIsDemo(true);
            if ($category !== null) {
                $product->addCategory($category);
            }
            $em->persist($product);
            $created++;
        }
        $em->flush();
        if ($created > 0) {
            $this->report[] = sprintf('%d produit(s) de démonstration sans coût créé(s) (marqués contenu d\'exemple, jamais rattachés)', $created);
        }
    }

    /**
     * Ventes de démonstration sur les périodes passées (`SALES`) : commandes clients `DEMO-GESTION-<clé>-<n°>` (statut complétée),
     * une variante par produit vendu (stock de la variante = stock du produit : la quantité du produit ne change pas), lignes aux prix
     * de vente en cents, totaux hors taxes ; reconnues par leur référence, jamais recréées.
     *
     * @param array<string, Collections> $collections
     */
    private function ensureSales(array $collections, ?User $owner): void
    {
        $em = $this->emProvider->getEntityManager();
        $status = $em->find(StatusCommande::class, 3);
        $typeSale = $em->find(OrderType::class, 1);
        $typeReturn = $em->find(OrderType::class, 2);
        if ($status === null || $typeSale === null || $typeReturn === null) {
            return;
        }
        $created = 0;
        foreach (self::SALES as $periodName => $sales) {
            $period = $collections[$periodName] ?? null;
            if ($period === null) {
                continue;
            }
            $start = \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->setTime(12, 0);
            foreach ($sales as $sale) {
                [$n, $source, $day, $lines] = $sale;
                $isReturn = ($sale[4] ?? null) === 'return';
                $reference = self::SALE_REFERENCE_PREFIX . $period->getPeriodKey() . '-' . $n;
                if ($em->getRepository(Order::class)->findOneBy(['reference' => $reference])) {
                    continue;
                }
                $order = (new Order())->setReference($reference)->setOrderDate(\DateTime::createFromImmutable($start->modify("+$day days")))->setStatus($status)
                    ->setOrderType($isReturn ? $typeReturn : $typeSale)->setOrderSource($em->find(OrderSource::class, $source))->setUserId($owner)->setTotalTax(0.0)->setPricesIncludeTax(false);
                if ($isReturn && isset($collections[$sale[5]])) {
                    $order->setReturnedFrom($em->getRepository(Order::class)->findOneBy(['reference' => self::SALE_REFERENCE_PREFIX . $collections[$sale[5]]->getPeriodKey() . '-' . $sale[6]]));
                }
                $em->persist($order);
                $total = 0;
                foreach ($lines as [$productName, $quantity]) {
                    $product = $em->getRepository(Product::class)->findOneBy(['name' => $productName]);
                    if ($product === null) {
                        continue;
                    }
                    $unit = (int) round((float) ($product->getPrice() ?? 0));
                    $item = (new OrderItems())->setOrderAssociated($order)->setProductVariant($this->variantFor($product))->setQuantity($quantity)->setUnitPrice((float) $unit)->setTotalPrice((float) ($unit * $quantity));
                    $em->persist($item);
                    $total += $unit * $quantity;
                }
                $signed = $isReturn ? -$total : $total;
                $order->setSubTotal((float) $signed)->setTotalAmount((float) $signed);
                $em->flush();
                $created++;
            }
        }
        if ($created > 0) {
            $this->report[] = sprintf('%d vente(s) de démonstration créée(s) sur les périodes passées (références %s…)', $created, self::SALE_REFERENCE_PREFIX);
        }
    }

    /** Première variante du produit, sinon une variante créée avec le stock du produit (sa quantité reste la même) */
    private function variantFor(Product $product): ProductVariant
    {
        $existing = $product->getVariants()->first();
        if ($existing instanceof ProductVariant) {
            return $existing;
        }
        $em = $this->emProvider->getEntityManager();
        $variant = (new ProductVariant())->setProduct($product)->setStockQuantity((int) ($product->getQuantity() ?? 0))->setPrice((int) round((float) ($product->getPrice() ?? 0)));
        $product->addVariant($variant);
        $em->persist($variant);
        $em->flush();

        return $variant;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     * @param callable(): T $factory
     * @return T
     */
    private function ensure(string $class, array $criteria, callable $factory, string $label): object
    {
        $em = $this->emProvider->getEntityManager();
        $entity = $em->getRepository($class)->findOneBy($criteria);
        if ($entity) {
            return $entity;
        }
        $entity = $factory();
        $em->persist($entity);
        $em->flush();
        $this->report[] = $label;

        return $entity;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' $';
    }
}
