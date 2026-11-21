<?php

namespace App\Tests\Functional\Abo;

use App\Abo\Entity\Benefit;
use App\Abo\Entity\BenefitSlot;
use App\Abo\Entity\PlanBenefit;
use App\Abo\Services\AboAgendaService;
use App\Abo\Services\AboReminderService;
use App\Abo\Services\AboRightsService;
use App\Abo\Services\AboWaitlistService;
use App\Abo\Services\BenefitService;
use App\Entity\Adress;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot ABO-4 (21/11/2026, contrat validé par le chef) : liste d'attente, rappel de la veille, liens d'agenda .ics.
 * - Une place libérée est offerte à la première personne, compte dans la capacité, s'accepte ; une lecture ne promeut jamais.
 * - Une attente se ferme d'elle-même dans les quatre cas du chef ; plus de promotion automatique à moins d'1 h, l'équipe peut proposer.
 * - Le rappel part à 18 h du magasin, une fois, coupé par l'abonné seul ; l'offre et le rappel partent même au niveau « Aucune » du site.
 * - Le lien d'agenda : le même 404 pour toute raison, changé = l'ancien ne vaut plus, débit limité ; le jeton jamais dans une anomalie
 *   ni dans un journal.
 */
class AboWaitlistTest extends WebTestCase
{
    private const CUSTOMERS = ['abo4-a@example.invalid' => 'Alice', 'abo4-b@example.invalid' => 'Bastien', 'abo4-c@example.invalid' => 'Chloé'];
    private const STAFF = 'abo4-intervenant@example.invalid';
    private const TZ = 'America/Toronto';

    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private int $planId;
    private string $tag;
    private ?string $tzBefore = null;
    private mixed $levelBefore = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeSubscriptionStripeGateway::reset();
        $this->tag = bin2hex(random_bytes(3));
        $provider = $this->prov();
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-abo2@example.invalid', false, false);
        $em = $provider->getEntityManager();
        foreach (self::CUSTOMERS + [self::STAFF => 'Inès'] as $email => $first) {
            $roles = $email === self::STAFF ? [TeamRoles::STAFF_PROVIDER, 'ROLE_USER_INTERNET'] : ['ROLE_USER', 'ROLE_USER_INTERNET'];
            $u = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($u === null) {
                $em->persist((new User())->setEmail($email)->setUsername($email)->setFirstname($first)->setLastname('Secretnom')->setRoles($roles)->setIsVerified(true)->setPassword('inutilisable'));
            } else {
                $u->setRoles($roles);
            }
        }
        $em->flush();
        $this->db = $em->getConnection();
        $this->planId = (int) $this->db->fetchOne('SELECT sp.id FROM subscription_plan sp JOIN product p ON p.id = sp.product_id WHERE p.code = ? ORDER BY sp.id LIMIT 1', ['DEMO-PANIER']);
        $this->tzBefore = $this->db->fetchOne('SELECT pos_timezone FROM entreprise ORDER BY id LIMIT 1') ?: null;
        $this->levelBefore = $this->db->fetchOne('SELECT notification_level FROM entreprise ORDER BY id LIMIT 1');
        $this->db->executeStatement('UPDATE entreprise SET pos_timezone = ?, is_boutique_active = TRUE', [self::TZ]);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $emails = [...array_keys(self::CUSTOMERS), self::STAFF];
        $in = implode(', ', array_fill(0, count($emails), '?'));
        $this->db->executeStatement("DELETE FROM calendar_feed WHERE (owner_type = 'subscription' AND owner_id IN (SELECT s.id FROM subscription s JOIN \"user\" u ON u.id = s.user_id WHERE u.email IN ($in)))
            OR (owner_type = 'staff' AND owner_id IN (SELECT id FROM \"user\" WHERE email IN ($in)))", [...$emails, ...$emails]);
        $this->db->executeStatement("DELETE FROM subscription WHERE user_id IN (SELECT id FROM \"user\" WHERE email IN ($in))", $emails);
        $this->db->executeStatement("DELETE FROM adress WHERE address LIKE '3 rue de l''essai abo4%'");
        $this->db->executeStatement('DELETE FROM benefit_waitlist');
        $this->db->executeStatement('DELETE FROM benefit_slot_hold');
        $this->db->executeStatement('DELETE FROM benefit_slot');
        $this->db->executeStatement('DELETE FROM benefit_slot_rule');
        $this->db->executeStatement('DELETE FROM plan_benefit');
        $this->db->executeStatement('DELETE FROM benefit');
        $this->db->executeStatement("DELETE FROM refresh_tokens WHERE username IN ($in)", $emails);
        $this->db->executeQuery("DELETE FROM \"user\" WHERE email IN ($in)", $emails);
        $this->db->executeStatement("DELETE FROM content_audit_log WHERE resource IN ('subscription', 'benefit_slot', 'benefit_waitlist')");
        $this->db->executeStatement('UPDATE entreprise SET pos_timezone = ?, notification_level = ?', [$this->tzBefore, $this->levelBefore === false ? null : $this->levelBefore]);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        parent::tearDown();
    }

    public function testAFreedPlaceIsOfferedToTheFirstPersonCountsInTheCapacityAndIsAccepted(): void
    {
        $yoga = $this->benefit('Yoga attente');
        [$a, $b, $c] = $this->subscriptions($yoga, ['abo4-a@example.invalid', 'abo4-b@example.invalid', 'abo4-c@example.invalid']);
        $slot = $this->slot($yoga, '+3 days', 1);
        $booked = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 201, 'abo4-a@example.invalid')['appointment'];

        // créneau complet : on s'inscrit, une fois ; rang 1 puis 2
        $wb = $this->json('POST', $this->join($b, $slot), null, 201, 'abo4-b@example.invalid')['wait'];
        $this->assertSame(['waiting', 1], [$wb['status'], $wb['position']]);
        $this->assertSame('ALREADY_WAITING', $this->json('POST', $this->join($b, $slot), null, 409, 'abo4-b@example.invalid')['code']);
        $this->assertSame(2, $this->json('POST', $this->join($c, $slot), null, 201, 'abo4-c@example.invalid')['wait']['position']);
        $this->assertSame(404, $this->api('GET', '/api/subscriptions/' . $a->getId() . '/attente', null, 'abo4-b@example.invalid')->getStatusCode(), 'les attentes d\'un autre');
        $team = $this->json('GET', '/api/site/abonnements/creneaux/' . $slot . '/attente', null, 200);
        $this->assertSame([0, ['Bastien', 'Chloé']], [$team['freePlaces'], array_map(fn ($w) => $w['customer']['firstname'], $team['waitlist'])]);
        // une place libre : on la réserve directement
        $free = $this->slot($yoga, '+4 days', 2);
        $this->assertSame('SLOT_NOT_FULL', $this->json('POST', $this->join($b, $free), null, 409, 'abo4-b@example.invalid')['code']);

        // A annule : la place est offerte à B (courriel, échéance de 2 h au plus) et n'est plus libre pour personne d'autre
        $this->json('DELETE', '/api/subscriptions/' . $a->getId() . '/rendez-vous/' . $booked['occurrenceId'], null, 200, 'abo4-a@example.invalid');
        $this->assertSame(['offered', 'waiting'], $this->db->fetchFirstColumn('SELECT status FROM benefit_waitlist WHERE slot_id = ? ORDER BY id', [$slot]));
        $offer = $this->mailTo('abo4-b@example.invalid');
        $this->assertNotNull($offer, 'le courriel de l\'offre');
        $this->assertStringStartsWith('Une place s\'est libérée : Yoga attente', (string) $offer->getSubject());
        $expires = new \DateTimeImmutable((string) $this->db->fetchOne('SELECT offer_expires_at FROM benefit_waitlist WHERE id = ?', [$wb['waitId']]), new \DateTimeZone('UTC'));
        $this->assertLessThanOrEqual(2 * 3600 + 5, $expires->getTimestamp() - time());
        $this->assertStringContainsString($expires->setTimezone(new \DateTimeZone(self::TZ))->format('d/m/Y H:i'), (string) $offer->getHtmlBody(), 'l\'heure limite à l\'heure du magasin');
        $this->assertSame('SLOT_FULL', $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 409, 'abo4-a@example.invalid')['code']);
        $this->assertSame('offered', $this->json('GET', '/api/subscriptions/' . $b->getId() . '/attente', null, 200, 'abo4-b@example.invalid')['waitlist'][0]['status']);

