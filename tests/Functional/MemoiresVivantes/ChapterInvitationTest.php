<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\Entity\ChapterInvitation;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Invitations par e-mail des chapitres (08/11/2026) : le lien envoyé ouvre bien le chapitre, doublon et adresse refusés,
 * renvoi, arrêt et nouvelle invitation, fin par le lien du chapitre ou par la validation d'un contributeur (chapitre par
 * chapitre), renouvellement automatique par la commande puis épuisement avec avis au propriétaire, réponse « lien expiré »
 * avec `renewalPending`, désinscription qui bloque l'adresse.
 */
class ChapterInvitationTest extends BookTypeApiTestCase
{
    private string $bookId;
    private string $chapterId;
    private string $chapter2Id;
    private array $lea;

    protected function setUp(): void
    {
        parent::setUp();
        [, $book] = $this->api('POST', '/books', ['title' => 'Famille invitée', 'type' => 'famille'], 'client');
        $this->bookId = $book['id'];
        [, $chapter] = $this->api('POST', "/books/{$this->bookId}/chapters", ['title' => 'Paroles d\'enfants', 'theme' => 'regards_croises', 'position' => 2, 'answers' => []], 'client');
        $this->chapterId = $chapter['id'];
        [, $chapter2] = $this->api('POST', "/books/{$this->bookId}/chapters", ['title' => 'Rituels', 'theme' => 'rituels_et_valeurs', 'position' => 3, 'answers' => []], 'client');
        $this->chapter2Id = $chapter2['id'];
        [, $this->lea] = $this->api('POST', "/books/{$this->bookId}/contributors", ['firstName' => 'Léa', 'role' => 'enfant'], 'client');
    }

