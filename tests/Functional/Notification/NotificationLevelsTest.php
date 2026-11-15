<?php

namespace App\Tests\Functional\Notification;

use App\Entity\User;
use App\Gestion\Services\GestionNotificationService;
use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Notification\NotificationCatalog as Catalog;
use App\Services\Notification\NotificationGate;
use App\Services\Notification\NotificationLevel;
use App\Services\Notification\NotificationLevelResolver;
use App\Services\Notification\NotificationSummaryService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformModeService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Niveaux des courriels de suivi (lot NOTIF-1, contrat validé par le propriétaire le 15/11/2026, `docs/notifications-courriels.md`).
 *
 * Les sites de test sont au niveau « Tout », sans plafond ni heure (`tests/bootstrap.php`, `config/services_test.yaml`), pour que le reste
 * de la suite voie ses courriels. Ici chaque test règle le niveau lui-même et monte la décision d'envoi avec les VRAIES valeurs
 * (plafond de 3, rien avant 8 h du magasin) ; les instants sont fixés, jamais lus à l'horloge.
 */
class NotificationLevelsTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private const ZONE = 'America/Toronto';
    private KernelBrowser $client;
    private string $tag = '';
    private ?bool $supportFlag = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tag = bin2hex(random_bytes(3));
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        $this->db()->executeStatement('DELETE FROM notification_preference');
        $this->supportFlag = (bool) $this->db()->fetchOne('SELECT is_support_active FROM entreprise ORDER BY id LIMIT 1');
    }

    protected function tearDown(): void
    {
        $this->siteLevel(NotificationLevel::ALL);
        static::getContainer()->get(PlatformModeService::class)->setMode('demo', 'test');
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND note = 'notif-test')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND note = 'notif-test'")->execute([MV_TEST_TENANT_CODE]);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        $this->db()->executeStatement('UPDATE "user" SET notification_level = NULL WHERE notification_level IS NOT NULL'); // les comptes d'essai restent dans la base de test : aucun ne garde un niveau
        if ($this->supportFlag !== null) { // accorder un droit projette son drapeau sur la fiche du site : remis comme avant le test
            $this->db()->executeStatement('UPDATE entreprise SET is_support_active = ?', [$this->supportFlag], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        $this->db()->executeStatement('DELETE FROM notification_preference');
        $this->db()->close(); // PHPUnit garde l'instance du test : sa connexion ne doit pas rester ouverte jusqu'à la fin de la suite
        parent::tearDown();
    }

    public function testNoneSendsNothingButTheEssentialsAndEssentialHoldsEverythingButTheUrgent(): void
    {
        $gate = $this->gate();
        $to = $this->address('aucune');
        $at = $this->moment('2031-03-10 10:00');

        // Aucune : ni urgent ni suivi ; rien n'est écrit au journal ; un indispensable part, sans être compté ni écrit
        $this->siteLevel(NotificationLevel::NONE);
        $sent = 0;
        $send = function () use (&$sent): bool {
            ++$sent;

            return true;
        };
        foreach (Catalog::governed() as $kind) {
            $this->assertSame(NotificationGate::DROP, $gate->deliver($kind, $to, 'Titre', [], $send, $at), $kind);
        }
        $this->assertSame([0, 0], [$sent, $this->rows($to)]);
        foreach (['password_reset', 'support_agent_invitation', 'order_confirmation', 'pos_receipt', 'customer_message', 'customer_acknowledgement', 'loss_exercise', 'chapter_invitation_exhausted', 'platform_feature_request'] as $kind) {
            $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver($kind, $to, 'Titre', [], $send, $at), $kind);
        }
        $this->assertSame([9, 0], [$sent, $this->rows($to)], 'les indispensables partent, ni comptés ni écrits');

        // Essentiel : l'urgent part tout de suite ; le suivi est retenu pour le résumé de la semaine, et la sortie du jour n'y touche pas
        $this->siteLevel(NotificationLevel::ESSENTIAL);
        $to = $this->address('essentiel');
        $sent = 0;
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::POS_HEALTH_ALERT, $to, 'Alerte de caisse', [], $send, $at));
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::ENTITLEMENT_URGENT, $to, 'Paiement en attente', [], $send, $at));
        foreach ([Catalog::ORDER_TO_SHIP, Catalog::BOOKING_NEW, Catalog::BOOK_PAYMENT_RECEIVED, Catalog::LOSS_DIGEST] as $kind) {
            $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver($kind, $to, "Suivi $kind", [], $send, $at), $kind);
        }
        $this->assertSame(2, $sent);
        $this->assertSame(['held' => 4, 'sent' => 2], $this->statuses($to));
        $this->assertSame(['weekly'], array_values(array_unique($this->reasons($to))));
        foreach (['2031-03-10 12:00', '2031-03-11 09:00', '2031-03-17 09:00'] as $later) {
            $this->assertSame(['ran' => true, 'emails' => 0, 'released' => 0, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment($later)), $later);
        }
        $this->assertSame(['held' => 4, 'sent' => 2], $this->statuses($to), 'toujours retenus : ils attendent le résumé de la semaine');
    }

    public function testNormalSendsTheFirstOfTheDayPerTopicNeverBeforeEightAndTheRestTheNextDay(): void
    {
        $this->siteLevel(NotificationLevel::NORMAL);
        $gate = $this->gate();
        $to = $this->address('normal');
        $sent = [];
        $send = function (string $what) use (&$sent): \Closure {
            return function () use (&$sent, $what): bool {
                $sent[] = $what;

                return true;
            };
        };

        // Avant 8 h du magasin : retenu ; il sort le jour même, au premier passage à partir de 8 h, pas avant
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande A', [], $send('A'), $this->moment('2031-03-10 07:59')));
        $this->assertSame(['before_hours'], $this->reasons($to));
        $this->assertFalse($this->summary($gate)->releaseDue($this->moment('2031-03-10 07:59'))['ran']);
        $before = count($this->getMailerMessages());
        $this->assertSame(['ran' => true, 'emails' => 1, 'released' => 1, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-03-10 08:00')));
        $mail = $this->getMailerMessages()[$before];
        $this->assertSame([$to, true], [$mail->getTo()[0]->getAddress(), str_contains((string) $mail->getHtmlBody(), 'Commande A')]);
        $this->assertStringContainsString('1 commande', (string) $mail->getSubject());

        // Le courriel sorti compte pour UN dans le plafond du jour, pas pour un de plus par ligne qu'il porte
        $this->assertSame([1, 1], [$gate->sentToday($to, '2031-03-10'), $gate->sentToday($to, '2031-03-10', Catalog::TOPIC_ORDERS)]);

        // Le sujet « commandes » a eu son courriel du jour : les suivantes sont retenues ; un autre sujet part, lui, tout de suite
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande B', [], $send('B'), $this->moment('2031-03-10 09:00')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande C', [], $send('C'), $this->moment('2031-03-10 15:00')));
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::BOOKING_NEW, $to, 'Réservation 1', [], $send('R1'), $this->moment('2031-03-10 15:05')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::BOOKING_NEW, $to, 'Réservation 2', [], $send('R2'), $this->moment('2031-03-10 15:10')));
        $this->assertSame(['R1'], $sent);
        // Le même jour, rien de plus ne sort ; rien de nouveau : aucun courriel
        $this->assertSame(0, $this->summary($gate)->releaseDue($this->moment('2031-03-10 20:00'))['emails']);

        // Le lendemain à 8 h : UN courriel par sujet, avec tout ce qui a été retenu ; un second passage ne renvoie rien
        $before = count($this->getMailerMessages());
        $this->assertSame(['ran' => true, 'emails' => 2, 'released' => 3, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-03-11 08:00')));
        $this->assertSame(['ran' => true, 'emails' => 0, 'released' => 0, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-03-11 09:00')), 'jamais deux fois');
        $bodies = array_map(fn ($m) => (string) $m->getHtmlBody(), array_slice($this->getMailerMessages(), $before));
        $this->assertCount(2, $bodies);
        $orders = array_values(array_filter($bodies, fn (string $b) => str_contains($b, 'Commande B')))[0];
        $this->assertSame([true, false], [str_contains($orders, 'Commande C'), str_contains($orders, 'Réservation 2')], 'un courriel par sujet');
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE recipient = ? AND status = 'held'", [$to]));
        // quatre lignes retenues sont sorties en tout : « Commande A » la veille à 8 h, puis B, C et « Réservation 2 » ce matin
        $this->assertSame(4, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE recipient = ? AND status = 'sent' AND released_at IS NOT NULL", [$to]));

        // Ce jour-là, le sujet a eu son courriel (celui des retenus) : une nouvelle commande attend le lendemain
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande D', [], $send('D'), $this->moment('2031-03-11 10:00')));
        // Des retenus plus anciens attendent encore (passage manqué) : le nouvel événement les rejoint, et tout sort ensemble
        $late = $this->address('retard');
        $gate->record(Catalog::ORDER_TO_SHIP, $late, null, NotificationGate::STATUS_HELD, 'Commande ancienne', [], '2031-03-08', NotificationGate::HELD_TOPIC_DONE);
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $late, 'Commande du jour', [], $send('J'), $this->moment('2031-03-11 10:00')));
        $this->assertSame(['topic_done', 'with_backlog'], $this->reasons($late));
        $this->assertSame(['ran' => true, 'emails' => 1, 'released' => 2, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-03-11 11:00')));
        $this->assertSame(['R1'], $sent, 'aucun autre envoi direct');
    }

    public function testAllSendsEveryEventUpToTheDailyCapAndAnUrgentIsNeverHeld(): void
    {
        $this->siteLevel(NotificationLevel::ALL);
        $gate = $this->gate();
        $to = $this->address('tout');
        $sent = 0;
        $send = function () use (&$sent): bool {
            ++$sent;

            return true;
        };
        $day = '2031-04-02';
        foreach (['Commande 1', 'Commande 2', 'Commande 3'] as $i => $title) {
            $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, $title, [], $send, $this->moment("$day 1$i:00")), $title);
        }
        // Le 4e courriel de notification du jour est retenu, quel que soit son sujet ; un urgent part quand même, et compte
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande 4', [], $send, $this->moment("$day 13:00")));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::BOOKING_NEW, $to, 'Réservation 1', [], $send, $this->moment("$day 13:30")));
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::POS_HEALTH_ALERT, $to, 'Alerte de caisse', [], $send, $this->moment("$day 14:00")));
        $this->assertSame([4, ['daily_cap', 'daily_cap']], [$sent, $this->reasons($to)]);
        $this->assertSame(4, $gate->sentToday($to, $day));
        $this->assertSame(0, $this->summary($gate)->releaseDue($this->moment("$day 18:00"))['emails'], 'plafond atteint : rien de plus aujourd\'hui');
        // Le lendemain : les retenus sortent, un courriel par sujet ; ils comptent dans le plafond du nouveau jour
        $this->assertSame(['ran' => true, 'emails' => 2, 'released' => 2, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-04-03 08:30')));
        $this->assertSame(2, $gate->sentToday($to, '2031-04-03'));
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande 5', [], $send, $this->moment('2031-04-03 09:00')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande 6', [], $send, $this->moment('2031-04-03 09:30')), 'plafond de 3 atteint de nouveau');
        // Deux retenus d'avant 8 h sortent en UN courriel : le plafond en compte un, il reste de la place pour deux autres ce jour-là
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'email'"); // la sortie traite tout le site : on repart d'un journal vide
        $early = $this->address('matin');
        foreach (['Commande de 6 h', 'Commande de 7 h'] as $i => $title) {
            $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $early, $title, [], $send, $this->moment('2031-04-10 0' . (6 + $i) . ':00')));
        }
        $this->assertSame(['ran' => true, 'emails' => 1, 'released' => 2, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-04-10 08:05')));
        $this->assertSame(1, $gate->sentToday($early, '2031-04-10'));
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::BOOKING_NEW, $early, 'Réservation de 9 h', [], $send, $this->moment('2031-04-10 09:00')));
        $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver(Catalog::BOOK_PAYMENT_RECEIVED, $early, 'Paiement de 10 h', [], $send, $this->moment('2031-04-10 10:00')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $early, 'Commande de 11 h', [], $send, $this->moment('2031-04-10 11:00')), 'le 4e du jour');
        // Quand le plafond ne laisse qu'une place, c'est le sujet au retenu le PLUS ANCIEN qui sort, pas le premier de l'alphabet
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        $queue = $this->address('file');
        $gate->record(Catalog::BOOK_PAYMENT_RECEIVED, $queue, null, NotificationGate::STATUS_HELD, 'Paiement ancien', [], '2031-04-18', NotificationGate::HELD_DAILY_CAP);
        $gate->record(Catalog::BOOKING_NEW, $queue, null, NotificationGate::STATUS_HELD, 'Réservation récente', [], '2031-04-19', NotificationGate::HELD_DAILY_CAP);
        $gate->record(Catalog::ORDER_TO_SHIP, $queue, null, NotificationGate::STATUS_SENT, 'Parti 1', [], '2031-04-20', null);
        $gate->record(Catalog::POS_HEALTH_ALERT, $queue, null, NotificationGate::STATUS_SENT, 'Parti 2', [], '2031-04-20', null);
        $this->assertSame(['ran' => true, 'emails' => 1, 'released' => 1, 'kept' => 0], $this->summary($gate)->releaseDue($this->moment('2031-04-20 09:00')));
        $this->assertSame(['Paiement ancien' => 'sent', 'Réservation récente' => 'held'], array_map('strval', $this->db()->fetchAllKeyValue("SELECT title, status FROM notification_log WHERE recipient = ? AND title IN ('Paiement ancien', 'Réservation récente') ORDER BY id", [$queue])));

        // Un envoi en échec n'est ni compté ni marqué parti
        $other = $this->address('echec');
        $this->assertSame(NotificationGate::STATUS_FAILED, $gate->deliver(Catalog::ORDER_TO_SHIP, $other, 'Commande perdue', [], fn (): bool => false, $this->moment("$day 10:00")));
        $this->assertSame([0, ['failed' => 1]], [$gate->sentToday($other, $day), $this->statuses($other)]);
    }

    public function testTheAccountLevelReplacesTheSiteLevelAndAContactAddressFollowsTheSite(): void
    {
        $levels = static::getContainer()->get(NotificationLevelResolver::class);
        $this->siteLevel(NotificationLevel::ESSENTIAL);
        $admin = $this->user(['ROLE_ADMIN']);
        $id = (int) $admin->getId();
        $contact = $this->address('contact');

        $this->assertSame(['value' => 'essential', 'source' => 'site', 'userLevel' => null], array_diff_key($levels->forUser($id), ['siteLevel' => 1]));
        $this->assertSame(['level' => 'essential', 'userId' => $id], $levels->forAddress(strtoupper((string) $admin->getEmail())), 'l\'adresse d\'un compte, quelle que soit la casse');
        $this->assertSame(['level' => 'essential', 'userId' => null], $levels->forAddress($contact), 'adresse de contact qui n\'est celle d\'aucun compte : le site');
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'all' WHERE id = ?", [$id]);
        $this->assertSame(['value' => 'all', 'source' => 'user', 'userLevel' => 'all'], array_diff_key($levels->forUser($id), ['siteLevel' => 1]));
        $this->assertSame(['all', 'essential'], [$levels->forAddress((string) $admin->getEmail())['level'], $levels->forAddress($contact)['level']]);

        // Seul un compte de l'ÉQUIPE du site porte un niveau : un compte client, un compte non vérifié ou supprimé à la même adresse ne
        // règle rien (l'adresse suit alors le site), même s'il avait choisi un niveau du temps où il était administrateur
        foreach ([['ROLE_USER_INTERNET'], ['ROLE_USER_POS']] as $roles) {
            $customer = $this->user($roles);
            $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'all' WHERE id = ?", [$customer->getId()]);
            $this->assertSame(['level' => 'essential', 'userId' => null], $levels->forAddress((string) $customer->getEmail()), implode(',', $roles));
        }
        foreach (['is_verified = false', 'deleted_at = NOW()'] as $state) {
            $gone = $this->user(['ROLE_ADMIN']);
            $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'all', $state WHERE id = ?", [$gone->getId()]);
            $this->assertSame(['level' => 'essential', 'userId' => null], $levels->forAddress((string) $gone->getEmail()), $state);
        }
        $agent = $this->user(['ROLE_SUPPORT']);
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'normal' WHERE id = ?", [$agent->getId()]);
        $this->assertSame(['level' => 'normal', 'userId' => (int) $agent->getId()], $levels->forAddress((string) $agent->getEmail()), 'un agent d\'assistance est de l\'équipe');

        // La décision suit : le compte au niveau « Tout » reçoit, l'adresse de contact du site (Essentiel) voit son courriel retenu
        $gate = $this->gate();
        $at = $this->moment('2031-05-05 10:00');
        $this->assertSame(NotificationGate::SEND, $gate->decide(Catalog::ORDER_TO_SHIP, (string) $admin->getEmail(), $at)['decision']);
        $this->assertSame([NotificationGate::HOLD, 'weekly'], array_values(array_intersect_key($gate->decide(Catalog::ORDER_TO_SHIP, $contact, $at), ['decision' => 1, 'reason' => 1])));
        // « Comme le site » : retour au niveau du site ; une valeur inconnue en base ne vaut rien
        $this->db()->executeStatement('UPDATE "user" SET notification_level = NULL WHERE id = ?', [$id]);
        $this->assertSame('essential', $levels->forUser($id)['value']);
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'bruyant' WHERE id = ?", [$id]);
        $this->assertSame(['essential', 'site'], [$levels->forUser($id)['value'], $levels->forUser($id)['source']]);

        // Un type coupé dans « Avancé » ne part à aucun niveau, pour ce compte seulement
        $this->siteLevel(NotificationLevel::ALL);
        $this->db()->executeStatement('UPDATE "user" SET notification_level = NULL WHERE id = ?', [$id]);
        $this->db()->executeStatement("INSERT INTO notification_preference (user_id, kind, enabled, updated_at) VALUES (?, 'loss_digest', false, NOW())", [$id]);
        $this->assertSame([NotificationGate::DROP, 'kind_off'], array_values(array_intersect_key($gate->decide(Catalog::LOSS_DIGEST, (string) $admin->getEmail(), $at), ['decision' => 1, 'reason' => 1])));
        $this->assertSame(NotificationGate::SEND, $gate->decide(Catalog::ORDER_TO_SHIP, (string) $admin->getEmail(), $at)['decision'], 'les autres types ne sont pas touchés');
        $this->assertSame(NotificationGate::SEND, $gate->decide(Catalog::LOSS_DIGEST, $contact, $at)['decision'], 'ni les autres destinataires');
        $this->db()->executeStatement("UPDATE notification_preference SET enabled = true WHERE user_id = ? AND kind = 'loss_digest'", [$id]);
        $this->assertSame(NotificationGate::SEND, $gate->decide(Catalog::LOSS_DIGEST, (string) $admin->getEmail(), $at)['decision']);
    }

    public function testHeldEmailsOfAnAccountThatTurnsThemOffAreKeptNeverSentNeverLost(): void
    {
        $this->siteLevel(NotificationLevel::NORMAL);
        $gate = $this->gate();
        $admin = $this->user(['ROLE_ADMIN']);
        $to = (string) $admin->getEmail();
        $send = fn (): bool => true;
        $gate->deliver(Catalog::LOSS_DIGEST, $to, 'Résumé du jour', [], $send, $this->moment('2031-06-02 09:00'));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::LOSS_DIGEST, $to, 'Résumé retenu', [], $send, $this->moment('2031-06-02 10:00')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande avant 8 h', [], $send, $this->moment('2031-06-02 06:00')));
        // Le compte coupe le type : sa ligne retenue est gardée (lisible, jamais envoyée) ; l'autre sujet sort normalement
        $this->db()->executeStatement("INSERT INTO notification_preference (user_id, kind, enabled, updated_at) VALUES (?, 'loss_digest', false, NOW())", [$admin->getId()]);
        $before = count($this->getMailerMessages());
        $this->assertSame(['ran' => true, 'emails' => 1, 'released' => 1, 'kept' => 1], $this->summary($gate)->releaseDue($this->moment('2031-06-03 08:00')));
        $this->assertCount($before + 1, $this->getMailerMessages());
        $this->assertStringNotContainsString('Résumé retenu', (string) $this->getMailerMessages()[$before]->getHtmlBody());
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE recipient = ? AND status = 'kept' AND title = 'Résumé retenu' AND released_at IS NOT NULL", [$to]));
        // Niveau « Aucune » choisi ensuite : ce qui était retenu est gardé, rien ne part
        $gate->deliver(Catalog::ORDER_TO_SHIP, $to, 'Commande retenue', [], $send, $this->moment('2031-06-03 09:00'));
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'none' WHERE id = ?", [$admin->getId()]);
        $before = count($this->getMailerMessages());
        $this->assertSame(['ran' => true, 'emails' => 0, 'released' => 0, 'kept' => 1], $this->summary($gate)->releaseDue($this->moment('2031-06-04 08:00')));
        $this->assertCount($before, $this->getMailerMessages());
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE recipient = ? AND status = 'held'", [$to]));
    }

    public function testTheAutomaticSiteLevelIsEssentialForATrialSiteAndNormalForAnActiveOne(): void
    {
        $levels = static::getContainer()->get(NotificationLevelResolver::class);
        $platform = static::getContainer()->get(PlatformModeService::class);
        $rights = static::getContainer()->get(EntitlementService::class);
        $cache = static::getContainer()->get(TenantCacheService::class);
        $this->siteLevel(null);

        // Plateforme en mode « demo » : site d'essai, même avec un droit payé
        $platform->setMode('demo', 'test');
        $this->assertSame(['value' => 'essential', 'mode' => 'auto', 'reason' => 'platform_demo'], $levels->site());
        $rights->grant(MV_TEST_TENANT_CODE, 'module.support', 'subscription', null, 'test', 'notif-test', null, null, false);
        $cache->invalidateTenant();
        $this->assertSame('platform_demo', $levels->site()['reason']);
        // Plateforme « live » et un droit payé actif : site en activité
        $platform->setMode('live', 'test');
        $this->assertSame(['value' => 'normal', 'mode' => 'auto', 'reason' => 'active'], $levels->site());
        // Plateforme « live », mais seulement un essai ou un droit accordé à la main : site d'essai
        $this->forget();
        foreach (['trial', 'manual', 'free'] as $source) {
            $source === 'trial' ? $rights->trial(MV_TEST_TENANT_CODE, 'module.support', 30, 'test', 'notif-test') : $rights->grant(MV_TEST_TENANT_CODE, 'module.support', $source, null, 'test', 'notif-test', null, null, false);
            $cache->invalidateTenant();
            $this->assertSame(['value' => 'essential', 'mode' => 'auto', 'reason' => 'trial'], $levels->site(), $source);
            $this->forget();
        }
        $rights->grant(MV_TEST_TENANT_CODE, 'module.support', 'purchase', null, 'test', 'notif-test', null, null, false);
        $cache->invalidateTenant();
        $this->assertSame('active', $levels->site()['reason'], 'un achat vaut un abonnement');
        // Le réglage de la plateforme passe avant tout calcul
        foreach (NotificationLevel::LEVELS as $level) {
            $this->siteLevel($level);
            $this->assertSame(['value' => $level, 'mode' => 'manual', 'reason' => null], $levels->site());
        }
        $this->siteLevel('bruyant');
        $this->assertSame('auto', $levels->site()['mode'], 'une valeur inconnue en base ne vaut rien');
    }

    public function testThePreferencesRouteGivesAndChangesTheLevelOfTheAccount(): void
    {
        $this->siteLevel(NotificationLevel::ESSENTIAL);
        $admin = $this->user(['ROLE_ADMIN']);
        $route = '/api/gestion/notifications/preferences';
        $body = $this->json('GET', $route, null, $admin);
        $this->assertSame(['level', 'kinds'], array_keys($body));
        $level = $body['level'];
        $this->assertSame(['value', 'source', 'userLevel', 'siteLevel', 'dailyCap', 'heldCount', 'siteChoiceLabel', 'choices'], array_keys($level));
        $this->assertSame(['essential', 'site', null, 3, 0, 'Comme le site'], [$level['value'], $level['source'], $level['userLevel'], $level['dailyCap'], $level['heldCount'], $level['siteChoiceLabel']]);
        $this->assertSame(['value' => 'essential', 'mode' => 'manual', 'reason' => null, 'label' => 'Essentiel', 'reasonLabel' => 'réglé par la plateforme'], $level['siteLevel']);
        $this->assertSame(['none', 'essential', 'normal', 'all'], array_column($level['choices'], 'key'));
        $this->assertSame(['Aucune', 'Essentiel', 'Normal', 'Tout'], array_column($level['choices'], 'label'));
        foreach ($level['choices'] as $choice) {
            $this->assertNotSame('', trim((string) $choice['description']));
        }
        $this->assertSame(['None', 'Essential', 'Normal', 'Everything'], array_column($this->json('GET', $route . '?locale=en', null, $admin)['level']['choices'], 'label'));
        // Chaque type dit sa famille et son sujet ; le point de la semaine est un type de suivi par courriel, actif par défaut
        $kinds = array_column($body['kinds'], null, 'kind');
        $this->assertSame(['kind' => 'weekly_digest', 'label' => 'Point de la semaine par courriel', 'enabled' => true, 'available' => true, 'channel' => 'email', 'family' => 'followup', 'subject' => null], $kinds['weekly_digest']);
        $this->assertSame([['urgent', 'management'], ['followup', 'loss'], ['followup', 'management']],
            [[$kinds['cash_critical']['family'], $kinds['cash_critical']['subject']], [$kinds['loss_digest']['family'], $kinds['loss_digest']['subject']], [$kinds['low_stock']['family'], $kinds['low_stock']['subject']]]);

        // Le compte choisit son niveau ; « comme le site » y revient ; niveau et types ensemble
        foreach (NotificationLevel::LEVELS as $chosen) {
            $after = $this->json('PATCH', $route, ['level' => $chosen], $admin)['level'];
            $this->assertSame([$chosen, 'user', $chosen], [$after['value'], $after['source'], $after['userLevel']]);
        }
        $this->assertSame('all', $this->db()->fetchOne('SELECT notification_level FROM "user" WHERE id = ?', [$admin->getId()]));
        $after = $this->json('PATCH', $route, ['level' => null, 'kinds' => ['weekly_digest' => false]], $admin);
        $this->assertSame(['essential', 'site', null], [$after['level']['value'], $after['level']['source'], $after['level']['userLevel']]);
        $this->assertFalse(array_column($after['kinds'], 'enabled', 'kind')['weekly_digest']);
        // Les types seuls, comme avant : le niveau ne bouge pas
        $this->json('PATCH', $route, ['level' => 'normal'], $admin);
        $this->assertSame('normal', $this->json('PATCH', $route, ['kinds' => ['weekly_digest' => true]], $admin)['level']['value']);
        // Refus
        foreach (['bruyant', '', 3, true, ['normal']] as $bad) {
            $r = $this->call('PATCH', $route, ['level' => $bad], $admin);
            $this->assertSame([422, 'VALIDATION_FAILED', 'INVALID_VALUE'], [$r->getStatusCode(), $this->decode($r)['code'] ?? null, $this->decode($r)['fields']['level'] ?? null], json_encode($bad));
        }
        $this->assertSame('normal', $this->db()->fetchOne('SELECT notification_level FROM "user" WHERE id = ?', [$admin->getId()]), 'un refus ne change rien');
        $this->assertSame(422, $this->call('PATCH', $route, [], $admin)->getStatusCode(), 'ni niveau ni types');
        // Un autre compte n'est pas touché ; sans session, rien
        $other = $this->user(['ROLE_ADMIN']);
        $this->assertSame(['essential', 'site'], [($l = $this->json('GET', $route, null, $other)['level'])['value'], $l['source']]);
        $this->assertSame(401, $this->call('GET', $route, null, null)->getStatusCode());

        // Les courriels retenus du compte : comptés, et lisibles dans son journal avec leur état
        $gate = $this->gate();
        $this->json('PATCH', $route, ['level' => 'essential'], $admin);
        $gate->deliver(Catalog::ORDER_TO_SHIP, (string) $admin->getEmail(), 'Commande retenue', ['screen' => 'commandes', 'params' => []], fn (): bool => true, $this->moment('2031-07-01 10:00'));
        $gate->deliver(Catalog::ORDER_TO_SHIP, (string) $other->getEmail(), 'Commande d\'un autre', [], fn (): bool => true, $this->moment('2031-07-01 10:00'));
        $this->assertSame(1, $this->json('GET', $route, null, $admin)['level']['heldCount']);
        $journal = $this->json('GET', '/api/gestion/notifications/journal', null, $admin);
        $this->assertCount(1, $journal);
        $this->assertSame(['order_to_ship', 'Commande retenue', 'held', 'email', 'orders', ['screen' => 'commandes', 'params' => []]],
            [$journal[0]['kind'], $journal[0]['title'], $journal[0]['status'], $journal[0]['channel'], $journal[0]['topic'], $journal[0]['action']]);
        // Le journal se filtre par canal : l'écran du téléphone ne demande que « push », celui des courriels que « email »
        $gate->record(Catalog::ORDER_TO_SHIP, (string) $admin->getEmail(), (int) $admin->getId(), NotificationGate::STATUS_SENT, 'Commande partie', [], '2031-07-01', null);
        $this->db()->executeStatement("INSERT INTO notification_log (user_id, kind, title, body, action, subject, status, devices, store_day, sent_at) VALUES (?, 'low_stock', 'Stock bas', 'Corps', '{}', '{}', 'sent', 1, '2031-07-01', NOW())", [$admin->getId()]);
        $titles = fn (string $query): array => array_column($this->json('GET', '/api/gestion/notifications/journal' . $query, null, $admin), 'title');
        $all = $titles('');
        sort($all);
        $this->assertSame(['Commande partie', 'Commande retenue', 'Stock bas'], $all);
        $emails = $titles('?channel=email');
        sort($emails);
        $this->assertSame([['Stock bas'], ['Commande partie', 'Commande retenue']], [$titles('?channel=push'), $emails]);
        $this->assertCount(1, $titles('?channel=email&limit=1'));
        $r = $this->call('GET', '/api/gestion/notifications/journal?channel=sms', null, $admin);
        $this->assertSame([422, 'INVALID_VALUE'], [$r->getStatusCode(), $this->decode($r)['fields']['channel'] ?? null]);
    }

    public function testAnEmailNeverConsumesThePhoneNotificationOfTheDayAndTheRealLimitsAreTheDefaults(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        $gate = $this->gate();
        $this->siteLevel(NotificationLevel::ALL);
        $at = $this->moment('2031-08-04 10:00');
        $gate->deliver(Catalog::ORDER_TO_SHIP, (string) $admin->getEmail(), 'Commande', [], fn (): bool => true, $at);
        $log = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getRepository(\App\Entity\NotificationLog::class);
        $this->assertFalse($log->usedToday((int) $admin->getId(), '2031-08-04'), 'un courriel parti ne prend pas la place de la notification du téléphone');
        $this->assertSame([], $log->subjectsSince((int) $admin->getId(), Catalog::ORDER_TO_SHIP, $at->modify('-1 day')));

        // Les valeurs de la production sont les défauts du constructeur ; seul le fichier de test les relève, et rien d'autre
        $defaults = [];
        foreach ((new \ReflectionClass(NotificationGate::class))->getConstructor()->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }
        $this->assertSame(['dailyCap' => 3, 'fromHour' => 8, 'logger' => null], $defaults);
        $this->assertSame([3, 8], [NotificationLevel::DAILY_CAP, NotificationLevel::FROM_HOUR]);
        $config = dirname(__DIR__, 3) . '/config';
        $elsewhere = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($config, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['yaml', 'yml', 'php', 'xml'], true) && $file->getFilename() !== 'services_test.yaml'
                && !str_contains($file->getPathname(), '/jwt') && !str_contains($file->getPathname(), '/secrets')
                && preg_match('/NotificationGate|dailyCap|fromHour/', (string) file_get_contents($file->getPathname()))) {
                $elsewhere[] = substr($file->getPathname(), strlen($config) + 1);
            }
        }
        $this->assertSame([], $elsewhere, 'seul config/services_test.yaml règle le plafond et l\'heure de la décision d\'envoi');
        $test = \Symfony\Component\Yaml\Yaml::parseFile($config . '/services_test.yaml')['services'][NotificationGate::class]['arguments'] ?? [];
        $this->assertSame(['$dailyCap', '$fromHour'], array_keys($test));
        $container = static::getContainer()->get(NotificationGate::class);
        $this->assertSame([1000000, 0], [$container->dailyCap(), $container->fromHour()], 'le service des tests porte les valeurs relevées');
    }

    /** La décision d'envoi avec les vraies valeurs de la production (plafond de 3, rien avant 8 h du magasin) */
    private function gate(): NotificationGate
    {
        $c = static::getContainer();

        return new NotificationGate($c->get(TenantEntityManagerProvider::class), $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class));
    }

    private function summary(NotificationGate $gate): NotificationSummaryService
    {
        $c = static::getContainer();

        return new NotificationSummaryService($c->get(TenantEntityManagerProvider::class), $gate, $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class),
            $c->get(EmailSenderService::class), $c->get(EmailLocale::class), $c->get(TranslatorInterface::class), $c->get(AdminSpaceUrl::class), $c->get(SiteName::class),
            $c->get(TenantConnectionManager::class), $c->get(TenantPublicHostResolver::class), new NullLogger());
    }

    /** Un instant donné à l'heure du magasin */
    private function moment(string $local): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function address(string $prefix): string
    {
        return "$prefix-{$this->tag}@example.invalid";
    }

    private function siteLevel(?string $level): void
    {
        $this->db()->executeStatement('UPDATE entreprise SET notification_level = ?', [$level]);
    }

    private function forget(): void
    {
        $master = static::getContainer()->get(TenantConnectionManager::class)->getPdoMaster();
        $master->prepare("DELETE FROM tenant_entitlement_events WHERE entitlement_id IN (SELECT id FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support')")->execute([MV_TEST_TENANT_CODE]);
        $master->prepare("DELETE FROM tenant_entitlements WHERE tenant_code = ? AND feature_key = 'module.support'")->execute([MV_TEST_TENANT_CODE]);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
    }

    private function rows(string $email): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM notification_log WHERE recipient = ?', [$email]);
    }

    /** @return array<string, int> état → nombre de lignes */
    private function statuses(string $email): array
    {
        return array_map('intval', $this->db()->fetchAllKeyValue('SELECT status, COUNT(*) FROM notification_log WHERE recipient = ? GROUP BY status ORDER BY status', [$email]));
    }

    /** @return list<string> raisons des lignes retenues, dans l'ordre de leur écriture */
    private function reasons(string $email): array
    {
        return array_map(fn ($s) => (string) ((json_decode((string) $s, true) ?: [])['reason'] ?? ''), $this->db()->fetchFirstColumn("SELECT subject FROM notification_log WHERE recipient = ? AND status = 'held' ORDER BY id", [$email]));
    }

    private function user(array $roles): User
    {
        $provider = $this->provider();
        $email = 'notif-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Nadia')->setLastname('Notifiée')->setRoles($roles)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $provider->getEntityManager()->persist($user);
        $provider->getEntityManager()->flush();

        return $user;
    }

    private function call(string $method, string $path, ?array $body, ?User $as): Response
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($as !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->provider()->getEntityManager()->find(User::class, $as->getId()));
        }
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], $server, $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function json(string $method, string $path, ?array $body, ?User $as, int $expected = 200): array
    {
        $response = $this->call($method, $path, $body, $as);
        $this->assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function provider(): TenantEntityManagerProvider
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->provider()->getEntityManager()->getConnection();
    }
}
