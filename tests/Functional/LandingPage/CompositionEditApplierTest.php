<?php

namespace App\Tests\Functional\LandingPage;

use App\Services\LandingAiService\CompositionEditApplier;
use PHPUnit\Framework\TestCase;

/**
 * Opérations de retouche : « set » fusionne les objets imbriqués, remplace les tableaux ;
 * « unset » accepte des chemins pointés.
 */
class CompositionEditApplierTest extends TestCase
{
    private function composition(): object
    {
        return json_decode(<<<'JSON'
            {"schemaVersion": 2, "mobile": {"layout": "stack"},
             "blocks": [
               {"id": "liste", "type": "container", "parentId": null, "repeat": {"source": "presentationGroup", "id": "12", "limit": 6},
                "mobile": {"w": 80, "order": 2}},
               {"id": "bouton", "type": "button", "parentId": "liste", "text": "Réserver",
                "bindings": {"text": "item.buttonText", "offer": "item.title"},
                "translations": {"en": {"text": "Book", "alt": "Button"}, "de": {"text": "Buchen"}}},
               {"id": "menu", "type": "nav", "parentId": null, "links": [{"title": "Accueil", "url": "/"}, {"title": "Contact", "url": "#contact"}],
                "iconCycle": ["star", "heart"]}
             ]}
            JSON, false);
    }

    private function apply(array $operations): array
    {
        return (new CompositionEditApplier())->apply($this->composition(), json_decode(json_encode($operations), false));
    }

    private function block(object $composition, string $id): object
    {
        foreach ($composition->blocks as $block) {
            if ($block->id === $id) {
                return $block;
            }
        }
        $this->fail("bloc $id absent");
    }

    public function testSetMergesNestedObjects(): void
    {
        $result = $this->apply([
            ['op' => 'update', 'id' => 'liste', 'set' => ['mobile' => ['align' => 'center'], 'repeat' => ['stagger' => 0.1]]],
            ['op' => 'update', 'id' => 'bouton', 'set' => ['bindings' => ['url' => 'item.buttonUrl'], 'translations' => ['en' => ['text' => 'Book now'], 'es' => ['text' => 'Reservar']]]],
            ['op' => 'section', 'set' => ['mobile' => ['layout' => 'stack']]],
        ]);

        $this->assertSame([], $result['errors']);
        $liste = $this->block($result['composition'], 'liste');
        $this->assertEquals((object) ['w' => 80, 'order' => 2, 'align' => 'center'], $liste->mobile, 'mobile.w et mobile.order gardés');
        $this->assertEquals((object) ['source' => 'presentationGroup', 'id' => '12', 'limit' => 6, 'stagger' => 0.1], $liste->repeat);
        $bouton = $this->block($result['composition'], 'bouton');
        $this->assertEquals((object) ['text' => 'item.buttonText', 'offer' => 'item.title', 'url' => 'item.buttonUrl'], $bouton->bindings);
        $this->assertEquals((object) ['text' => 'Book now', 'alt' => 'Button'], $bouton->translations->en, 'translations.en fusionné');
        $this->assertEquals((object) ['text' => 'Buchen'], $bouton->translations->de, 'autre langue intacte');
        $this->assertEquals((object) ['text' => 'Reservar'], $bouton->translations->es);
    }

    public function testSetReplacesArraysAndScalars(): void
    {
        $result = $this->apply([
            ['op' => 'update', 'id' => 'menu', 'set' => ['links' => [['title' => 'Services', 'url' => '#services']], 'iconCycle' => ['check']]],
            ['op' => 'update', 'id' => 'bouton', 'set' => ['text' => 'Choisir']],
        ]);

        $this->assertSame([], $result['errors']);
        $menu = $this->block($result['composition'], 'menu');
        $this->assertEquals([(object) ['title' => 'Services', 'url' => '#services']], $menu->links, 'tableau remplacé entier');
        $this->assertSame(['check'], $menu->iconCycle);
        $this->assertSame('Choisir', $this->block($result['composition'], 'bouton')->text);
    }

    public function testUnsetAcceptsDottedPaths(): void
    {
        $result = $this->apply([
            ['op' => 'update', 'id' => 'liste', 'unset' => ['mobile.w', 'repeat.limit']],
            ['op' => 'update', 'id' => 'bouton', 'unset' => ['bindings.offer', 'translations.en.alt', 'absent.cle', 'mobile.w']],
        ]);

        $this->assertSame([], $result['errors'], 'chemin absent : sans effet');
        $liste = $this->block($result['composition'], 'liste');
        $this->assertEquals((object) ['order' => 2], $liste->mobile);
        $this->assertEquals((object) ['source' => 'presentationGroup', 'id' => '12'], $liste->repeat);
        $bouton = $this->block($result['composition'], 'bouton');
        $this->assertEquals((object) ['text' => 'item.buttonText'], $bouton->bindings);
        $this->assertEquals((object) ['text' => 'Book'], $bouton->translations->en);
    }

    public function testInvalidUnsetPathsAreRefused(): void
    {
        foreach ([['text.longueur', 'n\'est pas un objet'], ['id', 'non supprimable'], ['mobile.', 'non supprimable'], ['links.0', 'n\'est pas un objet']] as [$path, $message]) {
            $result = $this->apply([['op' => 'update', 'id' => ($path === 'links.0' ? 'menu' : 'bouton'), 'unset' => [$path]]]);
            $this->assertNotSame([], $result['errors'], $path);
            $this->assertSame('operations[0].unset[0]', $result['errors'][0]['path'], $path);
            $this->assertStringContainsString($message, $result['errors'][0]['message'], $path);
        }
    }

    public function testOriginalCompositionIsNeverModified(): void
    {
        $original = $this->composition();
        $snapshot = json_encode($original);

        (new CompositionEditApplier())->apply($original, json_decode(json_encode([['op' => 'update', 'id' => 'liste', 'set' => ['mobile' => ['align' => 'center']], 'unset' => ['repeat.limit']]]), false));

        $this->assertSame($snapshot, json_encode($original));
    }
}
