<?php

namespace App\Tests\Functional\Security;

use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Opération unique du 14/11/2026 (docs/apps-membres.md § 2) : la famille `memoires` n'existe plus. `app:members:family-split <site>`
 * simule par défaut, puis avec `--apply --confirm=<site>` : une appartenance fondée sur l'application passe à `livingmemory` (même date,
 * même source) ; un compte client propriétaire d'un livre web devient biographe (journal user_team), affecté à ses livres web, sans
 * appartenance ; une appartenance sans trace est retirée ; un compte qui a un livre web ET des traces de l'application est laissé tel
 * quel. Joué sur le second site de test, dont les seuls comptes clients sont ceux de ce test.
 */
class AppFamilySplitTest extends KernelTestCase
{
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->useSite();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->useSite();
        $this->clean();
        parent::tearDown();
    }

    public function testTheFormerMemoiresFamilyIsReclassifiedAndWebBookOwnersBecomeBiographers(): void
    {
        $this->assertSame(['members' => 0, 'toLivingMemory' => 0, 'toBiographer' => 0, 'revoked' => 0, 'mixed' => 0, 'withoutPassword' => 0, 'webBooksOfNewBiographers' => 0, 'livingMemoryMembers' => 0],
            $this->run_(['--json' => true])['report'], 'le second site de test n\'a ni client ni appartenance');

        // Appartenance fondée sur l'application : elle passe à livingmemory
        $mobile = $this->account('mobile');
        $this->book($mobile, 'mobile');
        $this->member($mobile, 'memoires', '2024-02-03 04:05:06');
        // Compte client propriétaire de livres web : il devient biographe, affecté à ses livres (sauf celui qui a déjà un biographe)
        $colleague = $this->account('collegue', '["ROLE_MEMOIRES_BIOGRAPHE","ROLE_USER_INTERNET"]');
        $web = $this->account('web');
        $free = $this->book($web, 'web');
        $taken = $this->book($web, 'web', $colleague);
        $this->member($web, 'memoires', '2024-03-04 05:06:07');
        // Même chose sans appartenance à retirer
        $webWithoutMembership = $this->account('web-sans-appartenance');
        $third = $this->book($webWithoutMembership, 'web');
        // Appartenance sans aucune trace : retirée
        $nothing = $this->account('rien');
        $this->member($nothing, 'memoires', '2024-04-04 00:00:00');
        // Livre web ET traces de l'application : laissé tel quel, à décider
        $mixed = $this->account('les-deux');
        $this->book($mixed, 'web');
        $this->book($mixed, 'mobile');
        $this->member($mixed, 'memoires', '2024-05-05 00:00:00');
        // Déjà membre de livingmemory : l'ancienne ligne part, la nouvelle ne bouge pas
        $already = $this->account('deja', '["ROLE_USER_INTERNET"]', true, 'mobile');
        $this->member($already, 'memoires', '2024-06-06 00:00:00');
        $this->member($already, 'livingmemory', '2026-11-14 01:00:00', 'register');
        // Jamais touchés : un compte d'équipe propriétaire de livres web (il les garde), un compte sans mot de passe choisi
        $admin = $this->account('admin', '["ROLE_ADMIN","ROLE_USER_INTERNET"]');
        $this->book($admin, 'web');
        $guest = $this->account('invite', '["ROLE_USER_INTERNET"]', false);
        $this->book($guest, 'web');

        // 1. SIMULATION : des nombres, rien d'écrit
        $state = fn (): string => md5(json_encode([$this->db->fetchAllAssociative('SELECT user_id, app, source, joined_at FROM user_app_membership ORDER BY user_id, app'),
            $this->db->fetchAllAssociative('SELECT id, roles::text AS roles FROM "user" ORDER BY id'), $this->db->fetchAllAssociative('SELECT id, user_id, biographer_id FROM mv_book ORDER BY id')]));
        $before = $state();
        $simulation = $this->run_(['--json' => true]);
        $this->assertSame('simulation', $simulation['mode']);
        $this->assertSame(['members' => 5, 'toLivingMemory' => 2, 'toBiographer' => 2, 'revoked' => 1, 'mixed' => 1, 'withoutPassword' => 1, 'webBooksOfNewBiographers' => 3, 'livingMemoryMembers' => 1], $simulation['report']);
        $this->assertSame($before, $state(), 'la simulation n\'écrit rien');
        $detail = (string) file_get_contents($simulation['detail']);
        $this->assertStringNotContainsString('@', $detail, 'des identifiants, jamais un courriel');
        $operations = array_column(json_decode($detail, true)['operations'], 'operation', 'userId');
        $this->assertSame([$mobile => 'move', $web => 'promote', $webWithoutMembership => 'promote', $nothing => 'revoke', $mixed => 'mixed', $already => 'move', $guest => 'skipped'], $operations);
        $this->assertArrayNotHasKey($admin, $operations);
        $this->assertArrayNotHasKey($colleague, $operations);
        // --apply demande que le code du site soit redit
        foreach ([['--apply' => true], ['--apply' => true, '--confirm' => MV_TEST_TENANT_CODE]] as $arguments) {
            [$status, $display] = $this->execute($arguments);
            $this->assertSame([1, true, $before], [$status, str_contains($display, 'Rien n\'a été écrit'), $state()]);
        }
        [$status] = $this->execute([], 'site-inexistant');
        $this->assertSame(1, $status);

        // 2. APPLICATION
        $applied = $this->run_(['--apply' => true, '--confirm' => MV_TEST_TENANT2_CODE, '--json' => true]);
        $this->assertSame(['apply', ['moved' => 1, 'promoted' => 2, 'booksAssigned' => 2, 'revoked' => 3]], [$applied['mode'], $applied['done']]);
        $rows = fn (int $id): array => $this->db->fetchAllAssociative('SELECT app, source, joined_at FROM user_app_membership WHERE user_id = ? ORDER BY app', [$id]);
        $roles = fn (int $id): array => json_decode((string) $this->db->fetchOne('SELECT roles FROM "user" WHERE id = ?', [$id]), true);
        $journal = fn (string $resource, string $id): array => $this->db->fetchAllAssociative('SELECT action, fields FROM content_audit_log WHERE resource = ? AND resource_id = ? ORDER BY id', [$resource, $id]);
        // vers livingmemory : la même appartenance, ni sa date ni sa source ne changent ; au journal
        $this->assertSame([['app' => 'livingmemory', 'source' => 'backfill', 'joined_at' => '2024-02-03 04:05:06']], $rows($mobile));
        $this->assertSame([['action' => 'reclassify', 'fields' => 'livingmemory,family_split']], $journal('user_app', (string) $mobile));
        $this->assertSame([['app' => 'livingmemory', 'source' => 'register', 'joined_at' => '2026-11-14 01:00:00']], $rows($already), 'déjà membre : sa ligne ne bouge pas, l\'ancienne part');
        // biographes : le rôle par le chemin de la console Équipe (journal user_team), affectés à leurs livres web, sans appartenance ; ils gardent leurs livres
        foreach ([$web, $webWithoutMembership] as $id) {
            $this->assertContains(TeamRoles::MEMOIRES_BIOGRAPHE, $roles($id));
            $this->assertContains('ROLE_USER_INTERNET', $roles($id));
            $this->assertSame([], $rows($id), 'compte d\'équipe : aucune appartenance');
            $this->assertSame([['action' => 'grant', 'fields' => TeamRoles::MEMOIRES_BIOGRAPHE . ',family_split']], $journal('user_team', (string) $id));
        }
        $this->assertSame([['action' => 'revoke', 'fields' => 'memoires,family_split']], $journal('user_app', (string) $web));
        $this->assertSame([], $journal('user_app', (string) $webWithoutMembership), 'pas d\'appartenance : rien à retirer');
        $owner = fn (string $book): array => array_values($this->db->fetchAssociative('SELECT user_id, biographer_id FROM mv_book WHERE id = ?', [$book]));
        $this->assertSame([[$web, $web], [$web, $colleague], [$webWithoutMembership, $webWithoutMembership]], [$owner($free), $owner($taken), $owner($third)], 'propriétaire inchangé ; un biographe déjà affecté le reste');
        $this->assertSame([['action' => 'assign', 'fields' => 'biographerId,family_split']], $journal('book_biographer', $free));
        // sans trace : retirée ; les deux à la fois, l'équipe, le compte sans mot de passe : intacts
        $this->assertSame([[], [['app' => 'memoires', 'source' => 'backfill', 'joined_at' => '2024-05-05 00:00:00']]], [$rows($nothing), $rows($mixed)]);
        $this->assertSame([['ROLE_USER', 'ROLE_USER_INTERNET'], ['ROLE_USER_INTERNET'], ['ROLE_ADMIN', 'ROLE_USER_INTERNET']], [$roles($mixed), $roles($guest), $roles($admin)]);
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_app_membership WHERE app = 'memoires'"), 'il ne reste que le compte à décider');

        // 3. REJOUÉE : plus rien à faire, sauf le compte à décider et le compte sans mot de passe, toujours signalés
        $again = $this->run_(['--apply' => true, '--confirm' => MV_TEST_TENANT2_CODE, '--json' => true]);
        $this->assertSame([['moved' => 0, 'promoted' => 0, 'booksAssigned' => 0, 'revoked' => 0], 1, 0, 0, 0, 1, 1, 2],
            [$again['done'], $again['report']['members'], $again['report']['toLivingMemory'], $again['report']['toBiographer'], $again['report']['revoked'], $again['report']['mixed'], $again['report']['withoutPassword'], $again['report']['livingMemoryMembers']]);
        [$status, $display] = $this->execute([]);
        $this->assertSame([0, true, true], [$status, str_contains($display, 'simulation (aucune écriture)'), str_contains($display, 'Appartenances memoires : 1 ; vers livingmemory : 0')], $display);
    }

    // ---------------------------------------------------------------- aides

    private function useSite(): void
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT2_DB, MV_TEST_TENANT2_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
    }

    /** @return array<string, mixed> le rapport JSON de la commande */
    private function run_(array $arguments): array
    {
        [$status, $display] = $this->execute($arguments);
        $this->assertSame(0, $status, $display);

        return json_decode($display, true);
    }

    /** @return array{0: int, 1: string} */
    private function execute(array $arguments, string $site = MV_TEST_TENANT2_CODE): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:members:family-split'));
        $status = $tester->execute(['site' => $site] + $arguments);
        $this->useSite();

        return [$status, preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    private function account(string $name, string $roles = '["ROLE_USER","ROLE_USER_INTERNET"]', bool $passwordSet = true, ?string $signupChannel = null): int
    {
        $email = 'scission-' . $name . '-' . bin2hex(random_bytes(3)) . '@example.invalid';

        return (int) $this->db->fetchOne('INSERT INTO "user" (id, email, username, firstname, lastname, password, roles, is_verified, otp_enabled, password_set, signup_channel, created_at)
            VALUES (nextval(\'user_id_seq\'), ?, ?, \'Scission\', \'Essai\', \'inutilisable\', ?, true, false, ?, ?, \'2024-01-01 00:00:00\') RETURNING id', [$email, $email, $roles, $passwordSet ? 'true' : 'false', $signupChannel]);
    }

    private function book(int $owner, string $channel, ?int $biographer = null): string
    {
        return (string) $this->db->fetchOne("INSERT INTO mv_book (id, user_id, biographer_id, created_at, updated_at, type, title, format, status, channel) VALUES (gen_random_uuid(), ?, ?, '2024-01-02 00:00:00', NOW(), 'individuel', 'Scission', 'livre_s', 'draft', ?) RETURNING id",
            [$owner, $biographer, $channel]);
    }

    private function member(int $userId, string $app, string $joinedAt, string $source = 'backfill'): void
    {
        $this->db->executeStatement('INSERT INTO user_app_membership (user_id, app, source, joined_at, created_at) VALUES (?, ?, ?, ?, NOW())', [$userId, $app, $source, $joinedAt]);
    }

    private function clean(): void
    {
        $users = 'SELECT id FROM "user" WHERE email LIKE \'scission-%@example.invalid\'';
        foreach (["DELETE FROM mv_book WHERE user_id IN ($users)", "DELETE FROM user_app_membership WHERE user_id IN ($users)", "DELETE FROM \"user\" WHERE id IN ($users)"] as $sql) {
            $this->db->executeStatement($sql);
        }
    }
}
