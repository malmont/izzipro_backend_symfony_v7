<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Command\MemoiresTeamGrantCommand;
use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Équipe de Mémoires Vivantes, lot MV-BIOGRAPHE (13/11/2026) : rôle ROLE_MEMOIRES_BIOGRAPHE posé sur un compte de la base commune,
 * attribution et retrait journalisés (content_audit_log, user_team), population « team » réservée à ce rôle, « admin » pour un
 * administrateur sans lui, filtre serveur ?population=, liste légère GET /team, livres de l'entreprise ouverts au biographe,
 * écrans d'administration toujours réservés à ROLE_ADMIN. Contrat : docs/memoires-vivantes.md, « Équipe ».
 */
class MemoiresTeamTest extends BookTypeApiTestCase
{
    public function testAnAdminGrantsAndRevokesTheBiographerRoleWithAJournal(): void
    {
        $member = $this->user(['ROLE_USER_INTERNET'], 'futur-biographe');
        $admin = $this->existing(self::ADMIN_EMAIL);

        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => true], $admin);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['isBiographer']);
        $this->assertTrue($body['changed']);
        $this->assertSame([TeamRoles::MEMOIRES_BIOGRAPHE], $body['teamRoles']);
        $this->assertContains('ROLE_USER_INTERNET', $body['roles'], 'le rôle compagnon d\'accès à l\'API est garanti');

        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => true], $admin);
        $this->assertSame(200, $status);
        $this->assertFalse($body['changed'], 'idempotent');
        $journal = $this->journal($member);
        $this->assertCount(1, $journal, 'rien n\'est journalisé quand rien ne change');
        $this->assertSame('grant', $journal[0]['action']);
        $this->assertSame(self::ADMIN_EMAIL, $journal[0]['user_identifier']);
        $this->assertStringContainsString(TeamRoles::MEMOIRES_BIOGRAPHE, $journal[0]['fields']);
        $this->assertStringContainsString(TeamRoles::MEMOIRES_BIOGRAPHE, (string) $journal[0]['after_state']);
        $this->assertStringNotContainsString(TeamRoles::MEMOIRES_BIOGRAPHE, (string) $journal[0]['before_state']);

        // Population team : les biographes, et eux seuls ; admin : l'administrateur du site sans ce rôle
        [$status, $team, $headers] = $this->callWithHeaders('GET', '/api/memoires/admin/users?population=team', null, $admin);
        $this->assertSame(200, $status);
        $this->assertSame(count($team), (int) $headers['x-total-count']);
        $this->assertContains($member->getId(), array_column($team, 'id'));
        foreach ($team as $row) {
            $this->assertTrue($row['isBiographer'], $row['email']);
            $this->assertSame('team', $row['population']);
        }
        [$status, $admins] = $this->call('GET', '/api/memoires/admin/users?population=admin', null, $admin);
        $this->assertSame(200, $status);
        $this->assertContains($admin->getId(), array_column($admins, 'id'));
        $this->assertNotContains($member->getId(), array_column($admins, 'id'));
        foreach ($admins as $row) {
            $this->assertSame('admin', $row['population']);
            $this->assertFalse($row['isBiographer']);
        }
        [$status, $body] = $this->call('GET', '/api/memoires/admin/users?population=inconnue', null, $admin);
        $this->assertSame(400, $status);
        $this->assertSame('POPULATION_INVALID', $body['code']);

        // Le changement admin / utilisateur de l'écran existant ne fait pas perdre le rôle d'équipe
        [$status] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId(), ['role' => 'ROLE_USER'], $admin);
        $this->assertSame(200, $status);
        [, $team] = $this->call('GET', '/api/memoires/admin/users?population=team', null, $admin);
        $this->assertContains($member->getId(), array_column($team, 'id'), 'le rôle d\'équipe survit à l\'écran des rôles');

        // Erreurs
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/999999/team', ['biographer' => true], $admin);
        $this->assertSame(404, $status);
        $this->assertSame('USER_NOT_FOUND', $body['code']);
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => 'oui'], $admin);
        $this->assertSame(400, $status);
        $this->assertSame('INVALID_BODY', $body['code']);
        $super = $this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], 'proprietaire');
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $super->getId() . '/team', ['biographer' => true], $admin);
        $this->assertSame(403, $status);
        $this->assertSame('OWNER_PROTECTED', $body['code']);

        // Retrait
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $member->getId() . '/team', ['biographer' => false], $admin);
        $this->assertSame(200, $status);
        $this->assertFalse($body['isBiographer']);
        $this->assertSame([], $body['teamRoles']);
        $journal = $this->journal($member);
        $this->assertCount(2, $journal);
        $this->assertSame('revoke', $journal[0]['action']);
        [, $team] = $this->call('GET', '/api/memoires/admin/users?population=team', null, $admin);
        $this->assertNotContains($member->getId(), array_column($team, 'id'));
    }

    /**
     * Modèle du 14/11/2026 : un biographe ne travaille que sur SES livres, ceux qu'il possède et ceux dont il est le biographe affecté.
     * Il n'affecte pas, n'invite personne, ne voit ni l'équipe ni la console ; il confirme les rendez-vous de ses livres.
     */
    public function testABiographerWorksOnHisOwnAndAssignedBooksOnly(): void
    {
        $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'biographe');
        $colleague = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'collegue');
        $manager = $this->user([TeamRoles::MEMOIRES_RESPONSABLE, 'ROLE_USER_INTERNET'], 'responsable');
        $owned = $this->book('Livre que le biographe possède', $biographer);
        $assigned = $this->book('Livre d\'un collègue, affecté au biographe', $colleague, $biographer);
        $foreign = $this->book('Livre d\'un collègue', $colleague, $colleague); // affecté à un AUTRE biographe : toujours fermé
        $refused = fn (int $status): bool => in_array($status, [401, 403], true);

        // La liste : ses livres, possédés et affectés ; les filtres de l'entreprise n'ont aucun effet pour lui
        foreach (['', '?scope=all', '?scope=all&userId=' . $colleague->getId(), '?scope=all&biographerId=none', '?scope=all&biographerId=' . $colleague->getId()] as $query) {
            [$status, $books] = $this->call('GET', '/api/memoires/books' . $query, null, $biographer);
            $this->assertSame(200, $status);
            $this->assertSame(self::sorted([$assigned, $owned]), self::sorted(array_intersect(array_column($books, 'id'), [$owned, $assigned, $foreign])), "liste « $query » : possédé et affecté, jamais le livre d'un autre");
        }
        // Lire et modifier : ses livres ; le livre d'un autre lui est fermé (livre, chapitres, lien signé, rendez-vous du livre)
        foreach ([$owned, $assigned] as $id) {
            [$status] = $this->call('GET', "/api/memoires/books/$id", null, $biographer);
            $this->assertSame(200, $status);
            [$status, $chapter] = $this->call('POST', "/api/memoires/books/$id/chapters", ['title' => 'Chapitre du biographe', 'theme' => 'regards_croises', 'position' => 1], $biographer);
            $this->assertSame(201, $status, json_encode($chapter));
            [$status] = $this->call('PUT', '/api/memoires/chapters/' . $chapter['id'], ['title' => 'Chapitre revu'], $biographer);
            $this->assertSame(200, $status);
            [$status] = $this->call('GET', '/api/memoires/chapters/' . $chapter['id'] . '/share-link', null, $biographer);
            $this->assertSame(200, $status, 'il envoie des liens signés sur ses livres');
        }
        [$status, $updated] = $this->call('PUT', "/api/memoires/books/$assigned", ['title' => 'Titre revu par le biographe affecté'], $biographer);
        $this->assertSame([200, 'Titre revu par le biographe affecté'], [$status, $updated['title']]);
        [$status, $theirs] = $this->call('POST', "/api/memoires/books/$foreign/chapters", ['title' => 'Chapitre du collègue', 'theme' => 'regards_croises', 'position' => 1], $colleague);
        $this->assertSame(201, $status, json_encode($theirs));
        foreach ([['GET', "/api/memoires/books/$foreign", null], ['PUT', "/api/memoires/books/$foreign", ['title' => 'Interdit']], ['POST', "/api/memoires/books/$foreign/chapters", ['title' => 'Interdit', 'theme' => 'regards_croises', 'position' => 2]],
            ['GET', '/api/memoires/chapters/' . $theirs['id'], null], ['PUT', '/api/memoires/chapters/' . $theirs['id'], ['title' => 'Interdit']], ['GET', '/api/memoires/chapters/' . $theirs['id'] . '/share-link', null],
            ['GET', "/api/memoires/books/$foreign/reservations", null]] as [$method, $path, $body]) {
            [$status] = $this->call($method, $path, $body, $biographer);
            $this->assertTrue($refused($status), "$method $path : $status");
        }
        $this->assertSame('Livre d\'un collègue', self::db()->query("SELECT title FROM mv_book WHERE id = '$foreign'")->fetchColumn(), 'rien n\'a été modifié');

        // Il n'affecte pas (même sur son livre) ; renvoyer la valeur actuelle reste toléré
        [$status, $body] = $this->call('PUT', "/api/memoires/books/$assigned", ['biographerId' => null], $biographer);
        $this->assertSame([403, 'FORBIDDEN'], [$status, $body['code']]);
        [$status] = $this->call('PUT', "/api/memoires/books/$assigned", ['biographerId' => $biographer->getId()], $biographer);
        $this->assertSame(200, $status);
        [$status, $body] = $this->call('PUT', "/api/memoires/books/$owned", ['biographerId' => $colleague->getId()], $biographer);
        $this->assertSame(403, $status, json_encode($body));

        // Ni l'équipe, ni la console : il n'invite personne et ne donne aucun rôle
        foreach ([['GET', '/api/memoires/team', null], ['GET', '/api/memoires/admin/users', null], ['POST', '/api/memoires/admin/users', ['email' => 'x-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'X', 'lastName' => 'Y']],
            ['PUT', '/api/memoires/admin/users/' . $colleague->getId() . '/team', ['biographer' => false]], ['GET', '/api/memoires/admin/pricing', null]] as [$method, $path, $body]) {
            [$status] = $this->call($method, $path, $body, $biographer);
            $this->assertSame(403, $status, "$method $path");
        }
        $this->assertContains(TeamRoles::MEMOIRES_BIOGRAPHE, json_decode((string) self::db()->query('SELECT roles FROM "user" WHERE id = ' . (int) $colleague->getId())->fetchColumn(), true));

        // Le livre web qu'il crée : il en devient le biographe affecté (au journal), sans quota d'IA
        [$status, $created] = $this->call('POST', '/api/memoires/books', ['title' => 'Livre créé par le biographe', 'type' => 'individuel'], $biographer);
        $this->assertSame([201, $biographer->getId(), false, 'web'], [$status, $created['biographer']['id'] ?? null, $created['aiQuotaLimited'], $created['channel']], json_encode($created));
        $st = self::db()->prepare("SELECT action, fields FROM content_audit_log WHERE resource = 'book_biographer' AND resource_id = ?");
        $st->execute([$created['id']]);
        $this->assertSame([['action' => 'assign', 'fields' => 'biographerId,creator']], $st->fetchAll(\PDO::FETCH_ASSOC));

        // Rendez-vous : il ne voit que les siens ; il confirme ceux dont il est le biographe et ceux de SES livres, pas celui d'un autre livre
        $date = sprintf('2030-%02d-%02d', random_int(1, 12), random_int(1, 28));
        $payload = ['service_name' => 'Séance', 'reservation_date' => $date, 'client_name' => 'Cliente', 'client_email' => 'cliente-equipe@example.invalid', 'client_phone' => '0600000000'];
        $reserve = function (array $more) use ($payload, $manager): array {
            [$status, $res] = $this->call('POST', '/api/reservations', $more + $payload, $manager);
            $this->assertSame(201, $status, json_encode($res));

            return $res['data'];
        };
        $his = $reserve(['book_id' => $assigned, 'reservation_slot' => '09:00']);
        $ofHisBook = $reserve(['book_id' => $owned, 'biographer_id' => $colleague->getId(), 'reservation_slot' => '10:00']);
        $notHis = $reserve(['book_id' => $foreign, 'biographer_id' => $colleague->getId(), 'reservation_slot' => '11:00']);
        $this->assertSame([$biographer->getId(), $colleague->getId()], [$his['biographer_id'], $notHis['biographer_id']]);
        [$status, $list] = $this->call('GET', '/api/reservations?scope=all&biographer_id=' . $colleague->getId(), null, $biographer);
        $this->assertSame(200, $status);
        $seen = array_column($list['data'] ?? $list, 'id');
        $this->assertContains($his['id'], $seen);
        $this->assertNotContains($notHis['id'], $seen, 'le filtre par biographe ne lui est pas ouvert');
        $confirm = fn (int $id, User $as): int => $this->call('POST', "/api/reservations/$id/confirm", [], $as)[0];
        $this->assertSame([200, 200, 403], [$confirm($his['id'], $biographer), $confirm($ofHisBook['id'], $biographer), $confirm($notHis['id'], $biographer)]);

        // Retiré d'un livre par le responsable, il ne le voit plus
        [$status] = $this->call('PUT', "/api/memoires/books/$assigned", ['biographerId' => null], $manager);
        $this->assertSame(200, $status);
        [$status] = $this->call('GET', "/api/memoires/books/$assigned", null, $biographer);
        $this->assertTrue($refused($status));
        [, $books] = $this->call('GET', '/api/memoires/books', null, $biographer);
        $this->assertNotContains($assigned, array_column($books, 'id'));
        $this->assertContains($owned, array_column($books, 'id'));
    }

    /**
     * Le responsable des biographes (14/11/2026) : tous les livres du site, l'équipe (inviter un biographe, donner ou retirer le rôle de
     * biographe, affecter), tous les rendez-vous. Il ne nomme pas de responsable et n'a rien des écrans de l'administrateur.
     */
    public function testTheHeadOfBiographersManagesTheBooksAndTheTeamButNothingElse(): void
    {
        $manager = $this->user([TeamRoles::MEMOIRES_RESPONSABLE, 'ROLE_USER_INTERNET'], 'responsable');
        $biographer = $this->user([TeamRoles::MEMOIRES_BIOGRAPHE, 'ROLE_USER_INTERNET'], 'biographe');
        $candidate = $this->user(['ROLE_USER_INTERNET'], 'futur-biographe');
        $admin = $this->existing(self::ADMIN_EMAIL);
        $book = $this->book('Livre d\'un biographe', $biographer);
        $ofClient = $this->book('Livre d\'un client d\'avant', $candidate);

        // Tous les livres, avec les filtres ; lecture, modification, affectation
        $ids = fn (string $query): array => self::sorted(array_intersect(array_column($this->call('GET', '/api/memoires/books' . $query, null, $manager)[1], 'id'), [$book, $ofClient]));
        $this->assertSame(self::sorted([$ofClient, $book]), $ids(''));
        $this->assertSame([$ofClient], $ids('?scope=all&userId=' . $candidate->getId()));
        $this->assertSame(self::sorted([$ofClient, $book]), $ids('?scope=all&biographerId=none'));
        [$status, $updated] = $this->call('PUT', "/api/memoires/books/$ofClient", ['title' => 'Revu par le responsable', 'biographerId' => $biographer->getId()], $manager);
        $this->assertSame([200, 'Revu par le responsable', $biographer->getId()], [$status, $updated['title'], $updated['biographer']['id']], json_encode($updated));
        $this->assertSame([$ofClient], $ids('?scope=all&biographerId=' . $biographer->getId()));
        [$status, $created] = $this->call('POST', '/api/memoires/books', ['title' => 'Livre créé par le responsable', 'type' => 'individuel'], $manager);
        $this->assertSame([201, null, false], [$status, $created['biographer'], $created['aiQuotaLimited']], 'il n\'est pas biographe : le livre naît sans biographe ; pas de quota d\'IA');

        // L'équipe et la console Équipe
        [$status, $team] = $this->call('GET', '/api/memoires/team', null, $manager);
        $this->assertSame(200, $status);
        $this->assertContains($biographer->getId(), array_column($team, 'id'));
        [$status, $users] = $this->call('GET', '/api/memoires/admin/users?population=team', null, $manager);
        $this->assertSame(200, $status);
        $me = array_values(array_filter($users, fn ($u) => $u['id'] === $manager->getId()));
        $this->assertSame([true, false, 'team'], [$me[0]['isResponsable'], $me[0]['isBiographer'], $me[0]['population']], 'le responsable est de l\'équipe');
        // il invite un biographe (plus un client), et ne crée pas d'administrateur
        $email = 'biographe-invite-' . bin2hex(random_bytes(3)) . '@example.invalid';
        [$status, $body] = $this->call('POST', '/api/memoires/admin/users', ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Biographe', 'password' => 'Mot-de-passe-1!'], $manager);
        $this->assertSame([201, 'ROLE_MEMOIRES_BIOGRAPHE', true, [TeamRoles::MEMOIRES_BIOGRAPHE]], [$status, $body['user']['role'], $body['user']['isBiographer'], $body['user']['teamRoles']], json_encode($body));
        $st = self::db()->prepare("SELECT action, fields, user_identifier FROM content_audit_log WHERE resource = 'user_team' AND resource_id = ?");
        $st->execute([(string) $body['user']['id']]);
        $this->assertSame([['action' => 'grant', 'fields' => TeamRoles::MEMOIRES_BIOGRAPHE . ',invite', 'user_identifier' => $manager->getEmail()]], $st->fetchAll(\PDO::FETCH_ASSOC), 'le rôle est posé par le chemin de la console Équipe, au journal');
        foreach ([['ROLE_ADMIN', 'FORBIDDEN'], ['ROLE_USER', 'MEMOIRES_TEAM_ONLY'], ['ROLE_MEMOIRES_RESPONSABLE', 'MEMOIRES_TEAM_ONLY']] as [$role, $code]) {
            [$status, $body] = $this->call('POST', '/api/memoires/admin/users', ['email' => 'refus-' . bin2hex(random_bytes(3)) . '@example.invalid', 'firstName' => 'Refus', 'lastName' => 'Test', 'role' => $role], $manager);
            $this->assertSame([403, $code], [$status, $body['code']], $role);
        }
        // il donne et retire le rôle de biographe ; nommer un responsable reste à l'administrateur
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId() . '/team', ['biographer' => true], $manager);
        $this->assertSame([200, true, false], [$status, $body['isBiographer'], $body['isResponsable']]);
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId() . '/team', ['responsable' => true], $manager);
        $this->assertSame([403, 'FORBIDDEN'], [$status, $body['code']]);
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId() . '/team', ['biographer' => false, 'responsable' => true], $manager);
        $this->assertSame(403, $status, 'rien n\'est appliqué quand un champ est refusé');
        $this->assertContains(TeamRoles::MEMOIRES_BIOGRAPHE, json_decode((string) self::db()->query('SELECT roles FROM "user" WHERE id = ' . (int) $candidate->getId())->fetchColumn(), true));
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId() . '/team', ['responsable' => true], $admin);
        $this->assertSame([200, true, true, [TeamRoles::MEMOIRES_BIOGRAPHE, TeamRoles::MEMOIRES_RESPONSABLE]], [$status, $body['isResponsable'], $body['changed'], $body['teamRoles']], json_encode($body));
        $this->assertSame('grant', $this->journal($candidate)[0]['action']);
        $this->assertStringContainsString(TeamRoles::MEMOIRES_RESPONSABLE, $this->journal($candidate)[0]['fields']);
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId() . '/team', ['responsable' => false, 'biographer' => false], $admin);
        $this->assertSame([200, false, false, []], [$status, $body['isResponsable'], $body['isBiographer'], $body['teamRoles']]);
        [$status, $body] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId() . '/team', ['autre' => true], $admin);
        $this->assertSame([400, 'INVALID_BODY'], [$status, $body['code']]);

        // Rien d'autre : modifier ou supprimer un compte, les écrans de l'administrateur
        [$status] = $this->call('PUT', '/api/memoires/admin/users/' . $candidate->getId(), ['firstName' => 'X'], $manager);
        $this->assertSame(403, $status);
        [$status] = $this->call('DELETE', '/api/memoires/admin/users/' . $candidate->getId(), null, $manager);
        $this->assertSame(403, $status);
        foreach (['/api/memoires/admin/pricing', '/api/memoires/admin/payments', '/api/memoires/admin/revenue', '/api/memoires/admin/book-types', '/api/memoires/admin/ai-quotas', '/api/memoires/admin/mobile-users'] as $path) {
            [$status] = $this->call('GET', $path, null, $manager);
            $this->assertSame(403, $status, $path . ' reste à ROLE_ADMIN');
        }

        // Tous les rendez-vous : il les voit et confirme celui d'un biographe
        $date = sprintf('2031-%02d-%02d', random_int(1, 12), random_int(1, 28));
        [$status, $res] = $this->call('POST', '/api/reservations', ['service_name' => 'Séance', 'reservation_date' => $date, 'reservation_slot' => '09:00', 'client_name' => 'Cliente', 'client_email' => 'cliente-resp@example.invalid',
            'client_phone' => '0600000000', 'biographer_id' => $biographer->getId()], $biographer);
        $this->assertSame(201, $status, json_encode($res));
        [$status, $list] = $this->call('GET', '/api/reservations?biographer_id=' . $biographer->getId(), null, $manager);
        $this->assertSame(200, $status);
        $this->assertContains($res['data']['id'], array_column($list['data'] ?? $list, 'id'));
        [$status] = $this->call('POST', '/api/reservations/' . $res['data']['id'] . '/confirm', [], $manager);
        $this->assertSame(200, $status);
    }

    /** @param array<int|string, string> $ids @return list<string> */
    private static function sorted(array $ids): array
    {
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    /** Livre web posé en base ; rend son identifiant */
    private function book(string $title, User $owner, ?User $biographer = null): string
    {
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $book = (new Book())->setTitle($title)->setType('individuel')->setPerson1FirstName('Lina')->setUser($em->getRepository(User::class)->find($owner->getId()));
        if ($biographer !== null) {
            $book->setBiographer($em->getRepository(User::class)->find($biographer->getId()));
        }
        $em->persist($book);
        $em->flush();

        return (string) $book->getId();
    }

    public function testTheConsoleCommandGrantsTheRoleOnASiteWithAJournalMark(): void
    {
        $member = $this->user(['ROLE_USER_INTERNET'], 'par-console');
        $tester = new CommandTester(static::getContainer()->get(MemoiresTeamGrantCommand::class));
        $this->assertSame(0, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail()]), $tester->getDisplay());
        $this->assertStringContainsString('rôle ROLE_MEMOIRES_BIOGRAPHE ajouté', $tester->getDisplay());
        $journal = $this->journal($member);
        $this->assertCount(1, $journal);
        $this->assertSame('grant', $journal[0]['action']);
        $this->assertStringContainsString('console', $journal[0]['fields']);
        $roles = json_decode((string) self::db()->query('SELECT roles FROM "user" WHERE id = ' . (int) $member->getId())->fetchColumn(), true);
        $this->assertContains(TeamRoles::MEMOIRES_BIOGRAPHE, $roles);
        $this->assertContains('ROLE_USER_INTERNET', $roles);

        $this->assertSame(0, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail(), '--revoke' => true]));
        $this->assertStringContainsString('rôle ROLE_MEMOIRES_BIOGRAPHE retiré', $tester->getDisplay());
        // le responsable des biographes, par la même commande
        $this->assertSame(0, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail(), '--role' => 'responsable']));
        $this->assertStringContainsString('rôle ROLE_MEMOIRES_RESPONSABLE ajouté', $tester->getDisplay());
        $this->assertContains(TeamRoles::MEMOIRES_RESPONSABLE, json_decode((string) self::db()->query('SELECT roles FROM "user" WHERE id = ' . (int) $member->getId())->fetchColumn(), true));
        $this->assertSame(1, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail(), '--role' => 'directeur']));
        $this->assertSame(1, $tester->execute(['tenant' => 'site-inexistant', 'email' => $member->getEmail()]));
        $this->assertSame(1, $tester->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => 'personne@example.invalid']));
    }

    /** @return list<array<string, mixed>> entrées user_team du compte, la plus récente d'abord */
    private function journal(User $user): array
    {
        $st = self::db()->prepare('SELECT action, fields, user_identifier, before_state, after_state FROM content_audit_log WHERE resource = ? AND resource_id = ? ORDER BY id DESC');
        $st->execute([TeamRoles::AUDIT_RESOURCE, (string) $user->getId()]);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function user(array $roles, string $prefix): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = $prefix . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Test')->setLastname(ucfirst(explode('-', $prefix)[0]))->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function existing(string $email): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $user = $provider->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, $email);

        return $user;
    }

    /** @return array{0: int, 1: mixed} */
    private function call(string $method, string $path, ?array $body, ?User $as): array
    {
        [$status, $json] = $this->callWithHeaders($method, $path, $body, $as);

        return [$status, $json];
    }

    /** @return array{0: int, 1: mixed, 2: array<string, string>} */
    private function callWithHeaders(string $method, string $path, ?array $body, ?User $as): array
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($method !== 'GET') {
            $server['HTTP_X_XSRF_TOKEN'] = 'jeton-xsrf-de-test';
        }
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, $path . (str_contains($path, '?') ? '&' : '?') . 'locale=fr', [], [], $server, $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[strtolower($name)] = implode(',', $values);
        }

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $headers];
    }
}
