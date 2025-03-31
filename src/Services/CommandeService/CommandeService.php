<?php
namespace App\Services\CommandeService;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;
use App\Dto\FournisseurInputDTO;
use App\Entity\CollectionPicture;
use Doctrine\ORM\Query\ResultSetMapping;

class CommandeService
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getCommandesByCollection(Collections $collection)
    {
        return $this->entityManager->getRepository(Collections::class)
            ->find($collection->getId())
            ->getCommandes();
    }

    public function createCommande(array $data, Collections $collection, Fournisseur $fournisseur): Commande
    {
        $commande = new Commande();
        $commande->setBudget($data['budget']);
        $commande->setDate(new \DateTime($data['date']));
        $commande->setName($data['name']);
        $commande->setCollections($collection);
        $commande->setFournisseur($fournisseur);
        $randomPicture = $this->getRandomCollectionPicture();
        $commande->setCommandepictures($randomPicture);

        $this->entityManager->persist($commande);
        $this->entityManager->flush();

        return $commande;
    }

    public function findOrCreateFournisseur(FournisseurInputDTO $fournisseurDTO): Fournisseur
    {
        $fournisseur = $this->entityManager->getRepository(Fournisseur::class)->findOneBy([
            'id' => $fournisseurDTO->id,
            'name' => $fournisseurDTO->name,
            'adresse' => $fournisseurDTO->adresse,
            'ville' => $fournisseurDTO->ville,
            'pays' => $fournisseurDTO->pays,
            'tel' => $fournisseurDTO->tel
        ]);

        if (!$fournisseur) {
            $fournisseur = new Fournisseur();
            $fournisseur->setName($fournisseurDTO->name);
            $fournisseur->setTypeFournisseur($this->entityManager->getRepository(TypeFournisseur::class)->find($fournisseurDTO->typeFournisseur));
            $fournisseur->setAdresse($fournisseurDTO->adresse);
            $fournisseur->setVille($fournisseurDTO->ville);
            $fournisseur->setPays($fournisseurDTO->pays);
            $fournisseur->setTel($fournisseurDTO->tel);

            $this->entityManager->persist($fournisseur);
        }

        return $fournisseur;
    }

    /**
     * Sélectionne aléatoirement une CollectionPicture depuis la base de données.
     * Cette méthode utilise une requête native PostgreSQL qui trie les enregistrements par RANDOM().
     */
    private function getRandomCollectionPicture(): ?CollectionPicture
    {
        $sql = 'SELECT * FROM collection_picture ORDER BY RANDOM() LIMIT 1';
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CollectionPicture::class, 'cp');
        $rsm->addFieldResult('cp', 'id', 'id');
        // Assurez-vous que le nom de la colonne dans votre table est bien « image_url ».
        $rsm->addFieldResult('cp', 'image_url', 'imageUrl');

        return $this->entityManager->createNativeQuery($sql, $rsm)
            ->getOneOrNullResult();
    }
}
