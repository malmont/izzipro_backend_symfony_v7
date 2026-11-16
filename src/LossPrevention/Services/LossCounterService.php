<?php

namespace App\LossPrevention\Services;

use App\Entity\User;
use App\LossPrevention\Entity\LossBox;
use App\LossPrevention\Entity\LossDevice;
use App\LossPrevention\Entity\LossPiece;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosTerminalService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Ce que la CAISSE demande au module avant un paiement (lot L3-b1, 16/11/2026, `docs/prevention-pertes.md` § 16.15) : parmi les
 * articles suivis du panier, lesquels n'ont pas été lus sur le tapis de comptoir de CE poste.
 *
 * - **Une lecture : le module n'écrit rien** (ni fait, ni constat, ni journal). Aucun code de caisse n'appelle le module pendant
 *   une vente (§ 1) : c'est la caisse qui pose la question, avant de payer, et elle fait ce qu'elle veut de la réponse. (La garde de
 *   la tablette note son dernier usage, comme sur toute route de caisse : ce n'est pas ce service.)
 * - **Elle ne bloque jamais la vente** : sans le module, sans tapis pour ce poste, tapis ou boîtier silencieux, horloge du boîtier
 *   déréglée, tablette non rattachée, trop de questions : la réponse est `active: false` avec sa raison, en 200. Pas de contrôle
 *   plutôt qu'un faux avis.
 * - **Un caissier ne lit que le tapis de SON poste.** Le poste se résout comme pour une vente (`CashRegisterResolver` : un caissier
 *   sur une tablette rattachée à un poste est refusé pour un autre), il doit avoir une session ouverte, et EN PLUS : sur un site à
 *   plusieurs postes, un caissier dont l'appel ne vient pas d'une tablette rattachée à un poste n'a pas de contrôle (on ne sait pas
 *   quel poste est le sien). Le responsable et l'administrateur lisent tous les postes, comme à la caisse.
 * - Seul le tapis du poste résolu est lu ; la réponse ne décrit que les articles du panier envoyé (ce qui est posé d'autre n'est
 *   qu'un nombre). Aucun nom de personne.
 * - Un article est SUIVI quand sa variante a au moins une vraie pièce en service qui porte une puce. Une pièce est LUE si elle
 *   est sur le tapis, ou l'a quitté depuis moins de `READ_WINDOW` secondes.
 */
class LossCounterService
{
    /** Une pièce qui a quitté le tapis depuis moins longtemps compte encore comme lue (secondes) */
    public const READ_WINDOW = 600;
    public const MAX_ITEMS = 200;
    public const REASONS = ['MODULE_INACTIVE', 'TERMINAL_NOT_BOUND', 'NO_MAT', 'MAT_SILENT', 'TOO_MANY_REQUESTS'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly LossSettingsService $settings,
        private readonly LossHealthService $health,
        private readonly PosTerminalService $terminals,
        private readonly Security $security,
        private readonly LossBoxLimits $limits,
        private readonly TenantConnectionProvider $tenant,
        private readonly ?LossSaleLinkService $sales = null,
    ) {
    }

    /**
     * `{ cashRegisterId?, items: [{ productVariantId, quantity }] }` → `{ active, reason, cashRegisterId, tracked, read, unread, extra, checkedAt }`.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws CashRegisterException poste refusé, introuvable ou fermé ; corps invalide
     */
    public function unread(array $body): array
    {
        $items = $this->items($body);
        $lines = count($body['items']);
        $registerId = $body['cashRegisterId'] ?? null;
        if ($registerId !== null && (!is_int($registerId) || $registerId < 1 || $registerId > 2147483647)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Panier invalide', 422, [['path' => 'cashRegisterId', 'message' => 'entier positif attendu']]);
        }
        $now = time();
        $answer = static fn (?int $register, bool $active, ?string $reason, int $tracked = 0, int $read = 0, array $unread = [], int $extra = 0): array => ['active' => $active, 'reason' => $reason,
            'cashRegisterId' => $register, 'tracked' => $tracked, 'read' => $read, 'unread' => $unread, 'extra' => $extra, 'checkedAt' => gmdate('Y-m-d\TH:i:s\+00:00', $now)];
        // sans le module, la caisse n'a rien d'autre à savoir : ni le poste ni le panier ne sont regardés plus loin
        if (!$this->settings->moduleGranted()) {
            return $answer($registerId, false, 'MODULE_INACTIVE');
        }
        $user = $this->security->getUser();
        // une question par paiement suffit très au large : au-delà d'un plafond de LIGNES de panier par compte, pas de contrôle (la
        // route ne sert pas à sonder le catalogue). Le compteur en panne ne gêne pas la caisse : la question passe
        try {
            $over = $user instanceof User && $this->limits->till((string) $this->tenant->getTenantCode(), (int) $user->getId(), $lines) !== null;
        } catch (\Throwable) {
            $over = false;
        }
        if ($over) {
            return $answer($registerId, false, 'TOO_MANY_REQUESTS');
        }
        // le poste se résout comme pour une vente, session ouverte comprise
        $register = $this->registers->resolve($registerId);
        if ($this->registers->openSession($register) === null) {
            throw new CashRegisterException('CASH_REGISTER_CLOSED', sprintf('Aucune session ouverte sur le poste « %s »', $register->getName()), 409);
        }
        $registerId = (int) $register->getId();
        $repository = $this->emProvider->getEntityManager()->getRepository(LossBox::class);
        // Un caissier ne lit que le tapis de SON poste : sa tablette doit être rattachée à ce poste (le résolveur a déjà refusé une
        // tablette rattachée à un autre). Sans rattachement, sur un site à plusieurs postes, on ne sait pas quel poste est le sien
        if (!($user instanceof User && PosApprovalService::isManager($user)) && ($this->terminals->current()['cashRegisterId'] ?? null) !== $registerId
            && $repository->activeRegisterCount() > 1) {
            return $answer($registerId, false, 'TERMINAL_NOT_BOUND');
        }
        $mat = $repository->matOfRegister($registerId);
        if ($mat === null) {
            return $answer($registerId, false, 'NO_MAT');
        }
        $boxSeen = $mat['box_seen'] !== null ? (new \DateTimeImmutable((string) $mat['box_seen'], new \DateTimeZone('UTC')))->getTimestamp() : null;
        // tapis en panne, boîtier silencieux, horloge du boîtier déréglée ou pas encore mesurée (ses faits ne posent alors rien sur le
        // tapis) : on ne sait pas ce qui est posé
        if ($mat['status'] !== LossDevice::OK || $mat['box_status'] !== LossBox::ACTIVE || $boxSeen === null || $now - $boxSeen > $this->health->silentAfter()
            || $mat['box_clock'] === null || abs((int) $mat['box_clock']) > LossBoxChannelService::CLOCK_TOLERANCE) {
            return $answer($registerId, false, 'MAT_SILENT');
        }
        $tracked = array_flip($repository->trackedVariants(array_keys($items)));
        // lues : posées en ce moment, ou parties depuis peu ; posées : encore sur le tapis
        $readByVariant = $presentByVariant = [];
        // la fenêtre du tapis est celle du site (réglable depuis le lot L3-b2a), la même que celle qui relie la vente aux pièces
        foreach ($repository->readOnMat((int) $mat['id'], gmdate('Y-m-d H:i:s', $now - ($this->sales?->windows()['counter'] ?? self::READ_WINDOW))) as $piece) {
            $readByVariant[$piece['variant']] = ($readByVariant[$piece['variant']] ?? 0) + 1;
            if ($piece['present']) {
                $presentByVariant[$piece['variant']] = ($presentByVariant[$piece['variant']] ?? 0) + 1;
            }
        }
        $unread = [];
        $trackedCount = $readCount = $usedPresent = 0;
        $missing = [];
        foreach ($items as $variantId => $quantity) {
            if (!isset($tracked[$variantId])) {
                continue; // article non suivi : rien à lire
            }
            $read = min($quantity, $readByVariant[$variantId] ?? 0);
            $trackedCount += $quantity;
            $readCount += $read;
            $usedPresent += min($quantity, $presentByVariant[$variantId] ?? 0);
            if ($read < $quantity) {
                $missing[$variantId] = $quantity - $read;
            }
        }
        if ($missing !== []) {
            $catalogue = $this->emProvider->getEntityManager()->getRepository(LossPiece::class)->variants(array_keys($missing));
            foreach ($missing as $variantId => $count) {
                $label = LossPieceService::catalogue($catalogue[$variantId] ?? null);
                $unread[] = ['productVariantId' => $variantId, 'name' => $label['name'], 'variantLabel' => $label['variantLabel'], 'quantity' => $items[$variantId], 'missing' => $count];
            }
        }

        // ce qui est ENCORE posé sur le tapis et n'est pas dans le panier : un nombre, sans dire quoi
        return $answer($registerId, true, null, $trackedCount, $readCount, $unread, array_sum($presentByVariant) - $usedPresent);
    }

    /**
     * Les lignes du panier, par variante (deux lignes de la même variante s'additionnent).
     *
     * @param array<string, mixed> $body
     *
     * @return array<int, int> quantité par variante
     */
    private function items(array $body): array
    {
        $errors = [];
        $items = $body['items'] ?? null;
        $out = [];
        if (!is_array($items) || !array_is_list($items)) {
            $errors[] = ['path' => 'items', 'message' => 'liste attendue'];
        } elseif (count($items) > self::MAX_ITEMS) {
            $errors[] = ['path' => 'items', 'message' => sprintf('%d lignes au plus', self::MAX_ITEMS)];
        } else {
            foreach ($items as $i => $item) {
                $variant = is_array($item) ? ($item['productVariantId'] ?? null) : null;
                $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
                if (!is_int($variant) || $variant < 1 || $variant > 2147483647) {
                    $errors[] = ['path' => "items[$i].productVariantId", 'message' => 'entier positif attendu'];
                } elseif (!is_int($quantity) || $quantity < 1 || $quantity > 9999) {
                    $errors[] = ['path' => "items[$i].quantity", 'message' => 'entier de 1 à 9999 attendu'];
                } else {
                    $out[$variant] = min(9999, ($out[$variant] ?? 0) + $quantity);
                }
            }
        }
        if ($errors !== []) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Panier invalide', 422, $errors);
        }

        return $out;
    }
}
