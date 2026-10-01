<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\BookFontCatalog;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;

/**
 * Polices des livres (01/10/2026) : un catalogue court, un code enregistré sur le livre, un paramètre « font » pour
 * essayer une police dans les aperçus PDF. Les polices sont incorporées au PDF.
 */
class BookFontTest extends BookTypeApiTestCase
{
    public function testTheCatalogueIsPublicAndHasOneDefault(): void
    {
        [$status, $fonts] = $this->api('GET', '/book-fonts', null, null);

        $this->assertSame(200, $status);
        $this->assertGreaterThanOrEqual(5, count($fonts));
        $this->assertSame(['dejavu_serif'], array_column(array_filter($fonts, fn ($f) => $f['isDefault']), 'code'));
        foreach ($fonts as $font) {
            $this->assertSame(['code', 'label', 'family', 'titleFamily', 'description', 'category', 'isDefault'], array_keys($font));
        }
    }

    public function testTheBookKeepsItsFontAndIgnoresUnknownCodes(): void
    {
        [, $book] = $this->api('POST', '/books', ['title' => 'Livre en Garamond', 'type' => 'individuel', 'font' => 'eb_garamond'], 'client');
        $this->assertSame('eb_garamond', $book['font']);

        [, $unknown] = $this->api('PUT', "/books/{$book['id']}", ['font' => 'comic_sans'], 'client');
        $this->assertSame('eb_garamond', $unknown['font'], 'code hors catalogue ignoré');

        [, $changed] = $this->api('PUT', "/books/{$book['id']}", ['font' => 'lora'], 'client');
        $this->assertSame('lora', $changed['font']);

        [, $default] = $this->api('POST', '/books', ['title' => 'Livre sans choix', 'type' => 'individuel'], 'client');
        $this->assertSame(BookFontCatalog::DEFAULT, $default['font']);
    }

    public function testThePdfUsesTheBookFontOrTheOneBeingTried(): void
    {
        $service = static::getContainer()->get(BookPdfGeneratorService::class);
        $book = (new Book())->setTitle('Empreinte')->setPerson1FirstName('Dana')->setFont('lora');
        $book->addChapter((new Chapter())->setTitle('L\'enfance')->setTheme('enfance')->setPosition(1)
            ->setContentFinal("Je suis née à Fort-de-France, « l'île aux fleurs » : un cœur, une œuvre — et l'été.\n\n===Un sous-titre===\n\nSuite du récit."));

        $this->assertStringContainsString("font-family: 'Lora', 'DejaVu Serif', serif;", $service->renderInteriorHtml($book));
        $this->assertStringContainsString("font-family: 'Lora', 'DejaVu Serif', serif;", $service->renderCoverHtml($book, 40, 'classic_text'));
        $this->assertStringContainsString("font-family: 'EB Garamond', 'DejaVu Serif', serif;", $service->renderInteriorHtml($book, null, 'eb_garamond'), 'police à l\'essai');
        $this->assertStringContainsString("font-family: 'Lora', 'DejaVu Serif', serif;", $service->renderInteriorHtml($book, null, 'inconnue'), 'code inconnu : police du livre');
        $this->assertStringContainsString("font-family: 'Lato', 'DejaVu Serif', sans-serif;", $service->renderInteriorHtml($book, null, 'lato'));

        // Chaque police du catalogue produit un PDF qui l'incorpore
        foreach (BookFontCatalog::FONTS as $code => $font) {
            $pdf = $service->generateInteriorBinary($book, null, $code);
            $this->assertStringStartsWith('%PDF', $pdf, $code);
            $this->assertMatchesRegularExpression('/\/FontName \/[A-Z]{6}\+' . preg_quote(str_replace(' ', '', explode(' ', $font['family'])[0]), '/') . '/', $pdf, "$code incorporée au PDF");
        }

        // Le corps du titre suit la police : une police étroite autorise un titre plus grand dans la même colonne
        $this->assertGreaterThan(
            $service->fitFontSize('Anticonstitutionnellement', 196.0, 40, false, 0.0, 'dejavu_serif'),
            $service->fitFontSize('Anticonstitutionnellement', 196.0, 40, false, 0.0, 'eb_garamond')
        );
    }

    public function testAScriptFontOnlyStylesTheTitles(): void
    {
        // Écriture manuscrite : titres seulement (un livre entier en cursive serait illisible), sans capitales
        $service = static::getContainer()->get(BookPdfGeneratorService::class);
        $book = (new Book())->setTitle('Empreinte')->setPerson1FirstName('Dana')->setFont('dancing_script');
        $book->addChapter((new Chapter())->setTitle('L\'enfance')->setTheme('enfance')->setPosition(1)->setContentFinal("===Un sous-titre===\n\nLe récit commence ici, dans la maison de mon enfance."));

        $interior = $service->renderInteriorHtml($book);
        $this->assertStringContainsString("font-family: 'Lora', 'DejaVu Serif', serif;", $interior, 'texte courant en police de lecture');
        $this->assertStringContainsString("font-family: 'Dancing Script', 'Lora', 'DejaVu Serif', serif;", $interior, 'titres manuscrits');
        $this->assertStringContainsString('.half-title { text-transform: none; letter-spacing: 0; }', $interior);

        $cover = $service->renderCoverHtml($book, 40, 'classic_text');
        $this->assertStringContainsString("font-family: 'Dancing Script', 'Lora', 'DejaVu Serif', serif;", $cover);
        $this->assertDoesNotMatchRegularExpression('/class="title"[^>]*text-transform: uppercase/', $cover, 'pas de capitales en écriture manuscrite');
        $this->assertMatchesRegularExpression('/class="title"[^>]*text-transform: uppercase/', $service->renderCoverHtml($book->setFont('lora'), 40, 'classic_text'));

        $pdf = $service->generateInteriorBinary($book->setFont('dancing_script'));
        $this->assertMatchesRegularExpression('/\/FontName \/[A-Z]{6}\+DancingScript/', $pdf, 'police manuscrite incorporée');
        $this->assertMatchesRegularExpression('/\/FontName \/[A-Z]{6}\+Lora/', $pdf);

        [, $fonts] = $this->api('GET', '/book-fonts', null, null);
        $script = array_values(array_filter($fonts, fn ($f) => $f['code'] === 'dancing_script'))[0];
        $this->assertSame(['Lora', 'Dancing Script', 'script'], [$script['family'], $script['titleFamily'], $script['category']]);
    }

    public function testPreviewAcceptsTheFontParameter(): void
    {
        [, $book] = $this->api('POST', '/books', ['title' => 'Aperçu', 'type' => 'individuel'], 'client');
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor('client')];

        foreach (['preview-cover?locale=fr&pages=40&font=playfair_display', 'preview-interior?locale=fr&font=merriweather', 'preview-cover?locale=fr&font=inconnue'] as $path) {
            $this->client->request('GET', "/api/memoires/books/{$book['id']}/pdf/$path", [], [], $server);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
            $this->assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        }
        $this->api('DELETE', "/books/{$book['id']}", null, 'client');
    }
}
