<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\AiUsage;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Repository\UserActivityStatsRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Console d'administration : ce que font les utilisateurs de l'application mobile (16/10/2026). Assemble, pour une
 * page d'utilisateurs, les livres et chapitres, les paiements (livre payé par lien Stripe, commandes d'impression ;
 * les paiements par chapitre viendront avec l'achat intégré), les appels IA (cumul depuis le 16/10/2026, jour en
 * cours, reste des quotas) et la dernière activité. Crédits : aucun modèle par utilisateur n'existe (null).
 * Montants en unités avec la devise, dates ISO 8601 dans le fuseau du site.
 */
class MobileUserStatsService
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;
    public const HISTORY_DAYS = 30;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserActivityStatsRepository $stats,
        private readonly AiQuotaService $quotas,
        private readonly PricingService $pricing
    ) {}

    /** GET /admin/mobile-users */
    public function list(string $search, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        ['ids' => $ids, 'total' => $total] = $this->stats->mobileUserIds(trim($search), $page, $perPage);
        $users = $ids === [] ? [] : $this->emProvider->getEntityManager()->getRepository(User::class)->findBy(['id' => $ids]);
        usort($users, fn (User $a, User $b) => $b->getId() <=> $a->getId());
        [$from, $to] = $this->quotas->dayWindow();
        $settings = $this->quotas->settings();

        return [
            'date' => $from->setTimezone($this->quotas->timezone())->format('Y-m-d'),
            'timezone' => $this->quotas->timezone()->getName(),
            'limits' => ['transcriptionsPerDay' => $settings->getTranscriptionsPerDay(), 'improvementsPerDay' => $settings->getImprovementsPerDay()],
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
            'users' => array_map(fn (User $u) => $this->flatten($this->summary($u)), $users),
        ];
    }

    /** GET /admin/mobile-users/{userId} ; null si l'utilisateur n'existe pas ou n'est pas de la population mobile */
    public function detail(int $userId): ?array
    {
        $em = $this->emProvider->getEntityManager();
        $user = $em->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            return null;
        }
        $bookStats = $this->stats->bookStats([$userId])[$userId] ?? null;
        if (UserPopulationResolver::resolve($user, $bookStats['mobileBooks'] ?? 0) !== UserPopulationResolver::MOBILE) {
            return null;
        }
        $summary = $this->summary($user);
        $summary['user']['roles'] = $user->getRoles();
        $summary['user']['population'] = UserPopulationResolver::MOBILE;

        /** @var \App\MemoiresVivantes\Repository\BookRepository $books */
        $books = $em->getRepository(Book::class);
        $bookList = [];
        $payments = [];
        foreach ($books->findWithChaptersAndPhotosByUser($user) as $book) {
            $bookList[] = [
                'id' => (string) $book->getId(), 'title' => $book->getTitle(), 'type' => $book->getType(), 'channel' => $book->getChannel(),
                'createdAt' => $this->iso($book->getCreatedAt()), 'paymentStatus' => $book->getPaymentStatus(),
                'chapters' => array_map(fn ($c) => [
                    'id' => (string) $c->getId(), 'title' => $c->getTitle(), 'position' => $c->getPosition(), 'generationStatus' => $c->getGenerationStatus(),
                    'hasText' => trim((string) ($c->getContentFinal() ?: $c->getContentGenerated())) !== '', 'updatedAt' => $this->iso($c->getUpdatedAt()),
                    'paid' => $c->getPaid(), 'hiddenAfterRefund' => $c->isHiddenAfterRefund(),
                ], $book->getChapters()->toArray()),
            ];
            if ($book->getPaymentStatus() === 'paid') {
                $payments[] = ['id' => (string) $book->getId(), 'amount' => $book->getPaymentAmount(), 'currency' => strtolower((string) ($book->getPaymentCurrency() ?: 'eur')), 'status' => 'paid', 'paidAt' => $this->iso($book->getPaidAt()),
                    'object' => ['kind' => 'book', 'id' => (string) $book->getId(), 'title' => $book->getTitle()]];
            }
        }
        foreach ($em->getRepository(BookPrintOrder::class)->findBy(['user' => $user], ['createdAt' => 'DESC']) as $order) {
            // Commande de l'application : montant facturé et état du paiement Stripe ; commande web : coût Lulu, état Lulu
            $charged = $order->getChargedAmount();
            $payments[] = ['id' => (string) $order->getId(), 'amount' => $charged ?? ($order->getTotalCost() !== null ? round((float) $order->getTotalCost(), 2) : null),
                'currency' => $order->getChargedCurrency() ?? strtolower($order->getCurrency() ?: 'eur'), 'status' => $order->getPaymentStatus() ?? $order->getStatus(), 'paidAt' => $this->iso($order->getCreatedAt()),
                'refundedAt' => $this->iso($order->getRefundedAt()), 'refundedAmount' => $order->getRefundedAmount(), 'store' => $order->getPaymentStatus() !== null ? 'stripe' : null, 'paymentRef' => $order->getStripePaymentIntentId(),
                'object' => ['kind' => 'print', 'id' => $order->getBook() ? (string) $order->getBook()->getId() : null, 'title' => $order->getBook()?->getTitle()]];
        }
        // Achats de rédaction de chapitre (RevenueCat), remboursés compris
        $chapterTitles = [];
        foreach ($bookList as $b) {
            foreach ($b['chapters'] as $c) {
                $chapterTitles[$c['id']] = $c['title'];
            }
        }
        foreach ($em->getRepository(\App\MemoiresVivantes\Entity\ChapterPayment::class)->findForUser($user) as $purchase) {
            $payments[] = ['id' => 'chapter-payment-' . $purchase->getId(), 'amount' => $purchase->getAmount(), 'currency' => $purchase->getCurrency(), 'status' => $purchase->getStatus(),
                'paidAt' => $this->iso($purchase->getAssignedAt() ?? $purchase->getPurchasedAt() ?? $purchase->getCreatedAt()),
                'refundedAt' => $this->iso($purchase->getRefundedAt()), 'refundedAmount' => $purchase->getRefundedAmount(),
                'paymentRef' => $purchase->reference(), 'store' => $purchase->getStore(), 'environment' => $purchase->getEnvironment(),
                'object' => ['kind' => 'chapter', 'id' => $purchase->getChapterId() ? (string) $purchase->getChapterId() : null, 'title' => $purchase->getChapterId() ? ($chapterTitles[(string) $purchase->getChapterId()] ?? null) : null]];
        }
        usort($payments, fn ($a, $b) => strcmp((string) $b['paidAt'], (string) $a['paidAt']));
        $summary['ai']['history'] = $this->stats->aiHistory($userId, $this->quotas->timezone()->getName(), self::HISTORY_DAYS);

        return [
            'user' => $summary['user'],
            'books' => $bookList,
            'payments' => $payments,
            'credits' => null,
            'ai' => $summary['ai'],
        ];
    }

    /**
     * Champs ajoutés à chaque utilisateur de GET /admin/users : population, origine, création, dernière activité,
     * nombre de livres mobile
     *
     * @param list<User> $users
     * @return array<int, array{population: string, signupChannel: ?string, createdAt: ?string, lastActivityAt: ?string, mobileBooksCount: int}>
     */
    public function enrichment(array $users): array
    {
        $ids = array_map(fn (User $u) => (int) $u->getId(), $users);
        $bookStats = $this->stats->bookStats($ids);
        $aiTotals = $this->stats->aiTotals($ids);
        $out = [];
        foreach ($users as $user) {
            $id = (int) $user->getId();
            $mobile = $bookStats[$id]['mobileBooks'] ?? 0;
            $out[$id] = [
                'population' => UserPopulationResolver::resolve($user, $mobile),
                'signupChannel' => $user->getSignupChannel(),
                'createdAt' => $this->iso($user->getCreatedAt()),
                'lastActivityAt' => $this->lastActivity($user, $bookStats[$id]['lastActivity'] ?? null, $aiTotals[$id]['lastCallAt'] ?? null),
                'mobileBooksCount' => $mobile,
            ];
        }

        return $out;
    }

    /** Ligne d'un utilisateur dans la liste (et socle du détail) */
    private function summary(User $user): array
    {
        $id = (int) $user->getId();
        $bookStats = $this->stats->bookStats([$id])[$id] ?? ['books' => 0, 'mobileBooks' => 0, 'chaptersTotal' => 0, 'chaptersWritten' => 0, 'chaptersPaid' => 0, 'lastActivity' => null];
        $payments = $this->stats->paymentStats([$id])[$id] ?? ['count' => 0, 'totals' => [], 'lastPaidAt' => null];
        $defaultCurrency = $this->pricing->settings()->defaultCurrency();
        $defaultTotal = 0.0;
        foreach ($payments['totals'] as $t) {
            if ($t['currency'] === $defaultCurrency) {
                $defaultTotal = $t['amount'];
            }
        }
        $totals = $this->stats->aiTotals([$id])[$id] ?? ['kinds' => [], 'lastCallAt' => null];
        [$from, $to] = $this->quotas->dayWindow();
        $today = $this->stats->aiTotals([$id], $from, $to)[$id]['kinds'] ?? [];
        $settings = $this->quotas->settings();
        $remaining = fn (string $kind) => $settings->limitFor($kind) === null ? null : max(0, $settings->limitFor($kind) - ($today[$kind] ?? 0));

        return [
            'user' => [
                'userId' => $id, 'email' => $user->getEmail(), 'name' => trim($user->getFirstname() . ' ' . $user->getLastname()),
                'signupChannel' => $user->getSignupChannel(), 'createdAt' => $this->iso($user->getCreatedAt()),
                'lastActivityAt' => $this->lastActivity($user, $bookStats['lastActivity'], $totals['lastCallAt']),
            ],
            'books' => ['count' => $bookStats['books'], 'mobileCount' => $bookStats['mobileBooks'], 'chaptersTotal' => $bookStats['chaptersTotal'], 'chaptersWritten' => $bookStats['chaptersWritten'], 'chaptersPaid' => $bookStats['chaptersPaid'] ?? 0],
            // totalAmount et currency : repli (total de la devise par défaut du site) ; la vérité est dans totals, par devise
            'payments' => ['count' => $payments['count'], 'totalAmount' => $defaultTotal, 'currency' => $defaultCurrency, 'totals' => $payments['totals'], 'lastPaidAt' => $this->iso($payments['lastPaidAt'])],
            'credits' => null,
            'ai' => [
                'transcriptionsToday' => $today[AiUsage::KIND_TRANSCRIPTION] ?? 0, 'improvementsToday' => $today[AiUsage::KIND_IMPROVEMENT] ?? 0,
                'transcriptionsTotal' => $totals['kinds'][AiUsage::KIND_TRANSCRIPTION] ?? 0, 'improvementsTotal' => $totals['kinds'][AiUsage::KIND_IMPROVEMENT] ?? 0,
                'generationsTotal' => $totals['kinds'][AiUsage::KIND_GENERATION] ?? 0,
                'lastCallAt' => $this->iso($totals['lastCallAt']),
                'remaining' => ['transcriptions' => $remaining(AiUsage::KIND_TRANSCRIPTION), 'improvements' => $remaining(AiUsage::KIND_IMPROVEMENT)],
            ],
        ];
    }

    /** Liste : objet utilisateur à plat comme dans le contrat (userId, email, name, …) */
    public function flatten(array $summary): array
    {
        return $summary['user'] + ['books' => $summary['books'], 'payments' => $summary['payments'], 'credits' => $summary['credits'], 'ai' => $summary['ai']];
    }

    private function lastActivity(User $user, ?string $books, ?string $ai): ?string
    {
        $candidates = array_filter([$user->getLastLoginAt()?->format('Y-m-d H:i:s'), $books, $ai]);

        return $candidates === [] ? null : $this->iso(max($candidates));
    }

    /** Date de la base (UTC, texte) ou objet → ISO 8601 dans le fuseau du site */
    private function iso(\DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

        return $date->setTimezone($this->quotas->timezone())->format(\DateTimeInterface::ATOM);
    }
}
