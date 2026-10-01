<?php

namespace App\Tests\Functional\LandingPage;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\Eval\LandingAiContrastCheck;
use PHPUnit\Framework\TestCase;

/** Contraste des textes sur leur fond réel (V7 du jeu d'essai de l'assistant IA) */
final class LandingAiContrastCheckTest extends TestCase
{
    public function testContainerWithoutBackgroundCountsAsOpaqueWhite(): void
    {
        $issues = $this->issues(['background' => '#1B2A4A'], [
            ['id' => 'cadre', 'type' => 'container', 'parentId' => null],
            ['id' => 'titre', 'type' => 'title', 'parentId' => 'cadre', 'color' => '#ffffff', 'size' => 48],
        ]);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('titre : 1.0:1', $issues[0]);
    }

    public function testTransparentContainerShowsTheSectionBackground(): void
    {
        $this->assertSame([], $this->issues(['background' => '#1B2A4A'], [
            ['id' => 'cadre', 'type' => 'container', 'parentId' => null, 'background' => 'transparent'],
            ['id' => 'titre', 'type' => 'title', 'parentId' => 'cadre', 'color' => '#ffffff', 'size' => 48],
            ['id' => 'texte', 'type' => 'text', 'parentId' => 'cadre', 'color' => 'rgba(255, 255, 255, 0.85)', 'size' => 16],
        ]));
    }

    public function testLightColourOnALightBackgroundIsReported(): void
    {
        $issues = $this->issues(['background' => '#ffffff'], [
            ['id' => 'carte', 'type' => 'container', 'parentId' => null, 'background' => '#f5f5f5'],
            ['id' => 'prix', 'type' => 'text', 'parentId' => 'carte', 'color' => '#C9A227', 'size' => 16],
            ['id' => 'bouton', 'type' => 'button', 'parentId' => 'carte', 'color' => '#1B2A4A', 'background' => '#C9A227', 'size' => 16],
        ]);

        $this->assertCount(1, $issues, 'le bouton bleu nuit sur or est lisible');
        $this->assertStringContainsString('prix : 2.2:1 (#C9A227 sur #f5f5f5)', $issues[0]);
    }

    public function testLargeTextHasALowerThreshold(): void
    {
        // #767676 sur blanc : 4,54:1 ; #949494 : 3,03:1 (suffisant pour un grand titre seulement)
        $issues = $this->issues(['background' => '#ffffff'], [
            ['id' => 'grand', 'type' => 'title', 'parentId' => null, 'color' => '#949494', 'size' => 32],
            ['id' => 'petit', 'type' => 'text', 'parentId' => null, 'color' => '#949494', 'size' => 16],
            ['id' => 'limite', 'type' => 'text', 'parentId' => null, 'color' => '#767676', 'size' => 16],
        ]);

        $this->assertCount(1, $issues);
        $this->assertStringStartsWith('petit', $issues[0]);
    }

    public function testUnknownBackgroundsAreNotMeasured(): void
    {
        $blocks = [['id' => 'titre', 'type' => 'title', 'parentId' => null, 'color' => '#ffffff', 'size' => 48]];

        $this->assertSame([], $this->issues(['background' => '#ffffff', 'bgImage' => 'https://media.example.com/fond.jpg'], $blocks), 'image de fond');
        $this->assertSame([], $this->issues(['background' => '#ffffff', 'bindings' => ['background' => 'colorBackground']], $blocks), 'couleur liée à une donnée');
        $this->assertSame([], $this->issues(['background' => 'linear-gradient(90deg, #fff, #000)'], $blocks), 'dégradé');
    }

    /** @return list<string> */
    private function issues(array $section, array $blocks): array
    {
        $composition = json_decode(json_encode(['schemaVersion' => 2] + $section + ['blocks' => $blocks]), false);

        return (new LandingAiContrastCheck(new CompositionInspector()))->issues($composition);
    }
}
