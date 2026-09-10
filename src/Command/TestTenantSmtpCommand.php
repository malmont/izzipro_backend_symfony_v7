<?php

namespace App\Command;

use App\Entity\EmailConfiguration;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

#[AsCommand(
    name: 'app:test-tenant-smtp',
    description: 'Teste l\'envoi d\'email pour un tenant (Utilise le MAILER_DSN du .env + le From de la BDD).',
)]
class TestTenantSmtpCommand extends Command
{
    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private \App\Services\EmailConfigurationService\TenantMailerFactory $mailerFactory,
        private MailerInterface $defaultMailer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tenantCode', InputArgument::REQUIRED, 'Le CODE du tenant (ex: lintendantprive)');
        $this->addArgument('recipient', InputArgument::REQUIRED, 'L\'email qui recevra le test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantCode = $input->getArgument('tenantCode');
        $recipient = $input->getArgument('recipient');

        $output->writeln("<info>🔍 Recherche du tenant '$tenantCode'...</info>");

        // 1. Connexion au Tenant
        $tenant = $this->tenantManager->findTenantByCode($tenantCode);

        if (!$tenant) {
            $output->writeln("<error>❌ Tenant '$tenantCode' introuvable.</error>");
            return Command::FAILURE;
        }

        $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
        $em = $this->emProvider->getEntityManager();

        // 2. Récupération de la config
        $output->writeln("📂 Lecture de la table EmailConfiguration...");
        $config = $em->getRepository(EmailConfiguration::class)->findOneBy([]);

        if (!$config) {
            $output->writeln("<error>❌ Aucune configuration email trouvée dans la BDD de ce tenant.</error>");
            return Command::FAILURE;
        }

        $fromEmail = $config->getFromEmail();
        $fromName = $config->getFromName() ?: 'Test Command';

        $output->writeln("📧 Expéditeur prévu (BDD): <comment>$fromName <$fromEmail></comment>");
        
        if ($config->getSmtpHost() && $config->getSmtpUser() && $config->getSmtpPassword()) {
            $output->writeln("🔌 Serveur SMTP utilisé : <comment>SMTP DÉDIÉ TENANT ({$config->getSmtpHost()}:{$config->getSmtpPort()})</comment>");
        } else {
            $output->writeln("🔌 Serveur SMTP utilisé : <comment>Global .env (MAILER_DSN)</comment>");
        }

        // 3. Envoi via le Mailer adapté
        try {
            $mailer = $this->mailerFactory->createMailer($config);

            $email = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($recipient)
                ->subject("Test SMTP Tenant: $tenantCode")
                ->text("Ceci est un test.\nTenant: $tenantCode\nFrom: $fromEmail\nVia TenantMailerFactory.");

            $mailer->send($email);

            $output->writeln("<info>✅ SUCCÈS ! L'email a été accepté par le serveur SMTP.</info>");
            return Command::SUCCESS;
        } catch (TransportExceptionInterface $e) {
            $output->writeln("<error>💥 ÉCHEC DE L'ENVOI</error>");
            $output->writeln("Erreur technique : " . $e->getMessage());

            if (str_contains($e->getMessage(), '535')) {
                $output->writeln("\n👉 <comment>Diagnostic :</comment> Erreur 535 = Le mot de passe dans ton fichier .env est FAUX.");
            }

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln("<error>💥 ERREUR INCONNUE</error>");
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
