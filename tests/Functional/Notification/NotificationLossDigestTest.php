<?php

namespace App\Tests\Functional\Notification;

use App\LossPrevention\Services\LossDigestService;
use App\Services\CaisseService\StoreClock;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Notification\NotificationGate;
use App\Services\Notification\NotificationLevelResolver;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\LossPrevention\LossPreventionTestCase;

/**
 * Le résumé du jour de la prévention des pertes suit le niveau de chaque administrateur (lot NOTIF-1, `docs/notifications-courriels.md`).
 * Le VRAI point d'appel (`LossDigestService::sendIfDue`), à des niveaux autres que « Tout », avec la décision d'envoi aux valeurs de la
 * production (plafond de 3, rien avant 8 h) : ce que les réglages des tests (`config/services_test.yaml`) cacheraient sinon.
 */
class NotificationLossDigestTest extends LossPreventionTestCase
{
    protected function tearDown(): void
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db->executeStatement("UPDATE entreprise SET notification_level = 'all'");
        $this->db->executeStatement('UPDATE "user" SET notification_level = NULL WHERE notification_level IS NOT NULL');
        $this->db->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        parent::tearDown();
    }

    public function testTheDailyDigestIsHeldSentOrDroppedByTheLevelOfEachAdministrator(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $this->db->executeStatement("UPDATE entreprise SET locale = 'fr'");
        $this->db->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        $this->db->executeStatement('UPDATE "user" SET notification_level = NULL WHERE notification_level IS NOT NULL'); // des comptes d'essai d'autres tests restent dans la base
        $this->provider->getEntityManager()->clear();
        $this->flags(true, true);
        $this->place('CA', 'QC');
        $this->json($this->admin('PUT', self::BASE . '/reglages', ['staffNoticeConfirmed' => true]));
        $this->openSession();
        $this->closeWith(-1300, 'Niveaux');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $mine = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->adminId]);
        $other = $this->user(['ROLE_ADMIN'], 'Second');
        $day = fn (): ?string => ($v = $this->db->fetchOne('SELECT last_digest_day FROM loss_setting')) === null || $v === false ? null : (string) $v;
        $reset = function (): void {
            $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL, last_digest_finding_id = NULL');
            $this->db->executeStatement("DELETE FROM notification_log WHERE channel = 'email'");
        };
        $rows = fn (string $email): array => $this->db->fetchAllAssociative("SELECT kind, status, topic, subject::text AS subject, action::text AS action FROM notification_log WHERE channel = 'email' AND recipient = ? ORDER BY id", [mb_strtolower($email)]);
        $tz = static::getContainer()->get(StoreClock::class)->timezone();
        $at = fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($this->today() . ' ' . $time, $tz); // une heure du MAGASIN, aujourd'hui
        // Un envoi commun simulé : il note ses destinataires, et échoue pour ceux de $failing
        $sentTo = [];
        $failing = [];
        $mailer = $this->createMock(EmailSenderService::class);
        $mailer->method('subject')->willReturn('Prévention des pertes : 1 nouveau constat');
        $mailer->method('sendTemplatedEmail')->willReturnCallback(function (string $to) use (&$sentTo, &$failing): bool {
            if (in_array($to, $failing, true)) {
                return false;
            }
            $sentTo[] = $to;

            return true;
        });
        $brokenGate = false;
        $digest = function () use ($mailer, &$brokenGate): LossDigestService { // les services du noyau EN COURS ; la décision d'envoi aux valeurs de la production
            $c = static::getContainer();
            $c->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
            $gate = new NotificationGate($c->get(TenantEntityManagerProvider::class), $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class));
            if ($brokenGate) { // une décision d'envoi en panne : elle lève à chaque appel
                $gate = new class($c->get(TenantEntityManagerProvider::class), $c->get(NotificationLevelResolver::class), $c->get(StoreClock::class)) extends NotificationGate {
                    public function decide(string $kind, string $email, ?\DateTimeImmutable $now = null, bool $ignoreHours = false): array
                    {
                        throw new \RuntimeException('base illisible');
                    }
                };
            }

            return new LossDigestService($c->get(TenantEntityManagerProvider::class), $c->get(\App\LossPrevention\Services\LossSettingsService::class),
                $c->get(\App\LossPrevention\Services\LossSite::class), $c->get(\App\Gestion\Services\GestionNotificationService::class), $mailer,
                $c->get(\App\Services\EmailConfigurationService\EmailLocale::class), $c->get('translator'), new \Psr\Log\NullLogger(),
                $c->get(\App\Services\TenantConnectionManager::class), $c->get(\App\Services\TenantPublicHostResolver::class), $c->get(\App\Services\Site\AdminSpaceUrl::class), $gate);
        };
        $site = fn (?string $level) => $this->db->executeStatement('UPDATE entreprise SET notification_level = ?', [$level]);
        $own = fn (int $id, ?string $level) => $this->db->executeStatement('UPDATE "user" SET notification_level = ? WHERE id = ?', [$level, $id]);

        // Niveau « Aucune » pour tout le site : personne à servir ; ni courriel, ni ligne, et le jour n'est PAS posé (rien n'est annoncé)
        $reset();
        $site('none');
        $this->assertSame(['status' => 'no_recipient', 'sent' => 0], $digest()->sendIfDue($at('09:00')));
        $this->assertSame([[], null, []], [$sentTo, $day(), $rows($mine)]);

        // Niveau « Essentiel » (celui d'un site d'essai) : aucun courriel ne part ; le résumé est RETENU pour chaque administrateur, lisible
        // dans l'application, et le jour est posé : le constat est annoncé une fois, il ne sera pas redit demain
        $site('essential');
        $result = $digest()->sendIfDue($at('09:00'));
        $this->assertSame(['held', 0], [$result['status'], $result['sent']]);
        $this->assertGreaterThanOrEqual(2, $result['held'], 'le nombre de destinataires retenus est rendu (au moins les deux administrateurs de ce test)');
        $this->assertSame((int) $this->db->fetchOne("SELECT COUNT(*) FROM notification_log WHERE channel = 'email' AND kind = 'loss_digest' AND status = 'held'"), $result['held']);
        $this->assertSame([[], $this->today()], [$sentTo, $day()]);
        $held = $rows($mine);
        $this->assertCount(1, $held);
        $this->assertSame(['loss_digest', 'held', 'loss', ['reason' => 'weekly'], ['screen' => 'pertes.constats', 'params' => ['isNew' => true]]],
            [$held[0]['kind'], $held[0]['status'], $held[0]['topic'], json_decode($held[0]['subject'], true), json_decode($held[0]['action'], true)]);
        $this->assertSame(['status' => 'already_sent', 'sent' => 0], $digest()->sendIfDue($at('10:00')), 'un seul passage par jour, retenu ou parti');
        $this->assertCount(1, $rows($mine), 'jamais deux lignes pour le même résumé');

        // Les niveaux se mêlent : un administrateur au niveau « Tout » reçoit le courriel, l'autre (comme le site, Essentiel) voit le sien retenu
        $reset();
        $own($this->adminId, 'all');
        $this->assertSame('sent', $digest()->sendIfDue($at('09:00'))['status']);
        $this->assertSame([$mine], $sentTo);
        $this->assertSame(['sent', 'held'], [$rows($mine)[0]['status'], $rows((string) $other->getEmail())[0]['status']]);

        // Avant 8 h du magasin, le passage ordinaire ne fait rien ; lancé à la main (« maintenant »), le courriel PART, sans être retenu par
        // la règle des 8 h de la décision d'envoi
        $reset();
        $sentTo = [];
        $this->assertSame('too_early', $digest()->sendIfDue($at('07:00'))['status']);
        $this->assertSame('sent', $digest()->sendIfDue($at('07:00'), true)['status']);
        $this->assertSame([[$mine], 'sent'], [$sentTo, $rows($mine)[0]['status']]);

        // Un destinataire dont l'envoi échoue alors qu'un autre est retenu : le jour reste posé, donc il ne serait jamais réessayé ; son
        // courriel est retenu lui aussi (il sortira par le courriel des retenus), jamais perdu
        $reset();
        $sentTo = [];
        $failing = [$mine];
        $this->assertSame('held', $digest()->sendIfDue($at('09:00'))['status']);
        $this->assertSame([[], $this->today()], [$sentTo, $day()]);
        $this->assertSame(['held', 'send_failed'], [$rows($mine)[0]['status'], json_decode($rows($mine)[0]['subject'], true)['reason']]);
        // La décision d'envoi en panne : le résumé PART quand même, à tous, comme avant les niveaux (sans ce repli il ne partirait plus
        // du tout tant que la panne dure) ; le jour est posé
        $reset();
        $sentTo = [];
        $failing = [];
        $site('none');
        $brokenGate = true;
        $this->assertSame('sent', $digest()->sendIfDue($at('09:00'))['status']);
        $this->assertContains($mine, $sentTo);
        $this->assertSame($this->today(), $day());
        $brokenGate = false;

        // Tous en échec (aucun retenu) : comme avant le lot, le jour est rendu et tout sera rejoué
        $reset();
        $site('all');
        $own($this->adminId, null);
        $failing = array_map('strval', $this->db->fetchFirstColumn('SELECT email FROM "user"'));
        $this->assertSame(['status' => 'not_delivered', 'sent' => 0], $digest()->sendIfDue($at('09:00')));
        $this->assertSame([null, []], [$day(), $rows($mine)]);
    }
}
