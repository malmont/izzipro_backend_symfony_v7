<?php

namespace App\Tests\Functional\LandingPage;

use App\Command\LandingAiCompareCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:landingpage-ai:compare : fichier de comparaison au format du banc du frontend, à partir de rapports d'évaluation.
 */
class LandingAiCompareCommandTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/landing-ai-compare-' . bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testBuildsOneEntryPerCaseWithOneKeyPerVariant(): void
    {
        $composition = ['schemaVersion' => 2, 'blocks' => [['id' => 'a', 'type' => 'title'], ['id' => 'b', 'type' => 'text']]];
        $case = fn (string $id, string $mode, int $blocks, int $attempts) => [
            'id' => $id, 'mode' => $mode, 'componentKey' => 'Contact', 'prompt' => 'Demande ' . $id, 'status' => 'ok', 'dataType' => null,
            'summary' => "Résumé $id", 'warnings' => [], 'usage' => ['attempts' => $attempts, 'outputTokens' => 100 * $blocks, 'durationMs' => 1000],
            'composition' => ['schemaVersion' => 2, 'blocks' => array_slice($composition['blocks'], 0, $blocks)],
        ];
        $report = fn (array $cases) => ['tenant' => 'mvtest', 'models' => ['edit' => 'claude-sonnet-5', 'page' => 'claude-opus-5-5'], 'tuning' => ['effort' => []], 'cases' => $cases];
        file_put_contents($this->dir . '/avant.json', json_encode($report([$case('C6', 'create', 2, 1), $case('C7', 'create', 1, 2), ['id' => 'C8', 'mode' => 'create', 'status' => 'skipped']])));
        file_put_contents($this->dir . '/apres.json', json_encode($report([$case('C6', 'create', 1, 1), ['id' => 'C7', 'mode' => 'create', 'status' => 'failed', 'usage' => []]])));

        $command = new LandingAiCompareCommand($this->dir);
        $tester = new CommandTester($command);
        $tester->execute(['sujet' => 'test-c6', 'variantes' => ['avant=' . $this->dir . '/avant.json', 'apres=apres.json'], '--objet' => 'Essai']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $file = $this->dir . '/comparaison-test-c6-' . date('Ymd') . '.json';
        $this->assertFileExists($file);
        $comparison = json_decode(file_get_contents($file), true);
        $this->assertSame('Essai', $comparison['objet']);
        $this->assertSame(['avant' => 'avant.json', 'apres' => 'apres.json'], $comparison['rapports']);
        $this->assertSame(['C6', 'C7'], array_column($comparison['cas'], 'id'), 'cas ignorés exclus, ordre du premier rapport');
        $c6 = $comparison['cas'][0];
        $this->assertSame(['id', 'componentKey', 'prompt', 'avant', 'apres'], array_keys($c6));
        $this->assertSame(['dataType', 'summary', 'warnings', 'blocs', 'essais', 'jetonsSortie', 'dureeMs', 'composition'], array_keys($c6['avant']));
        $this->assertSame([2, 1], [$c6['avant']['blocs'], $c6['apres']['blocs']]);
        $this->assertNull($comparison['cas'][1]['apres'], 'cas en échec dans une variante : null');
    }

    public function testRefusesABadSubjectOrASingleVariant(): void
    {
        $tester = new CommandTester(new LandingAiCompareCommand($this->dir));
        $tester->execute(['sujet' => '../x', 'variantes' => ['a=b.json', 'c=d.json']]);
        $this->assertNotSame(0, $tester->getStatusCode());
        $tester->execute(['sujet' => 'ok', 'variantes' => ['a=b.json']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }
}
