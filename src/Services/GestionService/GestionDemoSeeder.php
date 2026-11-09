<?php

namespace App\Services\GestionService;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\FraisDePort;
use App\Entity\NoteDeFrais;
use App\Entity\Product;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CommandeService\CommandeProductAttachmentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CollectionDashboardUseCase\CloseCollectionUseCase;

/**
 * Jeu de données de l'application de gestion pour le site de démonstration (demande du propriétaire, 09/11/2026,
 * `docs/gestion-mise-a-niveau.md` § 8 ; enrichi le 10/11/2026 « le maximum de données ») : trois fournisseurs, deux
 * transporteurs d'achat, quatre collections sur l'année (hiver et printemps figées, été et automne ouvertes), huit
 * commandes fournisseurs, les **produits existants du site** plus des produits de démonstration (`is_demo`, créés quand le
 * site compte moins de 32 produits) rattachés aux commandes (coût d'achat = 55 % du prix de vente, coefficient 1,8 posé sur les
 * produits qui n'en ont pas : `CommandeProductAttachmentService`, prix de vente intacts), un frais de port par commande,
 * des notes de frais, le tout en cents. Les ventes (commandes clients) existantes alimentent déjà les statistiques.
 * Rejouable : chaque élément est reconnu par son nom, rien n'est effacé ni recréé ; le cache du site est invalidé à la fin
 * (un semis en console ne passe pas par l'invalidation des requêtes HTTP).
 */
class GestionDemoSeeder
{
    public const COLLECTION_WINTER = 'Démo gestion — Hiver 2025-2026';
    public const COLLECTION_CLOSED = 'Démo gestion — Printemps 2026';
    public const COLLECTION_SUMMER = 'Démo gestion — Été 2026';
    public const COLLECTION_OPEN = 'Démo gestion — Automne 2026';
    /** En dessous de ce nombre de produits dans le site, les produits de démonstration manquants sont créés (une seule fois : ils comptent ensuite) */
    public const MIN_PRODUCTS = 32;

    /** Produits de démonstration : [nom, prix de vente (cents), quantité, catégorie] */
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
        private readonly CloseCollectionUseCase $closeCollection,
        private readonly CacheInvalidationSubscriber $cacheInvalidation,
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

        $collections = [];
        foreach ([
            [self::COLLECTION_WINTER, 900000, '2025-12-01', '2026-02-28'],
            [self::COLLECTION_CLOSED, 1200000, '2026-03-01', '2026-05-31'],
            [self::COLLECTION_SUMMER, 1800000, '2026-06-01', '2026-08-31'],
            [self::COLLECTION_OPEN, 2500000, '2026-09-01', '2026-11-30'],
        ] as [$name, $budget, $start, $end]) {
            $collections[$name] = $this->ensure(Collections::class, ['nomCollection' => $name], fn () => (new Collections())->setNomCollection($name)->setBudgetCollection($budget)
                ->setStartDateCollection(new \DateTime($start))->setEndDateCollection(new \DateTime($end))->setDel(false)->setUserCollections($owner), "collection « $name »");
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
        $free = $em->getRepository(Product::class)->createQueryBuilder('p')->where('p.commande IS NULL')->orderBy('p.id', 'ASC')->getQuery()->getResult();
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

        foreach ([self::COLLECTION_WINTER, self::COLLECTION_CLOSED] as $name) {
            $collection = $collections[$name];
            if (!$collection->getIsClosed()) {
                $em->refresh($collection); // commandes créées après le chargement de la collection : relues avant de les figer avec elle
                $this->closeCollection->execute($collection);
                $this->report[] = "collection « $name » figée avec ses commandes (tableaux de bord conservés)";
            }
        }
        $this->cacheInvalidation->invalidateWrittenTenants(); // listes et tableaux de bord en cache : à jour dès la fin du semis

        return $this->report;
    }

    /** Produits de démonstration (`is_demo`) créés quand le site compte moins de MIN_PRODUCTS produits ; reconnus par leur nom, jamais recréés */
    private function ensureDemoProducts(): void
    {
        $em = $this->emProvider->getEntityManager();
        if ((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM product') >= self::MIN_PRODUCTS) {
            return;
        }
        $categories = [];
        foreach ($em->getRepository(Categories::class)->findAll() as $category) {
            $categories[(string) $category->getName()] = $category;
        }
        $created = 0;
        foreach (self::PRODUCTS as $i => [$name, $price, $quantity, $categoryName]) {
            if ($em->getRepository(Product::class)->findOneBy(['name' => $name])) {
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
