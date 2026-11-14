<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * APPS-MEMBRES, lot M4 (`docs/apps-membres.md`) : les appartenances LUES par l'administration. La lecture de profil, la console
 * Mémoires Vivantes (filtre `?app=livingmemory`, `memberships` par compte, `member` sur les comptes de l'application) et l'écran
 * Utilisateurs d'EasyAdmin (colonne et filtre « Applications », tableau de la fiche). Rien ici n'écrit une appartenance.
 */
class AppMembershipListsTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = $this->em()->getConnection();
    }

    public function testTheProfileSaysTheFamiliesAndTheTeamRolesOfTheAccount(): void
    {
        $member = $this->user(['ROLE_USER_INTERNET']);
        $this->membership($member, 'livingmemory', 'register', '2026-11-12 03:00:00');
        $this->membership($member, 'boutique', 'join', '2026-11-10 14:00:00', 'v-abc', '2026-11-10 14:00:05');
        $profile = $this->ok('/api/profile', $member);
        $this->assertSame(['id', 'email', 'firstName', 'lastName', 'username', 'roles', 'licenseNumber', 'licenseExpirationDate', 'phone', 'otpEnabled', 'passwordSet', 'memberships', 'teamRoles'], array_keys($profile),
            'deux champs en plus, rien d\'autre ne change');
        $this->assertSame([[['app' => 'boutique', 'joinedAt' => '2026-11-10T14:00:00+00:00', 'termsVersion' => 'v-abc'], ['app' => 'livingmemory', 'joinedAt' => '2026-11-12T03:00:00+00:00', 'termsVersion' => null]], []],
            [$profile['memberships'], $profile['teamRoles']], 'de la plus ancienne à la plus récente ; un client n\'a aucun rôle d\'équipe');

        // un compte d'équipe : ses rôles d'équipe, jamais d'appartenance ; un client sans famille : deux tableaux vides
        $team = $this->ok('/api/profile', $this->user([TeamRoles::MEMOIRES_RESPONSABLE, TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET']));
        $this->assertSame([[], [TeamRoles::MEMOIRES_BIOGRAPHE, TeamRoles::MEMOIRES_RESPONSABLE]], [$team['memberships'], $team['teamRoles']]);
        $nobody = $this->ok('/api/profile', $this->user(['ROLE_USER_INTERNET']));
        $this->assertSame([[], []], [$nobody['memberships'], $nobody['teamRoles']]);
        // les appartenances d'un autre compte ne se mêlent pas
        $other = $this->user(['ROLE_USER_INTERNET']);
        $this->membership($other, 'pos', 'pos_link', '2026-11-11 10:00:00');
        $this->assertSame(['pos'], array_column($this->ok('/api/profile', $other)['memberships'], 'app'));
    }

    public function testTheMemoiresConsoleFiltersAndShowsTheMembersOfLivingMemory(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $mobileMember = $this->user(['ROLE_USER_INTERNET'], 'mobile');
        $this->membership($mobileMember, 'livingmemory', 'register', '2026-11-12 03:00:00', 'v-lm', '2026-11-12 03:00:00');
        $mobileOnly = $this->user(['ROLE_USER_INTERNET'], 'mobile'); // dans la population mobile par sa trace, mais pas membre
        $webMember = $this->user(['ROLE_USER_INTERNET']); // membre, sans trace de l'application
        $this->membership($webMember, 'livingmemory', 'backfill', '2026-11-01 08:00:00');
        $this->membership($webMember, 'boutique', 'register', '2026-10-01 08:00:00');
        $shopper = $this->user(['ROLE_USER_INTERNET']);
        $this->membership($shopper, 'boutique', 'register', '2026-11-02 08:00:00');
        $mine = [$mobileMember->getId(), $mobileOnly->getId(), $webMember->getId(), $shopper->getId()];
        $among = fn (array $rows): array => array_values(array_intersect(array_column($rows, 'id'), $mine));

        // ?app=livingmemory : les membres de la famille, et eux seuls ; le total de l'en-tête suit le filtre
        $members = $this->ok('/api/memoires/admin/users?app=livingmemory', $admin);
        $this->assertSame([$mobileMember->getId(), $webMember->getId()], $among($members));
        $this->assertSame([(string) $this->db->fetchOne("SELECT COUNT(*) FROM user_app_membership WHERE app = 'livingmemory'"), count($members)],
            [$this->client->getResponse()->headers->get('X-Total-Count'), (int) $this->db->fetchOne("SELECT COUNT(*) FROM user_app_membership WHERE app = 'livingmemory'")]);
        // chaque ligne dit les familles du compte, dans l'ordre des familles ; sans filtre, la liste est celle d'avant
        $byId = array_column($this->ok('/api/memoires/admin/users?limit=200&page=' . (int) ceil($this->db->fetchOne('SELECT COUNT(*) FROM "user"') / 200), $admin), null, 'id');
        $this->assertSame([['livingmemory'], [], ['boutique', 'livingmemory'], ['boutique']], array_map(fn (int $id) => $byId[$id]['memberships'], $mine));
        $this->assertSame((string) $this->db->fetchOne('SELECT COUNT(*) FROM "user"'), $this->client->getResponse()->headers->get('X-Total-Count'));
        // la population (calculée d'après les traces) et l'appartenance (réelle) se combinent
        $this->assertSame([$mobileMember->getId(), $mobileOnly->getId()], $among($this->ok('/api/memoires/admin/users?population=mobile', $admin)));
        $this->assertSame([$mobileMember->getId()], $among($this->ok('/api/memoires/admin/users?population=mobile&app=livingmemory', $admin)));
        $this->assertSame([$webMember->getId()], $among($this->ok('/api/memoires/admin/users?population=web_client&app=livingmemory', $admin)));
        // toute autre valeur : 400, la liste des valeurs admises est dite
        foreach (['boutique', 'memoires', 'pos', ''] as $app) {
            [$status, $body] = $this->call('/api/memoires/admin/users?app=' . $app, $admin);
            $this->assertSame([400, 'APP_UNKNOWN', ['apps' => ['livingmemory']]], [$status, $body['code'], $body['params']], $app);
        }

        // Comptes de l'application : `member` dit l'appartenance à la famille, ou null (liste et fiche)
        $expected = ['joinedAt' => strtotime('2026-11-12 03:00:00 UTC'), 'source' => 'register', 'termsVersion' => 'v-lm'];
        $shape = fn (?array $m): ?array => $m === null ? null : ['joinedAt' => strtotime($m['joinedAt'])] + $m;
        $listed = array_column($this->ok('/api/memoires/admin/mobile-users?perPage=100', $admin)['users'], null, 'userId');
        $this->assertSame([$expected, null], [$shape($listed[$mobileMember->getId()]['member']), $listed[$mobileOnly->getId()]['member']]);
        $this->assertArrayHasKey('member', $listed[$mobileOnly->getId()], 'le champ est toujours là');
        $this->assertSame([$expected, null], [$shape($this->ok('/api/memoires/admin/mobile-users/' . $mobileMember->getId(), $admin)['user']['member']), $this->ok('/api/memoires/admin/mobile-users/' . $mobileOnly->getId(), $admin)['user']['member']]);
    }

    public function testTheUsersScreenShowsAndFiltersTheApplicationsAndNeverWritesThem(): void
    {
        $before = $this->db->fetchOne('SELECT pos_timezone FROM entreprise ORDER BY id LIMIT 1');
        $this->db->executeStatement("UPDATE entreprise SET pos_timezone = 'America/Toronto'");
        try {
            $admin = $this->user(['ROLE_ADMIN']);
            $member = $this->user(['ROLE_USER_INTERNET']);
            $this->membership($member, 'livingmemory', 'backfill', '2026-11-12 03:00:00'); // 22 h le 11 au magasin
            $this->membership($member, 'boutique', 'register', '2026-11-10 14:00:00', 'v-abc', '2026-11-10 14:30:00');
            $cashCustomer = $this->user(['ROLE_USER_INTERNET']);
            $this->membership($cashCustomer, 'pos', 'pos_link', '2026-11-11 10:00:00');
            $nobody = $this->user(['ROLE_USER_INTERNET']);
            $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET']);
            $mine = array_map(fn (User $u) => (int) $u->getId(), [$admin, $member, $cashCustomer, $nobody, $biographer]);
            $rows = $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership');
            $this->client->loginUser($admin, 'main');

            // Colonne « Applications » : les familles du compte, « Équipe » pour un compte d'équipe, « Aucune » sinon
            $shown = fn (array $query = []): array => $this->applications($query, $mine);
            $this->assertSame([
                $admin->getId() => ['team' => 'Équipe'], $member->getId() => ['boutique' => 'Boutique', 'livingmemory' => 'Living Memory'], $cashCustomer->getId() => ['pos' => 'Caisse'],
                $nobody->getId() => ['none' => 'Aucune'], $biographer->getId() => ['team' => 'Équipe'],
            ], $shown());

            // Filtre « Application » : il rend exactement les comptes dont la colonne porte la valeur ; « n'est pas » les retire
            $is = fn (string $value, string $comparison = '='): array => array_keys($shown(['filters' => ['applications' => ['comparison' => $comparison, 'value' => $value]]]));
            $this->assertSame([[$member->getId()], [$member->getId()], [$cashCustomer->getId()], [$nobody->getId()], [$admin->getId(), $biographer->getId()]],
                [$is('boutique'), $is('livingmemory'), $is('pos'), $is('none'), $is('team')]);
            $this->assertSame([[$admin->getId(), $cashCustomer->getId(), $nobody->getId(), $biographer->getId()], [$member->getId(), $cashCustomer->getId(), $nobody->getId()]], [$is('boutique', '!='), $is('team', '!=')]);
            // une famille sans aucun membre : liste vide, sans erreur ; son contraire : tout le monde
            $this->db->executeStatement("UPDATE user_app_membership SET app = 'boutique' WHERE app = 'pos' AND user_id <> ?", [$cashCustomer->getId()]);
            $this->db->executeStatement('DELETE FROM user_app_membership WHERE user_id = ?', [$cashCustomer->getId()]);
            $this->assertSame([[], $mine], [$is('pos'), $is('pos', '!=')]);
            $this->assertSame([$cashCustomer->getId(), $nobody->getId()], $is('none'), 'le compte qui n\'a plus de famille passe à « Aucune »');
            // une valeur forgée ne casse pas la liste
            $this->client->request('GET', $this->url(['crudAction' => 'index', 'filters' => ['applications' => ['comparison' => '=', 'value' => 'inconnue']]]));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            // la colonne ne se trie pas (ce n'est pas une colonne du compte)
            $this->client->request('GET', $this->url(['crudAction' => 'index']));
            $this->assertStringNotContainsString('sort%5Bapplications%5D', (string) $this->client->getResponse()->getContent());

            // Fiche d'un compte : ses appartenances, à l'heure du magasin, en lecture seule
            $crawler = $this->client->request('GET', $this->url(['crudAction' => 'detail', 'entityId' => $member->getId()]));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $table = $crawler->filter('table[data-memberships]');
            $this->assertSame('2', $table->attr('data-memberships'));
            $this->assertSame([['Boutique', '10/11/2026 09:00', 'Inscription', 'v-abc', '10/11/2026 09:30'], ['Living Memory', '11/11/2026 22:00', 'Reprise de l\'historique', '—', '—']],
                $table->filter('tbody tr')->each(fn ($tr) => $tr->filter('td')->each(fn ($td) => trim($td->text()))));
            $this->assertSame(0, $table->filter('a, button, form, input')->count(), 'aucune action sur une appartenance');
            $this->assertStringContainsString('heure du magasin', $crawler->filter('table[data-memberships]')->ancestors()->first()->text());
            $crawler = $this->client->request('GET', $this->url(['crudAction' => 'detail', 'entityId' => $nobody->getId()]));
            $this->assertSame(['0', 'Aucune appartenance'], [$crawler->filter('[data-memberships]')->attr('data-memberships'), trim($crawler->filter('[data-memberships]')->text())]);
            // le formulaire d'un compte ne porte aucun champ d'appartenance, et l'enregistrer n'en crée ni n'en retire
            $crawler = $this->client->request('GET', $this->url(['crudAction' => 'edit', 'entityId' => $nobody->getId()]));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertSame(0, $crawler->filter('[name*="applications"], [name*="appMemberships"]')->count());
            $this->assertSame((int) $rows - 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_app_membership'), 'seule la ligne retirée par le test manque : l\'écran n\'a rien écrit');
        } finally {
            $this->db->executeStatement('UPDATE entreprise SET pos_timezone = ?', [$before ?: null]);
        }
    }

    // ---------------------------------------------------------------- aides

    /** @return array<int, array<string, string>> compte => code d'application => libellé affiché, pour les comptes du test présents dans la liste */
    private function applications(array $query, array $among): array
    {
        $crawler = $this->client->request('GET', $this->url(['crudAction' => 'index', 'sort' => ['id' => 'DESC']] + $query));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));
        $found = [];
        $crawler->filter('table tbody tr[data-id]')->each(function ($row) use (&$found, $among): void {
            if (in_array((int) $row->attr('data-id'), $among, true)) {
                $found[(int) $row->attr('data-id')] = array_combine($row->filter('span[data-application]')->each(fn ($s) => $s->attr('data-application')), $row->filter('span[data-application]')->each(fn ($s) => trim($s->text())));
            }
        });
        ksort($found);

        return $found;
    }

    private function url(array $params): string
    {
        return 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query($params + ['crudControllerFqcn' => UserCrudController::class]);
    }

    private function membership(User $user, string $app, string $source, string $joinedAt, ?string $termsVersion = null, ?string $acceptedAt = null): void
    {
        $this->db->executeStatement("INSERT INTO user_app_membership (id, user_id, app, source, joined_at, terms_version, terms_accepted_at, created_at) VALUES (nextval('user_app_membership_id_seq'), ?, ?, ?, ?, ?, ?, NOW())",
            [$user->getId(), $app, $source, $joinedAt, $termsVersion, $acceptedAt]);
    }

    private function user(array $roles, ?string $signupChannel = null): User
    {
        $email = 'listes-m4-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Compte')->setLastname('Listes')->setRoles($roles)->setIsVerified(true)->setPasswordSet(true)->setPassword('inutilisable')->setSignupChannel($signupChannel);
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @return mixed corps d'une réponse 200 */
    private function ok(string $path, User $as): mixed
    {
        [$status, $body] = $this->call($path, $as);
        $this->assertSame(200, $status, $path . ' : ' . json_encode($body));

        return $body;
    }

    /** @return array{0: int, 1: mixed} lecture avec le jeton d'un compte créé par le test */
    private function call(string $path, User $as): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as)]);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)];
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
