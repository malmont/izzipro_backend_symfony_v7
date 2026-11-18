<?php

namespace App\Incident\Services;

use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionManager;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel au signaleur quand la plateforme répond à un signalement ou en change l'état (corrigé, ne sera pas corrigé) : lot INC-3
 * (18/11/2026), famille indispensable (il répond à son geste). Il ne porte que l'état et la réponse PUBLIQUE, jamais la note interne ni la
 * capture. Pour une escalade depuis l'Assistance, c'est l'agent qui a transmis le ticket qui le reçoit. L'adresse et la langue du compte
 * sont lues sur la base de son site par une connexion à part, en lecture : le site de la requête du super administrateur ne change pas.
 */
class BugReportMailer
{
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, sans bouton

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $report la ligne de `platform_bug_report` après la mise à jour ; rend vrai si un courriel est parti */
    public function answered(array $report): bool
    {
        $recipient = $report['reporter_user_id'] !== null ? $this->recipient((string) $report['tenant_code'], (int) $report['reporter_user_id']) : null;
        if ($recipient === null) {
            return false;
        }
        [$to, $language] = $recipient;
        $params = ['%id%' => (string) $report['id'], '%status%' => $this->translator->trans('bug_report_answer.status.' . $report['status'], [], 'emails', $language)];
        $t = fn (string $key): string => $this->translator->trans('bug_report_answer.' . $key, $params, 'emails', $language);
        $answer = trim((string) ($report['answer'] ?? ''));

        return $this->mailer->sendPlatformEmail($to, $t('subject'), self::TEMPLATE, [
            'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message') . ($answer !== '' ? ' ' . $t('answer') . ' ' . $answer : ''),
            'button' => '', 'link' => '', 'validity' => $t('where'), 'ignore' => '', 'siteLine' => '',
        ]);
    }

    /** @return array{0: string, 1: string}|null l'adresse et la langue d'un compte de ce site, s'il existe encore */
    private function recipient(string $tenantCode, int $userId): ?array
    {
        $tenant = $this->tenants->findTenantByCode($tenantCode);
        if ($tenant === null || !isset($tenant['dbname'])) {
            return null;
        }
        $params = $this->tenants->getTenantParams();
        $params['dbname'] = (string) $tenant['dbname'];
        $conn = DriverManager::getConnection($params);
        try {
            $email = $conn->fetchOne('SELECT email FROM "user" WHERE id = ? AND deleted_at IS NULL', [$userId]);
            try {
                $locale = $conn->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1');
            } catch (\Throwable) {
                $locale = null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Signalement : compte du signaleur illisible (' . $e::class . ').');

            return null;
        } finally {
            $conn->close();
        }

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email, $locale === 'en' ? 'en' : 'fr'] : null;
    }
}
