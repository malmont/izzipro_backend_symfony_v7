<?php
namespace App\Controller\CaisseController;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Repository\TransactionTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CaisseController extends AbstractController
{
    private $em;
    private $transactionTypeRepository;

    public function __construct(EntityManagerInterface $em, TransactionTypeRepository $transactionTypeRepository)
    {
        $this->em = $em;
        $this->transactionTypeRepository = $transactionTypeRepository;
    }

    /**
     * @Route("/caisse/open", name="caisse_open", methods={"POST"})
     */
    public function openCaisse(): JsonResponse
    {
        $existingCaisse = $this->getOpenCaisse();
        if ($existingCaisse) {
            return new JsonResponse(['error' => 'A caisse is already open'], 400);
        }

        $caisse = new Caisse();
        $caisse->setAmountTotal(0.0);
        $caisse->setCreatedAt(new \DateTime());
        $caisse->setIsOpen(true);

        $this->em->persist($caisse);

        // Créer une transaction d'ouverture (ID de transaction type: 4 pour Ouverture)
        $this->createTransactionCaisse($caisse, 4, 0.0);

        $this->em->flush();

        return new JsonResponse(['message' => 'Caisse opened successfully', 'caisse_id' => $caisse->getId()], 201);
    }

    /**
     * @Route("/caisse/close", name="caisse_close", methods={"POST"})
     */
    public function closeCaisse(): JsonResponse
    {
        $caisse = $this->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        // Mettre à jour la caisse pour la fermer
        $caisse->setIsOpen(false);
        $caisse->setClosedAt(new \DateTime());

        $this->em->persist($caisse);

        // Créer une transaction de fermeture (ID de transaction type: 5 pour Fermeture)
        $this->createTransactionCaisse($caisse, 5, $caisse->getAmountTotal());

        $this->em->flush();

        return new JsonResponse(['message' => 'Caisse closed successfully', 'caisse_id' => $caisse->getId()], 200);
    }

    /**
     * @Route("/caisse/deposit", name="caisse_deposit", methods={"POST"})
     */
    public function deposit(Request $request): JsonResponse
    {
        $amount = $request->request->get('amount');
        if ($amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        // Mettre à jour le montant total de la caisse
        $caisse->setAmountTotal($caisse->getAmountTotal() + $amount);
        $this->em->persist($caisse);

        // Créer une transaction de dépôt (ID de transaction type: 4 pour Dépôt)
        $this->createTransactionCaisse($caisse, 4, $amount);

        $this->em->flush();

        return new JsonResponse(['message' => 'Deposit successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * @Route("/caisse/withdraw", name="caisse_withdraw", methods={"POST"})
     */
    public function withdraw(Request $request): JsonResponse
    {
        $amount = $request->request->get('amount');
        if ($amount <= 0) {
            return new JsonResponse(['error' => 'Invalid amount'], 400);
        }

        $caisse = $this->getOpenCaisse();
        if (!$caisse) {
            return new JsonResponse(['error' => 'No open caisse found'], 400);
        }

        if ($caisse->getAmountTotal() < $amount) {
            return new JsonResponse(['error' => 'Insufficient funds in the caisse'], 400);
        }

        // Mettre à jour le montant total de la caisse
        $caisse->setAmountTotal($caisse->getAmountTotal() - $amount);
        $this->em->persist($caisse);

        // Créer une transaction de retrait (ID de transaction type: 5 pour Retrait)
        $this->createTransactionCaisse($caisse, 5, $amount);

        $this->em->flush();

        return new JsonResponse(['message' => 'Withdrawal successful', 'new_total' => $caisse->getAmountTotal()], 201);
    }

    /**
     * Méthode générique pour créer une transaction de caisse.
     *
     * @param Caisse $caisse
     * @param int $transactionTypeId
     * @param float $amount
     */
    private function createTransactionCaisse(Caisse $caisse, int $transactionTypeId, float $amount): void
    {
        $transactionType = $this->transactionTypeRepository->find($transactionTypeId);
        if (!$transactionType) {
            throw new \InvalidArgumentException("Invalid transaction type ID: $transactionTypeId");
        }

        $transactionCaisse = new TransactionCaisse();
        $transactionCaisse->setCaisse($caisse);
        $transactionCaisse->setUser($this->getUser());
        $transactionCaisse->setTransactionDate(new \DateTime());
        $transactionCaisse->setTransactionType($transactionType);
        $transactionCaisse->setAmount($amount);

        $this->em->persist($transactionCaisse);
    }

    /**
     * Méthode pour obtenir la caisse ouverte.
     *
     * @return Caisse|null
     */
    private function getOpenCaisse(): ?Caisse
    {
        return $this->em->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }
}
