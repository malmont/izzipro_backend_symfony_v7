<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\MemoiresVivantes\Services\ChapterTextFormatter;

/**
 * Mise en page du livre (PDF). Avant le 30/09/2026 : les sous-titres ===Titre=== et le balisage Markdown
 * s'imprimaient tels quels, le code technique du thème et « Chapitre 1 » en double apparaissaient, la couverture était
 * décalée et coupée (Dompdf ignore box-sizing) et « Générer les PDF » répondait 500 quand le frontend envoyait l'objet
 * auteur du livre.
 */
class BookPdfTest extends BookTypeApiTestCase
{
    public function testChapterTextIsSplitIntoSubtitlesAndCleanParagraphs(): void
    {
        $blocks = (new ChapterTextFormatter())->blocks(
            "===Ce que la terre garde===\r\n\r\nIl est des histoires qui commencent bien avant leurs protagonistes, **en silence**.\n\n"
            . "## Un titre Markdown\nDeuxième paragraphe, *en italique*, avec l'apostrophe d'époque.\n\n---\n\n**Un intertitre en gras**\n\n« Une citation » pour finir."
        );

        $this->assertSame(
            ['heading', 'paragraph', 'heading', 'paragraph', 'heading', 'paragraph'],
            array_column($blocks, 'type')
        );
        $this->assertSame('Ce que la terre garde', $blocks[0]['text']);
        $this->assertSame('I', $blocks[1]['dropCap'], 'lettrine sur le premier paragraphe, pas sur le sous-titre');
        $this->assertSame('l est des histoires qui commencent bien avant leurs protagonistes, en silence.', $blocks[1]['text']);
        $this->assertSame('Un titre Markdown', $blocks[2]['text']);
        $this->assertSame('Deuxième paragraphe, en italique, avec l\'apostrophe d\'époque.', $blocks[3]['text']);
        $this->assertSame('', $blocks[5]['dropCap'], 'pas de lettrine sur un guillemet');
        foreach ($blocks as $block) {
            $this->assertDoesNotMatchRegularExpression('/===|\*\*|^#/', $block['text']);
        }
    }

    public function testAnUnclosedBoldMarkIsRemoved(): void
    {
        $blocks = (new ChapterTextFormatter())->blocks("Un paragraphe dont la marque n'est jamais refermée.**(suite)");

        $this->assertSame("Un paragraphe dont la marque n'est jamais refermée.(suite)", $blocks[0]['dropCap'] . $blocks[0]['text']);
    }

    public function testChapterTitlesLoseEmojisAndTheDuplicatedNumber(): void
    {
        $formatter = new ChapterTextFormatter();

        $this->assertSame('L\'Histoire des parents & Nos racines', $formatter->chapterTitle('Chapitre 1 — 🌳 L\'Histoire des parents &amp; Nos racines'));
        $this->assertSame('La relève — Petits-enfants', $formatter->chapterTitle('Chapitre 3 — 🌱 La relève — Petits-enfants'));
        $this->assertSame('Chapitre 2', $formatter->chapterTitle('Chapitre 2'), 'un titre réduit à son numéro est conservé');
    }

    public function testInteriorOnlyContainsWrittenChaptersWithoutRawMarkup(): void
    {
        $book = (new Book())->setTitle('Famille &amp; mémoire')->setPerson1FirstName('Richard')->setPerson2FirstName('Émilie');
        $book->addChapter((new Chapter())->setTitle('Chapitre 1 — 🌳 Nos racines')->setTheme('histoire_parents')->setPosition(1)
            ->setContentFinal("===Ce que la terre garde===\n\nIl est des histoires qui commencent bien avant que leurs protagonistes ne se rencontrent.\n\n**Gras** et suite."));
        $book->addChapter((new Chapter())->setTitle('Chapitre 2 — Pas encore rédigé')->setTheme('regards_croises')->setPosition(2));

        $html = static::getContainer()->get(BookPdfGeneratorService::class)->renderInteriorHtml($book);

        $this->assertStringContainsString('<h3 class="story-subtitle">Ce que la terre garde</h3>', $html);
        $this->assertStringContainsString('<h2 class="chapter-title">Nos racines</h2>', $html);
        $body = substr($html, (int) strpos($html, '<body'));
        $this->assertStringNotContainsString('===', $body);
        $this->assertStringNotContainsString('**', $body);
        $this->assertStringNotContainsString('histoire_parents', $html, 'pas de code technique dans le livre');
        $this->assertStringNotContainsString('Pas encore rédigé', $html, 'ni page ni ligne de sommaire pour un chapitre sans texte');
        $this->assertStringContainsString('Richard &amp; Émilie', $html);
        $this->assertSame(200, $this->pdfStatus($html));
    }

