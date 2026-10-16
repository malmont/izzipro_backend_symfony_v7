<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterPayment;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\MemoiresVivantes\Repository\ChapterPaymentRepository;
use App\MemoiresVivantes\Services\RevenueCat\RevenueCatClient;
use App\MemoiresVivantes\Services\RevenueCat\RevenueCatClientInterface;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rédaction payante par chapitre (16/10/2026, livres de l'application mobile). Un achat consommable dans les
 * stores (RevenueCat) = une ligne ChapterPayment, affectée à un chapitre au déblocage ; le chapitre porte une copie
 * du paiement (Chapter::markPaid). Identifiant RevenueCat de l'utilisateur : "<code du site>:<userId>".
 * Qui paie : le propriétaire connecté non administrateur d'un livre mobile ; jamais un administrateur, jamais
 * un livre web. Un remboursement (webhook) masque le texte, un nouvel achat le rétablit.
 */
class ChapterPaymentService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenants,
        private readonly RevenueCatClientInterface $revenueCat,
        private readonly PricingService $pricing,
        private readonly LoggerInterface $logger
    ) {}

    public function appUserId(User $user): string
    {
        return ($this->tenants->getTenantCode() ?? 'site') . ':' . $user->getId();
    }

    /** La rédaction de ce chapitre exige un achat non encore fait (402 CHAPTER_NOT_PAID) */
    public function requiresPayment(?Chapter $chapter, ?UserInterface $user): bool
    {
        if ($chapter === null || !$user instanceof User) {
            return false;
        }

        return $this->pricing->paymentRequired($chapter->getBook(), $user) && !$chapter->isPaid();
    }

    /**
     * Déblocage d'un chapitre par une transaction de store
     *
     * @throws ApiErrorException BOOK_NOT_MOBILE (422), PURCHASE_NOT_FOUND (404), PURCHASE_ALREADY_USED (409),
     *                           PURCHASE_REFUNDED (409), CHAPTER_ALREADY_PAID (409), WRONG_PRODUCT (422)
     */
    public function unlock(Chapter $chapter, User $user, string $store, string $transactionId): Chapter
    {
        $book = $chapter->getBook();
        if ($book === null || $book->getChannel() !== Book::CHANNEL_MOBILE) {
            throw new ApiErrorException('BOOK_NOT_MOBILE', 'Seuls les chapitres des livres de l\'application se débloquent par un achat.', 422);
        }
        $store = RevenueCatClient::normalizeStore($store);
        $transactionId = trim($transactionId);
        if (!in_array($store, ChapterPayment::STORES, true) || $transactionId === '') {
            throw new ApiErrorException('PURCHASE_NOT_FOUND', 'Transaction inconnue : indiquez transactionId et store (app_store ou play_store).', 404);
        }
        $payment = $this->repository()->findByTransaction($store, $transactionId);
        if ($payment === null) {
            $this->sync($user);
            $payment = $this->repository()->findByTransaction($store, $transactionId);
        }
        if ($payment === null || $payment->getUser()->getId() !== $user->getId()) {
            throw new ApiErrorException('PURCHASE_NOT_FOUND', 'Achat introuvable. Réessayez dans quelques secondes : le store peut mettre un moment à le confirmer.', 404);
        }
        if ($payment->isRefunded()) {
            throw new ApiErrorException('PURCHASE_REFUNDED', 'Cet achat a été remboursé : il ne peut plus débloquer de chapitre.', 409);
        }
        if ($payment->isAssigned()) {
            if ((string) $payment->getChapterId() === (string) $chapter->getId()) {
                return $chapter; // même transaction, même chapitre : déjà fait
            }
            throw new ApiErrorException('PURCHASE_ALREADY_USED', 'Cet achat a déjà débloqué un autre chapitre.', 409);
        }
        if ($payment->getProductId() !== $this->pricing->settings()->getChapterProduct()) {
            throw new ApiErrorException('WRONG_PRODUCT', 'Cet achat ne correspond pas à la rédaction d\'un chapitre.', 422);
        }
        if ($chapter->isPaid()) {
            throw new ApiErrorException('CHAPTER_ALREADY_PAID', 'Ce chapitre est déjà payé : choisissez-en un autre pour cet achat.', 409);
        }
        $payment->assignTo($chapter);
        $chapter->markPaid($payment->getAmount(), $payment->getCurrency(), $payment->reference(), $payment->getPurchasedAt());
        $this->emProvider->getEntityManager()->flush();

        return $chapter;
    }

    /** Achats RevenueCat de l'utilisateur pas encore connus : enregistrés (non affectés). Rend le nombre d'ajouts. */
    public function sync(User $user): int
    {
        try {
            $purchases = $this->revenueCat->purchases($this->appUserId($user));
        } catch (\RuntimeException $e) {
            $this->logger->warning('RevenueCat : synchronisation impossible : ' . $e->getMessage());

            return 0;
        }
        $product = $this->pricing->settings()->getChapterProduct();
        $added = 0;
        foreach ($purchases as $p) {
            if ($p['productId'] !== $product || !in_array($p['store'], ChapterPayment::STORES, true)) {
                continue;
            }
            $at = null;
            try {
                $at = $p['purchasedAt'] ? new \DateTimeImmutable($p['purchasedAt']) : null;
            } catch (\Throwable) {
            }
            if ($this->recordPurchase($user, $p['store'], $p['productId'], $p['transactionId'], $p['amount'], $p['currency'], $at, false, $p['environment'] ?? null)) {
                $added++;
            }
        }
        if ($added > 0) {
            $this->emProvider->getEntityManager()->flush();
        }

        return $added;
    }

    /** Enregistre un achat s'il est inconnu (idempotent) ; rend vrai s'il a été créé */
    public function recordPurchase(User $user, string $store, string $productId, string $transactionId, ?float $amount, ?string $currency, ?\DateTimeImmutable $purchasedAt, bool $flush = true, ?string $environment = null): bool
    {
        $store = RevenueCatClient::normalizeStore($store);
        if (!in_array($store, ChapterPayment::STORES, true) || trim($transactionId) === '') {
            return false;
        }
        if ($this->repository()->findByTransaction($store, trim($transactionId)) !== null) {
            return false;
        }
        $payment = (new ChapterPayment($user, $store, $productId, trim($transactionId)))->setAmount($amount)->setCurrency($currency)->setPurchasedAt($purchasedAt ?? new \DateTimeImmutable())->setEnvironment($environment);
        $em = $this->emProvider->getEntityManager();
        $em->persist($payment);
        if ($flush) {
            $em->flush();
        }

        return true;
    }

    /** @return list<ChapterPayment> achats réglés non affectés, après synchronisation avec RevenueCat */
    public function unassigned(User $user): array
    {
        $this->sync($user);

        return $this->repository()->findUnassigned($user);
    }

    /** Remboursement rapporté par le store : la ligne passe en « refunded », le chapitre affecté repasse non payé, texte masqué */
    public function refund(string $store, string $transactionId, ?float $amount = null): ?ChapterPayment
    {
        $payment = $this->repository()->findByTransaction(RevenueCatClient::normalizeStore($store), trim($transactionId));
        if ($payment === null) {
            return null;
        }
        if (!$payment->isRefunded()) {
            $payment->markRefunded($amount);
            if ($payment->getChapterId() !== null) {
                $chapter = $this->emProvider->getEntityManager()->getRepository(Chapter::class)->find($payment->getChapterId());
                if ($chapter instanceof Chapter && $chapter->getPaymentRefMatches($payment->reference())) {
                    $chapter->markRefunded();
                }
            }
            $this->emProvider->getEntityManager()->flush();
        }

        return $payment;
    }

    private function repository(): ChapterPaymentRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(ChapterPayment::class);
    }
}
