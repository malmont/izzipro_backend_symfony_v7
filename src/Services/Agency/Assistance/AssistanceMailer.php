<?php

namespace App\Services\Agency\Assistance;

use App\Security\AgencyRoles;
use App\Services\Agency\AgencyMemberService;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le courriel à l'agence quand un commerçant demande son assistance (lot AG-ASSIST) : quel site, quelle section, quel mode, qui le
 * demande. Classé INDISPENSABLE (`NotificationCatalog`, `assistance_request`) : jamais retenu. Il part par l'expéditeur de la PLATEFORME
 * (pas par celui du site : son serveur d'envoi est celui du commerçant) à l'adresse de contact de la fiche d'agence, sinon aux
 * responsables de l'agence sur son site d'attache. Il porte le lien vers « Tous mes clients » du site d'attache, SANS aucun jeton.
 * Langue : celle du site qui demande.
 */
class AssistanceMailer
{
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, un bouton

    public function __construct(
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly AdminSpaceUrl $adminSpace,
        private readonly SiteName $siteName,
        private readonly AgencyMemberService $members,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $agency
     * @param array<string, mixed> $grant
     * @return int le nombre de courriels partis
     */
    public function notify(array $agency, array $grant): int
    {
        $recipients = $this->recipients($agency);
        if ($recipients === []) {
            $this->logger->warning('Assistance : aucune adresse pour prévenir l\'agence n° ' . (int) $agency['id'] . '.');

            return 0;
        }
        $language = $this->locale->language(null);
        $params = ['%site%' => $this->siteName->name(), '%agency%' => (string) $agency['name'], '%section%' => AssistanceSections::label((string) $grant['section'], $language),
            '%who%' => (string) $grant['requested_by'], '%number%' => (string) $grant['id']];
        $t = fn (string $key) => $this->translator->trans('assistance_request.' . $key, $params, 'emails', $language);
        $link = $this->adminSpace->onSite((string) $agency['home_tenant_code'], 'agence.clients');
        $sent = 0;
        foreach ($recipients as $email) {
            try {
                $sent += $this->mailer->sendPlatformEmail($email, $t('subject'), self::TEMPLATE, [
                    'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message_' . $grant['mode']), 'button' => $link !== null ? $t('button') : '',
                    // sans adresse connue du site d'attache : pas de bouton (jamais un lien vide)
                    'link' => (string) $link, 'validity' => $t('validity'), 'ignore' => $t('ignore'), 'siteLine' => $params['%site%'],
                ]) ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->error('Assistance : courriel à l\'agence en échec (' . $e::class . ').');
            }
        }

        return $sent;
    }

    /** @param array<string, mixed> $agency @return list<string> */
    private function recipients(array $agency): array
    {
        $contact = trim((string) ($agency['contact_email'] ?? ''));
        if ($contact !== '' && filter_var($contact, \FILTER_VALIDATE_EMAIL) !== false) {
            return [$contact];
        }
        $out = [];
        foreach ($this->members->members($agency) as $member) {
            if ($member['role'] === array_search(AgencyRoles::MANAGER, AgencyRoles::NAMES, true) && filter_var($member['email'], \FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = $member['email'];
            }
        }

        return array_values(array_unique($out));
    }
}
