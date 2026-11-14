<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestSuite;

/**
 * Libère les connexions à PostgreSQL à la fin de chaque test (12/10/2026, élargi le 14/11/2026). PHPUnit garde chaque objet de test
 * jusqu'à la fin de la suite : tout ce qu'une propriété du test tient (navigateur, connexion, fournisseur de site, gestionnaire
 * d'entités, service) reste en mémoire avec ses connexions au site et à la base maître. Le serveur de base de données est celui de la
 * production (100 connexions) : une suite qui les épuise fait tomber les sites.
 *
 * - À la fin de chaque test, les propriétés déclarées par les classes de test (`App\Tests\…`) qui tiennent un objet ou un tableau sont
 *   vidées (une connexion est d'abord fermée), puis les cycles sont collectés. Un test terminé ne ressert pas : rien ne les relit.
 * - Coupe-circuit : si, après ce ménage, le serveur compte plus de `LIMIT` connexions, la suite s'arrête en échec avec un message
 *   clair, bien avant la limite du serveur. Le pic de la suite est affiché à la fin.
 */
final class ReleaseTestResourcesListener implements TestListener
{
    use TestListenerDefaultImplementation;

    /** Au-delà, la suite s'arrête : la production garde au moins 40 connexions sur les 100 du serveur */
    public const LIMIT = 60;

    private ?\PDO $pdo = null;
    private int $peak = 0;
    private string $peakTest = '';
    private int $depth = 0;

    public function startTestSuite(TestSuite $suite): void
    {
        ++$this->depth;
    }

    public function endTestSuite(TestSuite $suite): void
    {
        if (--$this->depth === 0 && $this->peak > 0) {
            fwrite(STDERR, sprintf("\nConnexions à la base pendant la suite : pic de %d (coupe-circuit à %d), après %s\n", $this->peak, self::LIMIT, $this->peakTest));
        }
    }

    public function endTest(Test $test, float $time): void
    {
        for ($class = new \ReflectionClass($test); $class !== false && str_starts_with($class->getName(), 'App\\Tests\\'); $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || !$property->isInitialized($test)) {
                    continue;
                }
                $value = $property->getValue($test);
                if ($value instanceof Connection) {
                    $value->close();
                } elseif (!is_object($value) && !is_array($value)) {
                    continue;
                }
                $name = $property->getName();
                \Closure::bind(function () use ($name): void { unset($this->{$name}); }, $test, $class->getName())();
            }
        }
        gc_collect_cycles();

        $open = $this->openConnections();
        if ($open > $this->peak) {
            $this->peak = $open;
            $this->peakTest = $test instanceof TestCase ? $test::class . '::' . $test->getName(false) : $test::class;
        }
        $limit = (int) (getenv('TEST_DB_CONNECTION_LIMIT') ?: self::LIMIT); // réglable pour essayer le coupe-circuit lui-même
        if ($open > $limit && $test instanceof TestCase && ($result = $test->getTestResultObject()) !== null) {
            $message = sprintf('SUITE ARRÊTÉE : %d connexions ouvertes sur le serveur de base de données après ce test (coupe-circuit à %d). Ce serveur est aussi '
                . 'celui de la production. Un test garde une connexion ouverte (propriété statique, service gardé hors du test) ou une autre suite tourne en même temps.', $open, $limit);
            $result->addError($test, new \RuntimeException($message), $time);
            $result->stop();
        }
    }

    /** Connexions de clients ouvertes sur le serveur (toutes bases, production comprise) ; 0 si le compte est impossible */
    private function openConnections(): int
    {
        try {
            if ($this->pdo === null) {
                $u = parse_url((string) (getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? '')));
                if (!is_array($u) || !isset($u['host'])) {
                    return 0;
                }
                $this->pdo = new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=postgres', $u['host'], $u['port'] ?? 5432), urldecode($u['user'] ?? ''), urldecode($u['pass'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            }

            return (int) $this->pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE backend_type = 'client backend'")->fetchColumn();
        } catch (\Throwable) {
            $this->pdo = null;

            return 0;
        }
    }
}
