<?php

namespace App\Tests\Functional\LossPrevention;

/**
 * Prévention des pertes, lot L1-b : ce que le module branche dans la gestion. La vignette d'accueil « N nouveaux constats, X en jeu » (deux
 * nombres, aucun nom, seulement si le droit du module est accordé, écartée puis revenue quand la liste change) ; les guides des cinq écrans,
 * en français et en anglais.
 */
class LossPreventionGestionLinksTest extends LossPreventionTestCase
{
    public function testHomeTileCountsNewFindingsWithoutAnyNameAndFollowsTheModuleRight(): void
    {
        $this->drain();
        $this->loginPos(['ROLE_USER_POS']);
        $tile = function (string $locale = 'fr'): ?array {
            $tiles = $this->json($this->call('GET', '/api/gestion/accueil/vignettes?locale=' . $locale, null, $this->adminToken));
            $found = array_values(array_filter($tiles, fn (array $t) => $t['id'] === 'loss-findings'));

            return $found[0] ?? null;
        };
        $before = $tile()['sources'][0]['value'] ?? 0;
        $this->openSession();
        $report = $this->closeWith(-1300, 'Vignette');
        $this->assertSame(1, $this->json($this->admin('POST', self::BASE . '/analyse'))['opened']);
        $id = (int) $this->db->fetchOne('SELECT id FROM loss_finding WHERE dedup_key = ?', ['CASH_DISCREPANCY:z_report:' . $report['id']]);

        $t = $tile();
        $this->assertNotNull($t, 'un constat nouveau : la vignette est là');
        $this->assertSame(['alert', 78, ['screen' => 'pertes.constats', 'params' => ['isNew' => true]], true, 'CAD'], [$t['kind'], $t['priority'], $t['action'], $t['dismissible'], $t['currency']]);
        $this->assertSame([$before + 1, 'count', 'money'], [$t['sources'][0]['value'], $t['sources'][0]['unit'], $t['sources'][1]['unit']]);
        $this->assertSame((int) $this->db->fetchOne("SELECT COALESCE(SUM(amount_at_stake), 0) FROM loss_finding WHERE seen_at IS NULL AND backfill = false AND status = 'open'"), $t['sources'][1]['value']);
        $this->assertMatchesRegularExpression($before === 0 ? '/^1 nouveau constat à vérifier, 13,00/u' : '/^\d+ nouveaux constats à vérifier, /u', $t['text']);
        $this->assertStringNotContainsString($this->mark, (string) json_encode($t), 'la vignette ne nomme personne');
        $this->assertMatchesRegularExpression('/new findings? to check/', $tile('en')['text']);
        $this->assertSame('Loss prevention', $tile('en')['title']);

        // Écartée : elle disparaît ; un constat de plus change la liste : elle revient
        $this->assertSame(204, $this->call('POST', '/api/gestion/accueil/vignettes/loss-findings/ecarter', [], $this->adminToken)->getStatusCode());
        $this->assertNull($tile());
        $this->openSession();
        $this->closeWith(-1400, 'Vignette, second');
        $this->json($this->admin('POST', self::BASE . '/analyse'));
        $this->assertSame($before + 2, $tile()['sources'][0]['value'], 'la liste a changé : la vignette revient');

        // Ouvrir la fiche d'un constat le retire du compte
        $this->json($this->admin('GET', self::BASE . '/constats/' . $id));
        $this->assertSame($before + 1, $tile()['sources'][0]['value']);

        // Sans le droit du module : pas de vignette
        $this->flags(true, false);
        $this->assertNull($tile(), 'module non accordé : la vignette n\'existe pas');
    }

