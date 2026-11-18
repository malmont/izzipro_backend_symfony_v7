<?php

namespace App\Tests\Functional\Notification;

use App\Services\Notification\NotificationCatalog as Catalog;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du lot NOTIF-1 (15/11/2026, `docs/notifications-courriels.md`) : aucun courriel du serveur n'échappe au classement.
 * Tout fichier de `src/` qui envoie un courriel doit être inscrit dans `NotificationCatalog::SENDERS` avec les types qu'il envoie (ou
 * dans `TRANSPORT` s'il tient l'envoi lui-même), et un type urgent ou de suivi doit passer par `NotificationGate`.
 *
 * Un nouvel envoi fait échouer ce test : classer le courriel dans `KINDS`, inscrire le fichier dans `SENDERS`, et, s'il est urgent ou
 * de suivi, l'envoyer par `NotificationGate::deliver()`.
 */
class NotificationCatalogGuardTest extends TestCase
{
    /** Ce qui trahit un envoi de courriel dans un fichier */
    private const SEND = '/->(sendTemplatedEmail|sendCustomerEmail|sendPlatformEmail|sendPlatformEmailNow)\(|MailerInterface|TenantMailerFactory|->createMailer\(|createPlatformMailer\(/';

    /**
     * Fichiers qui envoient un courriel urgent ou de suivi SANS passer encore par la décision d'envoi, avec la raison. Toute entrée est une
     * dette écrite : elle se vide au lot indiqué.
     */
    /** Le service qui sort les courriels retenus : il applique lui-même le plafond et la règle du sujet (`allowed`) */
    private const RELEASE = ['src/Services/Notification/NotificationSummaryService.php', 'src/Services/Notification/NotificationWeeklyService.php'];

    private const NOT_YET_GATED = [];

    public function testEveryFileThatSendsAnEmailIsClassified(): void
    {
        $root = dirname(__DIR__, 3) . '/';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'NotificationCatalog.php') { // le catalogue nomme les fichiers d'envoi, il n'envoie rien
                continue;
            }
            $code = preg_replace('#^\s*(//|\*|/\*).*$#m', '', (string) file_get_contents($file->getPathname())); // les commentaires peuvent en parler
            if (preg_match(self::SEND, (string) $code)) {
                $found[] = substr($file->getPathname(), strlen($root));
            }
        }
        sort($found);
        $known = array_merge(array_keys(Catalog::SENDERS), Catalog::TRANSPORT);
        $this->assertSame([], array_values(array_diff($found, $known)), 'fichier qui envoie un courriel sans être classé (NotificationCatalog::SENDERS)');
        $this->assertSame([], array_values(array_diff($known, $found)), 'fichier inscrit au catalogue qui n\'envoie plus de courriel : le retirer');
        $this->assertSame([], array_intersect(array_keys(Catalog::SENDERS), Catalog::TRANSPORT));
    }

    public function testEveryKindIsKnownAndAGovernedOneGoesThroughTheGate(): void
    {
        $root = dirname(__DIR__, 3) . '/';
        $families = [Catalog::INDISPENSABLE, Catalog::URGENT, Catalog::FOLLOWUP, Catalog::INTERNAL];
        foreach (Catalog::KINDS as $kind => [$family, $topic]) {
            $this->assertContains($family, $families, $kind);
            $this->assertTrue($topic === null || in_array($topic, Catalog::TOPICS, true), $kind);
            $this->assertTrue($family === Catalog::FOLLOWUP || $topic === null, "$kind : seul un courriel de suivi a un sujet");
        }
        $sent = [];
        $ungated = [];
        foreach (Catalog::SENDERS as $file => $kinds) {
            $this->assertNotSame([], $kinds, $file);
            foreach ($kinds as $kind) {
                $this->assertArrayHasKey($kind, Catalog::KINDS, "$file : type inconnu");
                $sent[$kind] = true;
            }
            $governed = array_intersect($kinds, Catalog::governed());
            // un APPEL à la décision d'envoi, pas seulement son nom dans un constructeur ; la sortie des retenus et le résumé de la semaine sont la décision elle-même
            if ($governed !== [] && !in_array($file, self::RELEASE, true) && !preg_match('/->(deliver|decide)\(/', (string) file_get_contents($root . $file))) {
                $ungated[] = $file;
            }
        }
        sort($ungated);
        $expected = array_keys(self::NOT_YET_GATED);
        sort($expected);
        $this->assertSame($expected, $ungated, 'un courriel urgent ou de suivi doit passer par NotificationGate (ou être une dette écrite dans NOT_YET_GATED)');
        $this->assertSame([], array_values(array_diff(array_keys(Catalog::KINDS), array_keys($sent))), 'type classé que personne n\'envoie : le retirer');
        // Les types gouvernés sont exactement ceux des familles urgent et suivi
        $expectedGoverned = array_keys(array_filter(Catalog::KINDS, fn (array $k) => in_array($k[0], [Catalog::URGENT, Catalog::FOLLOWUP], true)));
        $this->assertSame($expectedGoverned, Catalog::governed());
        $this->assertNotContains('password_reset', Catalog::governed());
        $this->assertSame([Catalog::FOLLOWUP, Catalog::TOPIC_ORDERS], [Catalog::family(Catalog::ORDER_TO_SHIP), Catalog::topic(Catalog::ORDER_TO_SHIP)]);
        $this->expectException(\InvalidArgumentException::class);
        Catalog::family('courriel_jamais_classe');
    }
}
