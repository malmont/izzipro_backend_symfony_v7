<?php

namespace App\Tests\Functional\LandingPage;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\Eval\LandingAiAlignCheck;
use App\Services\LandingAiService\Eval\LandingAiLayoutCheck;
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

    public function testButtonsAndBadgesAreMeasuredOnTheirOwnBackground(): void
    {
        $issues = $this->issues(['background' => '#1B2A4A'], [
            ['id' => 'sans-fond', 'type' => 'button', 'parentId' => null, 'color' => '#ffffff', 'size' => 16], // absent : blanc opaque
            ['id' => 'contour', 'type' => 'button', 'parentId' => null, 'color' => '#ffffff', 'background' => 'transparent', 'size' => 16],
            ['id' => 'badge', 'type' => 'badge', 'parentId' => null, 'color' => '#1B2A4A', 'size' => 14],
        ]);

        $this->assertCount(1, $issues, 'le bouton à contour montre la section ; le badge bleu nuit est sur son fond blanc par défaut');
        $this->assertStringContainsString('sans-fond : 1.0:1', $issues[0]);
    }

    public function testBackgroundOfATitleOrATextIsNeverDrawn(): void
    {
        $issues = $this->issues(['background' => '#1B2A4A'], [
            ['id' => 'titre', 'type' => 'title', 'parentId' => null, 'color' => '#1B2A4A', 'background' => '#ffffff', 'size' => 48],
        ]);

        $this->assertCount(1, $issues, 'le fond écrit sur le titre n\'est pas dessiné : bleu nuit sur bleu nuit');
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

    public function testFitContentButtonShiftedFromItsStackAndItsTextsIsReported(): void
    {
        $composition = json_decode(json_encode(['schemaVersion' => 2, 'blocks' => [
            // bouton centré sous un titre et un texte à gauche : signalé
            ['id' => 'pile', 'type' => 'container', 'parentId' => null, 'layout' => 'stack', 'align' => 'left'],
            ['id' => 'titre', 'type' => 'title', 'parentId' => 'pile', 'align' => 'left'],
            ['id' => 'texte', 'type' => 'text', 'parentId' => 'pile'], // align absent : left
            ['id' => 'bouton', 'type' => 'button', 'parentId' => 'pile', 'fitContent' => true, 'align' => 'center'],
            // textes centrés comme le bouton dans une pile à gauche : mise en page centrée, voulue
            ['id' => 'centree', 'type' => 'container', 'parentId' => null, 'layout' => 'stack', 'align' => 'left'],
            ['id' => 'titre-centre', 'type' => 'title', 'parentId' => 'centree', 'align' => 'center'],
            ['id' => 'bouton-centre', 'type' => 'button', 'parentId' => 'centree', 'fitContent' => true, 'align' => 'center'],
            // icône ou flèche décalée : choix de mise en page
            ['id' => 'carte', 'type' => 'container', 'parentId' => null, 'layout' => 'stack', 'align' => 'left'],
            ['id' => 'carte-titre', 'type' => 'title', 'parentId' => 'carte', 'align' => 'left'],
            ['id' => 'fleche', 'type' => 'container', 'parentId' => 'carte', 'layout' => 'stack', 'fitContent' => true, 'align' => 'right'],
            // pile sans texte, ou rangée : non mesuré
            ['id' => 'seul', 'type' => 'container', 'parentId' => null, 'layout' => 'stack', 'align' => 'left'],
            ['id' => 'bouton-seul', 'type' => 'button', 'parentId' => 'seul', 'fitContent' => true, 'align' => 'center'],
            ['id' => 'rangee', 'type' => 'container', 'parentId' => null, 'layout' => 'row', 'align' => 'left'],
            ['id' => 'titre-rangee', 'type' => 'title', 'parentId' => 'rangee', 'align' => 'left'],
            ['id' => 'en-rangee', 'type' => 'button', 'parentId' => 'rangee', 'fitContent' => true, 'align' => 'center'],
        ]]), false);

        $this->assertSame(
            ['bouton : center (pile pile et ses textes : left)'],
            (new LandingAiAlignCheck(new CompositionInspector()))->issues($composition)
        );
    }

    public function testContactFormInsideACardAndServiceListWithoutPricesAreReported(): void
    {
        $composition = json_decode(json_encode(['schemaVersion' => 2, 'blocks' => [
            ['id' => 'carte', 'type' => 'container', 'parentId' => null, 'background' => '#ffffff', 'padding' => 28],
            ['id' => 'formulaire', 'type' => 'form', 'parentId' => 'carte', 'formType' => 'contact'],
            ['id' => 'liste', 'type' => 'container', 'parentId' => null, 'background' => 'transparent', 'repeat' => ['source' => 'services']],
            ['id' => 'carte-service', 'type' => 'container', 'parentId' => 'liste', 'background' => '#ffffff', 'padding' => 20],
            ['id' => 'nom', 'type' => 'title', 'parentId' => 'carte-service', 'bindings' => ['text' => 'item.title']],
            ['id' => 'prix', 'type' => 'stat', 'parentId' => 'carte-service', 'bindings' => ['text' => 'item.price']],
        ]]), false);
        $check = new LandingAiLayoutCheck(new CompositionInspector());

        $this->assertSame(
            ['formulaire formulaire dans un double cadre (parent carte)', 'liste de services liste sans liaison item.subtitle'],
            $check->issues($composition)
        );

        $composition->blocks[0]->background = 'transparent';
        $composition->blocks[0]->padding = 0;
        $composition->blocks[] = (object) ['id' => 'accroche', 'type' => 'text', 'parentId' => 'carte-service', 'bindings' => (object) ['text' => 'item.subtitle'], 'hideEmpty' => true];
        $this->assertSame([], $check->issues($composition));
    }

    /** @return list<string> */
    private function issues(array $section, array $blocks): array
    {
        $composition = json_decode(json_encode(['schemaVersion' => 2] + $section + ['blocks' => $blocks]), false);

        return (new LandingAiContrastCheck(new CompositionInspector()))->issues($composition);
    }
}
