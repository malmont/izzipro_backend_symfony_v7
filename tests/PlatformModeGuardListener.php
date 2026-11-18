<?php

namespace App\Tests;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestSuite;

/**
 * Garde-fou demandé par le chef (lot G-2, 18/11/2026) : aucun essai ne laisse la plateforme en mode « live ». Le mode est une ligne de la
 * base maître d'essai (`platform_settings`, clé `mode`), partagée par toute la suite : un essai qui le laisse en « live » change les droits
 * de module de ceux qui le suivent (incident du train 111 : un essai des pertes échouait seulement après un essai du groupe).
 *
 * À la fin de chaque essai, le mode est relu ; s'il vaut « live », il est remis à « demo » et l'essai est nommé. Un essai qui laisse le mode
 * en « live » ÉCHOUE : il doit le remettre lui-même (`tearDown`), ou ne pas le changer pour toute la plateforme (le lecteur ou le service
 * reçoit alors une plateforme simulée, comme `GroupAllShopsTest`). La mémoire du mode (60 s) est vidée par `PlatformModeService::setMode`
 * de l'essai suivant qui le pose ; la remise faite ici ne passe que par la base.
 */
final class PlatformModeGuardListener implements TestListener
{
    use TestListenerDefaultImplementation;

    private ?\PDO $pdo = null;

    public function endTest(Test $test, float $time): void
    {
        if (!$test instanceof TestCase || !defined('MV_TEST_MASTER_DB')) {
            return;
        }
        try {
            $pdo = $this->pdo();
            if ($pdo === null || $pdo->query("SELECT value FROM platform_settings WHERE key = 'mode'")->fetchColumn() !== 'live') {
                return;
            }
            $pdo->exec("UPDATE platform_settings SET value = 'demo', updated_by = 'garde-fou des essais' WHERE key = 'mode'");
        } catch (\Throwable) {
            return; // base maître d'essai illisible : rien à remettre
        }
        if (($result = $test->getTestResultObject()) !== null) {
            $result->addFailure($test, new \PHPUnit\Framework\AssertionFailedError(
                'Cet essai laisse la plateforme en mode « live » : remettez « demo » dans tearDown (setMode), ou simulez la plateforme pour le seul service essayé. Le mode a été remis à « demo ».'), $time);
        }
    }

    private function pdo(): ?\PDO
    {
        if ($this->pdo === null) {
            $u = parse_url((string) (getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? '')));
            if (!is_array($u) || !isset($u['host'])) {
                return null;
            }
            $this->pdo = new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $u['host'], $u['port'] ?? 5432, MV_TEST_MASTER_DB), urldecode($u['user'] ?? ''), urldecode($u['pass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }

        return $this->pdo;
    }
}
