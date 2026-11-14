<?php

namespace App\Services\AppMembershipService;

use App\Entity\User;
use App\Entity\UserAppMembership;
use App\Repository\UserAppMembershipRepository;
use App\Security\AppFamilies;
use App\Services\TenantEntityManagerProvider;

/**
 * Reprise de l'historique des appartenances d'un site (lot APPS-MEMBRES M2, 14/11/2026, `docs/apps-membres.md` § 8), pour le site
 * courant. `plan()` ne fait que LIRE : il dit quelles appartenances les traces d'usage justifient. `apply()` les crée par
 * `AppMembershipService::grant()` (source `backfill`, date de la plus ancienne trace, au journal avec ses traces) et marque la
 * reprise faite. Rejouable : une appartenance existante n'est jamais modifiée, un second passage ne crée rien.
 *
 * Jamais repris : un compte d'équipe, un compte effacé, un compte sans mot de passe choisi (même avec des commandes), un compte
 * déjà membre de la famille. Aucune version de conditions n'est inventée : seule la famille de la caisse en porte une, celle du
 * vrai consentement `account_link` de la fiche rattachée, avec sa date.
 */
final class AppMembershipBackfill
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly AppMembershipService $memberships)
    {
    }

    /**
     * @return array{report: array<string, mixed>, proposals: list<array{userId: int, app: string, joinedAt: string, traces: list<string>, termsVersion: ?string, termsAcceptedAt: ?string}>}
     */
    public function plan(): array
    {
        $repository = $this->repository();
        $accounts = ['total' => 0, 'erased' => 0, 'team' => 0, 'withoutPassword' => 0, 'eligible' => 0];
        $eligible = [];
        foreach ($repository->backfillAccounts() as $account) {
            $accounts['total']++;
            $reason = $account['deleted'] ? 'erased' : (AppFamilies::isTeam($account['roles']) ? 'team' : (!$account['passwordSet'] ? 'withoutPassword' : 'eligible'));
            $accounts[$reason]++;
            if ($reason === 'eligible') {
                $eligible[$account['id']] = true;
            }
        }
        $members = $repository->membersByFamily();
        // par famille et par compte : ses traces, la plus ancienne date, et pour la caisse la version et la date du consentement
        $found = array_fill_keys(AppFamilies::ALL, []);
        foreach ($repository->backfillTraces() as $t) {
            $entry = $found[$t['app']][$t['userId']] ?? ['at' => null, 'traces' => [], 'termsVersion' => null, 'termsAcceptedAt' => null];
            $entry['traces'][$t['trace']] = true;
            if ($t['at'] !== null && ($entry['at'] === null || $t['at'] < $entry['at'])) {
                $entry['at'] = $t['at'];
            }
            if ($t['app'] === AppFamilies::POS) {
                $entry['termsVersion'] = $t['textVersion'];
                $entry['termsAcceptedAt'] = $t['at'];
            }
            $found[$t['app']][$t['userId']] = $entry;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $proposals = [];
        $families = [];
        $concerned = [];
        foreach (AppFamilies::ALL as $family) {
            $byTrace = array_fill_keys(UserAppMembershipRepository::traceNames()[$family], 0);
            $dates = [];
            foreach ($found[$family] as $userId => $entry) {
                if (!isset($eligible[$userId]) || isset($members[$family][$userId])) {
                    continue;
                }
                $traces = array_keys($entry['traces']);
                $proposals[] = ['userId' => $userId, 'app' => $family, 'joinedAt' => $entry['at'] ?? $now, 'traces' => $traces,
                    'termsVersion' => $entry['termsVersion'], 'termsAcceptedAt' => $entry['termsVersion'] === null ? null : $entry['termsAcceptedAt']];
                foreach ($traces as $trace) {
                    $byTrace[$trace]++;
                }
                $dates[] = $entry['at'] ?? $now;
                $concerned[$userId] = true;
            }
            $families[$family] = ['members' => count($members[$family]), 'proposed' => count($dates), 'byTrace' => $byTrace, 'oldest' => $dates === [] ? null : min($dates), 'newest' => $dates === [] ? null : max($dates)];
            $concerned += $members[$family];
        }
        // comptes clients sans aucune trace ni appartenance : ils passeront par « Rejoindre » ; parmi eux, ceux qui n'ont qu'une adresse
        $withAddress = $repository->accountsWithAddress();
        $withoutTrace = array_diff_key($eligible, $concerned);

        return ['report' => ['accounts' => $accounts, 'families' => $families, 'withoutTrace' => count($withoutTrace), 'addressOnly' => count(array_intersect_key($withoutTrace, $withAddress)),
            'anomalies' => $repository->backfillAnomalies(), 'backfilledAt' => $repository->backfilledAt()], 'proposals' => $proposals];
    }

    /**
     * Crée les appartenances du plan, en une transaction (tout ou rien pour le site), puis marque la reprise faite.
     *
     * @return array{report: array<string, mixed>, proposals: list<array<string, mixed>>, created: array<string, int>} le plan appliqué et ce qui a été créé, par famille
     */
    public function apply(): array
    {
        $plan = $this->plan();
        $em = $this->emProvider->getEntityManager();
        $created = array_fill_keys(AppFamilies::ALL, 0);
        $em->getConnection()->beginTransaction();
        try {
            foreach ($plan['proposals'] as $p) {
                $user = $em->find(User::class, $p['userId']);
                if ($user instanceof User && $this->memberships->grant($user, $p['app'], AppFamilies::SOURCE_BACKFILL, $p['termsVersion'], new \DateTimeImmutable($p['joinedAt']),
                    $p['termsAcceptedAt'] === null ? null : new \DateTimeImmutable($p['termsAcceptedAt']), $p['traces'])) {
                    $created[$p['app']]++;
                }
            }
            $this->repository()->markBackfilled();
            $em->getConnection()->commit();
        } catch (\Throwable $e) {
            $em->getConnection()->rollBack();
            throw $e;
        }
        $plan['report']['backfilledAt'] = $this->repository()->backfilledAt();

        return $plan + ['created' => $created];
    }

    private function repository(): UserAppMembershipRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(UserAppMembership::class);
    }
}