    public function testDailyDigestEmailGoesOnceADayToAdministratorsWhoKeptItOnAndNamesNobody(): void
    {
        $this->drain();
        $cashier = $this->loginPos(['ROLE_USER_POS']);
        $this->openSession();
        $this->closeWith(-1300, 'Courriel du jour');
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        // Le courriel part dans la langue du site : français ici (le site de test, en Ontario, serait sinon en anglais)
        $this->db->executeStatement("UPDATE entreprise SET locale = 'fr'");
        $this->provider->getEntityManager()->clear();
        // Préférences de la gestion : le type existe, par courriel, disponible parce que le droit du module est accordé
        $this->flags(true, true);
        $prefs = array_column($this->json($this->call('GET', '/api/gestion/notifications/preferences?locale=fr', null, $this->adminToken))['kinds'], null, 'kind');
        $this->assertSame([true, true, 'email', 'Prévention des pertes : résumé du jour par courriel'], [$prefs['loss_digest']['enabled'], $prefs['loss_digest']['available'], $prefs['loss_digest']['channel'], $prefs['loss_digest']['label']]);
        $this->assertSame('push', $prefs['low_stock']['channel']);
        $adminEmail = (string) $this->db->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->adminId]);
        $pushLog = (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification_log');

        $run = function (): string {
            $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:pertes:analyser'));
            $tester->execute(['--tenant' => MV_TEST_TENANT_CODE]);
            $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

            return $tester->getDisplay();
        };
        $display = $run();
        $this->assertStringContainsString('courriel de résumé envoyé', $display);
        $mails = $this->getMailerMessages();
        $this->assertNotEmpty($mails);
        $to = array_merge(...array_map(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getTo()), $mails));
        $this->assertContains($adminEmail, $to, 'l\'administrateur du site le reçoit');
        $this->assertNotContains($cashier->getEmail(), $to, 'un caissier ne le reçoit pas');
        $mine = array_values(array_filter($mails, fn ($m) => $m->getTo()[0]->getAddress() === $adminEmail))[0];
        $this->assertMatchesRegularExpression('/^Prévention des pertes : \d+ nouveaux? constats? à vérifier$/u', (string) $mine->getSubject());
        $body = (string) $mine->getHtmlBody();
        $this->assertMatchesRegularExpression('/nouveaux? constats? attend/u', $body);
        $this->assertStringContainsString('Montant en jeu', $body);
        $this->assertStringContainsString('Ouvrez la section « Prévention des pertes »', html_entity_decode($body));
        $this->assertStringNotContainsString($this->mark, $body, 'le courriel ne nomme personne');
        $this->assertStringNotContainsString('Courriel du jour', $body, 'ni aucun motif saisi à la caisse');
        $this->assertDoesNotMatchRegularExpression('/href="https?:/i', preg_replace('/<img[^>]*>/i', '', $body), 'aucun lien dans ce lot');
        $this->assertSame($this->today(), (string) $this->db->fetchOne('SELECT last_digest_day FROM loss_setting'));
        $this->assertSame($pushLog, (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification_log'), 'rien dans le journal des notifications du téléphone');
        $sent = json_decode((string) $this->db->fetchOne("SELECT data FROM loss_journal WHERE type = 'DIGEST_SENT' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['day', 'count', 'amount', 'recipients'], array_keys($sent), 'au journal du module : des nombres, aucune adresse');

        // Les textes anglais existent (un site anglophone reçoit le courriel en anglais : la langue du site est lue par EmailLocale)
        $translator = static::getContainer()->get('translator');
        $this->assertSame('Loss prevention: 2 new findings to check', $translator->trans('loss_digest.subject', ['%count%' => 2], 'emails', 'en'));
        $this->assertSame('Loss prevention: 1 new finding to check', $translator->trans('loss_digest.subject', ['%count%' => 1], 'emails', 'en'));
        $this->assertSame('Prévention des pertes : 1 nouveau constat à vérifier', $translator->trans('loss_digest.subject', ['%count%' => 1], 'emails', 'fr'));

        // Le même jour : pas de second courriel, même avec un nouveau constat
        $this->openSession();
        $this->closeWith(-1500, 'Second constat du jour');
        $this->assertStringNotContainsString('courriel de résumé envoyé', $run());
        $this->assertEmailCount(0);

        // Un gérant qui coupe le type ne le reçoit plus (le lendemain : le jour est remis à zéro pour l'essai)
        $this->json($this->call('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['loss_digest' => false]], $this->adminToken));
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        $run();
        $this->assertNotContains($adminEmail, array_merge([], ...array_map(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getTo()), $this->getMailerMessages())));
        $this->json($this->call('PATCH', '/api/gestion/notifications/preferences', ['kinds' => ['loss_digest' => true]], $this->adminToken));

        // Sans le droit du module : la commande ne passe pas sur le site, aucun courriel ; le type n'est plus disponible dans les préférences
        $this->flags(true, false);
        $this->db->executeStatement('UPDATE loss_setting SET last_digest_day = NULL');
        $run();
        $this->assertEmailCount(0);
        $this->assertFalse(array_column($this->json($this->call('GET', '/api/gestion/notifications/preferences', null, $this->adminToken))['kinds'], 'available', 'kind')['loss_digest']);
    }

    public function testTheFiveScreensHaveTheirGuideInBothLanguages(): void
    {
        foreach (['pertes.home', 'pertes.constats', 'pertes.constat', 'pertes.regles', 'pertes.reglages'] as $code) {
            $fr = $this->json($this->call('GET', "/api/gestion/ecrans/$code/guide?locale=fr", null, $this->adminToken));
            $en = $this->json($this->call('GET', "/api/gestion/ecrans/$code/guide?locale=en", null, $this->adminToken));
            $this->assertSame([$code, $code], [$fr['screen'], $en['screen']]);
            $this->assertGreaterThan(40, mb_strlen($fr['purpose']), $code);
            $this->assertNotSame($fr['purpose'], $en['purpose'], "$code : le guide anglais n'est pas le texte français");
            $this->assertGreaterThanOrEqual(3, count($fr['points']), $code);
            $this->assertSame(count($fr['points']), count($en['points']));
            $this->assertNotSame($fr['title'], $en['title']);
            foreach ([$fr, $en] as $guide) {
                $this->assertStringNotContainsString('pertes_', (string) json_encode($guide), "$code : aucun texte en clé brute");
                $this->assertNotSame('', (string) $guide['next']);
            }
        }
        $this->assertSame('Prévention des pertes', $this->json($this->call('GET', '/api/gestion/ecrans/pertes.home/guide?locale=fr', null, $this->adminToken))['title']);
    }
}
