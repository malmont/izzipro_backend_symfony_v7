<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionNotificationService;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel de résumé du jour (lot L1-b, décision du propriétaire du 14/11/2026) : « N nouveaux constats, X en jeu ». Au plus un par journée du
 * magasin et par site ; envoyé aux administrateurs du site qui ont gardé le type « loss_digest » actif dans leurs préférences de la gestion
 * (`GestionNotificationService`, canal `email`) ; par le mailer du site, donc par la file et le worker des courriels existants.
 *
 * Le courriel ne porte AUCUN nom, aucun montant par personne, aucun motif saisi à la caisse : le nombre de constats nouveaux, le montant en
 * jeu total, la journée du magasin. Pas de lien : il n'existe pas de façon établie de fabriquer une adresse du web de gestion depuis le
 * serveur ; le texte dit où regarder. Sans configuration d'envoi sur le site, rien ne part et le jour n'est pas marqué : on réessaiera.
 */
class LossDigestService
{
    public const TEMPLATE = 'emails/loss_digest.html.twig';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSettingsService $settings,
        private readonly LossHomeTile $newFindings,
        private readonly LossSite $site,
        private readonly GestionNotificationService $preferences,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Envoie le résumé du jour s'il est dû.
     *
     * @return array{status: string, sent: int} status : sent | nothing_new | already_sent | no_recipient | not_delivered | not_granted
     */
    public function sendIfDue(): array
    {
        if (!$this->settings->moduleGranted()) {
            return ['status' => 'not_granted', 'sent' => 0];
        }
        $em = $this->emProvider->getEntityManager();
        $day = $this->site->clock()->day(new \DateTimeImmutable());
        if ((string) ($em->getRepository(LossSetting::class)->row()['last_digest_day'] ?? '') === $day) {
            return ['status' => 'already_sent', 'sent' => 0];
        }
        $new = $this->newFindings->newFindings();
        if ($new === null) {
            return ['status' => 'nothing_new', 'sent' => 0];
        }
        // Administrateurs du site (le rôle exact : ni un super administrateur seul, ni un compte Assistance) qui ont gardé le courriel actif
        $recipients = [];
        foreach ($em->getConnection()->fetchAllAssociative('SELECT id, email FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND deleted_at IS NULL AND is_verified = true ORDER BY id') as $user) {
            $email = trim((string) $user['email']);
            if ($email !== '' && str_contains($email, '@') && $this->preferences->isKindEnabled((int) $user['id'], GestionNotificationService::LOSS_DIGEST)) {
                $recipients[] = $email;
            }
        }
        if ($recipients === []) {
            return ['status' => 'no_recipient', 'sent' => 0];
        }
        $language = $this->locale->language(null);
        $t = fn (string $key, array $params = []) => $this->translator->trans('loss_digest.' . $key, $params, 'emails', $language);
        $siteName = (string) ($em->getConnection()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1') ?: '');
        $params = ['%count%' => $new['count'], '%amount%' => LossTexts::money($new['amount'], $language, $this->site->currency()), '%site%' => $siteName];
        $context = [
            'title' => $t('title'), 'greeting' => $t('greeting', $params), 'message' => $t('message', $params),
            'rows' => [$t('row_count') => (string) $new['count'], $t('row_amount') => $params['%amount%'], $t('row_day') => LossTexts::day(new \DateTimeImmutable($day), $language)],
            'nextStep' => $t('next'), 'notice' => $t('notice'), 'signature' => $t('signature', $params),
            'locale' => $language, 'intlLocale' => $language === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $subject = $this->mailer->subject('loss_digest.subject', $params, $language);
        $sent = 0;
        foreach ($recipients as $to) {
            $sent += $this->mailer->sendTemplatedEmail($to, $subject, self::TEMPLATE, $context, $language, 'emails') ? 1 : 0;
        }
        if ($sent === 0) { // pas de configuration d'envoi sur ce site : le jour n'est pas marqué, on réessaiera au passage suivant
            $this->logger->warning('Prévention des pertes : courriel de résumé non envoyé (aucun envoi n\'a abouti : configuration d\'envoi du site à vérifier).');

            return ['status' => 'not_delivered', 'sent' => 0];
        }
        $em->getRepository(LossSetting::class)->update(['last_digest_day' => $day]);
        $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::DIGEST_SENT, null, null, ['day' => $day, 'count' => $new['count'], 'amount' => $new['amount'], 'recipients' => $sent]);

        return ['status' => 'sent', 'sent' => $sent];
    }
}