    public function testCoverFitsTheVisibleAreaWhateverTheTitleAndColor(): void
    {
        $service = static::getContainer()->get(BookPdfGeneratorService::class);
        $book = (new Book())->setTitle('Les souvenirs extraordinaires de Marguerite-Élisabeth')->setPerson1FirstName('Marguerite');

        $html = $service->renderCoverHtml($book, 40, 'classic_text', null, 'f5f0e6; background: url(http://pirate.example/x)');
        $this->assertStringNotContainsString('box-sizing', $html, 'Dompdf ignore box-sizing : dimensions explicites uniquement');
        $this->assertStringNotContainsString('pirate.example', $html, 'couleur non valide ignorée');
        $this->assertStringContainsString('Marguerite', $html);

        $this->assertSame('#1b2838', BookPdfGeneratorService::normalizeColor('1B2838'));
        $this->assertNull(BookPdfGeneratorService::normalizeColor('rouge'));
        $this->assertTrue(BookPdfGeneratorService::isDarkColor('#1b2838'));
        $this->assertFalse(BookPdfGeneratorService::isDarkColor('#faf8f3'));
        // Le corps suit le mot le plus long (mesuré avec la police), sans jamais descendre sous le minimum lisible
        $this->assertSame(40, $service->fitFontSize('Carole', 400.0, 40));
        $this->assertLessThan(40, $service->fitFontSize('Anticonstitutionnellement', 196.0, 40));
        $this->assertGreaterThanOrEqual(7, $service->fitFontSize('Anticonstitutionnellement', 196.0, 40));
        $this->assertLessThan($service->fitFontSize('Extraordinaires', 300.0, 40), $service->fitFontSize('Extraordinaires', 300.0, 40, true, 1.5) + 1, 'capitales et interlettrage : corps plus petit ou égal');
    }

    public function testATitleIsNeverCutInTheMiddleOfAWord(): void
    {
        // Avant le 30/09/2026 (soir), « Empreinte » sortait en « Em » + « preinte » : règle écrite pour ce titre
        $service = static::getContainer()->get(BookPdfGeneratorService::class);

        foreach (['Empreinte', 'Mémoires de Dana', 'Anticonstitutionnellement', 'Les souvenirs lumineux d\'une enfance martiniquaise retrouvée'] as $title) {
            $book = (new Book())->setTitle($title)->setPerson1FirstName('Dana');
            foreach (['biographic_split', 'full_photo', 'gallery_frame', 'modern_banner', 'classic_text'] as $style) {
                $html = $service->renderCoverHtml($book, 40, $style);
                $this->assertStringContainsString('>' . htmlspecialchars($title, ENT_QUOTES) . '</div>', $html, "$title / $style : titre entier dans un seul bloc");
                $this->assertStringNotContainsString('break-word;', $html);
                $this->assertStringNotContainsString('>Em</span>', $html);
            }
            $interior = $service->renderInteriorHtml($book);
            $this->assertMatchesRegularExpression('/class="main-title" style="font-size: \d+pt;">' . preg_quote(htmlspecialchars($title, ENT_QUOTES), '/') . '</', $interior);
        }

        // Un mot démesuré réduit le corps de la page de titre au lieu de déborder
        $this->assertStringContainsString('class="main-title" style="font-size: 32pt;"', $service->renderInteriorHtml((new Book())->setTitle('Empreinte')));
        $this->assertStringNotContainsString('class="main-title" style="font-size: 32pt;"', $service->renderInteriorHtml((new Book())->setTitle('Anticonstitutionnellement')));
    }

