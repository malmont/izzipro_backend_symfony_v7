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
                $dsn = sprintf(
                    'smtp://%s:%s@%s:%d?encryption=%s',
                    urlencode($config->getSmtpUser()),
                    urlencode($config->getSmtpPassword()),
                    $config->getSmtpHost(),
                    $config->getSmtpPort() ?: 465,
                    $config->getSmtpEncryption() ?: 'ssl'
                );

                $transport = Transport::fromDsn($dsn);
                return new Mailer($transport);
            } catch (\Throwable $e) {
                $this->logger->error('Erreur lors de la création du transport SMTP dynamique pour le tenant : ' . $e->getMessage(), [
                    'exception' => $e
                ]);
            }
        }

        return $this->defaultMailer;
    }
}
