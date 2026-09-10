<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

class TenantMailerFactory
{
    public function __construct(
        private MailerInterface $defaultMailer,
        private LoggerInterface $logger
    ) {}

    public function createMailer(?EmailConfiguration $config): MailerInterface
    {
        if ($config && $config->getSmtpHost() && $config->getSmtpUser() && $config->getSmtpPassword()) {
            try {
                $port = (int) ($config->getSmtpPort() ?: 465);
                $encryption = strtolower((string) ($config->getSmtpEncryption() ?: 'ssl'));
                $scheme = ($port === 465 || $encryption === 'ssl') ? 'smtps' : 'smtp';

                $dsn = sprintf(
                    '%s://%s:%s@%s:%d',
                    $scheme,
                    urlencode($config->getSmtpUser()),
                    urlencode($config->getSmtpPassword()),
                    $config->getSmtpHost(),
                    $port
                );

                $this->logger->info(sprintf(
                    '[TenantMailerFactory] Création Mailer SMTP dédié tenant : %s via %s:%d (%s)',
                    $config->getSmtpUser(),
                    $config->getSmtpHost(),
                    $port,
                    $scheme
                ));

                $transport = Transport::fromDsn($dsn);
                return new Mailer($transport);
            } catch (\Throwable $e) {
                $this->logger->error('Erreur lors de la création du transport SMTP dynamique pour le tenant : ' . $e->getMessage(), [
                    'exception' => $e
                ]);
            }
        } else {
            $this->logger->warning('[TenantMailerFactory] EmailConfiguration incomplète pour SMTP dédié, repli sur defaultMailer.');
        }

        return $this->defaultMailer;
    }
}
