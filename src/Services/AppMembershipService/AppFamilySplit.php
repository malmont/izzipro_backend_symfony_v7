<?php

namespace App\Services\AppMembershipService;

use App\Entity\User;
use App\Entity\UserAppMembership;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookBiographerService;
use App\Repository\UserAppMembershipRepository;
use App\Security\AppFamilies;
use App\Security\TeamRoles;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TeamRoleService\TeamRoleService;
use App\Services\TenantEntityManagerProvider;

/**
 * Opération unique du 14/11/2026, pour le site courant (`docs/apps-membres.md` § 2, `docs/memoires-vivantes.md`, « Équipe ») : la
 * famille `memoires` n'existe plus. Mémoires Vivantes web devient l'outil de l'équipe ; la famille des clients est `livingmemory`.
 *
 *  - une appartenance `memoires` fondée sur des traces de l'application (inscription Living Memory, livre mobile, paiement de
 *    chapitre, impression d'un livre mobile) passe à `livingmemory`, sans changer de date ni de source ;
 *  - un compte CLIENT qui possède un livre web devient biographe (par `TeamRoleService`, le chemin de la console Équipe, journal
 *    `user_team`), est affecté à ses propres livres web, et perd son appartenance `memoires` : un compte d'équipe n'a pas d'appartenance ;
 *  - une appartenance `memoires` sans aucune de ces traces est retirée ;
 *  - un compte client qui a les DEUX (livre web et traces de l'application) est signalé et laissé tel quel : la décision est à prendre.
 *
 * `plan()` ne fait que lire ; `apply()` écrit en une transaction. Rejouable : un second passage ne trouve plus rien à faire.
 */
final class AppFamilySplit
{
    public const LEGACY_FAMILY = 'memoires';
    public const REASON = 'family_split';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AppMembershipService $memberships,
        private readonly TeamRoleService $teamRoles,
        private readonly BookBiographerService $bookBiographer,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /**
     * @return array{report: array<string, mixed>, operations: list<array{userId: int, operation: string, member: bool, webBooks: int, mobileTraces: list<string>}>}
     */
    public function plan(): array
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        /** @var UserAppMembershipRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(UserAppMembership::class);
        $members = array_fill_keys(array_map('intval', $connection->fetchFirstColumn('SELECT user_id FROM user_app_membership WHERE app = ?', [self::LEGACY_FAMILY])), true);
        $webBooks = [];
        foreach ($connection->fetchAllNumeric("SELECT user_id, COUNT(*) FROM mv_book WHERE user_id IS NOT NULL AND COALESCE(channel, 'web') <> 'mobile' GROUP BY user_id") as [$userId, $count]) {
            $webBooks[(int) $userId] = (int) $count;
        }
        $mobile = [];
        foreach ($repository->backfillTraces() as $trace) {
            if ($trace['app'] === AppFamilies::LIVINGMEMORY) {
                $mobile[$trace['userId']][] = $trace['trace'];
            }
        }
        $operations = [];
        $count = ['move' => 0, 'promote' => 0, 'revoke' => 0, 'mixed' => 0, 'skipped' => 0];
        foreach ($repository->backfillAccounts() as $account) {
            $id = $account['id'];
            $member = isset($members[$id]);
            $web = $webBooks[$id] ?? 0;
            $traces = $mobile[$id] ?? [];
            if (!$member && $web === 0) {
                continue; // ni appartenance à reclasser, ni livre web
            }
            $client = !$account['deleted'] && !AppFamilies::isTeam($account['roles']);
            $operation = match (true) {
                !$client => $member ? 'revoke' : null, // compte d'équipe ou effacé : il garde ses livres, il n'a pas d'appartenance
                $web > 0 && $traces !== [] => 'mixed',
                $web > 0 => $account['passwordSet'] ? 'promote' : 'skipped', // un compte sans mot de passe choisi ne devient pas biographe
                $traces !== [] => 'move',
                default => 'revoke',
            };
            if ($operation === null) {
                continue;
            }
            $count[$operation]++;
            $operations[] = ['userId' => $id, 'operation' => $operation, 'member' => $member, 'webBooks' => $web, 'mobileTraces' => $traces];
        }

        return ['report' => ['members' => count($members), 'toLivingMemory' => $count['move'], 'toBiographer' => $count['promote'], 'revoked' => $count['revoke'], 'mixed' => $count['mixed'], 'withoutPassword' => $count['skipped'],
            'webBooksOfNewBiographers' => array_sum(array_map(fn (array $o) => $o['operation'] === 'promote' ? $o['webBooks'] : 0, $operations)),
            'livingMemoryMembers' => (int) $connection->fetchOne('SELECT COUNT(*) FROM user_app_membership WHERE app = ?', [AppFamilies::LIVINGMEMORY])], 'operations' => $operations];
    }

    /**
     * @return array{report: array<string, mixed>, operations: list<array<string, mixed>>, done: array{moved: int, promoted: int, booksAssigned: int, revoked: int}}
     */
    public function apply(): array
    {
        $plan = $this->plan();
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $done = ['moved' => 0, 'promoted' => 0, 'booksAssigned' => 0, 'revoked' => 0];
        $connection->beginTransaction();
        try {
            foreach ($plan['operations'] as $o) {
                $userId = $o['userId'];
                if ($o['operation'] === 'move') {
                    // la même appartenance, dans la famille de l'application : ni sa date d'entrée ni sa source ne changent
                    if ((int) $connection->executeStatement('UPDATE user_app_membership SET app = ? WHERE user_id = ? AND app = ? AND NOT EXISTS (SELECT 1 FROM user_app_membership x WHERE x.user_id = ? AND x.app = ?)',
                        [AppFamilies::LIVINGMEMORY, $userId, self::LEGACY_FAMILY, $userId, AppFamilies::LIVINGMEMORY]) === 1) {
                        $this->audit->record(AppFamilies::AUDIT_RESOURCE, $userId, 'reclassify', ['app' => self::LEGACY_FAMILY], ['app' => AppFamilies::LIVINGMEMORY, 'traces' => $o['mobileTraces']], [AppFamilies::LIVINGMEMORY, self::REASON]);
                        $done['moved']++;
                    } elseif ($this->memberships->revoke($userId, self::LEGACY_FAMILY, self::REASON)) {
                        $done['revoked']++; // déjà membre de livingmemory : l'ancienne ligne part
                    }
                } elseif ($o['operation'] === 'promote') {
                    $user = $em->find(User::class, $userId);
                    if (!$user instanceof User) {
                        continue;
                    }
                    $this->teamRoles->set($user, TeamRoles::MEMOIRES_BIOGRAPHE, true, [self::REASON]);
                    foreach ($em->getRepository(Book::class)->findBy(['user' => $user]) as $book) {
                        if ($book->getChannel() !== Book::CHANNEL_MOBILE && $this->bookBiographer->assignCreator($book, $user, self::REASON)) {
                            $done['booksAssigned']++;
                        }
                    }
                    if ($o['member'] && $this->memberships->revoke($userId, self::LEGACY_FAMILY, self::REASON)) {
                        $done['revoked']++;
                    }
                    $done['promoted']++;
                } elseif ($o['operation'] === 'revoke' && $this->memberships->revoke($userId, self::LEGACY_FAMILY, self::REASON)) {
                    $done['revoked']++;
                }
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $plan + ['done' => $done];
    }
}
