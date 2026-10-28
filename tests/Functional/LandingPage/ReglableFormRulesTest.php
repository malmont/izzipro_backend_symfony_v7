<?php

namespace App\Tests\Functional\LandingPage;

use App\Services\LandingConfigService\LandingConfigStore;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\Services\LandingPageSettingsService\ReglableFormRules;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Formulaires à étapes (contrat du lot 1, 27/10/2026) : règles entre blocs (ReglableFormRules), identiques à celles de
 * l'éditeur, et règles du document (uid, navbar, footer). Le schéma du lot 1 n'est pas encore publié : il est simulé
 * à partir du contrat embarqué.
 */
class ReglableFormRulesTest extends KernelTestCase
{
    private static ?string $schemaFile = null;

    public function testAValidFormHasNoError(): void
    {
        $this->assertSame([], $this->errors($this->form()));
        $blocks = $this->form();
        $blocks[3] += ['minLength' => 2, 'maxLength' => 2];
        $this->assertSame([], $this->errors($blocks), 'minimum égal au maximum : permis');
    }

    public function testCompositionsWithoutFormAreNotConcerned(): void
    {
        $blocks = $this->blocks([['id' => 'c', 'type' => 'container', 'layout' => 'stack'], ['id' => 't', 'type' => 'text', 'parentId' => 'c', 'text' => 'x'], ['id' => 'b', 'type' => 'button', 'parentId' => 'c', 'action' => 'contact']]);
        $this->assertSame([], ReglableFormRules::compositionErrors($blocks, 'blocks'));
    }

