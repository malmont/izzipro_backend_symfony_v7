<?php

namespace App\Services\Platform;

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
 * - tracé : chaque connexion de ce compte, et chaque entrée dans l'espace d'administration d'un autre site sans se reconnecter
 *   (`?tenant=`, `PlatformAccessSubscriber`), laisse une ligne dans le journal du site (`content_audit_log`, ressource `RESOURCE`), que
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
            // Écriture directe, sans l'ORM : une panne du journal ne ferme pas le gestionnaire d'entités dont la connexion a encore
            // besoin (jeton de renouvellement), et rien d'autre n'est écrit au passage
            $this->emProvider->getEntityManager()->getConnection()->insert('content_audit_log', [
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'user_identifier' => mb_substr($user->getUserIdentifier(), 0, 180),
                'tenant' => $this->tenant(), 'resource' => self::RESOURCE, 'resource_id' => method_exists($user, 'getId') ? (string) $user->getId() : null,
                'action' => self::ACTION_LOGIN, 'fields' => 'channel', 'after_state' => json_encode(['channel' => $channel]),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Accès de la plateforme : connexion non tracée au journal du site (' . $e::class . ').');
        }
    }

    private function tenant(): string
    {
        return (string) ($this->tenantProvider->getTenantCode() ?? '');
    }

    /**
     * Les dernières connexions d'un compte de la plateforme sur le site courant, de la plus récente à la plus ancienne.
     *
     * @return list<array{at: \DateTimeImmutable, account: string, channel: string, channelLabel: string}> `at` en UTC
     */
    public function recent(int $limit = 100): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT created_at, user_identifier, after_state FROM content_audit_log WHERE resource = ? AND action = ? AND tenant = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [self::RESOURCE, self::ACTION_LOGIN, $this->tenant()]
        );

        return array_map(static function (array $row): array {
            $after = is_string($row['after_state']) ? json_decode($row['after_state'], true) : null;
            $channel = is_array($after) && is_string($after['channel'] ?? null) ? $after['channel'] : 'webadmin';

            return ['at' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')), 'account' => (string) $row['user_identifier'], 'channel' => $channel, 'channelLabel' => self::CHANNELS[$channel] ?? $channel];
        }, $rows);
    }

    /**
     * Lot AG-3b : les dernières ENTRÉES dans ce site (agence ou plateforme, jeton d'entrée), de la plus récente à la plus ancienne.
     * L'agence se lit « Agence X, prénom » ; la plateforme, par l'adresse de son compte.
     *
     * @return list<array{at: \DateTimeImmutable, account: string, channel: string, channelLabel: string}> `at` en UTC
     */
    public function entries(int $limit = 100): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT created_at, user_identifier, after_state FROM content_audit_log WHERE resource = ? AND action = ? AND tenant = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [\App\Services\Entry\EntryService::JOURNAL_RESOURCE, \App\Services\Entry\EntryService::JOURNAL_ACTION, $this->tenant()]
        );

        return array_map(static function (array $row): array {
            $after = is_string($row['after_state']) ? json_decode($row['after_state'], true) : null;
            $mode = is_array($after) ? ($after['mode'] ?? null) : null;
            [$channel, $label] = match ($mode) {
                \App\Services\Entry\EntryService::MODE_AGENCY => ['entry_agency', 'Entrée d\'une agence (15 minutes, renouvelable)'],
                \App\Services\Entry\EntryService::MODE_GROUP => ['entry_group', 'Entrée du directeur du groupe (15 minutes, renouvelable)'], // lot G-1b
                default => ['entry_platform', 'Entrée de la plateforme (15 minutes, renouvelable)'],
            };

            return ['at' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')), 'account' => (string) $row['user_identifier'],
                'channel' => $channel, 'channelLabel' => $label];
        }, $rows);
    }

    /**
     * Lot G-1b : chaque ÉCRITURE du directeur d'un groupe dans ce site (« Groupe X, prénom »), la plus récente d'abord : la méthode, le chemin, le
     * statut. Écrite par la garde du mode à la réponse.
     *
     * @return list<array{at: \DateTimeImmutable, account: string, channel: string, channelLabel: string}>
     */
    public function groupWrites(int $limit = 100): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT created_at, user_identifier, after_state FROM content_audit_log WHERE resource = ? AND action = 'write' AND tenant = ?"
            . " AND (CASE WHEN resource = 'external_access' AND action = 'write' THEN after_state::jsonb ->> 'mode' END) = ? ORDER BY id DESC LIMIT " . max(1, min(500, $limit)),
            [\App\Services\Entry\EntryService::JOURNAL_RESOURCE, $this->tenant(), \App\Services\Entry\EntryService::MODE_GROUP]
        );

        return array_map(static function (array $row): array {
            $after = is_string($row['after_state']) ? json_decode($row['after_state'], true) : null;
            $after = is_array($after) ? $after : [];

            return ['at' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')), 'account' => (string) $row['user_identifier'],
                'channel' => 'group_write', 'channelLabel' => sprintf('Modification par le directeur du groupe : %s %s', (string) ($after['method'] ?? ''), (string) ($after['path'] ?? ''))];
        }, $rows);
    }

    /**
     * Lot AG-ASSIST : chaque requête faite par une agence pendant une assistance ouverte par le commerçant (écran consulté, fiche lue,
     * écriture), la plus récente d'abord ; avec `$grantId`, celles d'UNE assistance.
     *
     * @return list<array{at: \DateTimeImmutable, account: string, channel: string, channelLabel: string, assistance: ?int, section: ?string, method: string, path: string, status: ?int}>
     */
    public function assistance(int $limit = 200, ?int $grantId = null): array
    {
        $sql = 'SELECT created_at, user_identifier, action, after_state FROM content_audit_log WHERE resource = ? AND action IN (?, ?, ?) AND tenant = ?';
        $params = [\App\Services\Entry\EntryService::JOURNAL_RESOURCE, 'assistance_read', 'assistance_write', 'assistance_refused', $this->tenant()];
        if ($grantId !== null) {
            // la ligne est relue en JSON (le statut ajouté après coup en change l'écriture) ; le CASE ne lit en JSON que les lignes d'assistance
            $sql .= " AND (CASE WHEN resource = 'external_access' AND action LIKE 'assistance\\_%' THEN after_state::jsonb ->> 'assistance' END) = ?";
            $params[] = (string) $grantId;
        }
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative($sql . ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)), $params);

        return array_map(static function (array $row): array {
            $after = is_string($row['after_state']) ? json_decode($row['after_state'], true) : null;
            $after = is_array($after) ? $after : [];
            $write = $row['action'] === 'assistance_write';
            $refused = $row['action'] === 'assistance_refused';
            $method = (string) ($after['method'] ?? '');
            $path = (string) ($after['path'] ?? '');

            return ['at' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')), 'account' => (string) $row['user_identifier'],
                'channel' => (string) $row['action'], 'channelLabel' => sprintf('Assistance n° %s, %s : %s %s', $after['assistance'] ?? '?', $refused ? 'refusé' : ($write ? 'modification' : 'consultation'), $method, $path),
                'assistance' => isset($after['assistance']) ? (int) $after['assistance'] : null, 'section' => isset($after['section']) ? (string) $after['section'] : null,
                'method' => $method, 'path' => $path, 'status' => isset($after['status']) ? (int) $after['status'] : null, 'reason' => isset($after['reason']) ? (string) $after['reason'] : null];
        }, $rows);
    }
}
