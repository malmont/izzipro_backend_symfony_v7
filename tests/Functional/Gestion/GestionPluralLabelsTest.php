<?php

namespace App\Tests\Functional\Gestion;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Accord des libellés de la gestion (demande du chef de projet, 13/11/2026, lot IA-ACT-5) : le serveur écrit « 1 semaine » et
 * « 2 semaines », jamais « semaine(s) », dans les deux langues. Garde-fou : aucun libellé de `translations/gestion.*.yaml` ne porte une
 * terminaison entre parenthèses, et chaque libellé à deux formes dit bien le nombre qui le commande.
 */
class GestionPluralLabelsTest extends KernelTestCase
{
    public function testLabelsAgreeWithTheirNumberInBothLanguages(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $tr = fn (string $id, int $count, string $locale, array $params = []): string => $translator->trans($id, ['%count%' => (string) $count] + $params, 'gestion', $locale);

        // Le délai de vente des semblables, dans le libellé du coefficient proposé
        $this->assertSame(['en 1 semaine pour les unités vendues', 'en 2 semaines pour les unités vendues', 'in 1 week for the units sold', 'in 3 weeks for the units sold'],
            [$tr('prices.coefficient.weeks', 1, 'fr'), $tr('prices.coefficient.weeks', 2, 'fr'), $tr('prices.coefficient.weeks', 1, 'en'), $tr('prices.coefficient.weeks', 3, 'en')]);
        // Action `price.set` : portée, prix à risque, promotions en cours
        $this->assertSame(['1 article choisi', '7 articles choisis', '1 selected item', '7 selected items'],
            [$tr('conversation.actions.price_set.scope.products', 1, 'fr'), $tr('conversation.actions.price_set.scope.products', 7, 'fr'), $tr('conversation.actions.price_set.scope.products', 1, 'en'), $tr('conversation.actions.price_set.scope.products', 7, 'en')]);
        $this->assertStringStartsWith('1 prix passerait sous son coût d\'achat', $tr('conversation.actions.price_set.force.text', 1, 'fr'));
        $this->assertStringStartsWith('3 prix passeraient sous leur coût d\'achat', $tr('conversation.actions.price_set.force.text', 3, 'fr'));
        $this->assertStringStartsWith('1 price would go below its purchase cost', $tr('conversation.actions.price_set.force.text', 1, 'en'));
        $this->assertStringStartsWith('3 prices would go below their purchase cost', $tr('conversation.actions.price_set.force.text', 3, 'en'));
        $this->assertSame(['1 article a une promotion en cours : son prix promotionnel ne change pas.', '2 articles ont une promotion en cours : leur prix promotionnel ne change pas.'],
            [$tr('conversation.actions.price_set.notice.promotions', 1, 'fr'), $tr('conversation.actions.price_set.notice.promotions', 2, 'fr')]);
        // Fil d'une facture : articles déjà au catalogue, résumé de la lecture, réponses
        $this->assertStringStartsWith('Cette facture comprend un article déjà en vente.', $tr('conversation.questions.price_mode.text', 1, 'fr'));
        $this->assertStringStartsWith('Cette facture comprend 30 articles déjà en vente.', $tr('conversation.questions.price_mode.text', 30, 'fr'));
        $this->assertSame(['Il me manque 1 réponse avant de vous montrer ce qui sera créé.', 'Il me manque 2 réponses avant de vous montrer ce qui sera créé.', 'I need 1 more answer before showing you what will be created.', 'I need 2 more answers before showing you what will be created.'],
            [$tr('conversation.summary.questions', 1, 'fr'), $tr('conversation.summary.questions', 2, 'fr'), $tr('conversation.summary.questions', 1, 'en'), $tr('conversation.summary.questions', 2, 'en')]);
        $read = ['%number%' => 'F1', '%date%' => '2026-11-13', '%supplier%' => 'Maison', '%total%' => '10,00', '%currency%' => 'CAD'];
        $this->assertSame(['Facture F1 du 2026-11-13, fournisseur Maison : 1 ligne, total 10,00 CAD.', 'Facture F1 du 2026-11-13, fournisseur Maison : 4 lignes, total 10,00 CAD.'],
            [$tr('conversation.summary.read', 1, 'fr', $read + ['%lines%' => '1']), $tr('conversation.summary.read', 4, 'fr', $read + ['%lines%' => '4'])]);
        $this->assertSame(["C'est noté : 1 réponse enregistrée.", "C'est noté : 2 réponses enregistrées.", 'Got it: 1 answer saved.', 'Got it: 2 answers saved.'],
            [$tr('conversation.reply.applied', 1, 'fr'), $tr('conversation.reply.applied', 2, 'fr'), $tr('conversation.reply.applied', 1, 'en'), $tr('conversation.reply.applied', 2, 'en')]);
        // Actions des lots précédents : coût d'achat, clôture
        $this->assertStringStartsWith('1 unité vendue dans les périodes ouvertes passera de', $tr('conversation.actions.product_cost_set.effect.some', 1, 'fr'));
        $this->assertStringStartsWith('3 unités vendues dans les périodes ouvertes passeront de', $tr('conversation.actions.product_cost_set.effect.some', 3, 'fr'));
        $this->assertStringStartsWith('1 commande fournisseur de la période sans frais de port', $tr('conversation.actions.period_close.alerts.without_shipping', 1, 'fr'));
        $this->assertStringStartsWith('2 commandes fournisseurs de la période sans frais de port', $tr('conversation.actions.period_close.alerts.without_shipping', 2, 'fr'));
        // Guide des séquences : le chiffre du constat commande le pluriel (`GestionSequenceService::PLURAL_FIGURE`)
        $this->assertSame(['1 voyage d\'achat touche cette période.', '2 voyages d\'achat touchent cette période.', '1 buying trip overlaps this period.', '2 buying trips overlap this period.'],
            [$tr('sequences.period.prepare.steps.trip.done', 1, 'fr', ['%trips%' => '1']), $tr('sequences.period.prepare.steps.trip.done', 2, 'fr', ['%trips%' => '2']),
                $tr('sequences.period.prepare.steps.trip.done', 1, 'en', ['%trips%' => '1']), $tr('sequences.period.prepare.steps.trip.done', 2, 'en', ['%trips%' => '2'])]);
        $this->assertSame('Dernier solde saisi il y a 1 jour : la projection de trésorerie est fiable.', $tr('sequences.period.prepare.steps.cash_balance.done', 1, 'fr', ['%ageDays%' => '1']));
        // En français, zéro s'accorde au singulier ; en anglais, au pluriel
        $this->assertSame(['0 article choisi', '0 selected items'], [$tr('conversation.actions.price_set.scope.products', 0, 'fr'), $tr('conversation.actions.price_set.scope.products', 0, 'en')]);

        // Garde-fou : plus aucune terminaison entre parenthèses, et les deux formes d'un libellé existent dans les deux langues
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/translations';
        $flat = function (array $tree, string $prefix = '') use (&$flat): array {
            $out = [];
            foreach ($tree as $key => $value) {
                $out += is_array($value) ? $flat($value, "$prefix$key.") : ["$prefix$key" => (string) $value];
            }

            return $out;
        };
        $labels = ['fr' => $flat(Yaml::parseFile("$dir/gestion.fr.yaml")), 'en' => $flat(Yaml::parseFile("$dir/gestion.en.yaml"))];
        foreach ($labels as $locale => $messages) {
            foreach ($messages as $id => $text) {
                $this->assertDoesNotMatchRegularExpression('/\p{L}\((?:s|es|e|x|nt|en|ent|ies)\)/u', $text, "gestion.$locale.yaml « $id » : écrire les deux formes (« un …|%count% … »), pas une terminaison entre parenthèses");
            }
        }
        // Piège du traducteur : une forme qui commence par « mot: » voit ce mot pris pour une étiquette et retiré (« Noted: 1 answer » → « 1 answer »)
        foreach ($labels as $locale => $messages) {
            foreach (array_filter($messages, fn (string $text) => str_contains($text, '|')) as $id => $text) {
                foreach (explode('|', $text) as $form) {
                    $this->assertDoesNotMatchRegularExpression('/^\w+:/u', $form, "gestion.$locale.yaml « $id » : une forme ne commence pas par « mot: »");
                }
            }
        }
        // Un libellé à deux formes ne s'affiche jamais avec sa barre : chacun est appelé avec le nombre qui le commande (vérifié sur tous)
        $plural = array_filter($labels['fr'], fn (string $text) => str_contains($text, '|'));
        $this->assertGreaterThanOrEqual(18, count($plural));
        foreach (array_keys($plural) as $id) {
            foreach ([1, 2] as $count) {
                $this->assertStringNotContainsString('|', $tr($id, $count, 'fr'), "$id : forme pour $count");
                $this->assertStringNotContainsString('|', $tr($id, $count, 'en'), "$id : forme anglaise pour $count");
            }
        }
    }
}
