<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionNotificationService;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel de résumé du jour (lot L1-b, décision du propriétaire du 14/11/2026) : « N nouveaux constats, X en jeu ». Au plus un par journée du
 * magasin et par site ; envoyé aux administrateurs du site qui ont gardé le type « loss_digest » actif dans leurs préférences de la gestion
 * (`GestionNotificationService`, canal `email`) ; par le mailer du site, donc par la file et le worker des courriels existants.
 *
 * Le courriel ne porte AUCUN nom, aucun montant par personne, aucun motif saisi à la caisse : le nombre de constats nouveaux, le montant en
 * jeu total, la journée du magasin. Pas de lien : il n'existe pas de façon établie de fabriquer une adresse du web de gestion depuis le
 * serveur ; le texte dit où regarder.
 *
 * Quatre règles, posées à la relecture du lot (14/11/2026) :
 * - RIEN ne part quand l'analyse est arrêtée (droit du module retiré, notice aux salariés exigée et non confirmée, ou retirée) : annoncer
 *   « N nouveaux constats » sur un site dont l'analyse est bloquée par la notice serait continuer le traitement sans elle ;
 * - le jour est RÉSERVÉ avant l'envoi (une écriture conditionnelle : un seul de deux lancements qui se chevauchent l'obtient), puis rendu si
 *   aucun envoi n'aboutit (pas de configuration d'envoi sur le site : on réessaiera au passage suivant) ;
 * - la dernière ligne du courriel est `siteLine`, pas `signature` : l'envoi commun écrase `signature` par celle de la configuration d'envoi
 *   du site, qui peut porter un nom de personne et un lien ;
 * - le domaine passé à l'envoi commun est l'hôte public du site (il sert à l'adresse du logo), jamais un domaine de traduction.
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
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
    ) {
    }

    /**
     * Envoie le résumé du jour s'il est dû.
     *
     * @return array{status: string, sent: int} status : sent | nothing_new | already_sent | no_recipient | not_delivered | not_granted | not_allowed
     */
    public function sendIfDue(): array
    {
        if (!$this->settings->moduleGranted()) {
            return ['status' => 'not_granted', 'sent' => 0];
        }
        if ($this->settings->blockedReason() !== null) { // notice exigée et non confirmée (ou retirée) : l'analyse est arrêtée, le courriel aussi
            return ['status' => 'not_allowed', 'sent' => 0];
        }
        $em = $this->emProvider->getEntityManager();
        $settings = $em->getRepository(LossSetting::class);
        $day = $this->site->clock()->day(new \DateTimeImmutable());
        if ((string) ($settings->row()['last_digest_day'] ?? '') === $day) {
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
        // Réserver le jour AVANT d'envoyer : de deux lancements qui se chevauchent (le minuteur et un lancement à la main), un seul passe
        $reserved = $settings->reserveDigestDay($day);
        if ($reserved === null) {
            return ['status' => 'already_sent', 'sent' => 0];
        }
        $language = $this->locale->language(null);
        $t = fn (string $key, array $params = []) => $this->translator->trans('loss_digest.' . $key, $params, 'emails', $language);
        $siteName = (string) ($em->getConnection()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1') ?: '');
        $params = ['%count%' => $new['count'], '%amount%' => LossTexts::money($new['amount'], $language, $this->site->currency()), '%site%' => $siteName];
        $context = [
            'title' => $t('title'), 'greeting' => $t('greeting', $params), 'message' => $t('message', $params),
            'rows' => [$t('row_count') => (string) $new['count'], $t('row_amount') => $params['%amount%'], $t('row_day') => LossTexts::day(new \DateTimeImmutable($day), $language)],
            'nextStep' => $t('next'), 'notice' => $t('notice'), 'siteLine' => $t('signature', $params),
            'locale' => $language, 'intlLocale' => $language === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $subject = $this->mailer->subject('loss_digest.subject', $params, $language);
        $host = $this->host();
        $sent = 0;
        foreach ($recipients as $to) {
            try {
                $sent += $this->mailer->sendTemplatedEmail($to, $subject, self::TEMPLATE, $context, $language, $host) ? 1 : 0;
            } catch (\Throwable $e) { // un destinataire en échec n'arrête pas les autres ; jamais d'adresse au journal
                $this->logger->error('Prévention des pertes : courriel de résumé, un envoi en échec : ' . $e->getMessage(), ['exception' => $e]);
            }
        }
        if ($sent === 0) { // pas de configuration d'envoi sur ce site : le jour est rendu, on réessaiera au passage suivant
            $settings->releaseDigestDay($day, $reserved['previous']);
            $this->logger->warning('Prévention des pertes : courriel de résumé non envoyé (aucun envoi n\'a abouti : configuration d\'envoi du site à vérifier).');

            return ['status' => 'not_delivered', 'sent' => 0];
        }
        $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::DIGEST_SENT, null, null, ['day' => $day, 'count' => $new['count'], 'amount' => $new['amount'], 'recipients' => $sent]);

        return ['status' => 'sent', 'sent' => $sent];
    }

    /** Hôte public du site (son domaine propre, sinon <code>.<domaine du frontend>) : l'envoi commun en fait l'adresse du logo */
    private function host(): string
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $code !== '' ? (string) ($this->hosts->describe($code)['host'] ?? $code) : 'localhost';
    }
}
