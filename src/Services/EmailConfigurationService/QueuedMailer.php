<?php

namespace App\Services\EmailConfigurationService;

use App\Message\SendTenantEmailMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Mailer rendu par TenantMailerFactory : compose le courriel dans la requête (gabarit Twig rendu, contexte retiré) puis
 * le confie au worker « email » ; la requête n'attend plus le serveur SMTP. File injoignable (Redis arrêté) : envoi
 * immédiat, comme avant. Un message qui n'est pas un Email (RawMessage) part aussi tout de suite.
 */
final class QueuedMailer implements MailerInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly BodyRendererInterface $renderer,
        private readonly TenantEmailDelivery $delivery,
        private readonly LoggerInterface $logger,
        private readonly ?string $tenantCode,
        private readonly ?string $tenantDb,
        private readonly ?int $configId
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (!$message instanceof Email) {
            $this->delivery->deliverRaw($message, $envelope, $this->tenantCode, $this->tenantDb, $this->configId);

            return;
        }
        $this->renderer->render($message); // TemplatedEmail : gabarit rendu ici, dans le contexte du site
        $email = self::plain($message);
        $queued = new SendTenantEmailMessage($email, $envelope ?? Envelope::create($email), $this->tenantCode, $this->tenantDb, $this->configId);
        try {
            $this->bus->dispatch($queued);
        } catch (\Throwable $e) {
            $this->logger->warning('[Email] File des courriels injoignable : envoi immédiat. ' . $e->getMessage());
            $this->delivery->deliver($queued);
        }
    }

    /**
     * Copie sans gabarit ni contexte (un TemplatedEmail garde ses données, entités comprises, qui ne se mettent pas en
     * file) : en-têtes, corps HTML et texte, pièces jointes et images intégrées.
     */
    public static function plain(Email $source): Email
    {
        $email = new Email();
        $email->setHeaders(clone $source->getHeaders());
        $html = $source->getHtmlBody();
        if ($html !== null) {
            $email->html(is_resource($html) ? (string) stream_get_contents($html) : $html, $source->getHtmlCharset() ?? 'utf-8');
        }
        $text = $source->getTextBody();
        if ($text !== null) {
            $email->text(is_resource($text) ? (string) stream_get_contents($text) : $text, $source->getTextCharset() ?? 'utf-8');
        }
        foreach ($source->getAttachments() as $part) {
            $email->addPart($part);
        }

        return $email;
    }
}