    public function testEachRuleGivesItsPath(): void
    {
        $cases = [
            '1. un seul container steps' => [fn (array &$b) => $b[] = ['id' => 'f2', 'type' => 'container', 'layout' => 'steps', 'parentId' => 'e1'], 'blocks[9].layout'],
            '2. field hors étape' => [fn (array &$b) => $b[] = ['id' => 'q9', 'type' => 'field', 'name' => 'hors', 'parentId' => 'f'], 'blocks[9].type'],
            '2. summary hors formulaire' => [fn (array &$b) => $b[] = ['id' => 'r9', 'type' => 'summary'], 'blocks[9].type'],
            '3. bouton next hors formulaire' => [fn (array &$b) => $b[] = ['id' => 'b9', 'type' => 'button', 'action' => 'next'], 'blocks[9].action'],
            '4. stepRole hors étape' => [fn (array &$b) => $b[] = ['id' => 'x9', 'type' => 'container', 'parentId' => 'e1', 'stepRole' => 'success'], 'blocks[9].stepRole'],
            '4. deux étapes success' => [fn (array &$b) => $b[1]['stepRole'] = 'success', 'blocks[2].stepRole'],
            '5. deux boutons submit' => [fn (array &$b) => $b[] = ['id' => 'b8', 'type' => 'button', 'parentId' => 'e1', 'action' => 'submit'], 'blocks[9].action'],
            '6. name absent' => [fn (array &$b) => $b[3]['name'] = null, 'blocks[3].name'],
            '6. name mal formé' => [fn (array &$b) => $b[3]['name'] = 'Nom complet', 'blocks[3].name'],
            '6. name en double' => [fn (array &$b) => $b[4]['name'] = 'nom', 'blocks[4].name'],
            '7. fieldRole en double' => [fn (array &$b) => $b[3]['fieldRole'] = 'email', 'blocks[4].fieldRole'],
            '7. fieldRole et fieldType' => [fn (array &$b) => $b[4]['fieldType'] = 'text', 'blocks[4].fieldType'],
            '8. choice sans options' => [fn (array &$b) => $b[5]['options'] = [], 'blocks[5].options'],
            '8. options sur un texte' => [fn (array &$b) => $b[3]['options'] = [['value' => 'a', 'label' => 'A']], 'blocks[3].options'],
            '8. optionsSource sur un texte' => [fn (array &$b) => $b[3]['optionsSource'] = 'services', 'blocks[3].optionsSource'],
            '9. summaryFields inconnu' => [fn (array &$b) => $b[7]['summaryFields'] = ['nom', 'age'], 'blocks[7].summaryFields'],
            '10. reservation sans courriel obligatoire' => [fn (array &$b) => $b[4]['required'] = false, 'blocks[0].formTarget'],
            '11. minLength > maxLength' => [fn (array &$b) => [$b[3]['minLength'], $b[3]['maxLength']] = [50, 10], 'blocks[3].minLength'],
            '11. minValue > maxValue' => [fn (array &$b) => $b[] = ['id' => 'n1', 'type' => 'field', 'parentId' => 'e1', 'fieldType' => 'number', 'name' => 'age', 'minValue' => 10, 'maxValue' => 5], 'blocks[9].minValue'],
            '11. minDays > maxDays' => [fn (array &$b) => $b[] = ['id' => 'd1', 'type' => 'field', 'parentId' => 'e1', 'fieldType' => 'date', 'name' => 'jour', 'minDays' => 30, 'maxDays' => 2], 'blocks[9].minDays'],
        ];
        foreach ($cases as $name => [$change, $path]) {
            $blocks = $this->form();
            $change($blocks);
            $errors = ReglableFormRules::compositionErrors($this->blocks($blocks), 'blocks');
            $this->assertContains($path, array_column($errors, 'path'), "$name : " . json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testAtMostThirtyQuestionsSuccessStepExcluded(): void
    {
        $blocks = $this->form();
        for ($n = 1; $n <= 27; $n++) {
            $blocks[] = ['id' => "z$n", 'type' => 'field', 'name' => "q$n", 'parentId' => 'e1'];
        }
        $this->assertSame([], ReglableFormRules::compositionErrors($this->blocks($blocks), 'blocks'), '3 + 27 = 30 questions');
        $blocks[] = ['id' => 'zs', 'type' => 'field', 'name' => 'dans_le_succes', 'parentId' => 'ok'];
        $this->assertSame([], ReglableFormRules::compositionErrors($this->blocks($blocks), 'blocks'), 'une question de l\'étape success ne compte pas');
        $blocks[] = ['id' => 'z31', 'type' => 'field', 'name' => 'q31', 'parentId' => 'e1'];
        $this->assertSame(['blocks[' . (count($blocks) - 1) . '].name'], array_column(ReglableFormRules::compositionErrors($this->blocks($blocks), 'blocks'), 'path'));
    }

    public function testValidatorAppliesTheRulesWithTheLot1Schema(): void
    {
        $validator = $this->validator();
        $this->assertSame([], $validator->validateComposition($this->blocksComposition($this->form())), 'le schéma simulé accepte le formulaire');

        $blocks = $this->form();
        $blocks[4]['name'] = 'nom';
        $errors = $validator->validateComposition($this->blocksComposition($blocks), 'tabs[0].sections[0].reglableConfig');
        $this->assertContains('tabs[0].sections[0].reglableConfig.blocks[4].name', array_column($errors, 'path'));
    }

    public function testFormSectionsNeedAUniqueUidAndNoFormInNavbarOrFooter(): void
    {
        $validator = $this->validator();
        $section = fn (?string $uid, bool $form) => (object) array_filter([
            'id' => 1, 'componentKey' => 'Contact', 'componentTypeKey' => 'typeReglable', 'uid' => $uid,
            'reglableConfig' => $form ? $this->blocksComposition($this->form()) : (object) ['schemaVersion' => 2, 'blocks' => []],
        ], fn ($v) => $v !== null);
        $configuration = fn (array $sections, bool $navbarForm = false) => (object) [
            'tabs' => [(object) ['id' => 'accueil', 'title' => (object) ['fr' => 'Accueil'], 'sections' => $sections]],
            'navbar' => (object) ['componentTypeKey' => 'typeReglable', 'reglableConfig' => $navbarForm ? $this->blocksComposition($this->form()) : (object) ['schemaVersion' => 2, 'blocks' => []]],
        ];

        $this->assertSame([], $validator->validateConfiguration($configuration([$section('s-a', true), $section('s-b', false), $section(null, false)])), 'uid unique ; une section sans formulaire n\'en exige pas');
        $this->assertSame(['tabs[0].sections[0].uid'], array_column($validator->validateConfiguration($configuration([$section(null, true)])), 'path'), 'formulaire sans uid');
        $this->assertSame(['tabs[0].sections[0].uid'], array_column($validator->validateConfiguration($configuration([$section('s-a', true), $section('s-a', false)])), 'path'), 'uid déjà porté par une autre section');
        $this->assertContains('navbar.reglableConfig.blocks', array_column($validator->validateConfiguration($configuration([], true)), 'path'));
    }

    public function testTheAssistantAddsFormsOnlyWhenAskedAndNeverInNavbarOrFooter(): void
    {
        self::bootKernel();
        $checker = self::getContainer()->get(\App\Services\LandingAiService\LandingAiCompositionChecker::class);
        $form = $this->blocksComposition($this->form());
        $plain = (object) ['schemaVersion' => 2, 'blocks' => $this->blocks([['id' => 'c', 'type' => 'container', 'layout' => 'stack']])];

        $this->assertStringContainsString('la demande n\'en demande pas', $checker->addedForms($form)[0]['message'] ?? '', 'formulaire non demandé : refusé');
        $this->assertSame([], $checker->addedForms($form, null, true, 'Presentation'), 'formulaire demandé : permis');
        $this->assertNotSame([], $checker->addedForms($form, null, true, 'Footer'), 'jamais dans le footer');
        $this->assertSame([], $checker->addedForms($form, $this->blocksComposition($this->form())), 'retouche d\'un formulaire existant : permise');
        $this->assertSame([], $checker->addedForms($plain), 'sans formulaire : rien');

        foreach (['Formulaire de prise de rendez-vous', 'Un devis en ligne', 'inscription à l\'atelier', 'questionnaire de satisfaction'] as $prompt) {
            $this->assertTrue(\App\Services\LandingAiService\LandingAiComposer::asksForm($prompt), $prompt);
        }
        foreach (['Rends le titre plus grand', 'Section héros avec photo'] as $prompt) {
            $this->assertFalse(\App\Services\LandingAiService\LandingAiComposer::asksForm($prompt), $prompt);
        }

        $schema = json_decode(file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/config/landingpage/' . LandingConfigStore::SCHEMA), false);
        $schema->{'$defs'}->block->properties->layout->enum = array_values(array_diff($schema->{'$defs'}->block->properties->layout->enum, ['steps']));
        $this->assertFalse(\App\Services\LandingAiService\LandingAiPromptBuilder::supportsFormSteps($schema), 'contrat sans formulaires : pas de consigne');
        $schema->{'$defs'}->block->properties->layout->enum[] = 'steps';
        $this->assertTrue(\App\Services\LandingAiService\LandingAiPromptBuilder::supportsFormSteps($schema));
    }

    /** Formulaire de réservation valide : 2 étapes + success, 3 questions, résumé, boutons */
    private function form(): array
    {
        return [
            ['id' => 'f', 'type' => 'container', 'layout' => 'steps', 'formName' => 'Rendez-vous', 'formTarget' => 'reservation', 'stepsProgress' => 'bar'],
            ['id' => 'e1', 'type' => 'container', 'parentId' => 'f', 'layout' => 'stack', 'tabLabel' => 'Vous'],
            ['id' => 'ok', 'type' => 'container', 'parentId' => 'f', 'layout' => 'stack', 'tabLabel' => 'Merci', 'stepRole' => 'success'],
            ['id' => 'q1', 'type' => 'field', 'parentId' => 'e1', 'fieldType' => 'text', 'name' => 'nom', 'fieldRole' => 'name', 'required' => true],
            ['id' => 'q2', 'type' => 'field', 'parentId' => 'e1', 'fieldType' => 'email', 'name' => 'courriel', 'fieldRole' => 'email', 'required' => true],
            ['id' => 'q3', 'type' => 'field', 'parentId' => 'e1', 'fieldType' => 'choice', 'name' => 'creneau', 'fieldRole' => 'slot', 'options' => [['value' => '09:00 - 12:00', 'label' => 'Matin']]],
            ['id' => 'b1', 'type' => 'button', 'parentId' => 'e1', 'action' => 'submit', 'text' => 'Envoyer', 'background' => '#111111'],
            ['id' => 'r1', 'type' => 'summary', 'parentId' => 'ok', 'summaryFields' => ['nom', 'creneau']],
            ['id' => 'b2', 'type' => 'button', 'parentId' => 'ok', 'action' => 'restart', 'text' => 'Recommencer', 'background' => '#111111'],
        ];
    }

    private function blocks(array $blocks): array
    {
        return json_decode(json_encode(array_values($blocks)), false);
    }

    private function blocksComposition(array $blocks): object
    {
        return (object) ['schemaVersion' => 2, 'blocks' => $this->blocks($blocks)];
    }

    private function errors(array $blocks): array
    {
        return ReglableFormRules::compositionErrors($this->blocks($blocks), 'blocks');
    }

    /** Validateur sur le contrat embarqué complété par les ajouts du lot 1 (container steps, field, summary, actions) */
    private function validator(): ReglableCompositionValidator
    {
        self::bootKernel();
        if (self::$schemaFile === null) {
            $schema = json_decode(file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/config/landingpage/' . LandingConfigStore::SCHEMA), false);
            $block = $schema->{'$defs'}->block;
            $block->properties->type->enum = [...$block->properties->type->enum, 'field', 'summary'];
            $block->properties->layout->enum = [...$block->properties->layout->enum, 'steps'];
            $block->properties->action->enum = [...$block->properties->action->enum, 'next', 'prev', 'submit', 'restart'];
            $text = (object) ['type' => 'string', 'maxLength' => 10000];
            foreach (['stepsProgress' => (object) ['enum' => ['bar', 'numbers', 'none']], 'formName' => $text, 'formTarget' => (object) ['enum' => ['generic', 'reservation', 'contact']],
                'stepRole' => (object) ['enum' => ['success']], 'fieldType' => (object) ['enum' => ['text', 'email', 'tel', 'textarea', 'number', 'date', 'select', 'choice', 'checkbox']],
                'name' => (object) ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,39}$'], 'label' => $text, 'placeholder' => $text, 'hint' => $text, 'required' => (object) ['type' => 'boolean'],
                'options' => (object) ['type' => 'array', 'maxItems' => 40], 'optionsSource' => (object) ['enum' => ['services']], 'fieldRole' => (object) ['type' => 'string'],
                'summaryFields' => (object) ['type' => 'array', 'maxItems' => 30, 'items' => (object) ['type' => 'string']]] as $key => $definition) {
                $block->properties->$key = $definition;
            }
            self::$schemaFile = tempnam(sys_get_temp_dir(), 'schema-formulaires');
            file_put_contents(self::$schemaFile, json_encode($schema));
        }

        return (new ReglableCompositionValidator(self::getContainer()->get(LandingConfigStore::class)))->withSchemaFile(self::$schemaFile);
    }
}
