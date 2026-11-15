<?php

namespace App\LossPrevention\Controller;

use App\LossPrevention\LossBoxGuardSubscriber;
use App\LossPrevention\Services\LossBoxChannelService;
use App\LossPrevention\Services\LossBoxException;
use App\LossPrevention\Services\LossBoxToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes appelées par le BOÎTIER de la boutique (lot L3-a, 15/11/2026, `docs/prevention-pertes.md` § 16), jamais par une personne.
 * Hors de `/api/gestion` : fermées par `LossBoxGuardSubscriber` (jeton `X-Loss-Box`, sauf l'inscription et l'échange de jeton, qui
 * vérifient la clé ou le jeton eux-mêmes), limitées en débit, fermées sans le droit du module (403 `MODULE_INACTIVE`). Le boîtier
 * ne lit ici que ses propres réglages : aucune vente, aucun client, aucun article, aucun constat.
 */
#[Route('/api/pertes/boitier')]
class LossBoxChannelController extends AbstractController
{
    /** Corps accepté au plus (octets) : 200 faits tiennent très au large */
    private const MAX_BODY = 262144;

    public function __construct(private readonly LossBoxChannelService $channel)
    {
    }

    /** Inscription d'une machine `{ key, machine, version, arch }` → 200 { box, token, expiresAt, serverTime, settings } ; 404 BOX_KEY_INVALID, 403 BOX_REVOKED, 409 BOX_KEY_ALREADY_ENROLLED */
    #[Route('/inscription', name: 'pertes_boitier_inscription', methods: ['POST'])]
    public function enrol(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->channel->enrol($this->body($request)));
    }

    /** Échange d'un jeton (même expiré depuis moins de 30 jours) `{ machine }` → 200 { token, expiresAt, serverTime } ; 401 BOX_TOKEN_INVALID, 403 BOX_REVOKED */
    #[Route('/jeton', name: 'pertes_boitier_jeton', methods: ['POST'])]
    public function renew(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->channel->renew($request->headers->get(LossBoxToken::HEADER), $this->body($request)));
    }

    /** Signe de vie `{ now, version?, devices }` → 200 { serverTime, clockOffsetSeconds, clockDoubt, token, settings } */
    #[Route('/signe-de-vie', name: 'pertes_boitier_signe_de_vie', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->channel->heartbeat($this->box($request), $this->body($request)));
    }

    /** Réglages du boîtier → 200 { box, serverTime, heartbeatSeconds, facts, zones, devices } ; une lecture */
    #[Route('/reglages', name: 'pertes_boitier_reglages', methods: ['GET'])]
    public function settings(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->channel->settings($this->box($request)));
    }

    /** Lot de faits `{ facts }` (1 à 200) → 200 { received, accepted, duplicates, refused, results, serverTime } ; rejouable */
    #[Route('/faits', name: 'pertes_boitier_faits', methods: ['POST'])]
    public function facts(Request $request): JsonResponse
    {
        return $this->answer(fn (): array => $this->channel->facts($this->box($request), $this->body($request)));
    }

    /** @param callable(): array<string, mixed> $work */
    private function answer(callable $work): JsonResponse
    {
        try {
            return $this->json($work());
        } catch (LossBoxException $e) {
            return $this->json($e->body(), $e->status);
        }
    }

    /**
     * Le boîtier authentifié par la garde. Sans lui, rien : une route du boîtier ne travaille jamais pour un inconnu, même si la
     * garde venait à manquer.
     *
     * @return array<string, mixed>
     */
    private function box(Request $request): array
    {
        $box = $request->attributes->get(LossBoxGuardSubscriber::ATTRIBUTE);
        if (!is_array($box) || !isset($box['id'], $box['generation'], $box['token_expires_at'])) {
            throw new LossBoxException('BOX_TOKEN_REQUIRED', 'En-tête ' . LossBoxToken::HEADER . ' requis.', 401);
        }

        return $box;
    }

    /** @return array<string, mixed> corps JSON ; un corps vide vaut {} */
    private function body(Request $request): array
    {
        $content = (string) $request->getContent();
        if (strlen($content) > self::MAX_BODY) {
            throw new LossBoxException('PAYLOAD_TOO_LARGE', 'Corps de la requête trop grand.', 413, ['maxBytes' => self::MAX_BODY]);
        }
        $body = trim($content) === '' ? [] : json_decode($content, true);
        if (!is_array($body)) {
            throw new LossBoxException('VALIDATION_FAILED', 'Corps JSON invalide.', 422, ['fields' => ['body' => 'INVALID_JSON']]);
        }

        return $body;
    }
}
