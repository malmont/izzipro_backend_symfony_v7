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

    public function __construct(TenantEntityManagerProvider $emProvider)
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
        
        // Note: La logique "ou Créer" n'est pas implémentée ici, mais la recherche est maintenant correcte.
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
}