        // B accepte : la date est posée ; C attend toujours
        $accepted = $this->json('POST', '/api/subscriptions/' . $b->getId() . '/attente/' . $wb['waitId'] . '/accepter', null, 201, 'abo4-b@example.invalid')['appointment'];
        $this->assertSame([$slot, 'scheduled'], [$accepted['slotId'], $accepted['status']]);
        $this->assertSame(['accepted', 'waiting'], $this->db->fetchFirstColumn('SELECT status FROM benefit_waitlist WHERE slot_id = ? ORDER BY id', [$slot]));
    }

    public function testAnExpiredOfferIsShownExpiredByAReadWithoutAnyWriteThenGoesToTheNextOnAWrite(): void
    {
        $yoga = $this->benefit('Yoga échue');
        [$a, $b, $c] = $this->subscriptions($yoga, ['abo4-a@example.invalid', 'abo4-b@example.invalid', 'abo4-c@example.invalid']);
        $slot = $this->slot($yoga, '+3 days', 1);
        $booked = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 201, 'abo4-a@example.invalid')['appointment'];
        $wb = $this->json('POST', $this->join($b, $slot), null, 201, 'abo4-b@example.invalid')['wait'];
        $this->json('POST', $this->join($c, $slot), null, 201, 'abo4-c@example.invalid');
        $this->json('DELETE', '/api/subscriptions/' . $a->getId() . '/rendez-vous/' . $booked['occurrenceId'], null, 200, 'abo4-a@example.invalid');
        $this->db->executeStatement("UPDATE benefit_waitlist SET offer_expires_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '1 minute' WHERE id = ?", [$wb['waitId']]);
        $before = $this->db->fetchAllAssociative('SELECT * FROM benefit_waitlist ORDER BY id');

        // décision du chef : un GET de l'abonné, de l'équipe ou de l'outil de l'IA ne change rien en base et n'envoie aucun courriel
        $this->assertSame('expired', $this->json('GET', '/api/subscriptions/' . $b->getId() . '/attente', null, 200, 'abo4-b@example.invalid')['waitlist'][0]['status']);
        $this->assertEmailCount(0);
        $this->assertSame(['expired', 'waiting'], array_column($this->json('GET', '/api/site/abonnements/creneaux/' . $slot . '/attente', null, 200)['waitlist'], 'status'));
        $this->assertEmailCount(0);
        $model = static::getContainer()->get(\App\Abo\Services\AboAssistantTools::class)->read('get_waitlist', ['slotId' => $slot]);
        $this->assertSame(['expired', 'waiting'], array_column($model[0]['waitlist'], 'status'));
        $this->assertArrayNotHasKey('customer', $model[0]['waitlist'][0], 'sans nom pour le modèle');
        $this->assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM benefit_waitlist ORDER BY id'), 'aucune écriture par une lecture');

        // un geste d'écriture (B veut accepter trop tard) : refus, et la place passe à C
        $this->assertSame('OFFER_EXPIRED', $this->json('POST', '/api/subscriptions/' . $b->getId() . '/attente/' . $wb['waitId'] . '/accepter', null, 409, 'abo4-b@example.invalid')['code']);
        $this->assertSame(['expired', 'offered'], $this->db->fetchFirstColumn('SELECT status FROM benefit_waitlist WHERE slot_id = ? ORDER BY id', [$slot]));
        $this->assertNotNull($this->mailTo('abo4-c@example.invalid'));
    }

    public function testAWaitClosesItselfInTheFourCasesOfTheChef(): void
    {
        $yoga = $this->benefit('Yoga fermetures');
        $pilates = $this->benefit('Pilates fermetures');
        [$a, $b, $c] = $this->subscriptions($yoga, ['abo4-a@example.invalid', 'abo4-b@example.invalid', 'abo4-c@example.invalid'], 1);
        [$d] = $this->subscriptions($pilates, ['abo4-c@example.invalid']);
        $s1 = $this->slot($yoga, '+3 days', 1);
        $s2 = $this->slot($yoga, '+5 days', 1);
        $p1 = $this->slot($pilates, '+3 days', 1);
        $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $s1], 201, 'abo4-a@example.invalid');
        [$aPilates] = $this->subscriptions($pilates, ['abo4-a@example.invalid']);
        $this->json('POST', '/api/subscriptions/' . $aPilates->getId() . '/rendez-vous', ['benefitId' => $pilates->getId(), 'slotId' => $p1], 201, 'abo4-a@example.invalid');
        $wb = $this->json('POST', $this->join($b, $s1), null, 201, 'abo4-b@example.invalid')['wait']['waitId'];
        $wc = $this->json('POST', $this->join($c, $s1), null, 201, 'abo4-c@example.invalid')['wait']['waitId'];
        $wd = $this->json('POST', $this->join($d, $p1), null, 201, 'abo4-c@example.invalid')['wait']['waitId'];
        $reason = fn (int $id) => $this->db->fetchAssociative('SELECT status, closed_reason FROM benefit_waitlist WHERE id = ?', [$id]);

        // 1. le droit porté est utilisé ailleurs : B (un seul droit) prend directement une date sur un autre créneau
        $this->json('POST', '/api/subscriptions/' . $b->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $s2], 201, 'abo4-b@example.invalid');
        $this->assertSame(['status' => 'left', 'closed_reason' => 'right_used'], $reason($wb));
        // 2. l'abonnement est résilié (écriture par l'ORM, comme le webhook)
        $em = $this->prov()->getEntityManager();
        $em->getRepository(Subscription::class)->find($c->getId())->setStatus(Subscription::STATUS_CANCELED);
        $em->flush();
        $this->assertSame(['status' => 'left', 'closed_reason' => 'subscription'], $reason($wc));
        // 3. la prestation est retirée de la formule
        $em->remove($em->getRepository(PlanBenefit::class)->findOneBy(['plan' => $this->plan(), 'benefit' => $em->getRepository(Benefit::class)->find($pilates->getId())]));
        $em->flush();
        $this->assertSame(['status' => 'left', 'closed_reason' => 'benefit_removed'], $reason($wd));
        // 4. le créneau est annulé : une nouvelle attente sur un créneau complet, puis l'annulation
        [$e] = $this->subscriptions($yoga, ['abo4-c@example.invalid']);
        $we = $this->json('POST', $this->join($e, $s1), null, 201, 'abo4-c@example.invalid')['wait']['waitId'];
        static::getContainer()->get(AboAgendaService::class)->cancelSlot($s1, 'Salle fermée', 'essai');
        $this->assertSame(['status' => 'left', 'closed_reason' => 'slot_gone'], $reason($we));
    }

    /** Relecture du 21/11/2026 (constats 1, 2 et 6) */
    public function testAFreedPlaceGoesToTheQueueBeforeADirectBookingAndAWaitKeepsAnotherValidRight(): void
    {
        $yoga = $this->benefit('Yoga file');
        [$a, $b, $c] = $this->subscriptions($yoga, ['abo4-a@example.invalid', 'abo4-b@example.invalid', 'abo4-c@example.invalid']);
        $slot = $this->slot($yoga, '+3 days', 1);
        $booked = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 201, 'abo4-a@example.invalid')['appointment'];
        $wb = $this->json('POST', $this->join($b, $slot), null, 201, 'abo4-b@example.invalid')['wait']['waitId'];
        $wc = $this->json('POST', $this->join($c, $slot), null, 201, 'abo4-c@example.invalid')['wait']['waitId'];
        $this->json('DELETE', '/api/subscriptions/' . $a->getId() . '/rendez-vous/' . $booked['occurrenceId'], null, 200, 'abo4-a@example.invalid');
        $status = fn (int $id) => $this->db->fetchAssociative('SELECT status, closed_reason FROM benefit_waitlist WHERE id = ?', [$id]);

        // l'offre de B échoit sans aucun geste : la place n'est pas libre pour autant, C attend juste derrière
        $this->db->executeStatement("UPDATE benefit_waitlist SET offer_expires_at = NOW() AT TIME ZONE 'UTC' - INTERVAL '1 minute' WHERE id = ?", [$wb]);
        $em = $this->prov()->getEntityManager();
        $em->clear();
        $this->assertSame(0, static::getContainer()->get(AboAgendaService::class)->slotViews([$em->getRepository(BenefitSlot::class)->find($slot)], false)[0]['remaining'], 'la place gardée par la file');
        $this->assertSame('SLOT_FULL', $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 409, 'abo4-a@example.invalid')['code'], 'A ne passe pas devant C');
        $this->assertSame(['expired', 'offered'], [$status($wb)['status'], $status($wc)['status']], 'le refus offre aussitôt la place à C');
        $this->assertNotNull($this->mailTo('abo4-c@example.invalid'));

        // la première personne de la file prend directement une place libérée (personne devant elle) ; son attente se ferme (`already_booked`)
        $s2 = $this->slot($yoga, '+4 days', 1);
        $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $s2], 201, 'abo4-a@example.invalid');
        $wb2 = $this->json('POST', $this->join($b, $s2), null, 201, 'abo4-b@example.invalid')['wait']['waitId'];
        $this->json('POST', $this->join($c, $s2), null, 201, 'abo4-c@example.invalid');
        $this->db->executeStatement('UPDATE benefit_slot SET capacity = 2 WHERE id = ?', [$s2]); // sans l'ORM : aucune promotion
        $this->json('POST', '/api/subscriptions/' . $b->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $s2], 201, 'abo4-b@example.invalid');
        $this->assertSame(['status' => 'left', 'closed_reason' => 'already_booked'], $status($wb2));

        // le droit porté est daté ailleurs, un autre droit vaut encore au début du créneau : l'attente passe sur lui, au lieu de se fermer
        $pilates = $this->benefit('Pilates droits');
        [$e] = $this->subscriptions($pilates, ['abo4-c@example.invalid'], 2);
        [$ap] = $this->subscriptions($pilates, ['abo4-a@example.invalid'], 1);
        $p = $this->slot($pilates, '+3 days', 1);
        $this->json('POST', '/api/subscriptions/' . $ap->getId() . '/rendez-vous', ['benefitId' => $pilates->getId(), 'slotId' => $p], 201, 'abo4-a@example.invalid');
        $we = $this->json('POST', $this->join($e, $p), null, 201, 'abo4-c@example.invalid')['wait']['waitId'];
        $carried = (int) $this->db->fetchOne('SELECT occurrence_id FROM benefit_waitlist WHERE id = ?', [$we]);
        $other = (int) $this->db->fetchOne("SELECT id FROM benefit_occurrence WHERE subscription_id = ? AND status = 'available' AND id <> ?", [$e->getId(), $carried]);
        $this->db->executeStatement("UPDATE benefit_occurrence SET valid_until = (SELECT starts_at FROM benefit_slot WHERE id = ?) + INTERVAL '1 day' WHERE id = ?", [$p, $other]);
        $later = $this->slot($pilates, '+10 days', 1);
        $this->json('POST', '/api/subscriptions/' . $e->getId() . '/rendez-vous', ['benefitId' => $pilates->getId(), 'slotId' => $later], 201, 'abo4-c@example.invalid');
        $this->assertSame(['waiting', $other], [$this->db->fetchOne('SELECT status FROM benefit_waitlist WHERE id = ?', [$we]), (int) $this->db->fetchOne('SELECT occurrence_id FROM benefit_waitlist WHERE id = ?', [$we])]);
    }

    public function testNoAutomaticOfferUnderAnHourButTheTeamCanProposeUntilTheStart(): void
    {
        $yoga = $this->benefit('Yoga dernière heure');
        [$a, $b] = $this->subscriptions($yoga, ['abo4-a@example.invalid', 'abo4-b@example.invalid']);
        $start = (new \DateTimeImmutable('+50 minutes'))->setTime((int) (new \DateTimeImmutable('+50 minutes'))->format('H'), (int) (new \DateTimeImmutable('+50 minutes'))->format('i'));
        $slot = $this->slotAt($yoga, $start, 1);
        $booked = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 201, 'abo4-a@example.invalid')['appointment'];
        $wb = $this->json('POST', $this->join($b, $slot), null, 201, 'abo4-b@example.invalid')['wait'];
        $this->json('DELETE', '/api/subscriptions/' . $a->getId() . '/rendez-vous/' . $booked['occurrenceId'], null, 200, 'abo4-a@example.invalid'); // annulation tardive
        $this->assertSame('waiting', $this->db->fetchOne('SELECT status FROM benefit_waitlist WHERE id = ?', [$wb['waitId']]), 'à moins d\'1 h : aucune offre automatique');
        $this->assertSame(0, static::getContainer()->get(AboWaitlistService::class)->sweep()['offers'], 'le passage horaire non plus');

        $offered = $this->json('POST', '/api/site/abonnements/attente/' . $wb['waitId'] . '/proposer', null, 200)['wait'];
        $this->assertSame('offered', $offered['status']);
        $this->assertSame($this->db->fetchOne('SELECT starts_at FROM benefit_slot WHERE id = ?', [$slot]), $this->db->fetchOne('SELECT offer_expires_at FROM benefit_waitlist WHERE id = ?', [$wb['waitId']]), 'jusqu\'au début de la séance');
        $this->assertNotNull($this->mailTo('abo4-b@example.invalid'));
    }

    public function testTheOfferAndTheReminderAreIndispensableAndOnlyTheSubscriberTurnsTheReminderOff(): void
    {
        $this->db->executeStatement("UPDATE entreprise SET notification_level = 'none'"); // le commerçant a coupé SES courriels
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        $yoga = $this->benefit('Yoga rappel');
        [$a, $b] = $this->subscriptions($yoga, ['abo4-a@example.invalid', 'abo4-b@example.invalid']);
        $tz = new \DateTimeZone(self::TZ);
        $tomorrow = (new \DateTimeImmutable('tomorrow', $tz))->setTime(15, 0);
        $slot = $this->slotAt($yoga, $tomorrow, 2);
        $ra = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 201, 'abo4-a@example.invalid')['appointment'];
        $rb = $this->json('POST', '/api/subscriptions/' . $b->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $slot], 201, 'abo4-b@example.invalid')['appointment'];
        $this->assertSame(['doneEmails' => true, 'reminders' => false], $this->json('PATCH', '/api/subscriptions/' . $b->getId() . '/courriels', ['reminders' => false], 200, 'abo4-b@example.invalid'));

        $reminders = static::getContainer()->get(AboReminderService::class);
        $this->assertSame(0, $reminders->sendDue((new \DateTimeImmutable('today', $tz))->setTime(17, 30)), 'rien avant 18 h du magasin');
        $this->assertSame(1, $reminders->sendDue((new \DateTimeImmutable('today', $tz))->setTime(18, 30)), 'le rappel de A seulement (B l\'a coupé)');
        $this->assertSame(0, $reminders->sendDue((new \DateTimeImmutable('today', $tz))->setTime(19, 30)), 'une fois par date');
        $this->assertNotNull($this->db->fetchOne('SELECT reminded_at FROM benefit_occurrence WHERE id = ?', [$ra['occurrenceId']]));
        $this->assertNull($this->db->fetchOne('SELECT reminded_at FROM benefit_occurrence WHERE id = ?', [$rb['occurrenceId']]));
        $mail = $this->mailTo('abo4-a@example.invalid');
        $this->assertNotNull($mail, 'site à « Aucune » : le rappel part quand même');
        $this->assertStringStartsWith('Rappel :', (string) $mail->getSubject());
        $ics = null;
        foreach ($mail->getAttachments() as $part) {
            $ics = str_contains((string) $part->getMediaType() . '/' . $part->getMediaSubtype(), 'text/calendar') ? $part->getBody() : $ics;
        }
        $this->assertNotNull($ics, 'la pièce .ics');
        $this->assertStringContainsString('SUMMARY:Yoga rappel', (string) $ics);
        // relecture du 21/11/2026 : PUBLISH (REQUEST exige un ORGANIZER), un identifiant par date (un droit rendu se redate sous le même numéro)
        $this->assertStringContainsString("METHOD:PUBLISH\r\n", (string) $ics);
        $this->assertStringContainsString('UID:abo-' . MV_TEST_TENANT_CODE . '-' . $ra['occurrenceId'] . '-' . $tomorrow->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z') . '@izzipro', (string) $ics);
        $this->assertStringContainsString('DTSTART:' . $tomorrow->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'), (string) $ics);

        // l'offre d'une place, au niveau « Aucune » aussi : B se met en attente d'un créneau complet, A annule
        $full = $this->slot($yoga, '+4 days', 1);
        $fa = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/rendez-vous', ['benefitId' => $yoga->getId(), 'slotId' => $full], 201, 'abo4-a@example.invalid')['appointment'];
        $this->json('POST', $this->join($b, $full), null, 201, 'abo4-b@example.invalid');
        $this->json('DELETE', '/api/subscriptions/' . $a->getId() . '/rendez-vous/' . $fa['occurrenceId'], null, 200, 'abo4-a@example.invalid');
        $this->assertNotNull($this->mailTo('abo4-b@example.invalid'), 'l\'offre part quand même, même avec le rappel coupé');
    }

    public function testCalendarLinksAnswerTheSame404ForEveryReasonAndRotate(): void
    {
        $visit = $this->benefit('Ménage', true);
        [$a] = $this->subscriptions($visit, ['abo4-a@example.invalid'], 3, 'admin');
        $this->withAddress($a);
        $staff = (int) $this->db->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::STAFF]);
        $slot = $this->slot($visit, '+2 days', 1, $staff);
        $this->json('POST', '/api/site/abonnements/' . $a->getId() . '/rendez-vous', ['benefitId' => $visit->getId(), 'slotId' => $slot], 201);

        // l'abonné : son lien, lu sans jeton d'accès par son agenda (le site par ?tenant=)
        $link = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/calendrier', null, 201, 'abo4-a@example.invalid')['url'];
        $this->assertMatchesRegularExpression('#^https://[^/]+/api/calendrier/[a-f0-9]{64}\.ics\?tenant=#', $link);
        $feed = $this->feed($link);
        $this->assertSame([200, 'text/calendar; charset=utf-8'], [$feed->getStatusCode(), $feed->headers->get('Content-Type')]);
        $this->assertStringContainsString('SUMMARY:Ménage', (string) $feed->getContent());
        $this->assertSame(404, $this->api('POST', '/api/subscriptions/' . $a->getId() . '/calendrier', null, 'abo4-b@example.invalid')->getStatusCode(), 'le lien d\'un autre abonnement');

        // changé : l'ancien ne vaut plus ; inconnu, révoqué, abonnement terminé : le même 404
        $new = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/calendrier', null, 201, 'abo4-a@example.invalid')['url'];
        $this->assertSame([404, '', 200], [$this->feed($link)->getStatusCode(), (string) $this->feed($link)->getContent(), $this->feed($new)->getStatusCode()]);
        $this->assertSame(404, $this->feed((string) preg_replace('#[a-f0-9]{64}#', str_repeat('a', 64), $new))->getStatusCode());
        $this->db->executeStatement("UPDATE subscription SET status = 'canceled' WHERE id = ?", [$a->getId()]);
        $this->assertSame(404, $this->feed($new)->getStatusCode(), 'abonnement terminé');
        $this->db->executeStatement("UPDATE subscription SET status = 'active' WHERE id = ?", [$a->getId()]);

        // l'intervenant : la phrase servie, son lien, le prénom et l'adresse de la visite, jamais le nom ni le téléphone
        $state = $this->json('GET', '/api/staff/abonnements/calendrier?locale=en', null, 200, self::STAFF);
        $this->assertSame(false, $state['exists']);
        $this->assertStringContainsString('visit addresses', $state['notice']);
        $mine = $this->json('POST', '/api/staff/abonnements/calendrier', null, 201, self::STAFF);
        $this->assertStringContainsString('adresses des visites', $mine['notice']);
        $body = (string) $this->feed($mine['url'])->getContent();
        $this->assertStringContainsString('SUMMARY:Ménage · Alice', $body);
        $this->assertStringContainsString('3 rue de l\'essai abo4', $body);
        foreach (['Secretnom', '555', 'abo4-a@example'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body, 'jamais dans l\'agenda de l\'intervenant : ' . $hidden);
        }
        $this->db->executeStatement('UPDATE "user" SET roles = ? WHERE email = ?', [json_encode(['ROLE_USER_INTERNET']), self::STAFF]);
        $this->assertSame(404, $this->feed($mine['url'])->getStatusCode(), 'rôle retiré');
    }

    public function testACalendarLinkIsRateLimitedAndItsTokenNeverReachesAnIncidentNorALog(): void
    {
        $yoga = $this->benefit('Yoga débit');
        [$a] = $this->subscriptions($yoga, ['abo4-a@example.invalid']);
        $link = $this->json('POST', '/api/subscriptions/' . $a->getId() . '/calendrier', null, 201, 'abo4-a@example.invalid')['url'];
        preg_match('#/api/calendrier/([a-f0-9]{64})\.ics#', $link, $m);
        $token = $m[1];

        // une panne sur la route, dont le message porte l'adresse : ni l'anomalie ni le journal n'ont le jeton
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->exec('DELETE FROM platform_incident');
        $log = static::getContainer()->getParameter('kernel.logs_dir') . '/test.log';
        $offset = is_file($log) ? filesize($log) : 0;
        $this->assertSame(500, $this->feed($link, ['HTTP_X_TEST_RAISE' => 'exception-uri'])->getStatusCode());
        $rows = $master->query("SELECT * FROM platform_incident WHERE route = 'api_calendar_feed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('/api/calendrier/***', (string) $rows[0]['message'], 'le chemin masqué');
        $this->assertStringNotContainsString($token, (string) json_encode($rows), 'jamais le jeton dans une anomalie');
        $written = is_file($log) ? (string) file_get_contents($log, false, null, $offset) : '';
        $this->assertStringContainsString('/api/calendrier/***', $written, 'la requête est bien au journal, masquée');
        $this->assertStringNotContainsString($token, $written, 'jamais le jeton dans un journal');
        // relecture du 21/11/2026 : une méthode refusée (405) est journalisée avec l'objet exception, dont le message porte l'adresse
        $offset = filesize($log);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $link, [], [], ['REMOTE_ADDR' => '10.79.1.' . random_int(1, 254)]);
        $this->prov()->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
        clearstatcache();
        $written = (string) file_get_contents($log, false, null, $offset);
        $this->assertStringContainsString('/api/calendrier/***', $written, 'la méthode refusée est au journal, masquée');
        $this->assertStringNotContainsString($token, $written, 'ni par l\'objet exception d\'une méthode refusée');
        $master->exec('DELETE FROM platform_incident');

        // débit : 60 lectures par heure et par jeton (la panne ci-dessus n'est pas passée par le contrôleur), puis 429
        for ($i = 0; $i < 60; ++$i) {
            $this->assertSame(200, $this->feed($link)->getStatusCode());
        }
        $this->assertSame(429, $this->feed($link)->getStatusCode());
    }

    // ---- aides ------------------------------------------------------------------------------------------------------------------------------

    private function prov(): TenantEntityManagerProvider
    {
        return static::getContainer()->get(TenantEntityManagerProvider::class);
    }

    private function plan(): SubscriptionPlan
    {
        return $this->prov()->getEntityManager()->getRepository(SubscriptionPlan::class)->find($this->planId);
    }

    private function benefit(string $name, bool $atCustomer = false): Benefit
    {
        return static::getContainer()->get(BenefitService::class)->saveBenefit(null, ['kind' => 'session', 'names' => ['fr' => $name], 'durationMinutes' => 60, 'atCustomer' => $atCustomer]);
    }

    /**
     * Un abonnement actif par adresse, droits nés d'une facture payée ; créneaux au choix de l'abonné (ou de l'équipe : `admin`).
     *
     * @param list<string> $emails
     * @return list<Subscription>
     */
    private function subscriptions(Benefit $benefit, array $emails, int $quantity = 3, string $choice = 'client'): array
    {
        $em = $this->prov()->getEntityManager();
        static::getContainer()->get(BenefitService::class)->setPlanBenefit($this->plan(), $em->getRepository(Benefit::class)->find($benefit->getId()), ['scheduling' => 'fixed_slots', 'slotChoice' => $choice, 'quantity' => $quantity]);
        $out = [];
        foreach ($emails as $i => $email) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            $stripe = 'sub_abo4_' . bin2hex(random_bytes(4));
            $s = (new Subscription())->setUser($user)->setPlan($this->plan())->setQuantity(1)->setStripeSubscriptionId($stripe)->setStatus(Subscription::STATUS_ACTIVE)->setCurrentPeriodEnd(new \DateTimeImmutable('+30 days'));
            $em->persist($s);
            $em->flush();
            FakeSubscriptionStripeGateway::$subscriptions[$stripe] = ['id' => $stripe, 'status' => 'active', 'currentPeriodEnd' => time() + 30 * 86400, 'cancelAtPeriodEnd' => false, 'paused' => false, 'itemId' => 'si_' . $stripe];
            static::getContainer()->get(AboRightsService::class)->birth((int) $s->getId(), 'in_abo4_' . $this->tag . '_' . $i . '_' . $s->getId(), time() - 60, time() + 30 * 86400, 4900, 'subscription_cycle');
            $out[] = $s;
        }

        return $out;
    }

    private function withAddress(Subscription $s): void
    {
        $em = $this->prov()->getEntityManager();
        $address = (new Adress())->setFirstname('Alice')->setLastname('Secretnom')->setFullname('Alice Secretnom')->setAddress('3 rue de l\'essai abo4')->setPhone('514 555 0123')
            ->setCity('Montréal')->setCodepostal('H2X 1Y4')->setCountry('CA')->setProvince('QC');
        $em->persist($address);
        $em->flush();
        $this->db->executeStatement('UPDATE subscription SET address_id = ? WHERE id = ?', [$address->getId(), $s->getId()]);
    }

    private function slot(Benefit $benefit, string $when, int $capacity, ?int $staff = null): int
    {
        return $this->slotAt($benefit, (new \DateTimeImmutable($when, new \DateTimeZone(self::TZ)))->setTime(15, 0), $capacity, $staff);
    }

    private function slotAt(Benefit $benefit, \DateTimeImmutable $start, int $capacity, ?int $staff = null): int
    {
        $em = $this->prov()->getEntityManager();
        $slot = (new BenefitSlot())->setBenefit($em->getRepository(Benefit::class)->find($benefit->getId()))->setStartsAt($start)->setEndsAt($start->modify('+1 hour'))->setCapacity($capacity)->setStaffUserId($staff);

        return (int) static::getContainer()->get(AboAgendaService::class)->createSlot($slot)->getId();
    }

    private function join(Subscription $s, int $slotId): string
    {
        return '/api/subscriptions/' . $s->getId() . '/creneaux/' . $slotId . '/attente';
    }

    /** Le dernier courriel de la dernière requête adressé à cette adresse */
    private function mailTo(string $email): ?\Symfony\Component\Mime\Email
    {
        $found = null;
        foreach ($this->getMailerMessages() as $m) {
            if ($m instanceof \Symfony\Component\Mime\Email && in_array($email, array_map(fn ($a) => $a->getAddress(), $m->getTo()), true)) {
                $found = $m;
            }
        }

        return $found;
    }

    /** Le lien lu comme un agenda : ni jeton d'accès, ni en-tête du site, ni session @param array<string, string> $server */
    private function feed(string $url, array $server = []): Response
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url, [], [], $server + ['REMOTE_ADDR' => '10.79.0.' . random_int(1, 254)]);
        $this->prov()->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $this->client->getResponse();
    }

    private function json(string $method, string $path, ?array $body, int $status, string $as = 'admin'): array
    {
        $r = $this->api($method, $path, $body, $as);
        $this->assertSame($status, $r->getStatusCode(), $method . ' ' . $path . ' : ' . (string) $r->getContent());

        return json_decode((string) $r->getContent(), true) ?? [];
    }

    private function api(string $method, string $path, ?array $body, string $as): Response
    {
        $this->prov()->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $email = $as === 'admin' ? 'admin@arkanoa-media.com' : $as;
        $user = $this->prov()->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        $token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], $body !== null ? json_encode($body) : null);
        $this->prov()->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $this->client->getResponse();
    }
}
