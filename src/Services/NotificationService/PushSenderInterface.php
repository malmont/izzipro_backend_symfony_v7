<?php

namespace App\Services\NotificationService;

/**
 * Envoi d'une notification sur téléphone à un appareil (lot N1, 11/11/2026, `docs/notifications-gestion.md`). Aujourd'hui
 * `NotConfiguredPushSender` (aucun fournisseur : rien ne part, le journal le dit) ; demain une classe Firebase (FCM HTTP v1, qui relaie
 * vers APNs) derrière la même interface, dès que les clés du propriétaire existent. Tests : `Tests\Fake\FakePushSender`.
 */
interface PushSenderInterface
{
    public const SENT = 'sent';
    public const INVALID_TOKEN = 'invalid_token'; // jeton refusé par le fournisseur (appareil désinstallé) : l'appareil est désactivé
    public const FAILED = 'failed';
    public const NOT_CONFIGURED = 'not_configured';

    /**
     * @param array<string, string> $data charge utile lue par l'application (kind, screen, params en JSON)
     * @return array{status: string, error: ?string}
     */
    public function send(string $token, string $title, string $body, array $data): array;
}
