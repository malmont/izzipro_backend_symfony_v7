<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionNotificationService;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossSetting;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Courriel de résumé du jour (lot L1-b, décision du propriétaire du 14/11/2026) : « N nouveaux constats, X en jeu ». Au plus un par journée du
 * magasin et par site, à partir de 8 h à l'heure du magasin (le premier passage du minuteur après 8 h envoie), et seulement pour les
 * constats NÉS depuis le courriel précédent : aucun courriel les jours où rien n'est né, même s'il reste des constats ouverts (la vignette
 * de l'accueil continue de les rappeler) ; un constat né après l'envoi part le lendemain. Un constat de reprise redevenu « nouveau » parce
 * qu'une preuve récente l'a complété est annoncé lui aussi, une seule fois, avec la mention « complété par une nouvelle preuve » ; envoyé aux administrateurs du site qui ont gardé le type « loss_digest » actif dans leurs préférences de la gestion
 * (`GestionNotificationService`, canal `email`) ; par le mailer du site, donc par la file et le worker des courriels existants.
 *
 * Le courriel ne porte AUCUN nom, aucun montant par personne, aucun motif saisi à la caisse : le nombre de constats nouveaux, le montant en
 * jeu total, la journée du magasin. UN lien, vers la liste des constats nouveaux de l'espace d'administration (`AdminSpaceUrl`, lot LIEN :
 * `<adresse>/admin/ouvrir?screen=pertes.constats&isNew=1`) : c'est la seule adresse du courriel ; sans adresse connue pour le site, pas de
 * lien, le texte dit où regarder.
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
    /** Heure du magasin à partir de laquelle le courriel part (comme les autres courriels de la gestion) */
    public const SEND_HOUR = 8;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossSettingsService $settings,
        private readonly LossSite $site,
        private readonly GestionNotificationService $preferences,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly AdminSpaceUrl $links,
    ) {
    }

    /**
     * Envoie le résumé du jour s'il est dû.
     *
     * @param \DateTimeImmutable|null $now l'instant du passage (les tests le fixent) ; `$anyHour` : sans attendre 8 h (lancement à la main)
     * @return array{status: string, sent: int} status : sent | nothing_new | already_sent | too_early | no_recipient | not_delivered | not_granted | not_allowed
     */
    public function sendIfDue(?\DateTimeImmutable $now = null, bool $anyHour = false): array
    {
        $now ??= new \DateTimeImmutable();
        if (!$this->settings->moduleGranted()) {
            return ['status' => 'not_granted', 'sent' => 0];
        }
        if ($this->settings->blockedReason() !== null) { // notice exigée et non confirmée (ou retirée) : l'analyse est arrêtée, le courriel aussi
            return ['status' => 'not_allowed', 'sent' => 0];
        }
        $em = $this->emProvider->getEntityManager();
        $settings = $em->getRepository(LossSetting::class);
        $clock = $this->site->clock();
        if (!$anyHour && (int) $now->setTimezone($clock->timezone())->format('G') < self::SEND_HOUR) { // l'heure du MAGASIN, pas celle du serveur
            return ['status' => 'too_early', 'sent' => 0];
        }
        $day = $clock->day($now);
        $row = $settings->row();
        if ((string) ($row['last_digest_day'] ?? '') === $day) {
            return ['status' => 'already_sent', 'sent' => 0];
        }
        // Ce qui est NÉ depuis le courriel précédent (`lastId` : la borne que ce courriel posera), et les constats de reprise REDEVENUS
        // « nouveaux » depuis (une preuve récente les a complétés) : annoncés à part, une seule fois par retour à « nouveau » (décision du
        // propriétaire). Leur rang est lu au journal du module ; le courriel le note dans sa ligne « courriel envoyé » (`renewedUpTo`)
        $journal = $em->getRepository(LossJournalEntry::class);
        $findings = $em->getRepository(LossFinding::class);
        // Les trois lectures se font sur la MÊME photo de la base (transaction en lecture répétable) : une analyse qui validerait entre deux
        // d'entre elles ferait compter un constat « né » aujourd'hui puis « complété » demain
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $renewedMark = $journal->digestRenewedMark();
            $renewed = $journal->renewedAfter($renewedMark);
            $again = $findings->renewedAmong($renewed['findingIds']);
            $new = $findings->bornAfter((int) ($row['last_digest_finding_id'] ?? 0), $renewed['findingIds']);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $total = ['count' => $new['count'] + $again['count'], 'amount' => $new['amount'] + $again['amount']];
        if ($total['count'] === 0) {
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
        // Tout ce qui peut échouer est préparé AVANT la réservation (langue, textes, objet, hôte du site lu dans la base maître) : une
        // exception ici ne laisse ni jour ni borne posés. Avec la borne, une réservation jamais rendue ferait perdre ces constats pour toujours
        $language = $this->locale->language(null);
        $t = fn (string $key, array $params = []) => $this->translator->trans('loss_digest.' . $key, $params, 'emails', $language);
        $siteName = (string) ($em->getConnection()->fetchOne('SELECT name FROM entreprise ORDER BY id LIMIT 1') ?: '');
        $params = ['%count%' => $total['count'], '%amount%' => LossTexts::money($total['amount'], $language, $this->site->currency()), '%site%' => $siteName];
        $context = [
            'title' => $t('title'), 'greeting' => $t('greeting', $params),
            // la mention « complété par une nouvelle preuve » distingue un constat plus ancien qui revient d'un constat né depuis
            // le montant en jeu est la somme des constats qui en portent un ; quand aucun n'en porte (tiroir ouvert sans vente, validations
            // refusées), ni la phrase ni le tableau ne disent « 0,00 $ »
            'message' => $t($total['amount'] > 0 ? 'message' : 'message_no_amount', $params) . ($again['count'] > 0 ? ' ' . $t('message_renewed', ['%count%' => $again['count']]) : ''),
            'rows' => [$t('row_count') => (string) $total['count']] + ($again['count'] > 0 ? [$t('row_renewed') => (string) $again['count']] : [])
                + ($total['amount'] > 0 ? [$t('row_amount') => $params['%amount%']] : []) + [$t('row_day') => LossTexts::day(new \DateTimeImmutable($day), $language)],
            'nextStep' => $t('next'), 'notice' => $t('notice'), 'siteLine' => $t('signature', $params),
            // Lien direct vers la liste des constats nouveaux (lot LIEN) : la seule adresse du courriel ; null (site sans adresse connue) : pas de lien
            'linkUrl' => $this->links->to('pertes.constats', ['isNew' => true]), 'linkLabel' => $t('link'),
            'locale' => $language, 'intlLocale' => $language === 'en' ? 'en_CA' : 'fr_CA',
        ];
        $subject = $this->mailer->subject('loss_digest.subject', $params, $language);
        $host = $this->host();
        // Réserver le jour AVANT d'envoyer : de deux lancements qui se chevauchent (le minuteur et un lancement à la main), un seul passe
        $reserved = $settings->reserveDigestDay($day, $new['lastId'] > 0 ? $new['lastId'] : null); // rien de né : la borne des constats nés ne bouge pas
        if ($reserved === null) {
            return ['status' => 'already_sent', 'sent' => 0];
        }
        $sent = 0;
        try {
            foreach ($recipients as $to) {
                try {
                    $sent += $this->mailer->sendTemplatedEmail($to, $subject, self::TEMPLATE, $context, $language, $host) ? 1 : 0;
                } catch (\Exception $e) { // un destinataire en échec n'arrête pas les autres
                    // Jamais le MESSAGE de l'exception au journal du serveur : il peut porter l'adresse du destinataire. Sa classe suffit.
                    $this->logger->error('Prévention des pertes : courriel de résumé, un envoi en échec (' . $e::class . ').');
                }
            }
        } finally {
            if ($sent === 0) { // rien n'est parti (pas de configuration d'envoi, ou une erreur) : jour et borne sont RENDUS, on réessaiera
                $settings->releaseDigestDay($day, $reserved['previous'], $reserved['previousFindingId']);
            }
        }
        if ($sent === 0) {
            $this->logger->warning('Prévention des pertes : courriel de résumé non envoyé (aucun envoi n\'a abouti : configuration d\'envoi du site à vérifier).');

            return ['status' => 'not_delivered', 'sent' => 0];
        }
        // `renewedUpTo` : rang du journal jusqu'où les constats redevenus « nouveaux » sont annoncés ; le courriel suivant repart de là
        $journal->append(LossJournalEntry::DIGEST_SENT, null, null, ['day' => $day, 'count' => $total['count'], 'amount' => $total['amount'], 'recipients' => $sent,
            'renewed' => $again['count'], 'renewedUpTo' => $renewed['lastId']]);

        return ['status' => 'sent', 'sent' => $sent];
    }

    /** Hôte public du site (son domaine propre, sinon <code>.<domaine du frontend>) : l'envoi commun en fait l'adresse du logo */
    private function host(): string
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $code !== '' ? (string) ($this->hosts->describe($code)['host'] ?? $code) : 'localhost';
    }
}
