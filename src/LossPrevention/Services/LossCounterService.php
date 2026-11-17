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
use App\LossPrevention\Entity\LossCounterAlertDay;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
 * - **Alerte en caisse** (lot L5-a, 17/11/2026, § 18.2, « B ») : quand le gérant l'a allumée et qu'il manque des articles, la réponse
 *   porte `display: true`, le texte à montrer (`notice`) et une vérification (`checkId`, gardée `CHECK_TTL` secondes dans le pool des
 *   limites de débit, rien en base). Au paiement, la caisse rend la vérification (`after`) : le serveur ajoute UN aux compteurs du
 *   poste et du jour (montrée ; « continuer quand même »), une fois par vérification. Ni personne, ni vente, ni article ne sont gardés.
 */
class LossCounterService
{
    /** Une pièce qui a quitté le tapis depuis moins longtemps compte encore comme lue (secondes) */
    public const READ_WINDOW = 600;
    public const MAX_ITEMS = 200;
    public const REASONS = ['MODULE_INACTIVE', 'TERMINAL_NOT_BOUND', 'NO_MAT', 'MAT_SILENT', 'TOO_MANY_REQUESTS'];
    /** Durée de vie d'une vérification montrée en caisse, le temps d'un paiement (secondes) */
    public const CHECK_TTL = 900;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly LossSettingsService $settings,
        private readonly LossHealthService $health,
        private readonly PosTerminalService $terminals,
        private readonly Security $security,
        private readonly LossBoxLimits $limits,
        private readonly TenantConnectionProvider $tenant,
        #[Autowire(service: 'cache.rate_limiter')] private readonly CacheItemPoolInterface $checks,
        private readonly LossSite $site,
        private readonly LossTexts $texts,
        private readonly ?LossSaleLinkService $sales = null,
    ) {
    }

    /**
     * `{ cashRegisterId?, items: [{ productVariantId, quantity }] }` → `{ active, reason, cashRegisterId, tracked, read, unread, extra, checkedAt,
     * display, notice, checkId }` (les trois derniers : lot L5-a).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws CashRegisterException poste refusé, introuvable ou fermé ; corps invalide
     */
    public function unread(array $body, string $locale = 'fr'): array
    {
        $items = $this->items($body);
        $lines = count($body['items']);
        $registerId = $body['cashRegisterId'] ?? null;
        if ($registerId !== null && (!is_int($registerId) || $registerId < 1 || $registerId > 2147483647)) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Panier invalide', 422, [['path' => 'cashRegisterId', 'message' => 'entier positif attendu']]);
        }
        $now = time();
        $answer = static fn (?int $register, bool $active, ?string $reason, int $tracked = 0, int $read = 0, array $unread = [], int $extra = 0): array => ['active' => $active, 'reason' => $reason,
            'cashRegisterId' => $register, 'tracked' => $tracked, 'read' => $read, 'unread' => $unread, 'extra' => $extra, 'checkedAt' => gmdate('Y-m-d\TH:i:s\+00:00', $now),
            'display' => false, 'notice' => null, 'checkId' => null];
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
        $result = $answer($registerId, true, null, $trackedCount, $readCount, $unread, array_sum($presentByVariant) - $usedPresent);
        // lot L5-a : la caisse ne montre l'alerte que si le gérant l'a allumée, et seulement quand il manque des articles
        if ($unread !== [] && $this->settings->cashierAlertsOn()) {
            $checkId = bin2hex(random_bytes(16));
            $item = $this->checks->getItem($this->checkKey($checkId));
            $item->set($registerId)->expiresAfter(self::CHECK_TTL);
            if ($this->checks->save($item)) { // sans le pool, pas de vérification : l'alerte se montre quand même, elle ne se comptera pas
                $result['checkId'] = $checkId;
            }
            $missingTotal = array_sum($missing);
            $result['display'] = true;
            $result['notice'] = $this->texts->t($missingTotal > 1 ? 'counter.notice.many' : 'counter.notice.one', $locale, ['count' => $missingTotal]);
        }

        return $result;
    }

    /**
     * Lot L5-a : la caisse rend la vérification d'une alerte montrée, au paiement `{ checkId, proceeded }`. Une vérification ne compte
     * qu'UNE fois, pour le poste où elle a été montrée et le jour du magasin du paiement. Inconnue, expirée, déjà comptée, ou module
     * retiré : rien n'est compté, et la caisse n'en sait rien (204 dans tous ces cas : elle n'est jamais gênée).
     *
     * @param array<string, mixed> $body
     *
     * @throws CashRegisterException corps invalide (422)
     */
    public function after(array $body): void
    {
        $checkId = $body['checkId'] ?? null;
        $proceeded = $body['proceeded'] ?? null;
        $errors = [];
        if (!is_string($checkId) || preg_match('/^[0-9a-f]{32}$/', $checkId) !== 1) {
            $errors[] = ['path' => 'checkId', 'message' => 'vérification attendue (32 caractères hexadécimaux)'];
        }
        if (!is_bool($proceeded)) {
            $errors[] = ['path' => 'proceeded', 'message' => 'booléen attendu'];
        }
        if ($errors !== []) {
            throw new CashRegisterException('VALIDATION_FAILED', 'Suite d\'alerte invalide', 422, $errors);
        }
        if (!$this->settings->moduleGranted()) {
            return;
        }
        $key = $this->checkKey($checkId);
        $item = $this->checks->getItem($key);
        if (!$item->isHit() || !is_int($item->get())) {
            return;
        }
        // retirée AVANT de compter : une suite rejouée (réseau lent, double appui) ne compte pas deux fois
        $this->checks->deleteItem($key);
        $this->emProvider->getEntityManager()->getRepository(LossCounterAlertDay::class)
            ->bump($item->get(), $this->site->clock()->day(new \DateTimeImmutable()), $proceeded);
    }

    /** Clé d'une vérification : propre au site (le pool est commun à toute la plateforme) */
    private function checkKey(string $checkId): string
    {
        return 'loss_check.' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $this->tenant->getTenantCode()) . '.' . $checkId;
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
