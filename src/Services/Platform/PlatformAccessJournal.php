<?php

namespace App\Services\Platform;

use App\Entity\ContentAuditLog;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Le compte de la plateforme sur un site (décision du propriétaire, 15/11/2026, lot AG-2 ; `docs/agences-et-import.md` § 8.9). Chaque
 * site porte le compte du propriétaire de la plateforme (`ROLE_SUPER_ADMIN`, `SuperAdminAccountService`), pour le dépannage et la
 * sécurité. Trois garde-fous, sur TOUS les sites :
 * - visible : dans la liste des utilisateurs du site, ce compte porte la mention `LABEL` ; l'administrateur du site le voit, sans
 *   pouvoir le modifier ni le supprimer (`UserCrudController::protectOwner`) ;
 * - écrit : la clause « la plateforme peut accéder au site pour le dépannage et la sécurité » fait partie des conditions (liste remise
 *   à l'avocat) ;
 * - tracé : chaque connexion de ce compte laisse une ligne dans le journal du site (`content_audit_log`, ressource `RESOURCE`), que
 *   l'administrateur du site lit (page « Accès de la plateforme », `GET /api/landingpage-audit?resource=platform_access`).
 *
 * La ligne dit qui (l'adresse du compte, que l'administrateur voit déjà dans sa liste), quand, et par où (`channel`). Elle ne porte ni
 * adresse IP ni appareil. Le journal du site garde ses lignes 180 jours.
 */
class PlatformAccessJournal
{
    public const RESOURCE = 'platform_access';
    public const ACTION_LOGIN = 'login';
    public const LABEL = 'Compte de la plateforme, pour le dépannage';
    /** Par où le compte est entré : l'espace d'administration (EasyAdmin), ou la plateforme de la connexion par l'API */
    public const CHANNELS = ['easyadmin' => 'Espace d\'administration', 'webadmin' => 'Espace d\'administration du web', 'admin' => 'Application de gestion',
        'web' => 'Site web', 'mobile' => 'Application mobile', 'pos' => 'Caisse'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param list<string> $roles */
    public static function isPlatformAccount(array $roles): bool
    {
        return in_array(RoleAssignmentPolicy::SUPER_ADMIN, $roles, true);
    }

    /** Une connexion aboutie : une ligne au journal du site si c'est un compte de la plateforme. Ne fait jamais échouer la connexion. */
    public function recordLogin(UserInterface $user, string $channel): void
    {
        if (!self::isPlatformAccount($user->getRoles())) {
            return;
        }
        try {
            $channel = isset(self::CHANNELS[$channel]) ? $channel : 'webadmin';
            $entry = (new ContentAuditLog())->setUser($user->getUserIdentifier())->setTenant((string) ($this->tenantProvider->getTenantCode() ?? ''))
                ->setResource(self::RESOURCE)->setResourceId(method_exists($user, 'getId') ? (string) $user->getId() : null)->setAction(self::ACTION_LOGIN)
                ->setFields(['channel'])->setBefore(null)->setAfter(json_encode(['channel' => $channel]));
            $em = $this->emProvider->getEntityManager();
            $em->persist($entry);
            $em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Accès de la plateforme : connexion non tracée au journal du site (' . $e::class . ').');
        }
    }

    /**
     * Les dernières connexions d'un compte de la plateforme sur le site courant, de la plus récente à la plus ancienne.
     *
     * @return list<array{at: \DateTimeImmutable, account: string, channel: string, channelLabel: string}> `at` en UTC
     */
    public function recent(int $limit = 100): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT created_at, user_identifier, after_state FROM content_audit_log WHERE resource = ? AND action = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [self::RESOURCE, self::ACTION_LOGIN]
        );

        return array_map(static function (array $row): array {
            $after = is_string($row['after_state']) ? json_decode($row['after_state'], true) : null;
            $channel = is_array($after) && is_string($after['channel'] ?? null) ? $after['channel'] : 'webadmin';

            return ['at' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')), 'account' => (string) $row['user_identifier'], 'channel' => $channel, 'channelLabel' => self::CHANNELS[$channel] ?? $channel];
        }, $rows);
    }
}
