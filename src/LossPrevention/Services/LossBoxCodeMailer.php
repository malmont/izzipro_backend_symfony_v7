<?php

namespace App\LossPrevention\Services;

use App\LossPrevention\Master\LossPairingRepository;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionManager;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le code de l'étiquette d'un boîtier vendu, envoyé aux administrateurs du site acheteur (lot L6-c, 17/11/2026, `docs/prevention-pertes.md`
 * § 19.6) : une fois à la réservation (A), et sur demande de la plateforme qui relit le code (B). Le code n'est GARDÉ NULLE PART :
 * - il part en direct pendant la requête, par le serveur de la plateforme (`EmailSenderService::sendPlatformEmailNow`), jamais par une
 *   file de messages ni par la table des échecs ;
 * - il n'apparaît dans aucun journal (ni celui de l'application, ni celui des envois, ni une trace d'exception), ni dans le sujet ;
 * - le journal des expéditions (base maître) écrit « code envoyé à N administrateurs », avec la série, jamais le code.
 * Les administrateurs du site acheteur sont lus par une connexion SÉPARÉE à sa base, en lecture : le site de la requête du super
 * administrateur ne change pas. Le code seul ne fait rien de dangereux : la machine est fixée à la préparation, le code est réservé à CE
 * site, toute saisie par un autre site est refusée.
 */
class LossBoxCodeMailer
{
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, un bouton

    public function __construct(
        private readonly LossPairingRepository $pairings,
        private readonly TenantConnectionManager $tenants,
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Envoie le code aux administrateurs du site de l'expédition `$shipmentId`. Rend combien de courriels sont partis (0 : rien, la
     * réservation tient quand même ; le super administrateur renverra le code, B).
     */
    public function send(int $shipmentId, string $code, string $by): int
    {
        $shipment = $this->pairings->shipment($shipmentId);
        $tenant = $shipment !== null ? $this->pairings->tenant((string) $shipment['tenant_code']) : null;
        $info = $shipment !== null ? $this->pairings->tenantInfo((string) $shipment['tenant_code']) : null;
        if ($shipment === null || $tenant === null || $info === null) {
            return 0;
        }
        [$admins, $language] = $this->adminsOf($tenant['dbname']);
        $serial = (string) $shipment['serial'];
        $params = ['%serial%' => $serial, '%site%' => $info['name'], '%code%' => $code];
        $t = fn (string $key): string => $this->translator->trans('loss_box_code.' . $key, $params, 'emails', $language);
        $sent = 0;
        foreach ($admins as $to) {
            // le sujet ne porte pas le code ; le corps seul le porte
            $sent += $this->mailer->sendPlatformEmailNow($to, $t('subject'), self::TEMPLATE, [
                'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message'), 'button' => '', 'link' => '',
                'validity' => $t('validity'), 'ignore' => $t('ignore'), 'siteLine' => $info['name'],
            ]) ? 1 : 0;
        }
        $this->pairings->shipmentEvent($shipmentId, 'code_sent', $by, (string) $shipment['tenant_code'], ['admins' => $sent, 'serial' => $serial], gmdate('Y-m-d H:i:s'));

        return $sent;
    }

    /**
     * Les adresses des administrateurs d'un site (jamais le compte de la plateforme) et sa langue, lues par une connexion à part.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function adminsOf(string $dbname): array
    {
        $params = $this->tenants->getTenantParams();
        $params['dbname'] = $dbname;
        $conn = DriverManager::getConnection($params);
        try {
            $emails = $conn->fetchFirstColumn('SELECT email FROM "user" WHERE roles::text LIKE \'%"ROLE_ADMIN"%\' AND roles::text NOT LIKE \'%"ROLE_SUPER_ADMIN"%\''
                . ' AND deleted_at IS NULL AND is_verified = true ORDER BY id');
            try {
                $locale = $conn->fetchOne('SELECT locale FROM entreprise ORDER BY id LIMIT 1');
            } catch (\Throwable) {
                $locale = null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Prévention des pertes : administrateurs du site acheteur illisibles (' . $e::class . ').');

            return [[], 'fr'];
        } finally {
            $conn->close();
        }

        return [array_values(array_filter(array_map('strval', $emails), fn (string $e): bool => filter_var($e, \FILTER_VALIDATE_EMAIL) !== false)), $locale === 'en' ? 'en' : 'fr'];
    }
}