    public function testCoverGeometryIsExposed(): void
    {
        $geometry = static::getContainer()->get(BookPdfGeneratorService::class)->coverGeometry(40);

        $this->assertSame('pt', $geometry['unit']);
        $this->assertEqualsWithDelta(658.28, $geometry['panels']['back']['width'], 0.01, 'plat : 210 mm + rembordage 19,05 mm + fond perdu 3,175 mm');
        $this->assertEqualsWithDelta(18.43, $geometry['panels']['spine']['width'], 0.01, 'tranche minimale de 6,5 mm');
        $this->assertEqualsWithDelta(967.89, $geometry['height'], 0.01);
        $this->assertEqualsWithDelta($geometry['width'], 2 * 658.28 + $geometry['panels']['spine']['width'], 0.02);
        $this->assertEqualsWithDelta(595.28, $geometry['visible']['front']['width'], 0.01, 'zone visible : une page A4');
        $this->assertEqualsWithDelta(841.89, $geometry['visible']['front']['height'], 0.01);
        $this->assertSame($geometry['panels']['front']['x'], $geometry['visible']['front']['x'], 'la 1re de couverture visible commence juste après la tranche');
        $this->assertGreaterThan($geometry['panels']['spine']['width'], static::getContainer()->get(BookPdfGeneratorService::class)->coverGeometry(400)['panels']['spine']['width']);
    }

    public function testAuthorNameAcceptsTheBookAuthorObject(): void
    {
        $this->assertSame('Client Test', BookPdfGeneratorService::normalizeAuthorName(['firstName' => 'Client', 'lastName' => 'Test', 'fullName' => 'Client Test']));
        $this->assertSame('Jean Dupont', BookPdfGeneratorService::normalizeAuthorName(['firstName' => 'Jean', 'lastName' => 'Dupont']));
        $this->assertSame('Danielle Almont', BookPdfGeneratorService::normalizeAuthorName(' Danielle Almont '));
        $this->assertNull(BookPdfGeneratorService::normalizeAuthorName('Auteur'), 'libellé par défaut du champ');
        $this->assertNull(BookPdfGeneratorService::normalizeAuthorName(42));

        [, $book] = $this->api('POST', '/books', ['title' => 'Livre « PDF »', 'type' => 'individuel', 'person1FirstName' => 'Danielle'], 'client');
        [, $chapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Chapitre 1 — L\'enfance', 'theme' => 'enfance', 'position' => 1, 'answers' => []], 'client');
        self::db()->prepare('UPDATE mv_chapter SET content_final = ? WHERE id = ?')->execute(["===Un sous-titre===\n\nUn paragraphe assez long pour recevoir une lettrine en début de chapitre.", $chapter['id']]);
        $directory = static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/public_bucket/uploads/memoires/books/' . $book['id'];

        try {
            [$status, $body] = $this->api('POST', "/books/{$book['id']}/pdf/generate", [
                'cover_style' => 'full_photo',
                'author_name' => ['firstName' => 'Client', 'lastName' => 'Test', 'fullName' => 'Client Test'],
            ], 'client');

            $this->assertSame(200, $status, json_encode($body));
            $this->assertTrue($body['success']);
            $this->assertFileExists($directory . '/interior.pdf');
            $this->assertFileExists($directory . '/cover.pdf');

            $this->client->request('GET', "/api/memoires/books/{$book['id']}/pdf/preview-interior?locale=fr", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor('client')]);
            $response = $this->client->getResponse();
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
            $this->assertIsArray($body['cover_geometry']['visible']['front'], 'géométrie de la couverture dans la réponse de génération');

            $this->client->request('GET', "/api/memoires/books/{$book['id']}/pdf/preview-cover?locale=fr&pages=40", [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor('client')]);
            $header = json_decode((string) $this->client->getResponse()->headers->get('X-Cover-Geometry'), true);
            $this->assertEqualsWithDelta(595.28, $header['visible']['front']['width'], 0.01, 'géométrie dans l\'en-tête de l\'aperçu');
            $this->assertStringContainsString("filename*=utf-8''", (string) $response->headers->get('Content-Disposition'), 'nom de fichier avec accents et guillemets');
        } finally {
            // Les PDF des tests ne restent pas dans le stockage partagé
            array_map('unlink', glob($directory . '/*.pdf') ?: []);
            @rmdir($directory);
            $this->api('DELETE', "/books/{$book['id']}", null, 'client');
        }
    }

    /** Le HTML produit est accepté par Dompdf */
    private function pdfStatus(string $html): int
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return str_starts_with((string) $dompdf->output(), '%PDF') ? 200 : 500;
    }
}
