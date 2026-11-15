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

    public function testTheWeeklySummaryLeavesOnMondayMorningForEachAdministratorWithTheHeldEmails(): void
    {
        $gate = $this->gate();
        $weekly = $this->weekly($gate);
        $this->siteLevel(NotificationLevel::ESSENTIAL);
        $anna = $this->user(['ROLE_ADMIN']);
        $bruno = $this->user(['ROLE_ADMIN']); // a coupé le résumé de la semaine
        $this->db()->executeStatement("INSERT INTO notification_preference (user_id, kind, enabled, updated_at) VALUES (?, 'weekly_digest', false, NOW())", [$bruno->getId()]);
        $chloe = $this->user(['ROLE_ADMIN']); // a coupé le résumé des pertes : elle n'en lit pas les titres
        $this->db()->executeStatement("INSERT INTO notification_preference (user_id, kind, enabled, updated_at) VALUES (?, 'loss_digest', false, NOW())", [$chloe->getId()]);
        $dana = $this->user(['ROLE_ADMIN']); // a choisi « Normal » : ses retenus sortent au passage horaire, pas dans le résumé
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'normal' WHERE id = ?", [$dana->getId()]);
        $owner = $this->user(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']); // le compte de la plateforme, présent sur chaque base
        $contact = $this->address('contact'); // adresse de contact du site, sans compte
        $send = fn (): bool => true;
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, (string) $dana->getEmail(), "Avant 8 h {$this->tag}", [], $send, $this->moment('2031-09-08 06:00')));
        // Mercredi : deux courriels de suivi retenus pour le résumé de la semaine (le même titre pour deux destinataires n'est écrit qu'une fois)
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, $contact, "Commande {$this->tag} à expédier", [], $send, $this->moment('2031-09-03 11:00')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::LOSS_DIGEST, (string) $anna->getEmail(), "Pertes {$this->tag}", [], $send, $this->moment('2031-09-03 12:00')));
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::LOSS_DIGEST, (string) $bruno->getEmail(), "Pertes {$this->tag}", [], $send, $this->moment('2031-09-03 12:00')));

        // Ni le dimanche, ni le lundi avant 8 h, ni le lundi après la fenêtre, ni un mardi
        foreach (['2031-09-07 10:00', '2031-09-08 07:59', '2031-09-08 12:00', '2031-09-09 09:00'] as $when) {
            $this->assertFalse($weekly->sendIfDue($this->moment($when))['ran'], $when);
        }
        $this->assertSame(4, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE status = 'held' AND title LIKE ?", ["%{$this->tag}%"]));

        // Lundi 8 h : un courriel par administrateur qui l'accepte, avec les retenus ; Bruno, qui l'a coupé, ne reçoit rien
        $before = count($this->getMailerMessages());
        $run = $weekly->sendIfDue($this->moment('2031-09-08 08:00'));
        $this->assertTrue($run['ran']);
        $this->assertContains((string) $anna->getEmail(), $run['recipients']);
        $this->assertNotContains((string) $bruno->getEmail(), $run['recipients']);
        $this->assertNotContains($contact, $run['recipients'], 'l\'adresse de contact ne reçoit le résumé que si elle est celle d\'un administrateur');
        $this->assertNotContains((string) $owner->getEmail(), $run['recipients'], 'le super administrateur de la plateforme n\'est pas un administrateur du site');
        $this->assertSame([3, 3], [$run['held'], $run['released']], 'le retenu d\'un destinataire au niveau « Normal » n\'est pas pris');
        $mine = array_values(array_filter(array_slice($this->getMailerMessages(), $before), fn ($m) => $m->getTo()[0]->getAddress() === $anna->getEmail()));
        $this->assertCount(1, $mine);
        $html = (string) $mine[0]->getHtmlBody();
        $this->assertStringContainsString('point de la semaine', (string) $mine[0]->getSubject());
        $this->assertStringContainsString("Commande {$this->tag} à expédier", $html);
        $this->assertSame(1, substr_count($html, "Pertes {$this->tag}"), 'un même titre retenu pour deux destinataires n\'est écrit qu\'une fois');
        $this->assertStringContainsString('Les courriels de suivi de la semaine', $html);
        $this->assertStringNotContainsString("Avant 8 h {$this->tag}", $html);
        $hers = array_values(array_filter(array_slice($this->getMailerMessages(), $before), fn ($m) => $m->getTo()[0]->getAddress() === $chloe->getEmail()));
        $this->assertCount(1, $hers);
        $this->assertStringContainsString("Commande {$this->tag} à expédier", (string) $hers[0]->getHtmlBody());
        $this->assertStringNotContainsString("Pertes {$this->tag}", (string) $hers[0]->getHtmlBody(), 'un type coupé dans « Avancé » ne se lit pas dans le résumé');
        $this->assertSame(["Avant 8 h {$this->tag}"], $this->db()->fetchFirstColumn("SELECT title FROM notification_log WHERE status = 'held'"), 'il attend le passage horaire, pas le résumé');
        $this->assertSame(3, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE status = 'sent' AND released_at IS NOT NULL AND title LIKE ?", ["%{$this->tag}%"]));
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'weekly_digest' AND status = 'sent' AND recipient = ? AND store_day = '2031-09-08'", [$anna->getEmail()]));
        $this->assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'weekly_digest' AND recipient = ?", [$bruno->getEmail()]));

        // Passage suivant du même lundi, puis lancement à la main dans la semaine : personne n'a deux résumés la même semaine
        $gate->deliver(Catalog::ORDER_TO_SHIP, $contact, "Seconde commande {$this->tag}", [], $send, $this->moment('2031-09-08 08:30'));
        $before = count($this->getMailerMessages());
        $again = $weekly->sendIfDue($this->moment('2031-09-08 09:00'));
        $this->assertSame([true, 0, 0], [$again['ran'], $again['emails'], $again['released']]);
        $forced = $weekly->sendIfDue($this->moment('2031-09-10 15:00'), true, true);
        $this->assertSame([true, 0], [$forced['ran'], $forced['emails']]);
        $this->assertCount($before, $this->getMailerMessages());
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE status = 'held' AND recipient = ?", [$contact]), 'retenu après le résumé : il attend celui de la semaine suivante');

        // Lundi suivant : il part ; un essai à blanc avant n'envoie ni n'écrit rien
        $dry = $weekly->sendIfDue($this->moment('2031-09-15 08:00'), false, false, true);
        $this->assertSame([0, 0, 1], [$dry['emails'], $dry['released'], $dry['held']]);
        $this->assertContains((string) $anna->getEmail(), $dry['recipients']);
        $this->assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE kind = 'weekly_digest' AND recipient = ?", [$anna->getEmail()]), 'un essai à blanc n\'écrit rien');
        $next = $weekly->sendIfDue($this->moment('2031-09-15 08:00'));
        $this->assertSame(1, $next['released']);
        $this->assertContains((string) $anna->getEmail(), $next['recipients']);
    }

    public function testHeldEmailsAreKeptWhenNoAdministratorTakesTheWeeklySummary(): void
    {
        $gate = $this->gate();
        // Le site est au niveau « Aucune » ; seule Carla a choisi « Essentiel », mais elle a coupé le résumé de la semaine
        $this->siteLevel(NotificationLevel::NONE);
        $carla = $this->user(['ROLE_ADMIN']);
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'essential' WHERE id = ?", [$carla->getId()]);
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::ORDER_TO_SHIP, (string) $carla->getEmail(), 'Commande sans résumé', [], fn (): bool => true, $this->moment('2031-10-01 11:00')));
        $this->db()->executeStatement("INSERT INTO notification_preference (user_id, kind, enabled, updated_at) VALUES (?, 'weekly_digest', false, NOW())", [$carla->getId()]);
        $before = count($this->getMailerMessages());
        $run = $this->weekly($gate)->sendIfDue($this->moment('2031-10-06 08:00'));
        $this->assertSame([true, 0, 0, 1, []], [$run['ran'], $run['emails'], $run['released'], $run['kept'], $run['recipients']]);
        $this->assertCount($before, $this->getMailerMessages());
        $this->assertSame(['kept' => 1], $this->statuses((string) $carla->getEmail()), 'gardé, lisible dans l\'application, jamais envoyé');
    }

    public function testAnAddressIsCountedWhateverItsCaseAndAHeldEmailWithoutTopicLeavesToo(): void
    {
        $gate = $this->gate();
        $send = fn (): bool => true;
        // Niveau « Tout » : trois courriels à une adresse écrite avec des majuscules, le quatrième bute sur le plafond même écrit autrement
        $this->siteLevel(NotificationLevel::ALL);
        $mixed = 'Majuscules-' . $this->tag . '@Example.INVALID';
        foreach ([Catalog::ORDER_TO_SHIP, Catalog::BOOKING_NEW, Catalog::BOOK_PAYMENT_RECEIVED] as $kind) {
            $this->assertSame(NotificationGate::STATUS_SENT, $gate->deliver($kind, $mixed, "Courriel $kind", [], $send, $this->moment('2031-11-03 10:00')));
        }
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::LOSS_DIGEST, mb_strtolower($mixed), 'Quatrième', [], $send, $this->moment('2031-11-03 11:00')));
        $this->assertSame(['daily_cap'], $this->reasons(mb_strtolower($mixed)));
        $this->assertSame(4, $this->rows(mb_strtolower($mixed)), 'les quatre lignes portent l\'adresse en minuscules');
        $this->assertSame(1, $gate->heldCount($mixed));

        // Niveau « Normal » : un courriel de suivi sans sujet retenu avant 8 h sort au premier passage, comme les autres
        $this->siteLevel(NotificationLevel::NORMAL);
        $to = $this->address('sans-sujet');
        $this->assertSame(NotificationGate::STATUS_HELD, $gate->deliver(Catalog::WEEKLY_DIGEST, $to, 'Suivi sans sujet', [], $send, $this->moment('2031-11-04 06:00')));
        $before = count($this->getMailerMessages());
        $out = $this->summary($gate)->releaseDue($this->moment('2031-11-04 08:00'));
        $this->assertGreaterThanOrEqual(1, $out['released']);
        $mine = array_values(array_filter(array_slice($this->getMailerMessages(), $before), fn ($m) => $m->getTo()[0]->getAddress() === $to));
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('Suivi sans sujet', (string) $mine[0]->getHtmlBody());
        $this->assertStringContainsString('nouveauté(s) à voir', (string) $mine[0]->getSubject());
        $this->assertSame(0, $gate->heldCount($to));
    }

    public function testThePhoneFollowsTheLevelNoneNothingEssentialOnlyTheUrgentNormalOneADayAllThree(): void
    {
        \App\Tests\Fake\FakePushSender::reset();
        $admin = $this->user(['ROLE_ADMIN']);
        $id = (int) $admin->getId();
        $this->json('POST', '/api/notifications/appareils', ['token' => 'niveau-' . $this->tag, 'platform' => 'admin', 'locale' => 'fr'], $admin, 201);
        $service = static::getContainer()->get(GestionNotificationService::class);
        $push = fn (string $kind, string $when): ?string => $service->notify($id, $kind, ['%count%' => 2, '%amount%' => '10 $', '%date%' => '1 déc.', '%place%' => 'Lyon'], ['screen' => 'stock.low', 'params' => []], [], $this->moment($when));
        $count = fn (): int => (int) $this->db()->fetchOne("SELECT COUNT(*) FROM notification_log WHERE channel = 'push' AND user_id = ?", [$id]);

        $this->siteLevel(NotificationLevel::NONE);
        $this->assertNull($push(GestionNotificationService::CASH_CRITICAL, '2031-12-01 10:00'));
        $this->assertNull($push(GestionNotificationService::LOW_STOCK, '2031-12-01 10:00'));
        $this->assertSame(0, $count());

        $this->siteLevel(NotificationLevel::ESSENTIAL);
        $this->assertNull($push(GestionNotificationService::LOW_STOCK, '2031-12-02 10:00'), 'au niveau « Essentiel », le stock bas ne part pas sur le téléphone');
        $this->assertSame('sent', $push(GestionNotificationService::CASH_CRITICAL, '2031-12-02 10:00'), 'la trésorerie critique est urgente');
        $this->assertNull($push(GestionNotificationService::CASH_CRITICAL, '2031-12-02 11:00'), 'une seule par jour');

        $this->siteLevel(NotificationLevel::NORMAL);
        $this->assertSame(NotificationGate::STATUS_SENT, $this->gate()->deliver(Catalog::ORDER_TO_SHIP, (string) $admin->getEmail(), 'Courriel du jour', [], fn (): bool => true, $this->moment('2031-12-03 09:00')));
        $this->assertSame('sent', $push(GestionNotificationService::LOW_STOCK, '2031-12-03 10:00'), 'un courriel parti le même jour ne prend pas la place de la notification du téléphone');
        $this->assertNull($push(GestionNotificationService::TRIP_BUDGET, '2031-12-03 11:00'), 'au niveau « Normal », une notification par jour');

        $this->siteLevel(NotificationLevel::ALL);
        foreach ([GestionNotificationService::LOW_STOCK, GestionNotificationService::TRIP_BUDGET, GestionNotificationService::DUPLICATE_INVOICE] as $kind) {
            $this->assertSame('sent', $push($kind, '2031-12-04 10:00'), $kind);
        }
        $this->assertNull($push(GestionNotificationService::LOW_STOCK, '2031-12-04 12:00'), 'au niveau « Tout », au plus trois par jour');
        $this->assertSame('sent', $push(GestionNotificationService::CASH_CRITICAL, '2031-12-04 13:00'), 'l\'urgent passe au-dessus du plafond');
        $this->assertSame(6, $count());

        // Le niveau du compte passe avant celui du site
        $this->db()->executeStatement("UPDATE \"user\" SET notification_level = 'none' WHERE id = ?", [$id]);
        $this->assertNull($push(GestionNotificationService::CASH_CRITICAL, '2031-12-05 10:00'));
        $this->db()->executeStatement("DELETE FROM notification_log WHERE channel = 'push' AND user_id = ?", [$id]);
    }

    public function testEntitlementEmailsFollowTheLevelOfTheSiteTheyAreAbout(): void
    {
        $handler = static::getContainer()->get(\App\MessageHandler\EntitlementChangedHandler::class);
        $this->assertSame(
            ['platform_feature_request', 'entitlement_purchase', 'entitlement_purchase', Catalog::ENTITLEMENT_FOLLOWUP, Catalog::ENTITLEMENT_FOLLOWUP, Catalog::ENTITLEMENT_FOLLOWUP, Catalog::ENTITLEMENT_URGENT, Catalog::ENTITLEMENT_URGENT, Catalog::ENTITLEMENT_URGENT, Catalog::ENTITLEMENT_URGENT, Catalog::ENTITLEMENT_FOLLOWUP],
            [$handler::kind('request_new', []), $handler::kind('granted', ['source' => 'purchase']), $handler::kind('granted', ['source' => 'subscription']), $handler::kind('granted', ['source' => 'manual']),
                $handler::kind('trial_started', ['source' => 'trial']), $handler::kind('expiring_7', []), $handler::kind('expiring_1', []), $handler::kind('expired', []), $handler::kind('grace', []), $handler::kind('suspended', []), $handler::kind('resumed', [])]
        );
        $contact = $this->address('droits');
        $previous = $this->db()->fetchOne('SELECT email FROM entreprise ORDER BY id LIMIT 1');
        $this->db()->executeStatement('UPDATE entreprise SET email = ?', [$contact]);
        static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        try {
            $this->provider(); // le site courant est le site concerné : la décision d'envoi lit ses niveaux
            $message = fn (string $event, array $payload = []) => new \App\Message\EntitlementChangedMessage(MV_TEST_TENANT_CODE, 'module.support', $event, 1, $payload);

            // Niveau « Aucune » : ni suivi ni urgent ; l'achat, indispensable, part
            $this->siteLevel(NotificationLevel::NONE);
            $before = count($this->getMailerMessages());
            $handler($message('trial_started', ['source' => 'trial']));
            $handler($message('suspended'));
            $this->assertCount($before, $this->getMailerMessages());
            $handler($message('granted', ['source' => 'purchase']));
            $this->assertCount($before + 1, $this->getMailerMessages());
            $this->assertSame(0, $this->rows($contact), 'un indispensable n\'est ni compté ni écrit');

            // Niveau « Essentiel » : le suivi est retenu pour le résumé de la semaine, l'urgent part
            $this->siteLevel(NotificationLevel::ESSENTIAL);
            $before = count($this->getMailerMessages());
            $handler($message('trial_started', ['source' => 'trial']));
            $this->assertCount($before, $this->getMailerMessages());
            $this->assertSame(['weekly'], $this->reasons($contact));
            $handler($message('suspended'));
            $this->assertCount($before + 1, $this->getMailerMessages());
            $this->assertSame(['held' => 1, 'sent' => 1], $this->statuses($contact));
            $this->assertSame(['entitlement_followup', 'entitlement_urgent'], $this->db()->fetchFirstColumn('SELECT kind FROM notification_log WHERE recipient = ? ORDER BY id', [$contact]));

            // Un AUTRE site que le site courant. Hors d'un worker : la connexion n'est pas basculée, le courriel part comme avant les
            // niveaux et rien n'est écrit. Dans un worker : la décision du site concerné s'applique (ici « Essentiel » : retenu chez lui)
            $other = static::getContainer()->get(\App\Services\Platform\TenantDatabaseConnector::class)->pdo(MV_TEST_TENANT2_DB);
            $otherBefore = [$other->query('SELECT email FROM entreprise ORDER BY id LIMIT 1')->fetchColumn(), $other->query('SELECT notification_level FROM entreprise ORDER BY id LIMIT 1')->fetchColumn()];
            $far = $this->address('autre-site');
            $other->prepare("UPDATE entreprise SET email = ?, notification_level = 'essential'")->execute([$far]);
            $worker = static::getContainer()->get(\App\Services\Messenger\WorkerContext::class);
            $elsewhere = fn (string $event) => new \App\Message\EntitlementChangedMessage(MV_TEST_TENANT2_CODE, 'module.support', $event, 1, ['source' => 'trial']);
            try {
                $before = count($this->getMailerMessages());
                $handler($elsewhere('trial_started'));
                $this->assertCount($before + 1, $this->getMailerMessages(), 'hors d\'un worker : envoyé comme avant les niveaux');
                $this->assertSame(0, (int) $other->query("SELECT COUNT(*) FROM notification_log WHERE recipient = " . $other->quote($far))->fetchColumn());
                $worker->simulate(true);
                $handler($elsewhere('trial_started'));
                // lu tout de suite, sans passer par les aides du test (elles se replacent elles-mêmes sur le site de test)
                $back = [static::getContainer()->get(TenantConnectionManager::class)->getCurrentTenantCode(),
                    static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager()->getConnection()->fetchOne('SELECT current_database()')];
                $this->assertCount($before + 1, $this->getMailerMessages(), 'dans un worker : retenu par le niveau du site concerné');
                $this->assertSame(['held'], $other->query("SELECT status FROM notification_log WHERE recipient = " . $other->quote($far))->fetchAll(\PDO::FETCH_COLUMN));
                $this->assertSame(0, $this->rows($far), 'rien n\'est écrit dans la base du site courant');
                $this->assertSame([MV_TEST_TENANT_CODE, MV_TEST_TENANT_DB], $back, 'le gestionnaire revient au site d\'avant');
            } finally {
                $worker->simulate(false);
                $other->prepare('UPDATE entreprise SET email = ?, notification_level = ?')->execute($otherBefore);
                $other->prepare("DELETE FROM notification_log WHERE recipient = ?")->execute([$far]);
                $this->provider();
            }
        } finally {
            $this->db()->executeStatement('UPDATE entreprise SET email = ?', [$previous]);
            static::getContainer()->get(TenantCacheService::class)->invalidateTenant();
        }
    }

    /** La décision d'envoi avec les vraies valeurs de la production (plafond de 3, rien avant 8 h du magasin) */
    private function gate(): NotificationGate
    {
        $c = static::getContainer();

        return new NotificationGate($c->get(TenantEntityManagerProvider::class), $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class));
    }

    /** Le résumé de la semaine monté sur la décision d'envoi aux vraies valeurs (8 h du magasin) */
    private function weekly(NotificationGate $gate): \App\Services\Notification\NotificationWeeklyService
    {
        $c = static::getContainer();

        return new \App\Services\Notification\NotificationWeeklyService($c->get(TenantEntityManagerProvider::class), $gate, $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class),
            $c->get(\App\Services\Platform\SiteReadinessService::class), $c->get(\App\Services\OrderService\OrderDeskService::class), $c->get(\App\Services\Platform\SiteControlsService::class),
            $c->get(\App\Services\LandingAiService\LandingAiQuotaService::class), $c->get(EmailSenderService::class), $c->get(EmailLocale::class), $c->get(TranslatorInterface::class),
            $c->get(TenantConnectionManager::class), $c->get(TenantPublicHostResolver::class), new NullLogger());
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
