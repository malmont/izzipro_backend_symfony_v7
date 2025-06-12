<?php
namespace App\Services\CaisseService;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse; 
use App\Services\TenantEntityManagerProvider; 
use DateTime;

class CaisseService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getOpenCaisse(): ?Caisse
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $caisseRepository = $em->getRepository(Caisse::class);

        return $caisseRepository->findOneBy(['isOpen' => true]);
    }

    public function getLastClosedCaisse(): ?Caisse
    {
        $em = $this->emProvider->getEntityManager();
        $caisseRepository = $em->getRepository(Caisse::class);

        return $caisseRepository->getLastClosedCaisse();
    }

    public function getCaisse(?int $days = null)
    {
        $em = $this->emProvider->getEntityManager();
        $caisseRepository = $em->getRepository(Caisse::class);

        if ($days) {
            $date = new \DateTime();
            $date->modify("-$days days");

            // On utilise la variable locale $caisseRepository
            return $caisseRepository->createQueryBuilder('o')
                ->where('o.createdAt >= :date')
                ->setParameter('date', $date)
                ->getQuery()
                ->getResult();
        }

        return $caisseRepository->findAll();
    }

    public function getTransactionsForOpenCaisse(): array
    {

        $openCaisse = $this->getOpenCaisse();
        if ($openCaisse) {

            $em = $this->emProvider->getEntityManager();
            $transactionCaisseRepository = $em->getRepository(TransactionCaisse::class);
            
            return $transactionCaisseRepository->findBy(['caisse' => $openCaisse]);
        }
        return [];
    }
}