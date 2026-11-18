<?php

namespace App\Tests\Functional\Support;

use App\Entity\User;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Aide et support » des applications du client, lot AIDE-APP (18/11/2026, `docs/assistance.md`) : la demande du client connecté tombe
 * dans la boîte de l'Assistance (canal `app`, application lue dans le JETON, catégorie) ; le client suit SES demandes (état simplifié,
 * `unread`), lit le fil sans les notes internes et répond ; limites ; module inactif ; cloisonnement entre clients.
 */
class SupportCustomerAppTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $this->tag = bin2hex(random_bytes(3));
        $this->clean();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $this->clean();
        parent::tearDown();
    }

    public function testTheCustomerSendsFollowsAndRepliesAndTheTeamSeesCategoryAndApp(): void
    {
        [$alice, $aliceToken] = $this->customer('livingmemory');
        $agent = $this->staff(['ROLE_SUPPORT']);
        $this->assertSame(['available' => true, 'reason' => null], $this->json('GET', '/api/account/support/availability', null, $aliceToken, 200));
        // refus champ par champ
        $this->assertSame(['subject' => 'REQUIRED', 'message' => 'REQUIRED', 'category' => 'REQUIRED'], $this->json('POST', '/api/account/support/requests', [], $aliceToken, 422)['fields']);
        $this->assertSame(['category' => 'INVALID_VALUE', 'orderId' => 'ORDER_NOT_FOUND'], $this->json('POST', '/api/account/support/requests', ['subject' => 'Bonjour', 'message' => 'Un message assez long.', 'category' => 'divers', 'orderId' => 999999999], $aliceToken, 422)['fields']);
        // la demande : le canal et l'application viennent du jeton, jamais du corps
        $sent = $this->json('POST', '/api/account/support/requests', ['subject' => 'L\'enregistrement s\'arrête', 'message' => 'L\'enregistrement audio s\'arrête au bout de dix secondes.', 'category' => 'app_problem', 'appVersion' => '1.9.2', 'app' => 'pos', 'channel' => 'phone'], $aliceToken, 201)['request'];
        $this->assertMatchesRegularExpression('/^AS-\d{6}$/', $sent['reference']);
        $this->assertSame(['app_problem', 'in_progress', false], [$sent['category'], $sent['status'], $sent['unread']]);
        $row = $this->db->fetchAssociative('SELECT * FROM support_ticket WHERE reference = ?', [$sent['reference']]);
        $this->assertSame(['app', 'livingmemory', 'app_problem', $alice->getId(), $alice->getEmail()], [$row['channel'], $row['source_app'], $row['category'], (int) $row['customer_user_id'], $row['customer_email']]);
        // l'Assistance du commerçant voit la catégorie et l'application ; un problème de l'application est mis en avant
        $list = $this->json('GET', '/api/support/tickets?category=app_problem', null, $agent, 200);
        $this->assertSame([[$sent['reference'], 'app_problem', 'livingmemory', true]], array_map(fn ($t) => [$t['reference'], $t['category'], $t['sourceApp'], $t['highlight']], $list['items']));
        $this->assertSame(['category' => 'INVALID_CATEGORY'], $this->json('GET', '/api/support/tickets?category=divers', null, $agent, 422)['fields']);
        // l'équipe répond et note : le client voit « répondu » et le point non lu ; jamais la note interne
        $id = (int) $row['id'];
        $this->json('POST', "/api/support/tickets/$id/messages", ['body' => 'Mettez l\'application à jour, puis réessayez.'], $agent, 201);
        $this->json('POST', "/api/support/tickets/$id/messages", ['body' => 'note interne : voir le signalement technique', 'internal' => true], $agent, 201);
        $mine = $this->json('GET', '/api/account/support/requests', null, $aliceToken, 200)['requests'];
        $this->assertSame([[$sent['reference'], 'answered', true]], array_map(fn ($r) => [$r['reference'], $r['status'], $r['unread']], $mine));
        $thread = $this->json('GET', '/api/account/support/requests/' . $sent['reference'], null, $aliceToken, 200);
        $this->assertSame([['customer', 'L\'enregistrement audio s\'arrête au bout de dix secondes.' . "\n\n(version de l'application : 1.9.2)"], ['team', 'Mettez l\'application à jour, puis réessayez.']],
            array_map(fn ($m) => [$m['from'], $m['body']], $thread['messages']));
        $this->assertStringNotContainsString('note interne', json_encode($thread));
        $this->assertFalse($thread['request']['unread']);
        $this->assertFalse($this->json('GET', '/api/account/support/requests', null, $aliceToken, 200)['requests'][0]['unread'], 'la lecture du fil remet le point à faux');
        // le client répond : la demande repasse « en cours », l'équipe la voit en attente de réponse
        $replied = $this->json('POST', '/api/account/support/requests/' . $sent['reference'] . '/messages', ['message' => 'Mise à jour faite, le problème continue.'], $aliceToken, 201);
        $this->assertSame('in_progress', $replied['request']['status']);
        $this->assertSame('open', $this->json('GET', "/api/support/tickets/$id", null, $agent, 200)['status'], 'l\'équipe voit la demande rouverte (en attente : le client a écrit)');
        // cloisonnement : un autre client ne voit ni la demande, ni son fil
        [, $bobToken] = $this->customer('boutique');
        $this->assertSame([], $this->json('GET', '/api/account/support/requests', null, $bobToken, 200)['requests']);
        $this->assertSame('REQUEST_NOT_FOUND', $this->json('GET', '/api/account/support/requests/' . $sent['reference'], null, $bobToken, 404)['code']);
        $this->assertSame('REQUEST_NOT_FOUND', $this->json('POST', '/api/account/support/requests/' . $sent['reference'] . '/messages', ['message' => 'Je ne suis pas le client.'], $bobToken, 404)['code']);
        // fermée : plus de réponse dans ce fil
        $this->db->executeStatement("UPDATE support_ticket SET status = 'closed', closed_at = NOW() WHERE id = ?", [$id]);
        $this->assertSame('REQUEST_CLOSED', $this->json('POST', '/api/account/support/requests/' . $sent['reference'] . '/messages', ['message' => 'Encore une question.'], $aliceToken, 409)['code']);
        $this->assertSame('closed', $this->json('GET', '/api/account/support/requests', null, $aliceToken, 200)['requests'][0]['status']);
        // le compte client seul : ni un visiteur, ni le personnel
        $this->assertSame(401, $this->api('GET', '/api/account/support/requests', null, null)->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/api/account/support/requests', null, $this->staff(['ROLE_ADMIN']))->getStatusCode());
    }

    public function testLimitsAttachmentsAndAnInactiveModule(): void
    {
        [, $token] = $this->customer('boutique');
        $request = fn (string $subject): Response => $this->api('POST', '/api/account/support/requests', ['subject' => $subject, 'message' => 'Le colis n\'est pas arrivé.', 'category' => 'delivery'], $token);
        for ($i = 1; $i <= 3; ++$i) {
            $this->assertSame(201, $request("Colis $i")->getStatusCode());
        }
        $this->assertSame(['TOO_MANY_OPEN_REQUESTS', 3], array_values(array_intersect_key(json_decode((string) $request('Colis 4')->getContent(), true), ['code' => 1, 'max' => 1])), '3 demandes ouvertes au plus');
        $this->db->executeStatement("UPDATE support_ticket SET status = 'closed', closed_at = NOW() WHERE channel = 'app'");
        $this->assertSame(201, $request('Colis 5')->getStatusCode());
        $this->assertSame(201, $request('Colis 6')->getStatusCode());
        $limited = $request('Colis 7');
        $this->assertSame([429, 'account'], [$limited->getStatusCode(), json_decode((string) $limited->getContent(), true)['scope']], '5 demandes par compte et par jour');
        // pièces jointes : vérifiées par leur contenu ; celle de l'équipe lisible, une note interne jamais
        [, $other] = $this->customer('boutique');
        $png = tempnam(sys_get_temp_dir(), 'png') . '.png';
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $png);
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/account/support/requests', ['subject' => 'Photo du colis', 'message' => 'Voici la photo du colis abîmé.', 'category' => 'delivery'],
            ['attachments' => [new UploadedFile($png, 'colis.png', null, null, true)]], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $other]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $reference = json_decode((string) $this->client->getResponse()->getContent(), true)['request']['reference'];
        $thread = $this->json('GET', "/api/account/support/requests/$reference", null, $other, 200);
        $attachment = $thread['messages'][0]['attachments'][0];
        $this->assertSame(['colis.png', 'image/png'], [$attachment['name'], $attachment['mime']]);
        $this->assertSame(200, $this->api('GET', "/api/account/support/requests/$reference/attachments/" . $attachment['id'], null, $other)->getStatusCode());
        $this->assertSame(404, $this->api('GET', "/api/account/support/requests/$reference/attachments/" . $attachment['id'], null, $token)->getStatusCode(), 'pas celle d\'un autre client');
        // module inactif (plateforme en service réel) : l'écran le sait d'avance, l'envoi est refusé
        static::getContainer()->get(PlatformModeService::class)->setMode('live', 'test');
        $this->db->executeStatement('UPDATE entreprise SET is_support_active = false');
        static::getContainer()->get(\App\Services\TenantCacheService::class)->invalidateTenant();
        $this->assertSame(['available' => false, 'reason' => 'MODULE_INACTIVE'], $this->json('GET', '/api/account/support/availability', null, $other, 200));
        $refused = $this->json('POST', '/api/account/support/requests', ['subject' => 'Bonjour', 'message' => 'Une question sur ma commande.', 'category' => 'order'], $other, 403);
        $this->assertSame(['MODULE_INACTIVE', 'module.support'], [$refused['code'], $refused['feature']]);
    }

    private function clean(): void
    {
        foreach (['support_attachment', 'support_message', 'support_ticket'] as $t) {
            $this->db->executeStatement("DELETE FROM $t");
        }
        $this->db->executeStatement('UPDATE entreprise SET is_support_active = false');
    }

    /** @return array{0: User, 1: string} un client et son jeton, qui porte la famille d'application `$app` */
    private function customer(string $app): array
    {
        $user = $this->user(['ROLE_USER_INTERNET']);

        return [$user, static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->createFromPayload($user, ['app' => $app])];
    }

    private function staff(array $roles): string
    {
        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user($roles));
    }

    private function user(array $roles): User
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'aide-app-' . bin2hex(random_bytes(3)) . '-' . $this->tag . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Client')->setLastname('Essai')->setRoles($roles)->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, ?array $body, ?string $token, int $expected): array
    {
        $response = $this->api($method, $path, $body, $token);
        $this->assertSame($expected, $response->getStatusCode(), "$method $path : " . substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, ?string $token): Response
    {
        $this->client->getCookieJar()->clear();
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }
}
