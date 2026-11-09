<?php
namespace App\Services\CommandeService;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\CollectionPicture;
use App\Services\TenantEntityManagerProvider; 
use App\Dto\FournisseurInputDTO;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;

class CommandeService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null) // devise du site (lot G6)
    {
        $this->emProvider = $emProvider;
    }

    public function getCommandesByCollection(Collections $collection)
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();
        
        // On s'assure que l'entité $collection est bien gérée par cet EM
        $managedCollection = $em->merge($collection);

        return $managedCollection->getCommandes();
    }

    public function createCommande(array $data, Collections $collection, Fournisseur $fournisseur): Commande
    {
        $em = $this->emProvider->getEntityManager();

        $commande = new Commande();
        $commande->setBudget($data['budget']);
        $commande->setDate(new \DateTime($data['date']));
        $commande->setName($data['name']);
        $commande->setCollections($collection);
        $commande->setFournisseur($fournisseur);
        // devise d'achat figée d'après le fournisseur (lot G6, 10/11/2026) ; taux obligatoire si elle diffère de celle du site
        $siteCurrency = strtoupper($this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT);
        $purchaseCurrency = $fournisseur->getCurrency();
        if ($purchaseCurrency !== null && $purchaseCurrency !== $siteCurrency) {
            if (!isset($data['exchangeRate']) || (float) $data['exchangeRate'] <= 0) {
                throw new \App\Services\GestionService\GestionValidationException(['exchangeRate' => 'EXCHANGE_RATE_REQUIRED'], sprintf('Fournisseur en %s : taux vers %s requis', $purchaseCurrency, $siteCurrency));
            }
            $commande->setCurrency($purchaseCurrency)->setExchangeRate((float) $data['exchangeRate']);
        }
        
        // On passe l'EM du tenant à la méthode privée
        $randomPicture = $this->getRandomCollectionPicture($em);
        $commande->setCommandepictures($randomPicture);

        // GARDE-FOU : On s'assure que les entités liées sont bien gérées par l'EM
        $em->persist($collection);
        $em->persist($fournisseur);

        $em->persist($commande);
        $em->flush();

        return $commande;
    }

    public function findOrCreateFournisseur(FournisseurInputDTO $fournisseurDTO): Fournisseur
    {
        $em = $this->emProvider->getEntityManager();
        $fournisseur = $em->getRepository(Fournisseur::class)->find($fournisseurDTO->id);
        if (!$fournisseur) {
            throw new \App\Services\GestionService\GestionConflictException('FOURNISSEUR_NOT_FOUND', 'Fournisseur inconnu : ' . $fournisseurDTO->id, 404); // lot G4 (09/11/2026)
        }

        return $fournisseur;
    }

    /**
     * MODIFICATION 3 : La méthode privée reçoit l'EntityManager en paramètre
     */
    private function getRandomCollectionPicture(EntityManagerInterface $em): ?CollectionPicture
    {
        $sql = 'SELECT * FROM collection_picture ORDER BY RANDOM() LIMIT 1';
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CollectionPicture::class, 'cp');
        $rsm->addFieldResult('cp', 'id', 'id');
        $rsm->addFieldResult('cp', 'image_url', 'imageUrl');

        return $em->createNativeQuery($sql, $rsm)
            ->getOneOrNullResult();
    }

    /**
     * Modification d'une commande ouverte (PATCH /api/commandes/{id}, lot G6, 10/11/2026) : budget, nom, date, taux de change.
     *
     * @param array<string, mixed> $data champs validés présents seulement
     */
    public function updateCommande(Commande $commande, array $data): Commande
    {
        if ($commande->getIsClosed()) {
            throw new \App\Services\GestionService\GestionConflictException('COMMANDE_CLOSED', 'La commande est figée.');
        }
        if (array_key_exists('budget', $data)) {
            $commande->setBudget((int) $data['budget']);
        }
        if (array_key_exists('name', $data)) {
            $commande->setName((string) $data['name']);
        }
        if (array_key_exists('date', $data)) {
            $commande->setDate(new \DateTime((string) $data['date']));
        }
        if (array_key_exists('exchangeRate', $data)) {
            if ($commande->getCurrency() === null) {
                throw new \App\Services\GestionService\GestionValidationException(['exchangeRate' => 'NOT_APPLICABLE'], 'Commande dans la devise du site : aucun taux');
            }
            $commande->setExchangeRate((float) $data['exchangeRate']);
        }
        $em = $this->emProvider->getEntityManager();
        $em->persist($commande);
        $em->flush();

        return $commande;
    }
}
