<?php

namespace App\Message;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Courriel à envoyer par le worker « email » (09/10/2026). Le message est déjà composé dans la requête (gabarit rendu,
 * langue et domaine du site) : la file ne contient que l'e-mail final, jamais les identifiants SMTP. Le worker relit
 * la configuration d'envoi du site (configId) dans la base du site (tenantDb) ; configId null = serveur de la
 * plateforme (MAILER_DSN).
 */
final class SendTenantEmailMessage
{
    public function __construct(
        public readonly Email $email,
        public readonly Envelope $envelope,
        public readonly ?string $tenantCode,
        public readonly ?string $tenantDb,
        public readonly ?int $configId
    ) {
    }

    /** Pour les journaux : jamais le contenu */
    public function describe(): string
    {
        return sprintf('« %s » → %d destinataire(s), site %s, %s', mb_substr((string) $this->email->getSubject(), 0, 80),
            count($this->envelope->getRecipients()), $this->tenantCode ?? '—', $this->configId !== null ? 'serveur du site' : 'serveur de la plateforme');
    }
}
