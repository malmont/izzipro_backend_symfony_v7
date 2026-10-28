<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Entity\PricingSetting;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\MemoiresVivantes\Repository\PricingSettingRepository;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tarifs du site (16/10/2026) : grille du livre imprimé par devise (premier exemplaire d'un livre, exemplaires
 * suivants du même livre, livraison comprise), produit « rédaction d'un chapitre » des stores. La grille ne
 * s'applique qu'aux commandes passées depuis l'application par le propriétaire d'un livre mobile ; un biographe
 * ou un administrateur commande comme avant (coût Lulu, pas de paiement). Un livre garde la devise de son premier
 * exemplaire.
 */
class PricingService implements ResetInterface
{
    /** Grille déjà lue, par base du site (28/10/2026 : l'EntityManager est le même objet pour tous les sites) ; vidée entre deux requêtes ou messages (kernel.reset) */
    private array $cache = [];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function settings(): PricingSetting
    {
        $key = (string) ($this->emProvider->getEntityManager()->getConnection()->getParams()['dbname'] ?? '');

        return $this->cache[$key] ??= ($this->repository()->current() ?? new PricingSetting());
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /** @param array<string, array{firstCopy: float, nextCopies: float}> $grid grille validée (PricingInputDto) */
    public function save(array $grid): PricingSetting
    {
        $em = $this->emProvider->getEntityManager();
        $settings = $this->settings()->setPrintGrid($grid);
        if ($settings->getId() === null) {
            $em->persist($settings);
        }
        $em->flush();
        $this->cache = [];

        return $settings;
    }

    public function toArray(?PricingSetting $settings = null): array
    {
        $settings ??= $this->settings();

        return [
            'print' => $settings->getPrintGrid(),
            'chapterProduct' => $settings->getChapterProduct(),
            'defaultCurrency' => $settings->defaultCurrency(),
            'allowedCurrencies' => PricingSetting::ALLOWED_CURRENCIES,
            'isDefault' => $settings->getId() === null,
            'updatedAt' => $settings->getId() === null ? null : $settings->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** La grille et le paiement Stripe s'appliquent : livre mobile, utilisateur connecté non administrateur */
    public function paymentRequired(?Book $book, ?UserInterface $user): bool
    {
        if ($book === null || !$user instanceof User || $book->getChannel() !== Book::CHANNEL_MOBILE) {
            return false;
        }

        return !in_array('ROLE_ADMIN', $user->getRoles(), true) && !in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    /** Devise fixée par le premier exemplaire facturé (payé ou en attente de paiement) ; null si aucun */
    public function bookCurrency(Book $book): ?string
    {
        foreach ($this->ordersOf($book) as $order) {
            if ($order->getChargedCurrency() !== null && in_array($order->getPaymentStatus(), ['pending', 'paid'], true)) {
                return $order->getChargedCurrency();
            }
        }

        return null;
    }

    /** Exemplaires déjà payés du livre (commandes de l'application réglées) */
    public function alreadyPrinted(Book $book): int
    {
        $count = 0;
        foreach ($this->ordersOf($book) as $order) {
            if ($order->getPaymentStatus() === 'paid') {
                $count += max(1, $order->getQuantity());
            }
        }

        return $count;
    }

    /**
     * Lignes de facturation d'une commande de n exemplaires
     *
     * @return array{quantity: int, currency: string, lines: list<array{copy: int, label: string, quantity: int, amount: float}>, total: float, alreadyPrinted: int}
     * @throws ApiErrorException CURRENCY_NOT_SUPPORTED (422)
     */
    public function estimate(Book $book, int $quantity, ?string $currency): array
    {
        $quantity = max(1, min(100, $quantity));
        $grid = $this->settings()->getPrintGrid();
        $currency = $this->bookCurrency($book) ?? strtolower(trim((string) ($currency ?: $this->settings()->defaultCurrency())));
        if (!isset($grid[$currency])) {
            throw new ApiErrorException('CURRENCY_NOT_SUPPORTED', sprintf('Devise non prise en charge : « %s » (%s).', $currency, implode(', ', array_keys($grid))), 422, ['currencies' => array_keys($grid)]);
        }
        $already = $this->alreadyPrinted($book);
        $lines = [];
        $total = 0.0;
        for ($i = 1; $i <= $quantity; $i++) {
            $first = $already + $i === 1;
            $amount = $first ? $grid[$currency]['firstCopy'] : $grid[$currency]['nextCopies'];
            $lines[] = ['copy' => $already + $i, 'label' => $first ? 'Premier exemplaire' : 'Exemplaire suivant', 'quantity' => 1, 'amount' => $amount];
            $total += $amount;
        }

        return ['quantity' => $quantity, 'currency' => $currency, 'lines' => $lines, 'total' => round($total, 2), 'alreadyPrinted' => $already];
    }

    /** @return list<BookPrintOrder> */
    private function ordersOf(Book $book): array
    {
        return $this->emProvider->getEntityManager()->getRepository(BookPrintOrder::class)->findBy(['book' => $book], ['createdAt' => 'ASC']);
    }

    private function repository(): PricingSettingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(PricingSetting::class);
    }
}
