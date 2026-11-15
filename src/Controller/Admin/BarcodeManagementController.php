<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Services\BarcodeService\BarcodeCatalogList;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BarcodeManagementController extends AbstractController
{
    private TenantEntityManagerProvider $emProvider;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        AdminUrlGenerator $adminUrlGenerator,
        TenantEntityManagerProvider $emProvider,
        private readonly CatalogBarcodeService $barcodes, // lot CB-1 (15/11/2026, `docs/codes-barres.md`)
        private readonly LoggerInterface $logger,
        private readonly BarcodeCatalogList $catalog, // lot CB-2 : liste paginée, impression de la sélection
        private readonly BarcodeLabelPrinter $printer,
        private readonly TenantCurrencyProvider $currency,
    ) {
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->emProvider = $emProvider;
    }

    /**
     * Écran « Gestion des Codes-Barres » (repris par le lot CB-2, `docs/codes-barres.md`) : une ligne par variante et par article sans
     * variante, page par page (plus de chargement de tout le catalogue), recherche par nom, code d'article ou code-barres, filtre « sans
     * code ». On donne un nombre d'étiquettes par ligne, puis on imprime (écran ou PDF).
     */
    #[Route('/admin/barcode-management', name: 'admin_barcode_management', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $missingOnly = $request->query->get('missing') === '1';
        $list = $this->catalog->page($search, $missingOnly, max(1, $request->query->getInt('page', 1)));

        return $this->render('admin/barcode_management/index.html.twig', $list + [
            'search' => $search,
            'missingOnly' => $missingOnly,
            'missing' => $this->barcodes->missingCount(),
            'bulkLimit' => CatalogBarcodeService::BULK_LIMIT,
            'maxLabels' => BarcodeLabelPrinter::MAX_LABELS,
            'currency' => $this->currency->code(),
        ]);
    }

    /**
     * Impression de la sélection (lot CB-2) : `copies[variant-412]=3`, `copies[product-95]=1`, `showPrice=1`, `output=html|pdf`. Une
     * lecture : rien n'est écrit. Planche d'étiquettes 50 × 30 mm ; au plus `MAX_LABELS` étiquettes ; une ligne sans code est refusée.
     */
    #[Route('/admin/barcode-management/print', name: 'admin_barcode_print', methods: ['POST'])]
    public function printSelection(Request $request): Response
    {
        $copies = [];
        foreach ($request->request->all('copies') as $key => $count) {
            if (is_scalar($count) && (int) $count > 0) {
                $copies[(string) $key] = (int) $count;
            }
        }
        // retour à la même page de la liste (recherche, filtre et page repris du formulaire, jamais d'une adresse fournie)
        $back = $this->redirectToRoute('admin_barcode_management', array_filter([
            'search' => trim((string) $request->request->get('search', '')),
            'missing' => $request->request->get('missing') === '1' ? '1' : null,
            'page' => max(1, $request->request->getInt('page', 1)) > 1 ? max(1, $request->request->getInt('page', 1)) : null,
        ]));
        if ($copies === []) {
            $this->addFlash('error', 'Rien à imprimer : donnez un nombre d\'étiquettes à au moins une ligne.');

            return $back;
        }
        if (array_sum($copies) > BarcodeLabelPrinter::MAX_LABELS) {
            $this->addFlash('error', sprintf('Au plus %d étiquettes par impression (%d demandées) : imprimez en plusieurs fois.', BarcodeLabelPrinter::MAX_LABELS, array_sum($copies)));

            return $back;
        }
        $rows = $this->catalog->rowsFor(array_keys($copies));
        $without = array_filter($rows, static fn (array $row): bool => $row['barcode'] === null);
        if (count($rows) !== count($copies) || $without !== []) {
            $this->addFlash('error', $without !== []
                ? sprintf('%d ligne(s) de la sélection n\'ont pas de code-barres (« %s ») : générez les codes manquants, ou retirez-les.', count($without), (string) reset($without)['title'])
                : 'Une ligne de la sélection n\'existe plus : rechargez la page.');

            return $back;
        }
        $showPrice = $request->request->get('showPrice') === '1';
        $formatter = new \NumberFormatter('fr_CA', \NumberFormatter::CURRENCY);
        $currency = $this->currency->code();
        $labels = [];
        foreach ($copies as $key => $count) {
            $row = $rows[$key];
            $labels[] = ['title' => $row['title'], 'subtitle' => $row['subtitle'], 'barcode' => $row['barcode'], 'copies' => $count,
                'price' => $showPrice && $row['priceCents'] !== null ? (string) $formatter->formatCurrency($row['priceCents'] / 100, $currency) : null];
        }
        if ($request->request->get('output') === 'pdf') {
            return new Response($this->printer->pdf($labels), Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="etiquettes.pdf"']);
        }

        return new Response($this->printer->html($labels));
    }

    /**
     * « Générer les codes manquants » (lot CB-1) : un code généré pour chaque variante sans code et chaque article sans variante ni code,
     * sur tout le catalogue, au plus `BULK_LIMIT` par lancement (on relance pour la suite). Ne touche jamais un code présent ; une ligne
     * au journal du site par lancement. Écriture : formulaire POST avec jeton, réservé à l'administrateur comme tout `/admin`.
     */
    #[Route('/admin/barcode-management/generate-missing', name: 'admin_barcode_generate_missing', methods: ['POST'])]
    public function generateMissing(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('catalog_barcode_generate_missing', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Formulaire expiré : rien n\'a été généré. Rechargez la page et recommencez.');

            return $this->redirectToRoute('admin_barcode_management');
        }
        try {
            $run = $this->barcodes->generateMissing();
        } catch (\Throwable $e) {
            $this->logger->error('Codes-barres : génération des codes manquants en échec : ' . $e->getMessage(), ['exception' => $e]);
            $this->addFlash('error', 'La génération a échoué : aucun code n\'a été écrit. Réessayez dans un instant.');

            return $this->redirectToRoute('admin_barcode_management');
        }
        if ($run['written'] === 0 && $run['remaining'] > 0) {
            // Rien d'écrit alors qu'il en manque : les fiches sans code sont tenues ailleurs (une vente en cours, un autre lancement) et
            // le lancement ne les attend pas
            $this->addFlash('error', sprintf('Aucun code écrit pour l\'instant : %d fiche(s) sans code-barres sont occupées (une vente en cours, ou un autre lancement). Relancez dans un instant.', $run['remaining']));
        } elseif ($run['written'] === 0) {
            $this->addFlash('success', 'Aucun code à générer : toutes les variantes et tous les articles sans variante ont déjà un code-barres.');
        } else {
            $this->addFlash('success', sprintf(
                '%d code(s)-barres généré(s) : %d variante(s), %d article(s) sans variante.%s',
                $run['written'], $run['variants'], $run['products'],
                $run['remaining'] > 0 ? sprintf(' Il en reste %d à générer : relancez.', $run['remaining']) : ''
            ));
        }

        return $this->redirectToRoute('admin_barcode_management');
    }

    /** Ancienne adresse de la recherche : la liste la porte désormais */
    #[Route('/admin/barcode-management/search', name: 'admin_barcode_management_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        return $this->redirectToRoute('admin_barcode_management', array_filter(['search' => trim((string) $request->query->get('search', ''))]));
    }

    /** Anciennes adresses d'impression d'un article (écran, PDF) : même planche que la sélection, bornée à `MAX_LABELS` étiquettes */
    #[Route('/admin/print-barcode/{id}/{copies}', name: 'admin_print_barcode', requirements: ['id' => '\\d+', 'copies' => '\\d+'], methods: ['GET'])]
    #[Route('/admin/pdf/print-barcode/{id}/{copies}', name: 'admin_pdf_print_barcode', requirements: ['id' => '\\d+', 'copies' => '\\d+'], methods: ['GET'])]
    public function printProduct(int $id, int $copies, Request $request): Response
    {
        $product = $this->emProvider->getEntityManager()->getRepository(Product::class)->find($id);
        if ($product === null) {
            throw $this->createNotFoundException('Produit non trouvé.');
        }
        if ($product->getBarcode() === null) {
            $this->addFlash('error', 'Ce produit n\'a pas de code-barres.');

            return $this->redirectToRoute('admin_barcode_management');
        }
        $labels = [['title' => (string) $product->getName(), 'subtitle' => null, 'barcode' => $product->getBarcode(), 'price' => null, 'copies' => max(1, min($copies, BarcodeLabelPrinter::MAX_LABELS))]];
        if ($request->attributes->get('_route') === 'admin_pdf_print_barcode') {
            return new Response($this->printer->pdf($labels), Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="etiquettes.pdf"']);
        }

        return new Response($this->printer->html($labels));
    }
}
