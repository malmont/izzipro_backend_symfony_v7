<?php

namespace App\Tests\Functional\LandingPage;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\Eval\LandingAiTasteCheck;
use PHPUnit\Framework\TestCase;

/** Défauts de goût courants (V10 du jeu d'essai de l'assistant IA) */
final class LandingAiTasteCheckTest extends TestCase
{
    public function testCardInsideACardIsReported(): void
    {
        $issues = $this->issues(['background' => '#060A18'], [
            ['id' => 'carte', 'type' => 'container', 'parentId' => null, 'w' => 100, 'background' => '#0B1530', 'padding' => 24, 'layout' => 'stack'],
            ['id' => 'sous-carte', 'type' => 'container', 'parentId' => 'carte', 'w' => 100, 'background' => '#060A18', 'padding' => 16, 'layout' => 'stack'],
            ['id' => 'cadre', 'type' => 'container', 'parentId' => 'carte', 'w' => 100, 'background' => 'transparent', 'padding' => 16, 'layout' => 'stack'],
        ]);

        $this->assertSame(['carte sous-carte dans la carte carte (une seule carte autour d\'un contenu)'], $issues, 'un cadre transparent n\'est pas une carte');
    }

    public function testMissingWidthsAndTooManyFontsAreReported(): void
    {
        $issues = $this->issues(['background' => '#ffffff', 'rootLayout' => 'stack'], [
            ['id' => 'titre', 'type' => 'title', 'parentId' => null, 'fontFamily' => 'Outfit', 'color' => '#111111'],
            ['id' => 'texte', 'type' => 'text', 'parentId' => null, 'w' => 60, 'fontFamily' => 'Inter', 'color' => '#111111'],
            ['id' => 'etiquette', 'type' => 'text', 'parentId' => null, 'w' => 100, 'fontFamily' => 'Michroma', 'color' => '#111111'],
            ['id' => 'bouton', 'type' => 'button', 'parentId' => null, 'fitContent' => true, 'fontFamily' => 'Montserrat', 'background' => '#111111', 'color' => '#ffffff'],
        ]);

        $this->assertCount(2, $issues, implode(' | ', $issues));
        $this->assertStringContainsString('bloc titre sans largeur (w) dans une pile', $issues[0]);
        $this->assertStringContainsString('4 polices dans la section', $issues[1]);
    }

    public function testMidGreyTextOnAColouredBackgroundIsReported(): void
    {
        $issues = $this->issues(['background' => '#1E3F8A'], [
            ['id' => 'cadre', 'type' => 'container', 'parentId' => null, 'w' => 100, 'background' => 'transparent', 'layout' => 'stack'],
            ['id' => 'terne', 'type' => 'text', 'parentId' => 'cadre', 'w' => 100, 'color' => '#888888'],
            ['id' => 'clair', 'type' => 'text', 'parentId' => 'cadre', 'w' => 100, 'color' => '#A9B3CC'],
            ['id' => 'carte', 'type' => 'container', 'parentId' => null, 'w' => 100, 'background' => '#ffffff', 'padding' => 16, 'layout' => 'stack'],
            ['id' => 'sur-blanc', 'type' => 'text', 'parentId' => 'carte', 'w' => 100, 'color' => '#888888'],
        ]);

        $this->assertSame(['terne : texte gris moyen (#888888) sur un fond coloré (#1E3F8A)'], $issues, 'le gris bleuté clair et le gris sur blanc passent');
    }

    public function testMoreThanTwoSolidButtonColoursAreReported(): void
    {
        $issues = $this->issues(['background' => '#ffffff'], [
            ['id' => 'a', 'type' => 'button', 'parentId' => null, 'fitContent' => true, 'background' => '#FFA552', 'color' => '#000000'],
            ['id' => 'b', 'type' => 'button', 'parentId' => null, 'fitContent' => true, 'background' => '#5AA9FF', 'color' => '#000000'],
            ['id' => 'c', 'type' => 'button', 'parentId' => null, 'fitContent' => true, 'background' => '#1E3F8A', 'color' => '#ffffff'],
            ['id' => 'd', 'type' => 'button', 'parentId' => null, 'fitContent' => true, 'background' => 'transparent', 'color' => '#000000'],
        ]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('3 couleurs de boutons pleins', $issues[0]);
    }

    public function testACleanSectionHasNoIssue(): void
    {
        $this->assertSame([], $this->issues(['background' => '#060A18', 'rootLayout' => 'stack'], [
            ['id' => 'grille', 'type' => 'container', 'parentId' => null, 'w' => 100, 'background' => 'transparent', 'layout' => 'grid', 'columns' => 3],
            ['id' => 'carte', 'type' => 'container', 'parentId' => 'grille', 'w' => 100, 'background' => '#0B1530', 'padding' => 24, 'layout' => 'stack'],
            ['id' => 'titre', 'type' => 'title', 'parentId' => 'carte', 'w' => 100, 'fontFamily' => 'Outfit', 'color' => '#F4F1EA'],
            ['id' => 'texte', 'type' => 'text', 'parentId' => 'carte', 'w' => 100, 'fontFamily' => 'Inter', 'color' => '#A9B3CC'],
            ['id' => 'bouton', 'type' => 'button', 'parentId' => 'carte', 'fitContent' => true, 'background' => '#FFA552', 'color' => '#060A18'],
        ]));
    }

    /** @return list<string> */
    private function issues(array $section, array $blocks): array
    {
        $composition = json_decode(json_encode(['schemaVersion' => 2] + $section + ['blocks' => $blocks]), false);

        return (new LandingAiTasteCheck(new CompositionInspector()))->issues($composition);
    }
}