    public function testTheInvitationIsEmailedWithAWorkingLinkAndListed(): void
    {
        [$status, $invitation] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => ' Invitee@Example.invalid '], 'client');
        $this->assertSame(201, $status, json_encode($invitation));
        $this->assertSame('active', $invitation['status']);
        $this->assertSame('invitee@example.invalid', $invitation['email']);
        $this->assertNull($invitation['contributorId']);
        $this->assertSame(0, $invitation['renewalsUsed']);
        $this->assertSame(3, $invitation['renewalsMax']);
        $this->assertSame($this->bookId, $invitation['bookId']);
        $this->assertGreaterThan(time() + 6 * 86400, strtotime($invitation['expiresAt']));

        $email = $this->lastEmailTo('invitee@example.invalid');
        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString('Paroles d', $html);
        $this->assertStringContainsString('/invitations/unsubscribe/' . MV_TEST_TENANT_CODE . '/', $html);
        $query = $this->shareQueryFrom($html);
        $this->assertStringContainsString('expires=' . strtotime($invitation['expiresAt']), $query, 'la signature porte la date d\'expiration enregistrée');

        // Le lien de l'e-mail ouvre le chapitre sans compte, comme un lien de share-link
        [$status, $chapter] = $this->api('GET', "/chapters/{$this->chapterId}?{$query}", null, null);
        $this->assertSame(200, $status, json_encode($chapter));
        $this->assertSame($this->chapterId, $chapter['id']);

        [$status, $body] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'autre@example.invalid'], 'client');
        $this->assertSame(409, $status);
        $this->assertSame('INVITATION_ALREADY_ACTIVE', $body['code']);
        $this->assertSame($invitation['id'], $body['params']['invitationId']);

        [$status, $body] = $this->api('POST', "/chapters/{$this->chapter2Id}/invitations", ['email' => 'pas-une-adresse'], 'client');
        $this->assertSame(400, $status);
        $this->assertSame('EMAIL_INVALID', $body['code']);

        [$status, $list] = $this->api('GET', "/books/{$this->bookId}/invitations", null, 'client');
        $this->assertSame(200, $status);
        $this->assertSame([$invitation['id']], array_column($list, 'id'));
        $this->assertSame('Paroles d\'enfants', $list[0]['chapterTitle']);

        [$status] = $this->api('GET', "/books/{$this->bookId}/invitations", null, null);
        $this->assertSame(401, $status, 'le suivi exige un compte connecté');
        [$status] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'x@example.invalid'], null);
        $this->assertSame(401, $status);
    }

    public function testResendCancelThenANewInvitation(): void
    {
        [, $invitation] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'renvoi@example.invalid'], 'client');
        $id = $invitation['id'];

        [$status, $body] = $this->api('POST', "/invitations/{$id}/resend", null, 'client');
        $this->assertSame(429, $status);
        $this->assertSame('RESEND_TOO_SOON', $body['code']);
        $this->assertGreaterThan(0, $body['params']['retryAfterSeconds']);

        $this->backdate($id, 'last_sent_at', '-11 minutes');
        [$status, $body] = $this->api('POST', "/invitations/{$id}/resend", null, 'client');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('active', $body['status']);
        $this->assertSame(0, $body['renewalsUsed']);
        // Les e-mails visibles sont ceux de la dernière requête (le noyau redémarre entre deux)
        $this->assertStringContainsString('nouveau lien', (string) $this->lastEmailTo('renvoi@example.invalid')->getHtmlBody());

        [$status, $body] = $this->api('DELETE', "/invitations/{$id}", null, 'client');
        $this->assertSame(200, $status);
        $this->assertSame('cancelled', $body['status']);

        [$status, $body] = $this->api('POST', "/invitations/{$id}/resend", null, 'client');
        $this->assertSame(409, $status);
        $this->assertSame('INVITATION_CLOSED', $body['code']);
        $this->assertSame('cancelled', $body['params']['status']);

        // Changer d'adresse : arrêter, puis créer
        [$status, $again] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'nouvelle@example.invalid'], 'client');
        $this->assertSame(201, $status, json_encode($again));
        $this->assertNotSame($id, $again['id']);

        [$status] = $this->api('POST', "/invitations/{$id}/resend", null, 'admin');
        $this->assertSame(409, $status, 'un administrateur a le droit, l\'invitation reste fermée');
        [$status, $body] = $this->api('POST', '/invitations/00000000-0000-4000-8000-000000000000/resend', null, 'client');
        $this->assertSame(404, $status);
        $this->assertSame('INVITATION_NOT_FOUND', $body['code']);
    }

    public function testFinishingThroughTheChapterLinkClosesTheInvitation(): void
    {
        [, $invitation] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'fin@example.invalid'], 'client');

        [$status] = $this->api('POST', "/chapters/{$this->chapterId}/invitation/complete", null, null);
        $this->assertSame(401, $status, 'sans lien signé ni compte, rien');

        [$status, $body] = $this->api('POST', "/chapters/{$this->chapterId}/invitation/complete?" . $this->signed($this->chapterId), null, null);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(['status' => 'completed', 'applied' => true], $body);

        [, $list] = $this->api('GET', "/books/{$this->bookId}/invitations", null, 'client');
        $this->assertSame('completed', $list[0]['status']);
        $this->assertNotNull($list[0]['completedAt']);

        // Lien copié à la main ou invitation déjà fermée : même réponse, sans effet
        [$status, $body] = $this->api('POST', "/chapters/{$this->chapterId}/invitation/complete?" . $this->signed($this->chapterId), null, null);
        $this->assertSame(200, $status);
        $this->assertFalse($body['applied']);

        [$status, $body] = $this->api('POST', "/invitations/{$invitation['id']}/resend", null, 'client');
        $this->assertSame(409, $status);
        $this->assertSame('INVITATION_CLOSED', $body['code']);
    }

    public function testAContributorApprovalClosesOnlyTheChapterOfTheLink(): void
    {
        [$status, $onA] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'lea@example.invalid', 'contributorId' => $this->lea['id']], 'client');
        $this->assertSame(201, $status, json_encode($onA));
        $this->assertSame($this->lea['id'], $onA['contributorId']);
        $this->assertSame('Léa', $onA['contributorFirstName']);
        [$status, $onB] = $this->api('POST', "/chapters/{$this->chapter2Id}/invitations", ['email' => 'lea@example.invalid', 'contributorId' => $this->lea['id']], 'client');
        $this->assertSame(201, $status, 'la même personne peut être invitée sur un autre chapitre');

        $this->assertStringContainsString('contributorId=' . $this->lea['id'], $this->shareQueryFrom((string) $this->lastEmailTo('lea@example.invalid')->getHtmlBody()));
        $this->assertStringContainsString('Léa', (string) $this->lastEmailTo('lea@example.invalid')->getHtmlBody());

        [$status] = $this->api('POST', "/contributors/{$this->lea['id']}/approve?" . $this->signed($this->chapterId, $this->lea['id']), null, null);
        $this->assertSame(200, $status);

        $statuses = $this->statusesByChapter();
        $this->assertSame('completed', $statuses[$this->chapterId]);
        $this->assertSame('active', $statuses[$this->chapter2Id], 'le chapitre B reste ouvert');

        // Validation par le propriétaire, sans chapitre : tout le contributeur
        [$status] = $this->api('POST', "/contributors/{$this->lea['id']}/approve", null, 'client');
        $this->assertSame(200, $status);
        $this->assertSame('completed', $this->statusesByChapter()[$this->chapter2Id]);

        // Contributeur d'un autre livre
        [, $other] = $this->api('POST', '/books', ['title' => 'Autre famille', 'type' => 'famille'], 'client');
        [, $marc] = $this->api('POST', "/books/{$other['id']}/contributors", ['firstName' => 'Marc', 'role' => 'enfant'], 'client');
        [$status, $body] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'marc@example.invalid', 'contributorId' => $marc['id']], 'client');
        $this->assertSame(404, $status);
        $this->assertSame('CONTRIBUTOR_NOT_FOUND', $body['code']);
    }

    public function testExpiredLinksAreRenewedByTheCommandThenExhausted(): void
    {
        [, $invitation] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'lent@example.invalid'], 'client');
        $id = $invitation['id'];

        // Lien expiré : code stable et renouvellement à venir
        [$status, $body] = $this->api('GET', "/chapters/{$this->chapterId}?" . $this->signed($this->chapterId, null, time() - 60), null, null);
        $this->assertSame(401, $status);
        $this->assertSame('SHARE_LINK_EXPIRED', $body['code']);
        $this->assertTrue($body['params']['renewalPending']);
        [$status, $body] = $this->api('GET', "/books/{$this->bookId}?" . $this->signed($this->chapterId, null, time() - 60), null, null);
        $this->assertSame(401, $status);
        $this->assertSame('SHARE_LINK_EXPIRED', $body['code']);
        $this->assertTrue($body['params']['renewalPending']);

        $this->backdate($id, 'expires_at', '-1 hour');
        $output = $this->renew();
        $this->assertStringContainsString('1 lien(s) renouvelé(s)', $output);
        // E-mails lus avant la requête suivante (le noyau redémarre entre deux requêtes du client de test)
        $renewal = $this->lastEmailTo('lent@example.invalid');
        $expiresAt = (string) self::db()->query("SELECT expires_at FROM mv_chapter_invitation WHERE id = '{$id}'")->fetchColumn();
        $this->assertGreaterThan(time() + 6 * 86400, strtotime($expiresAt));
        $this->assertStringContainsString('expires=' . strtotime($expiresAt), $this->shareQueryFrom((string) $renewal->getHtmlBody()));
        $this->assertStringContainsString('plus actif', (string) $renewal->getHtmlBody());

        [, $list] = $this->api('GET', "/books/{$this->bookId}/invitations", null, 'client');
        $this->assertSame('active', $list[0]['status']);
        $this->assertSame(1, $list[0]['renewalsUsed']);

        // Dernier renouvellement consommé : épuisée, avis au propriétaire, plus de renouvellement annoncé
        self::db()->exec("UPDATE mv_chapter_invitation SET renewals_used = 3 WHERE id = '{$id}'");
        $this->backdate($id, 'expires_at', '-1 hour');
        $output = $this->renew();
        $this->assertStringContainsString('1 invitation(s) épuisée(s)', $output);
        $owner = $this->lastEmailTo(self::CLIENT_EMAIL);
        $this->assertStringContainsString('lent@example.invalid', (string) $owner->getHtmlBody());
        $this->assertStringContainsString('Paroles d', (string) $owner->getHtmlBody());
        [, $list] = $this->api('GET', "/books/{$this->bookId}/invitations", null, 'client');
        $this->assertSame('exhausted', $list[0]['status']);

        [, $body] = $this->api('GET', "/chapters/{$this->chapterId}?" . $this->signed($this->chapterId, null, time() - 60), null, null);
        $this->assertFalse($body['params']['renewalPending']);

        // « À relancer » : le renvoi repart de zéro
        $this->backdate($id, 'last_sent_at', '-11 minutes');
        [$status, $body] = $this->api('POST', "/invitations/{$id}/resend", null, 'client');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('active', $body['status']);
        $this->assertSame(0, $body['renewalsUsed']);
    }

    public function testUnsubscribingBlocksTheAddressOnThisSite(): void
    {
        [, $invitation] = $this->api('POST', "/chapters/{$this->chapterId}/invitations", ['email' => 'stop@example.invalid'], 'client');
        $token = self::db()->query("SELECT unsubscribe_token FROM mv_chapter_invitation WHERE id = '{$invitation['id']}'")->fetchColumn();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $token);

        $this->client->request('GET', '/api/memoires/invitations/unsubscribe/' . MV_TEST_TENANT_CODE . '/' . $token);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('stop@example.invalid', (string) $this->client->getResponse()->getContent());

        [, $list] = $this->api('GET', "/books/{$this->bookId}/invitations", null, 'client');
        $this->assertSame('unsubscribed', $list[0]['status']);

        [$status, $body] = $this->api('POST', "/chapters/{$this->chapter2Id}/invitations", ['email' => 'STOP@example.invalid'], 'client');
        $this->assertSame(409, $status);
        $this->assertSame('EMAIL_UNSUBSCRIBED', $body['code']);
        [$status] = $this->api('POST', "/chapters/{$this->chapter2Id}/invitations", ['email' => 'autre@example.invalid'], 'client');
        $this->assertSame(201, $status);

        $this->client->request('GET', '/api/memoires/invitations/unsubscribe/' . MV_TEST_TENANT_CODE . '/' . str_repeat('0', 64));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/memoires/invitations/unsubscribe/site-inconnu/' . $token);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function renew(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:memoires:invitations:renew'));
        $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);

        return $tester->getDisplay();
    }

    private function backdate(string $id, string $column, string $modifier): void
    {
        $value = (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s');
        self::db()->exec("UPDATE mv_chapter_invitation SET {$column} = '{$value}' WHERE id = '{$id}'");
    }

    /** @return array<string, string> statut de l'invitation par chapitre */
    private function statusesByChapter(): array
    {
        [, $list] = $this->api('GET', "/books/{$this->bookId}/invitations", null, 'client');

        return array_column($list, 'status', 'chapterId');
    }

    /** @return list<Email> */
    private function emailsTo(string $address): array
    {
        $emails = [];
        foreach (self::getMailerEvents() as $event) {
            $message = $event->getMessage();
            if (!$event->isQueued() && $message instanceof Email && $message->getTo()[0]->getAddress() === $address) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    private function lastEmailTo(string $address): Email
    {
        $emails = $this->emailsTo($address);
        $this->assertNotEmpty($emails, "aucun e-mail envoyé à {$address}");

        return end($emails);
    }

    /** Paramètres du lien partagé contenu dans l'e-mail */
    private function shareQueryFrom(string $html): string
    {
        $this->assertSame(1, preg_match('#/memoires/shared/books/' . $this->bookId . '/chapters/[0-9a-f-]+\?([^"\s<]+)#', $html, $m), 'lien partagé absent de l\'e-mail');

        return html_entity_decode($m[1]);
    }

    private function signed(string $chapterId, ?string $contributorId = null, ?int $expires = null): string
    {
        $expires ??= time() + 600;
        $secret = static::getContainer()->getParameter('kernel.secret');
        $data = $contributorId === null ? "chapterId={$chapterId}&expires={$expires}" : "chapterId={$chapterId}&contributorId={$contributorId}&expires={$expires}";

        return http_build_query(array_filter(['chapterId' => $chapterId, 'contributorId' => $contributorId, 'expires' => $expires, 'signature' => hash_hmac('sha256', $data, $secret)]));
    }
}
