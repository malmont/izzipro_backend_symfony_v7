<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Reservation;
use App\Entity\User;
use App\Services\Agency\Assistance\AssistanceSections;
use App\Services\ReservationService\ShopBookingAssistantTools;
use App\Services\ReservationService\ShopBookingException;
use App\Services\ReservationService\ShopBookingService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot CMD-WEB-1 (20/11/2026, contrat validé par le chef) : les rendez-vous de la BOUTIQUE (« Réservations de Prestations ») pour la gestion
 * web. La table porte aussi les entretiens de Mémoires Vivantes : jamais servis ici (404). Confirmer deux fois ne change rien la seconde fois ;
 * confirmer un rendez-vous annulé : 409 ; l'agent d'Assistance lit, confirme et annule ; un client est refusé ; l'agence le fait pendant une
 * assistance « clients » ; un rendez-vous ne porte aucun paiement (l'annulation n'a rien à rembourser).
 */
class ShopBookingApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->em()->getConnection()->executeStatement("DELETE FROM reservation WHERE client_email LIKE 'cmdweb1-%@example.invalid'");
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement("DELETE FROM reservation WHERE client_email LIKE 'cmdweb1-%@example.invalid'");
        $this->em()->getConnection()->executeStatement("DELETE FROM content_audit_log WHERE resource = 'reservation'");
        parent::tearDown();
    }

    public function testTheListServesOnlyTheShopBookingsUpcomingFirst(): void
    {
        [$soon, $later, $past, $mv, $book] = [$this->booking('+2 days'), $this->booking('+9 days', 'confirmed'), $this->booking('-3 days', 'cancelled'), $this->booking('+1 day', 'pending', true), $this->booking('+1 day', 'pending', false, 'livre-1')];
        $step = $this->booking('+1 day');
        $this->em()->getConnection()->executeStatement('UPDATE reservation SET step_number = 2, total_steps = 5 WHERE id = ?', [$step]); // séance MV dont le biographe a été supprimé
        $admin = $this->token(['ROLE_ADMIN']);
        $list = $this->call('GET', '/api/site/reservations-boutique?page=1&limit=50', $admin);
        $mine = array_values(array_filter($list['items'], fn (array $b) => str_starts_with((string) $b['clientEmail'], 'cmdweb1-' . $this->tag)));
        $this->assertSame([$soon, $later, $past], array_column($mine, 'id'), 'à venir d\'abord (le plus proche en tête), puis les passés ; jamais un entretien MV');
        $this->assertNotContains($mv, array_column($list['items'], 'id'));
        $this->assertNotContains($book, array_column($list['items'], 'id'));
        $this->assertNotContains($step, array_column($list['items'], 'id'), 'une séance (numéro d\'étape) sans livre ni biographe reste un entretien MV');
        $this->call('GET', "/api/site/reservations-boutique/$step", $admin, 404);
        $this->assertCount(1, $this->call('GET', '/api/site/reservations-boutique?limit=1', $admin)['items'], 'limit sans page : la première page');

        // le directeur entré : ni courriel, ni téléphone, ni notes du client
        $this->assertSame(['id', 'serviceId', 'serviceName', 'forfaitName', 'date', 'slot', 'guests', 'clientName', 'status', 'createdAt'],
            array_keys(ShopBookingService::forAudience($mine[0], \App\Services\OrderService\OrderDeskView::DIRECTOR)));
        $this->assertSame($mine[0], ShopBookingService::forAudience($mine[0], \App\Services\OrderService\OrderDeskView::LIMITED));
        $this->assertSame((string) $list['total'], $this->client->getResponse()->headers->get('X-Total-Count'));
        $this->assertSame(['id', 'serviceId', 'serviceName', 'forfaitName', 'date', 'slot', 'guests', 'clientName', 'clientEmail', 'clientPhone', 'notes', 'status', 'createdAt'], array_keys($mine[0]));

        $pending = $this->call('GET', '/api/site/reservations-boutique?status=pending&from=' . date('Y-m-d') . '&page=1&limit=50', $admin)['items'];
        $this->assertContains($soon, array_column($pending, 'id'));
        $this->assertNotContains($later, array_column($pending, 'id'));
        $this->assertSame('STATUS_INVALID', $this->call('GET', '/api/site/reservations-boutique?status=paid', $admin, 422)['code']);
        $this->assertSame('DATE_INVALID', $this->call('GET', '/api/site/reservations-boutique?from=20-11-2026', $admin, 422)['code']);

        $this->assertSame($soon, $this->call('GET', "/api/site/reservations-boutique/$soon", $admin)['id']);
        $this->assertSame('RESERVATION_NOT_FOUND', $this->call('GET', "/api/site/reservations-boutique/$mv", $admin, 404)['code'], 'un entretien MV : 404, sans dire pourquoi');
        $this->assertSame('RESERVATION_NOT_FOUND', $this->call('GET', "/api/site/reservations-boutique/$book", $admin, 404)['code']);
    }

    public function testConfirmingTwiceChangesNothingTheSecondTimeAndACancelledOneCannotBeConfirmed(): void
    {
        $id = $this->booking('+2 days');
        $mv = $this->booking('+2 days', 'pending', true);
        $admin = $this->token(['ROLE_ADMIN']);
        $first = $this->call('POST', "/api/site/reservations-boutique/$id/confirmer", $admin);
        $this->assertSame(['confirmed', true, true], [$first['reservation']['status'], $first['changed'], $first['emailRequested']]);
        $again = $this->call('POST', "/api/site/reservations-boutique/$id/confirmer", $admin);
        $this->assertSame(['confirmed', false, false], [$again['reservation']['status'], $again['changed'], $again['emailRequested']], 'pas de second courriel');
        $this->assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM content_audit_log WHERE resource = 'reservation' AND resource_id = ? AND action = 'confirm'", [(string) $id]));

        $cancelled = $this->call('POST', "/api/site/reservations-boutique/$id/annuler", $admin);
        $this->assertSame(['cancelled', true], [$cancelled['reservation']['status'], $cancelled['changed']]);
        $this->assertFalse($this->call('POST', "/api/site/reservations-boutique/$id/annuler", $admin)['changed']);
        $this->assertSame('RESERVATION_CANCELLED', $this->call('POST', "/api/site/reservations-boutique/$id/confirmer", $admin, 409)['code']);

        $this->assertSame('RESERVATION_NOT_FOUND', $this->call('POST', "/api/site/reservations-boutique/$mv/confirmer", $admin, 404)['code']);
        $this->assertSame('pending', $this->status($mv), 'un entretien MV n\'est jamais touché par ces routes');

        // L'écran EasyAdmin, par le même service : toutes les lignes, et un rendez-vous annulé s'y reconfirme (comme avant)
        $service = static::getContainer()->get(ShopBookingService::class);
        $this->assertTrue($service->changeStatus($id, 'confirmed', false, 'admin', 'fr', null, true)['changed']);
        $this->assertTrue($service->changeStatus($mv, 'confirmed', false, 'admin')['changed']);
        $this->expectException(ShopBookingException::class);
        $service->changeStatus($mv, 'cancelled', true, 'admin');
    }

    public function testTheSupportAgentHandlesBookingsAndACustomerIsRefused(): void
    {
        $id = $this->booking('+2 days');
        $agent = $this->token(['ROLE_SUPPORT']);
        $this->assertContains($id, array_column($this->call('GET', '/api/site/reservations-boutique', $agent)['items'], 'id'));
        $this->assertSame('confirmed', $this->call('POST', "/api/site/reservations-boutique/$id/confirmer", $agent)['reservation']['status']);
        $this->assertSame('cancelled', $this->call('POST', "/api/site/reservations-boutique/$id/annuler", $agent)['reservation']['status']);
        $this->call('GET', '/api/reservations', $agent, 401); // route publique : l'agent y est servi comme un anonyme, la liste des entretiens MV exige un compte

        $customer = $this->token(['ROLE_USER_INTERNET']);
        $this->call('GET', '/api/site/reservations-boutique', $customer, 403);
        $this->call('POST', "/api/site/reservations-boutique/$id/confirmer", $customer, 403);
        $this->call('GET', '/api/site/reservations-boutique', null, 401);

        // L'agence entrée : pendant une assistance « clients » en modification (liste blanche des écritures) ; jamais par la section « commandes »
        $this->assertTrue(AssistanceSections::writeAllowed('clients', 'POST', "/api/site/reservations-boutique/$id/confirmer"));
        $this->assertTrue(AssistanceSections::writeAllowed('clients', 'POST', "/api/site/reservations-boutique/$id/annuler"));
        $this->assertFalse(AssistanceSections::writeAllowed('commandes', 'POST', "/api/site/reservations-boutique/$id/confirmer"));
    }

    public function testTheAssistantProposesAndTheStatusChangesOnlyAtConfirmation(): void
    {
        $id = $this->booking('+2 days');
        $mv = $this->booking('+2 days', 'pending', true);
        $tools = static::getContainer()->get(ShopBookingAssistantTools::class);
        $read = $tools->read(['status' => 'pending']);
        $this->assertContains($id, array_column($read['bookings'], 'reservationId'));
        $this->assertNotContains($mv, array_column($read['bookings'], 'reservationId'));
        $this->assertLessThanOrEqual(ShopBookingAssistantTools::MAX_ROWS, count($read['bookings']));

        [$input, $summary, $error, $preview] = $tools->propose(['reservationId' => $id, 'decision' => 'confirm']);
        $this->assertNull($error);
        $this->assertSame(['pending', 'confirmed', true], [$preview['before'], $preview['after'], $preview['emailToClient']]);
        $this->assertStringContainsString('Confirmer le rendez-vous n° ' . $id, $summary);
        $this->assertSame('pending', $this->status($id), 'la proposition n\'écrit rien');
        $this->assertSame('RESERVATION_NOT_FOUND', $tools->propose(['reservationId' => $mv, 'decision' => 'confirm'])[2]['code']);
        $this->assertSame('STATUS_INVALID', $tools->propose(['reservationId' => $id, 'decision' => 'delete'])[2]['code']);

        $done = $tools->apply($input, 'Essai', 'fr', null);
        $this->assertSame(['confirmed', true], [$done['status'], $done['changed']]);
        $this->assertSame('NOTHING_TO_DO', $tools->propose(['reservationId' => $id, 'decision' => 'confirm'])[2]['code']);
    }

    /**
     * Un rendez-vous de la boutique ne porte AUCUN paiement : ni association de l'entité, ni entité qui le cite, ni colonne d'une autre table
     * en dehors du lien de la soumission de formulaire. Si un lien de paiement apparaît, cet essai casse : poser alors la garde
     * 409 RESERVATION_PAID sur l'annulation (décision du chef, CMD-WEB-1), puis mettre l'essai à jour.
     */
    public function testABookingCarriesNoPayment(): void
    {
        $em = $this->em();
        $associations = array_keys($em->getClassMetadata(Reservation::class)->getAssociationMappings());
        sort($associations);
        $this->assertSame(['biographer', 'entreprise'], $associations);
        $citing = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if ($mapping['targetEntity'] === Reservation::class) {
                    $citing[] = $metadata->getName() . '::' . $field;
                }
            }
        }
        $this->assertSame([], $citing);
        $columns = $em->getConnection()->fetchFirstColumn("SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name LIKE '%reservation%id%' ORDER BY 1");
        $this->assertSame(['form_submission.reservation_id'], $columns);
    }

    private function booking(string $when, string $status = 'pending', bool $biographer = false, ?string $bookId = null): int
    {
        $em = $this->em();
        $r = (new Reservation())->setServiceName('Massage ' . $this->tag)->setReservationDate(new \DateTime($when))->setReservationSlot('10:00')
            ->setClientName('Client ' . $this->tag)->setClientEmail('cmdweb1-' . $this->tag . '-' . bin2hex(random_bytes(2)) . '@example.invalid')->setClientPhone('+1 514 555 0000')
            ->setStatus($status)->setCreatedAt(new \DateTime())->setBookId($bookId);
        if ($biographer) {
            $email = 'cmdweb1-bio-' . bin2hex(random_bytes(3)) . '@example.invalid';
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Bio')->setLastname('Graphe')->setRoles(['ROLE_USER'])->setIsVerified(true)->setPassword('x');
            $em->persist($user);
            $r->setBiographer($user);
        }
        $em->persist($r);
        $em->flush();

        return (int) $r->getId();
    }

    private function status(int $id): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM reservation WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?string $token, int $status = 200): array
    {
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
            + ($token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : []), $method === 'POST' ? '{}' : null);
        $this->assertSame($status, $this->client->getResponse()->getStatusCode(), "$method $path : " . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function token(array $roles): string
    {
        $em = $this->em();
        $email = 'cmdweb1-compte-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Essai')->setLastname('Rdv')->setRoles($roles)->setIsVerified(true)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }

    private function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
