<?php

namespace App\Services\NotificationService;

/** Aucun fournisseur d'envoi configuré (clés Firebase pas encore créées) : rien ne part ; le journal note `not_configured`. */
class NotConfiguredPushSender implements PushSenderInterface
{
    public function send(string $token, string $title, string $body, array $data): array
    {
        return ['status' => self::NOT_CONFIGURED, 'error' => null];
    }
}
