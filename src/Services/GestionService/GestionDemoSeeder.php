<?php

namespace App\Services\GestionService;

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
use App\Services\CommandeService\CommandeProductAttachmentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CollectionDashboardUseCase\CloseCollectionUseCase;

/**
 * Jeu de données de l'application de gestion pour le site de démonstration (demande du propriétaire, 09/11/2026,
 * `docs/gestion-mise-a-niveau.md` § 8) : fournisseurs, transporteurs d'achat, deux collections (une figée, une ouverte),
 * trois commandes fournisseurs auxquelles les **produits existants du site** sont rattachés (coût d'achat = 55 % du prix
 * de vente, coefficient 1,8 : `CommandeProductAttachmentService`, prix de vente intacts), frais de port et notes de
 * frais, le tout en cents. Les ventes (commandes clients) existantes du site alimentent déjà les statistiques.
 * Rejouable : chaque élément est reconnu par son nom, rien n'est effacé ni recréé ; les produits déjà rattachés sont laissés.
 */
class GestionDemoSeeder
{
    public const COLLECTION_CLOSED = 'Démo gestion — Printemps 2026';
    public const COLLECTION_OPEN = 'Démo gestion — Automne 2026';

    /** @var list<string> */
    private array $report = [];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CommandeProductAttachmentService $attachments,
        private readonly CloseCollectionUseCase $closeCollection,
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
        foreach (['Déplacement', 'Repas', 'Hébergement', 'Fournitures'] as $name) {
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

        $closed = $this->ensure(Collections::class, ['nomCollection' => self::COLLECTION_CLOSED], fn () => (new Collections())->setNomCollection(self::COLLECTION_CLOSED)->setBudgetCollection(1200000)
            ->setStartDateCollection(new \DateTime('2026-03-01'))->setEndDateCollection(new \DateTime('2026-05-31'))->setDel(false)->setUserCollections($owner), 'collection « ' . self::COLLECTION_CLOSED . ' »');
        $open = $this->ensure(Collections::class, ['nomCollection' => self::COLLECTION_OPEN], fn () => (new Collections())->setNomCollection(self::COLLECTION_OPEN)->setBudgetCollection(2500000)
            ->setStartDateCollection(new \DateTime('2026-09-01'))->setEndDateCollection(new \DateTime('2026-11-30'))->setDel(false)->setUserCollections($owner), 'collection « ' . self::COLLECTION_OPEN . ' »');

        $commandes = [
            'Commande textile printemps' => $this->ensure(Commande::class, ['name' => 'Commande textile printemps'], fn () => (new Commande())->setName('Commande textile printemps')->setBudget(600000)->setDate(new \DateTime('2026-03-10 10:00:00'))->setCollections($closed)->setFournisseur($suppliers['Atelier Nord Textile']), 'commande « Commande textile printemps »'),
            'Réassort automne' => $this->ensure(Commande::class, ['name' => 'Réassort automne'], fn () => (new Commande())->setName('Réassort automne')->setBudget(900000)->setDate(new \DateTime('2026-10-05 09:30:00'))->setCollections($open)->setFournisseur($suppliers['Distribution Laurentides']), 'commande « Réassort automne »'),
            'Accessoires des fêtes' => $this->ensure(Commande::class, ['name' => 'Accessoires des fêtes'], fn () => (new Commande())->setName('Accessoires des fêtes')->setBudget(400000)->setDate(new \DateTime('2026-11-02 14:00:00'))->setCollections($open)->setFournisseur($suppliers['Atelier Nord Textile']), 'commande « Accessoires des fêtes »'),
        ];

        // Produits existants du site, sans commande, répartis sur les trois commandes (5 / 5 / le reste)
        $free = $em->getRepository(Product::class)->createQueryBuilder('p')->where('p.commande IS NULL')->orderBy('p.id', 'ASC')->getQuery()->getResult();
        $targets = array_values($commandes);
        $slices = [array_slice($free, 0, 5), array_slice($free, 5, 5), array_slice($free, 10)];
        foreach ($slices as $i => $products) {
            $commande = $targets[$i];
            if ($products === [] || $commande->getIsClosed()) {
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

        $shipping = [
            'Commande textile printemps' => ['Expédition Purolator', 18500, 'PUR-2026-0312', 'PUR123456789CA', 'Purolator'],
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
            [self::COLLECTION_CLOSED, 'Visite de l\'atelier — essence', 6500, '2026-03-12', 'Déplacement'],
            [self::COLLECTION_CLOSED, 'Repas avec le fournisseur', 8400, '2026-03-12', 'Repas'],
            [self::COLLECTION_OPEN, 'Salon des détaillants — hébergement', 32000, '2026-10-14', 'Hébergement'],
            [self::COLLECTION_OPEN, 'Fournitures d\'emballage', 12300, '2026-10-20', 'Fournitures'],
        ];
        foreach ($notes as [$collectionName, $description, $amount, $date, $type]) {
            $collection = $collectionName === self::COLLECTION_CLOSED ? $closed : $open;
            if (!$em->getRepository(NoteDeFrais::class)->findOneBy(['Collection' => $collection, 'Description' => $description])) {
                $note = (new NoteDeFrais())->setName($description)->setDescription($description)->setMontant($amount)->setDate(new \DateTime($date))->setTypeNoteDeFrais($noteTypes[$type])->setCollection($collection);
                $em->persist($note);
                $this->report[] = sprintf('note de frais « %s » (%s)', $description, self::money($amount));
            }
        }
        $em->flush();

        if (!$closed->getIsClosed()) {
            $this->closeCollection->execute($closed); // fige ses chiffres et ceux de sa commande
            $this->report[] = 'collection « ' . self::COLLECTION_CLOSED . ' » figée (tableaux de bord conservés)';
        }

        return $this->report;
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
