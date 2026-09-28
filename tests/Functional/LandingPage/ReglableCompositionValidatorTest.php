<?php

namespace App\Tests\Functional\LandingPage;

use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Contrat des compositions réglables (schemaVersion 2) : JSON Schema du frontend + règles entre blocs.
 */
class ReglableCompositionValidatorTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/landingpage/production-compositions.json';

    private function validator(): ReglableCompositionValidator
    {
        self::bootKernel();

        return static::getContainer()->get(ReglableCompositionValidator::class);
    }

    public function testAllProductionCompositionsAreAccepted(): void
    {
        // 51 compositions des 4 sites en production au 28/09/2026, vérifiées valides par le frontend
        $compositions = json_decode(file_get_contents(self::FIXTURE), false);
        $this->assertCount(51, (array) $compositions);

        $validator = $this->validator();
        $refused = [];
        foreach ($compositions as $name => $composition) {
            foreach ($validator->validateComposition($composition, explode(' ', $name, 2)[1]) as $error) {
                $refused[] = "$name → {$error['path']} : {$error['message']}";
            }
        }
        $this->assertSame([], $refused, "Compositions de production refusées :\n" . implode("\n", $refused));
    }

    public function testValidCompositionIsAccepted(): void
    {
        $this->assertSame([], $this->validator()->validateComposition($this->composition()));
    }

    /** @dataProvider refusedCompositions */
    public function testInvalidCompositionIsRefused(callable $alter, string $expectedPath, string $expectedMessage): void
    {
        $composition = $this->composition();
        $alter($composition);

        $errors = $this->validator()->validateComposition($composition);

        $this->assertNotEmpty($errors, 'composition refusée');
        $matching = array_filter($errors, fn ($e) => $e['path'] === $expectedPath && str_contains($e['message'], $expectedMessage));
        $this->assertNotEmpty($matching, "erreur attendue « $expectedPath : $expectedMessage », reçues :\n" . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function refusedCompositions(): iterable
    {
        yield 'propriété inconnue sur un bloc' => [fn ($c) => $c->blocks[1]->fontColor = '#ffffff', 'blocks[1].fontColor', 'propriété inconnue'];
        yield 'propriété inconnue sur la section' => [fn ($c) => $c->sectionColor = '#ffffff', 'sectionColor', 'propriété inconnue'];
        yield 'valeur null' => [fn ($c) => $c->blocks[1]->color = null, 'blocks[1].color', 'null'];
        yield 'parentId introuvable' => [fn ($c) => $c->blocks[1]->parentId = 'absent', 'blocks[1].parentId', 'introuvable'];
        yield 'parent non container' => [fn ($c) => $c->blocks[2]->parentId = 'titre', 'blocks[2].parentId', 'n\'est pas un bloc container'];
        yield 'identifiant en double' => [fn ($c) => $c->blocks[2]->id = 'titre', 'blocks[2].id', 'en double'];
        yield 'boucle de parents' => [function ($c) {
            $c->blocks[] = (object) ['id' => 'boucle-a', 'type' => 'container', 'parentId' => 'boucle-b'];
            $c->blocks[] = (object) ['id' => 'boucle-b', 'type' => 'container', 'parentId' => 'boucle-a'];
        }, 'blocks[3].parentId', 'boucle'];
        yield 'profondeur 9' => [function ($c) {
            $c->blocks = self::chain(10); // le dernier bloc a 9 parents
        }, 'blocks[9].parentId', 'profondeur'];
        yield '81 blocs' => [function ($c) {
            for ($i = count($c->blocks); $i < 81; $i++) {
                $c->blocks[] = (object) ['id' => "bloc-$i", 'type' => 'text', 'parentId' => null, 'text' => "Bloc $i"];
            }
        }, 'blocks', '80 éléments au plus'];
        yield 'ancre invalide' => [fn ($c) => $c->anchor = 'Tarifs !', 'anchor', 'format'];
        yield 'action de bouton inconnue' => [fn ($c) => $c->blocks[2]->action = 'script', 'blocks[2].action', 'valeur non autorisée'];
        yield 'liaison vers __proto__' => [fn ($c) => $c->blocks[2]->bindings->offer = '__proto__.x', 'blocks[2].bindings.offer', 'interdite'];
        yield 'propriété réservée à un autre type de bloc' => [fn ($c) => $c->blocks[1]->action = 'contact', 'blocks[1].action', 'non autorisée sur un bloc « title »'];
        yield 'couleur invalide' => [fn ($c) => $c->blocks[1]->color = 'red; background: url(x)', 'blocks[1].color', 'valeur non autorisée'];
        yield 'dégradé aux parenthèses déséquilibrées' => [fn ($c) => $c->background = 'linear-gradient(red, blue', 'background', 'parenthèses'];
        yield 'url javascript' => [fn ($c) => $c->blocks[2]->url = 'javascript:alert(1)', 'blocks[2].url', 'format'];
        yield 'clé de liaison inconnue' => [fn ($c) => $c->blocks[2]->bindings->color = 'item.color', 'blocks[2].bindings.color', 'propriété inconnue'];
    }

    public function testDepthOfEightParentsIsAccepted(): void
    {
        $composition = $this->composition();
        $composition->blocks = self::chain(9); // le dernier bloc a 8 parents

        $this->assertSame([], $this->validator()->validateComposition($composition));
    }

    public function testErrorPathsArePrefixedWithTheirLocation(): void
    {
        $invalid = $this->composition();
        $invalid->blocks[1]->fontColor = '#ffffff';
        $configuration = (object) [
            'navbar' => (object) ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $this->composition()],
            'tabs' => [(object) ['sections' => [
                (object) ['id' => 1, 'componentKey' => 'Presentation', 'componentTypeKey' => 'typeA', 'reglableConfig' => (object) ['ancienne' => true]],
                (object) ['id' => 2, 'componentKey' => 'Presentation', 'componentTypeKey' => 'typeReglable'],
                (object) ['id' => 3, 'componentKey' => 'Presentation', 'componentTypeKey' => 'typeReglable', 'reglableConfig' => $invalid],
            ]]],
            'reglablePresets' => [(object) ['id' => 'modele', 'name' => 'Modèle', 'config' => $invalid]],
        ];

        $paths = array_column($this->validator()->validateConfiguration($configuration), 'path');

        // Section revenue au typeA : sa composition est conservée sans être validée ; section réglable sans composition : acceptée
        $this->assertEqualsCanonicalizing([
            'tabs[0].sections[2].reglableConfig.blocks[1].fontColor',
            'reglablePresets[0].config.blocks[1].fontColor',
        ], $paths);
    }

    /** Composition valide : un container racine, un titre, un bouton de réservation lié aux données */
    private function composition(): object
    {
        return json_decode(<<<'JSON'
            {
              "schemaVersion": 2,
              "layout": "free",
              "anchor": "tarifs",
              "background": "linear-gradient(135deg, rgba(37,99,235,0.2) 0%, transparent 70%)",
              "blocks": [
                {"id": "carte", "type": "container", "parentId": null, "x": 0, "y": 0, "w": 100, "h": 100, "layout": "stack"},
                {"id": "titre", "type": "title", "parentId": "carte", "text": "Nos formules", "color": "#243b35", "translations": {"en": {"text": "Our plans"}}},
                {"id": "reserver", "type": "button", "parentId": "carte", "text": "Réserver", "url": "#reservation", "action": "reservation", "offer": "Formule découverte", "bindings": {"offer": "item.title"}}
              ]
            }
            JSON, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Chaîne de containers imbriqués : le bloc i a i parents */
    private static function chain(int $length): array
    {
        $blocks = [];
        for ($i = 0; $i < $length; $i++) {
            $blocks[] = (object) ['id' => "niveau-$i", 'type' => 'container', 'parentId' => $i === 0 ? null : 'niveau-' . ($i - 1)];
        }

        return $blocks;
    }
}
