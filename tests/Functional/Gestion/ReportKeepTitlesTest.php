<?php

namespace App\Tests\Functional\Gestion;

use App\Gestion\Services\Report\ReportRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Rapports PDF de la gestion, retouche du 16/11/2026 : un titre ne reste jamais seul en bas de page. Le rendu marque les sections à
 * tenir ensemble (`keepOpen` sur la première, `keepClose` sur la dernière) ; le gabarit les enferme dans un cadre qui passe entier à
 * la page suivante. La pagination réelle a été vérifiée sur 31 documents (titre poussé vers le bas de la page) avant la livraison.
 */
class ReportKeepTitlesTest extends TestCase
{
    public function testATitleIsKeptWithTheFirstBlockThatFollowsItUnlessThatBlockIsTooLongToMoveAsOne(): void
    {
        $heading = fn (string $title): array => ['type' => 'heading', 'title' => $title, 'note' => ''];
        $figures = fn (int $rows): array => ['type' => 'figures', 'rows' => array_fill(0, $rows, [['label' => 'a', 'value' => '1', 'compared' => []]])];
        $table = fn (string $title, int $rows): array => ['type' => 'table', 'title' => $title, 'columns' => [], 'rows' => array_fill(0, $rows, []), 'more' => ''];
        $text = fn (int $length): array => ['type' => 'text', 'paragraphs' => [str_repeat('a', $length)]];
        $marks = static fn (array $sections): array => array_map(static fn (array $s): string => (($s['keepOpen'] ?? false) ? '[' : '') . $s['type'] . (($s['keepClose'] ?? false) ? ']' : ''), $sections);
        $keep = new \ReflectionMethod(ReportRenderer::class, 'keepTitles');
        $keep->setAccessible(true);
        $run = static fn (array $sections): array => $marks($keep->invoke(null, $sections));

        // un titre de partie, puis ses cartes : tenus ensemble ; deux titres de suite : les deux avec le bloc
        $this->assertSame(['[heading', 'figures]', 'text'], $run([$heading('Budget'), $figures(2), $text(10)]));
        $this->assertSame(['text', '[heading', 'heading', 'figures]'], $run([$text(10), $heading('Budget'), $heading('Automne 2026'), $figures(6)]));
        // un bloc qui porte son propre titre est tenu avec lui ; sans titre, rien à tenir
        $this->assertSame(['[table]', 'table', '[verdicts]', '[guide]'], $run([$table('Par poste', 25), $table('', 3), ['type' => 'verdicts', 'title' => 'Verdicts', 'groups' => [], 'items' => array_fill(0, 20, [])], ['type' => 'guide', 'title' => 'Guide', 'points' => array_fill(0, 5, [])]]));
        // un bloc trop long pour passer d'un seul tenant n'est pas enfermé (son titre reste tenu « au mieux »)
        $this->assertSame(['heading', 'figures'], $run([$heading('Budget'), $figures(7)]));
        $this->assertSame(['table', 'heading', 'table', 'verdicts', 'guide', 'heading', 'text'], $run([$table('Long', 26), $heading('Partie'), $table('Long', 26), ['type' => 'verdicts', 'title' => 'V', 'groups' => [], 'items' => array_fill(0, 21, [])],
            ['type' => 'guide', 'title' => 'G', 'points' => array_fill(0, 6, [])], $heading('Texte'), $text(1501)]));
        $this->assertSame(['[heading', 'text]', '[heading', 'lesson]'], $run([$heading('Avis'), $text(1500), $heading('Notion'), ['type' => 'lesson', 'term' => 't', 'definition' => 'd']]));
        // des titres en fin de document : rien à tenir avec eux ; un document vide ne casse rien
        $this->assertSame(['text', 'heading', 'heading'], $run([$text(5), $heading('Fin'), $heading('Fin')]));
        $this->assertSame([], $run([]));
    }

    public function testTheTemplateWrapsKeptSectionsInOneFrame(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/gestion/report/document.html.twig');
        $this->assertStringContainsString('{% if section.keepOpen|default(false) %}<table class="keep"><tr class="keeprow"><td class="keepcell">{% endif %}', $template);
        $this->assertStringContainsString('{% if section.keepClose|default(false) %}</td></tr></table>{% endif %}', $template);
        $this->assertMatchesRegularExpression('/table\.keep \{[^}]*page-break-inside: avoid;/', $template);
        $this->assertLessThan(strpos($template, "{% if section.type == 'heading' %}"), strpos($template, 'section.keepOpen'), 'le cadre s\'ouvre avant le titre');
        // les deux documents (réponse de la console, bilan de période ou d'année) appliquent la règle à leurs sections
        $renderer = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Gestion/Services/Report/ReportRenderer.php');
        $this->assertSame(2, substr_count($renderer, "'sections' => self::keepTitles("));
    }
}
