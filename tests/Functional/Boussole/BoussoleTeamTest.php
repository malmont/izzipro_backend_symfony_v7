<?php

namespace App\Tests\Functional\Boussole;

use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Entity\User;
use App\ESG\Entity\DiagnosticReport;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgCompany;
use App\ESG\Entity\EsgDocument;
use App\ESG\Entity\EsgUser;
use App\ESG\Enum\DomainEnum;
use App\ESG\Enum\ReportStatusEnum;
use App\ESG\Enum\SectorEnum;
use App\ESG\Enum\SessionStatusEnum;
use App\ESG\Enum\SizeEnum;
use App\ESG\Enum\TerritoryEnum;
use App\ESG\Exception\EmailUnavailableException;
use App\ESG\Service\TeamViewJournal;
use App\Security\RoleAssignmentPolicy;
use App\Security\TeamRoles;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Premier test fonctionnel de la Boussole ESG, lot BOUSSOLE-CONSEILLER (13/11/2026, `docs/boussole-esg.md`, « Équipe ») : le conseiller
 * (`ROLE_BOUSSOLE_CONSEILLER`, compte de la base commune) lit toutes les entreprises du site, leurs diagnostics, rapports et documents,
 * et chaque lecture d'un détail est inscrite au journal des consultations ; il n'écrit rien ; un client de la boutique et un compte
 * d'entreprise n'entrent pas dans les routes d'équipe ; une entreprise ne lit toujours que ses propres données ; l'attribution du rôle
 * est journalisée et le super administrateur protégé ; l'inscription avec une adresse connue rend un seul code ; « qui suis-je ».
 *
 * Le test crée lui-même ses entreprises, ses fichiers et leurs dossiers, et les retire à la fin.
 */
class BoussoleTeamTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $tag;
    /** @var list<int> */
    private array $companyIds = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = 'bsl' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach ($this->companyIds as $id) {
            $conn->executeStatement('DELETE FROM esg_user WHERE company_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM esg_company WHERE id = ?', [$id]); // diagnostics, rapports et documents suivent (ON DELETE CASCADE)
        }
        $conn->executeStatement('DELETE FROM esg_user WHERE email LIKE ?', ['%' . $this->tag . '%']);
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testTheAdvisorReadsEveryCompanyAndEachDetailReadIsJournaled(): void
    {
        [$a, $owner, $session, $document] = $this->companyWithEverything('Alpha');
        $b = $this->company('Bravo');
        $advisor = $this->user(['ROLE_USER_INTERNET', TeamRoles::BOUSSOLE_CONSEILLER], 'conseiller');
        $uuid = $session->getUuid()->toRfc4122();

        // Liste : toutes les entreprises du site, sans assignation, par nom, avec compteurs et dernier diagnostic ; non journalisée
        [$status, $list] = $this->call('GET', '/api/boussole/team/companies?q=' . $this->tag, null, $advisor);
        $this->assertSame(200, $status, json_encode($list));
        $this->assertSame([2, 1, 50], [$list['total'], $list['page'], $list['limit']]);
        $this->assertSame([$a->getId(), $b->getId()], array_column($list['companies'], 'id'), 'par nom');
        [$rowA, $rowB] = $list['companies'];
        $this->assertSame([1, 1, 1, true], [$rowA['usersCount'], $rowA['sessionsCount'], $rowA['documentsCount'], $rowA['isActive']]);
        $this->assertSame(['uuid' => $uuid, 'status' => 'completed', 'scoreGlobal' => 62.5, 'maturityLevel' => 'Confirmé'], array_intersect_key($rowA['lastSession'], array_flip(['uuid', 'status', 'scoreGlobal', 'maturityLevel'])));
        $this->assertNotNull($rowA['lastSession']['completedAt']);
        $this->assertSame([0, 0, 0, null], [$rowB['usersCount'], $rowB['sessionsCount'], $rowB['documentsCount'], $rowB['lastSession']]);
        foreach (['name', 'slug', 'sector', 'sizeCategory', 'territory', 'contactEmail', 'existingCertifications', 'createdAt'] as $key) {
            $this->assertArrayHasKey($key, $rowA, 'la fiche rendue à l\'entreprise, plus les champs d\'équipe');
        }
        [, $page2] = $this->call('GET', '/api/boussole/team/companies?q=' . $this->tag . '&limit=1&page=2', null, $advisor);
        $this->assertSame([2, 2, 1, [$b->getId()]], [$page2['total'], $page2['page'], $page2['limit'], array_column($page2['companies'], 'id')]);
        [, $capped] = $this->call('GET', '/api/boussole/team/companies?q=' . $this->tag . '&limit=5000', null, $advisor);
        $this->assertSame(200, $capped['limit'], 'plafond');
        [, $byCity] = $this->call('GET', '/api/boussole/team/companies?q=' . strtoupper('ville-alpha-' . $this->tag), null, $advisor);
        $this->assertSame([$a->getId()], array_column($byCity['companies'], 'id'), 'recherche sans casse, sur la ville aussi');
        $this->assertSame([], $this->views($a), 'une liste n\'est pas journalisée');

        // Détail d'une entreprise : fiche, comptes, diagnostics, documents ; journalisé
        [$status, $detail] = $this->call('GET', '/api/boussole/team/companies/' . $a->getId(), null, $advisor);
        $this->assertSame(200, $status, json_encode($detail));
        $this->assertSame([$a->getId(), 1], [$detail['company']['id'], $detail['company']['sessionsCount']]);
        $this->assertSame([['id' => $owner->getId(), 'email' => $owner->getEmail(), 'firstName' => 'Alice', 'lastName' => 'Alpha', 'isActive' => true]], $detail['users']);
        $this->assertSame([$uuid], array_column($detail['sessions'], 'uuid'));
        $this->assertSame([['id' => $document->getId(), 'downloadUrl' => '/api/boussole/team/documents/' . $document->getId() . '/download']], array_map(fn (array $d) => ['id' => $d['id'], 'downloadUrl' => $d['downloadUrl']], $detail['documents']));
        $views = $this->views($a);
        $this->assertCount(1, $views);
        $this->assertSame([$advisor->getUserIdentifier(), TeamViewJournal::ACTION, 'company'], [$views[0]['user_identifier'], $views[0]['action'], $views[0]['fields']]);
        $this->assertSame(['what' => 'company', 'company' => $a->getName()], json_decode((string) $views[0]['after_state'], true));
        $this->assertNotNull($views[0]['created_at'], 'quand');
        [$status, $missing] = $this->call('GET', '/api/boussole/team/companies/2147483000', null, $advisor);
        $this->assertSame([404, 'COMPANY_NOT_FOUND'], [$status, $missing['code']]);

        // Diagnostic et rapport : les lectures existantes, ouvertes à l'équipe, avec leurs formes d'aujourd'hui
        [$status, $read] = $this->call('GET', '/api/boussole/sessions/' . $uuid, null, $advisor);
        $this->assertSame([200, $uuid, 'completed'], [$status, $read['uuid'], $read['status']]);
        $this->assertSame(['what' => 'session', 'company' => $a->getName(), 'sessionUuid' => $uuid], json_decode((string) $this->views($a)[1]['after_state'], true));
        [$status, $recommendations] = $this->call('GET', '/api/boussole/sessions/' . $uuid . '/recommendations', null, $advisor);
        $this->assertSame([200, []], [$status, $recommendations]);
        $this->assertCount(3, $this->views($a), 'les recommandations sont une lecture du diagnostic');
        [$status, $report] = $this->call('GET', '/api/boussole/sessions/' . $uuid . '/report', null, $advisor);
        $this->assertSame([200, 'ready'], [$status, $report['status']]);
        $this->assertCount(3, $this->views($a), 'l\'état du rapport (prêt ou non) n\'est pas une lecture du rapport');
        $this->request('GET', '/api/boussole/sessions/' . $uuid . '/report/download', null, $advisor);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('rapport-' . $this->tag, file_get_contents($this->client->getResponse()->getFile()->getPathname()));
        $this->assertSame(['what' => 'report', 'company' => $a->getName(), 'sessionUuid' => $uuid], json_decode((string) $this->views($a)[3]['after_state'], true));

        // Document : téléchargement d'équipe, journalisé avec le document
        $this->request('GET', '/api/boussole/team/documents/' . $document->getId() . '/download', null, $advisor);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('document-' . $this->tag, file_get_contents($this->client->getResponse()->getFile()->getPathname()));
        $this->assertStringContainsString('politique.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertSame(['what' => 'document', 'company' => $a->getName(), 'documentId' => $document->getId(), 'name' => 'politique.pdf'], json_decode((string) $this->views($a)[4]['after_state'], true));
        [$status, $missing] = $this->call('GET', '/api/boussole/team/documents/2147483000/download', null, $advisor);
        $this->assertSame([404, 'DOCUMENT_NOT_FOUND'], [$status, $missing['code']]);
        $this->assertCount(5, $this->views($a));
        $this->assertSame([], $this->views($b), 'rien de lu dans l\'autre entreprise');

        // L'administrateur du site a les mêmes lectures, journalisées à son nom
        $admin = $this->user(['ROLE_ADMIN'], 'admin');
        $this->assertSame(200, $this->call('GET', '/api/boussole/team/companies/' . $b->getId(), null, $admin)[0]);
        $this->assertSame([$admin->getUserIdentifier()], array_column($this->views($b), 'user_identifier'));
        $this->assertSame(200, $this->call('GET', '/api/boussole/sessions/' . $uuid, null, $admin)[0]);

        // L'entreprise lit ses propres données comme avant, sans rien écrire au journal des consultations
        $before = count($this->views($a));
        [$status, $own] = $this->call('GET', '/api/boussole/sessions/' . $uuid, null, $owner);
        $this->assertSame([200, $uuid], [$status, $own['uuid']]);
        $this->request('GET', '/api/boussole/sessions/' . $uuid . '/report/download', null, $owner);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount($before, $this->views($a));
    }

    public function testTheTeamWritesNothingAndOtherAccountsDoNotEnter(): void
    {
        [$a, $owner, $session, $document] = $this->companyWithEverything('Alpha');
        $b = $this->company('Bravo');
        $other = $this->esgUser($b, 'bravo');
        $advisor = $this->user(['ROLE_USER_INTERNET', TeamRoles::BOUSSOLE_CONSEILLER], 'conseiller');
        $customer = $this->user(['ROLE_USER_INTERNET'], 'client');
        $uuid = $session->getUuid()->toRfc4122();
        $open = $this->session($a, SessionStatusEnum::IN_PROGRESS);
        $openUuid = $open->getUuid()->toRfc4122();

        // Deux diagnostics pour la même entreprise : la liste rend le plus récent
        [, $list] = $this->call('GET', '/api/boussole/team/companies?q=' . $this->tag, null, $advisor);
        $rowA = array_values(array_filter($list['companies'], fn (array $c) => $c['id'] === $a->getId()))[0];
        $this->assertSame([2, $openUuid, 'in_progress', null], [$rowA['sessionsCount'], $rowA['lastSession']['uuid'], $rowA['lastSession']['status'], $rowA['lastSession']['completedAt']]);

        // L'équipe : aucune écriture
        $this->assertSame(403, $this->call('PUT', '/api/boussole/sessions/' . $openUuid . '/answers', ['answers' => [['questionId' => 1, 'answerValue' => 2]]], $advisor)[0], 'répondre');
        $this->assertSame(403, $this->call('POST', '/api/boussole/sessions/' . $openUuid . '/submit', [], $advisor)[0], 'soumettre');
        $this->assertSame(401, $this->call('POST', '/api/boussole/sessions', [], $advisor)[0], 'créer un diagnostic');
        $this->assertSame(401, $this->call('PUT', '/api/boussole/company', ['name' => 'Autre'], $advisor)[0], 'modifier une fiche');
        $this->assertSame(403, $this->call('POST', '/api/boussole/documents', [], $advisor)[0], 'déposer un document');
        $this->assertSame(403, $this->call('DELETE', '/api/boussole/documents/' . $document->getId(), null, $advisor)[0], 'supprimer un document');
        $this->assertSame('in_progress', $this->em()->getConnection()->fetchOne('SELECT status FROM esg_diag_session WHERE id = ?', [$open->getId()]));
        $this->assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM esg_document WHERE id = ?', [$document->getId()]));

        // Un client de la boutique, un compte d'entreprise, un anonyme : aucune route d'équipe, aucune lecture d'une entreprise
        $team = ['/api/boussole/team/companies', '/api/boussole/team/companies/' . $a->getId(), '/api/boussole/team/documents/' . $document->getId() . '/download', '/api/boussole/team/members'];
        foreach ($team as $path) {
            $this->assertSame(403, $this->call('GET', $path, null, $customer)[0], "client de la boutique : $path");
            $this->assertSame(403, $this->call('GET', $path, null, $owner)[0], "compte d'entreprise : $path");
            $this->assertSame(401, $this->call('GET', $path, null, null)[0], "anonyme : $path");
        }
        $this->assertSame(403, $this->call('GET', '/api/boussole/sessions/' . $uuid, null, $customer)[0]);
        $this->assertSame(403, $this->call('PUT', '/api/boussole/team/members/' . $customer->getId(), ['member' => true], $customer)[0]);
        // Un compte d'entreprise n'est jamais de l'équipe, même si ses rôles portaient ceux de l'équipe
        $forge = fn (array $roles) => $this->em()->getConnection()->executeStatement('UPDATE esg_user SET roles = ? WHERE id = ?', [json_encode($roles), $owner->getId()]);
        $forge(['ROLE_COMPANY', 'ROLE_ADMIN', TeamRoles::BOUSSOLE_CONSEILLER]);
        $this->assertStringContainsString(TeamRoles::BOUSSOLE_CONSEILLER, (string) $this->em()->getConnection()->fetchOne('SELECT roles FROM esg_user WHERE id = ?', [$owner->getId()]));
        foreach ($team as $path) {
            $this->assertSame(403, $this->call('GET', $path, null, $owner)[0], "compte d'entreprise aux rôles forgés : $path");
        }
        $forge(['ROLE_COMPANY']);

        // Une entreprise ne lit toujours que ses propres données
        $this->assertSame(403, $this->call('GET', '/api/boussole/sessions/' . $uuid, null, $other)[0]);
        $this->request('GET', '/api/boussole/sessions/' . $uuid . '/report/download', null, $other);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(403, $this->call('GET', '/api/boussole/documents/download/' . $document->getId(), null, $other)[0]);
        $this->assertSame(200, $this->call('GET', '/api/boussole/sessions/' . $uuid, null, $owner)[0]);
        $this->assertSame([], $this->views($a), 'aucune de ces lectures refusées ou d\'entreprise n\'est une consultation de l\'équipe');
    }

    public function testAnAdminGrantsAndRevokesTheAdvisorRoleWithAJournal(): void
    {
        $member = $this->user(['ROLE_USER_INTERNET'], 'futur-conseiller');
        $admin = $this->user(['ROLE_ADMIN'], 'admin');
        $path = '/api/boussole/team/members/' . $member->getId();

        $this->assertSame(403, $this->call('GET', '/api/boussole/team/companies', null, $member)[0], 'avant : pas de l\'équipe');
        [$status, $body] = $this->call('PUT', $path, ['member' => true], $admin);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([$member->getId(), true, true, [TeamRoles::BOUSSOLE_CONSEILLER]], [$body['id'], $body['isAdvisor'], $body['changed'], $body['teamRoles']]);
        $this->assertContains('ROLE_USER_INTERNET', $body['roles'], 'le rôle compagnon d\'accès à l\'API est garanti');
        [, $again] = $this->call('PUT', $path, ['member' => true], $admin);
        $this->assertFalse($again['changed'], 'idempotent');
        $journal = $this->teamJournal($member);
        $this->assertCount(1, $journal, 'rien n\'est journalisé quand rien ne change');
        $this->assertSame(['grant', $admin->getUserIdentifier()], [$journal[0]['action'], $journal[0]['user_identifier']]);
        $this->assertStringContainsString(TeamRoles::BOUSSOLE_CONSEILLER, (string) $journal[0]['after_state']);
        $this->assertStringNotContainsString(TeamRoles::BOUSSOLE_CONSEILLER, (string) $journal[0]['before_state']);

        $this->assertSame(200, $this->call('GET', '/api/boussole/team/companies', null, $member)[0], 'après : de l\'équipe');
        [$status, $members] = $this->call('GET', '/api/boussole/team/members', null, $member);
        $this->assertSame(200, $status);
        $mine = array_values(array_filter($members, fn (array $m) => $m['id'] === $member->getId()));
        $this->assertSame([['id' => $member->getId(), 'fullName' => 'Boussole Test', 'email' => $member->getEmail(), 'teamRoles' => [TeamRoles::BOUSSOLE_CONSEILLER]]], $mine);
        $this->assertNotContains($admin->getId(), array_column($members, 'id'), 'la liste est celle des conseillers');

        // Attribution réservée à l'administrateur du site ; corps vérifié ; compte inconnu ; super administrateur protégé
        $this->assertSame(403, $this->call('PUT', '/api/boussole/team/members/' . $admin->getId(), ['member' => true], $member)[0], 'un conseiller n\'attribue pas');
        [$status, $body] = $this->call('PUT', $path, ['member' => 'oui'], $admin);
        $this->assertSame([400, 'INVALID_BODY'], [$status, $body['code']]);
        [$status, $body] = $this->call('PUT', '/api/boussole/team/members/2147483000', ['member' => true], $admin);
        $this->assertSame([404, 'USER_NOT_FOUND'], [$status, $body['code']]);
        $super = $this->user(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN], 'super');
        [$status, $body] = $this->call('PUT', '/api/boussole/team/members/' . $super->getId(), ['member' => true], $admin);
        $this->assertSame([403, 'OWNER_PROTECTED'], [$status, $body['code']]);
        $this->assertSame([], $this->teamJournal($super));

        // Retrait : journalisé, et l'accès tombe aussitôt
        [$status, $body] = $this->call('PUT', $path, ['member' => false], $admin);
        $this->assertSame([200, false, true, []], [$status, $body['isAdvisor'], $body['changed'], $body['teamRoles']]);
        $this->assertSame(['revoke', 'grant'], array_column($this->teamJournal($member), 'action'));
        $this->assertSame(403, $this->call('GET', '/api/boussole/team/companies', null, $member)[0]);

        // Commande de reprise : même service, même journal, repère « console »
        $command = new CommandTester((new Application(static::$kernel))->find('app:boussole:team:grant'));
        $this->assertSame(0, $command->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail()]));
        $this->assertStringContainsString('conseiller ajouté', $command->getDisplay());
        $journal = $this->teamJournal($member);
        $this->assertSame('grant', $journal[0]['action']);
        $this->assertStringContainsString('console', (string) $journal[0]['fields']);
        $this->assertStringContainsString(TeamRoles::BOUSSOLE_CONSEILLER, (string) $journal[0]['fields']);
        $this->assertStringContainsString(TeamRoles::BOUSSOLE_CONSEILLER, (string) $this->em()->getConnection()->fetchOne('SELECT roles FROM "user" WHERE id = ?', [$member->getId()]));
        $this->assertSame(200, $this->call('GET', '/api/boussole/team/companies', null, $member)[0], 'le rôle posé par la commande ouvre les routes d\'équipe');
        $this->assertSame(0, $command->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => $member->getEmail(), '--revoke' => true]));
        $this->assertSame('revoke', $this->teamJournal($member)[0]['action']);
        $this->assertSame(1, $command->execute(['tenant' => MV_TEST_TENANT_CODE, 'email' => 'inconnu-' . $this->tag . '@example.invalid']));

        // Écran Utilisateurs d'EasyAdmin : le rôle est attribuable, par la convention commune
        $this->assertSame(TeamRoles::BOUSSOLE_CONSEILLER, (new RoleAssignmentPolicy())->choices(false)['Conseiller (Boussole ESG)'] ?? null);
    }

    public function testRegisteringWithAKnownAddressAnswersOneCodeForBothCases(): void
    {
        $company = $this->company('Alpha');
        $esg = $this->esgUser($company, 'connu');
        $site = $this->user(['ROLE_USER_INTERNET'], 'compte-du-site');
        $bodies = [];
        foreach ([$esg->getEmail(), $site->getEmail()] as $email) {
            [$status, $body] = $this->call('POST', '/api/boussole/auth/register', [
                'email' => $email, 'password' => 'motdepasse-' . $this->tag, 'firstName' => 'Nouvelle', 'lastName' => 'Entreprise', 'companyName' => 'Doublon ' . $this->tag,
                'sector' => SectorEnum::cases()[0]->value, 'sizeCategory' => SizeEnum::cases()[0]->value, 'territory' => TerritoryEnum::QUEBEC->value, 'contactEmail' => $email,
            ], null);
            $this->assertSame(409, $status, json_encode($body));
            $bodies[] = $body;
        }
        $this->assertSame(['error' => EmailUnavailableException::MESSAGE, 'code' => 'EMAIL_UNAVAILABLE'], $bodies[0]);
        $this->assertSame($bodies[0], $bodies[1], 'la même réponse, que l\'adresse soit celle d\'un compte Boussole ou d\'un compte du site');
        $this->assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM esg_company WHERE name = ?', ['Doublon ' . $this->tag]), 'rien de créé');
    }

    public function testWhoAmIForEachKindOfAccount(): void
    {
        $company = $this->company('Alpha');
        $owner = $this->esgUser($company, 'alpha');
        $advisor = $this->user(['ROLE_USER_INTERNET', TeamRoles::BOUSSOLE_CONSEILLER], 'conseiller');
        $admin = $this->user(['ROLE_ADMIN'], 'admin');
        $customer = $this->user(['ROLE_USER_INTERNET'], 'client');

        [$status, $me] = $this->call('GET', '/api/boussole/me', null, $owner);
        $this->assertSame(200, $status, json_encode($me));
        $this->assertSame(['company', $owner->getId(), $owner->getEmail(), 'Alice', 'Alpha', [], false], [$me['kind'], $me['id'], $me['email'], $me['firstName'], $me['lastName'], $me['teamRoles'], $me['isAdvisor']]);
        $this->assertSame([$company->getId(), $company->getName()], [$me['company']['id'], $me['company']['name']]);
        $this->assertContains('ROLE_COMPANY', $me['roles']);

        [$status, $me] = $this->call('GET', '/api/boussole/me', null, $advisor);
        $this->assertSame([200, 'team', $advisor->getId(), $advisor->getEmail(), 'Boussole', 'Test', [TeamRoles::BOUSSOLE_CONSEILLER], true, null], [$status, $me['kind'], $me['id'], $me['email'], $me['firstName'], $me['lastName'], $me['teamRoles'], $me['isAdvisor'], $me['company']]);

        [, $me] = $this->call('GET', '/api/boussole/me', null, $admin);
        $this->assertSame(['team', [], false], [$me['kind'], $me['teamRoles'], $me['isAdvisor']], 'l\'administrateur du site est de l\'équipe sans porter le rôle');

        [$status, $me] = $this->call('GET', '/api/boussole/me', null, $customer);
        $this->assertSame([200, 'none', false, null], [$status, $me['kind'], $me['isAdvisor'], $me['company']], 'un client de la boutique : aucun droit dans la Boussole');

        $this->assertSame(401, $this->call('GET', '/api/boussole/me', null, null)[0]);
    }

    /** @return array{0: EsgCompany, 1: EsgUser, 2: DiagnosticSession, 3: EsgDocument} entreprise avec un compte, un diagnostic terminé, son rapport prêt et un document, fichiers compris */
    private function companyWithEverything(string $name): array
    {
        $company = $this->company($name);
        $owner = $this->esgUser($company, strtolower($name));
        $session = $this->session($company, SessionStatusEnum::COMPLETED);
        $em = $this->em();
        $root = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/esg/';
        $reportFile = $session->getUuid()->toRfc4122() . '.pdf';
        $this->write($root . 'reports/' . $reportFile, 'rapport-' . $this->tag);
        $report = (new DiagnosticReport())->setStatus(ReportStatusEnum::READY)->setFilePath('reports/' . $reportFile)->setFileSize(20)->setGeneratedAt(new \DateTimeImmutable())
            ->setSession($em->find(DiagnosticSession::class, $session->getId()));
        $em->persist($report);
        $stored = $company->getId() . '/doc_' . $this->tag . '.pdf';
        $this->write($root . 'documents/' . $stored, 'document-' . $this->tag);
        $document = (new EsgDocument())->setCode('ENV-01')->setDomain(DomainEnum::ENVIRONMENT)->setOriginalName('politique.pdf')->setStoredName('doc_' . $this->tag . '.pdf')
            ->setFilePath($stored)->setFileSize(20)->setMimeType('application/pdf')->setCompany($em->find(EsgCompany::class, $company->getId()));
        $em->persist($document);
        $em->flush();
        $this->endOfWrite();

        return [$company, $owner, $session, $document];
    }

    private function company(string $name): EsgCompany
    {
        $company = (new EsgCompany())->setName($name . ' ' . $this->tag)->setSector(SectorEnum::cases()[0])->setSizeCategory(SizeEnum::cases()[0])->setTerritory(TerritoryEnum::QUEBEC)
            ->setCity('Ville-' . $name . '-' . $this->tag)->setContactEmail(strtolower($name) . '-contact@example.invalid')->setIsActive(true);
        $em = $this->em();
        $em->persist($company);
        $em->flush();
        $this->companyIds[] = (int) $company->getId();
        $this->endOfWrite();

        return $company;
    }

    private function esgUser(EsgCompany $company, string $prefix): EsgUser
    {
        $em = $this->em(); // em() bascule le site et vide l'EntityManager : l'entreprise est relue
        $user = (new EsgUser())->setEmail($prefix . '-' . $this->tag . '@example.invalid')->setFirstName('Alice')->setLastName('Alpha')->setRoles(['ROLE_COMPANY'])
            ->setCompany($em->find(EsgCompany::class, $company->getId()))->setIsActive(true)->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return $user;
    }

    private function session(EsgCompany $company, SessionStatusEnum $status): DiagnosticSession
    {
        $em = $this->em();
        $session = (new DiagnosticSession())->setCompany($em->find(EsgCompany::class, $company->getId()))->setStatus($status)->setReferentialVersion('test');
        if ($status === SessionStatusEnum::COMPLETED) {
            $session->setScoreEnvironment(70.0)->setScoreGovernance(60.0)->setScoreSocial(65.0)->setScoreClimate(55.0)->setScoreGlobal(62.5)->setMaturityLevel('Confirmé')->setCompletedAt(new \DateTimeImmutable());
        }
        $em->persist($session);
        $em->flush();
        $this->endOfWrite();

        return $session;
    }

    private function user(array $roles, string $prefix): User
    {
        $email = $prefix . '-' . $this->tag . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Boussole')->setLastname('Test')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable');
        $em = $this->em();
        $em->persist($user);
        $em->flush();
        $this->endOfWrite();

        return $user;
    }

    /** Le test crée lui-même les dossiers de ses fichiers */
    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        $this->files[] = $path;
    }

    /** @return list<array<string, mixed>> consultations d'une entreprise par l'équipe, de la plus ancienne à la plus récente */
    private function views(EsgCompany $company): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT user_identifier, action, fields, after_state, created_at FROM content_audit_log WHERE resource = ? AND resource_id = ? ORDER BY id', [TeamViewJournal::RESOURCE, (string) $company->getId()]);
    }

    /** @return list<array<string, mixed>> attributions et retraits d'un compte, du plus récent au plus ancien */
    private function teamJournal(User $user): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT action, fields, user_identifier, before_state, after_state FROM content_audit_log WHERE resource = ? AND resource_id = ? ORDER BY id DESC', [TeamRoles::AUDIT_RESOURCE, (string) $user->getId()]);
    }

    private function request(string $method, string $path, ?array $body, ?UserInterface $as): void
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($method !== 'GET') {
            $server['HTTP_X_XSRF_TOKEN'] = 'jeton-xsrf-de-test';
        }
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($as);
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(string $method, string $path, ?array $body, ?UserInterface $as): array
    {
        $this->request($method, $path, $body, $as);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true)];
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }
}
