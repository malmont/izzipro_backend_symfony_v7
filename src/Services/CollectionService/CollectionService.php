<?php

namespace App\Services\CollectionService;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTO;
use App\Entity\Collections;
use App\Entity\CollectionPicture;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider; 
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;

class CollectionService
{
    // MODIFICATION 1 : On ne garde qu'une seule dépendance, notre provider.
    private TenantEntityManagerProvider $emProvider;

    // MODIFICATION 2 : Le constructeur n'injecte plus que le provider.
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null, private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null) // devise du site (lot G1, 09/11/2026) ; calendrier des périodes (lot P1, 10/11/2026)
    {
        $this->emProvider = $emProvider;
    }

    public function createCollection(CollectionInputDTO $inputDTO): Collections
    {
        // MODIFICATION 3 : On récupère l'EntityManager du tenant au début de la méthode.
        $em = $this->emProvider->getEntityManager();

        // On récupère le UserRepository à partir de l'EM du tenant.
        $userRepository = $em->getRepository(User::class);
        $user = $userRepository->find($inputDTO->userId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $collection = new Collections();
        $collection->setBudgetCollection($inputDTO->budgetCollection);
        $collection->setStartDateCollection($inputDTO->startDateCollection);
        $collection->setEndDateCollection($inputDTO->endDateCollection);
        $collection->setDel($inputDTO->del);
        $collection->setNomCollection($inputDTO->nomCollection);
        
        // On passe l'EM du tenant à la méthode privée.
        $randomPicture = $this->getRandomCollectionPicture($em);
        $collection->setPhotoCollections($randomPicture);
        
        $collection->setUserCollections($user);

        // On utilise l'EM du tenant pour persister les données.
        $em->persist($collection);
        $em->flush();

        return $collection;
    }

    /**
     * Liste filtrée et paginable (lot G5, 10/11/2026 ; sans filtre : ouvertes et fermées, les supprimées exclues depuis le lot G4).
     *
     * @return array{items: list<CollectionOutputDTO>, total: int}
     */
    public function getCollections(string $host, ?\App\Gestion\Services\GestionListQuery $query = null): array
    {
        $em = $this->emProvider->getEntityManager();
        $this->calendar?->ensurePeriods(); // périodes créées à la lecture, sans trou (lot P1, 10/11/2026)
        $result = $em->getRepository(Collections::class)->search($query?->status, $query?->q, $query?->page, $query?->limit, $query?->kind, $this->calendar?->today());
        $currency = $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT;

        return ['items' => array_map(fn($collection) => new CollectionOutputDTO($collection, $host, $currency, $this->calendar), $result['items']), 'total' => $result['total']];
    }

    /** Suppression logique (`del`, lot G4, 09/11/2026) : les commandes, notes de frais et statistiques restent en base, la collection disparaît des listes et du combiné */
    public function deleteCollection(Collections $collection): void
    {
        if ($collection->isPeriod()) {
            throw new \App\Gestion\Services\GestionConflictException('PERIOD_NOT_DELETABLE', 'Une période du calendrier ne se supprime pas (elle serait recréée à la lecture).'); // lot P1 (10/11/2026)
        }
        $em = $this->emProvider->getEntityManager();
        $collection->setDel(true);
        $em->persist($collection);
        $em->flush();
    }
    
    /**
     * MODIFICATION 4 : La méthode privée reçoit maintenant l'EntityManager en paramètre
     * pour s'assurer qu'elle utilise bien la connexion du tenant.
     */
    private function getRandomCollectionPicture(EntityManagerInterface $em): ?CollectionPicture
    {
        // Note: cette requête suppose que la table `collection_picture` existe dans chaque BDD de tenant.
        $sql = 'SELECT * FROM collection_picture ORDER BY RANDOM() LIMIT 1';
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CollectionPicture::class, 'cp');
        $rsm->addFieldResult('cp', 'id', 'id');
        $rsm->addFieldResult('cp', 'image_url', 'imageUrl');

        // On utilise l'EM qui a été passé en argument.
        return $em->createNativeQuery($sql, $rsm)
            ->getOneOrNullResult();
    }

    /**
     * Budget (cents) et, pour une collection manuelle, nom (lot P1, 10/11/2026 : PATCH /api/collections/{id}, PATCH /api/periodes/{id}) ;
     * refusé si fermée (409 PERIOD_CLOSED / COLLECTION_CLOSED).
     *
     * @param array<string, mixed> $fields champs validés présents seulement
     */
    public function updateCollection(Collections $collection, array $fields, \App\Gestion\Services\PeriodGuard $guard): Collections
    {
        $guard->assertCollectionWritable($collection);
        if (array_key_exists('budget', $fields)) {
            $collection->setBudgetCollection((int) $fields['budget']);
        }
        if (array_key_exists('nomCollection', $fields)) {
            if ($collection->isPeriod()) {
                throw new \App\Gestion\Services\GestionValidationException(['nomCollection' => 'NOT_APPLICABLE'], 'Le nom d\'une période vient du calendrier');
            }
            $collection->setNomCollection((string) $fields['nomCollection']);
        }
        $em = $this->emProvider->getEntityManager();
        $em->persist($collection);
        $em->flush();

        return $collection;
    }
}
