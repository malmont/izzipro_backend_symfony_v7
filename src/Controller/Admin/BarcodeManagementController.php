<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BarcodeManagementController extends AbstractController
{
    private const MAX_COPIES = 500;

    private TenantEntityManagerProvider $emProvider;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        AdminUrlGenerator $adminUrlGenerator,
        TenantEntityManagerProvider $emProvider,
        private readonly CatalogBarcodeService $barcodes, // lot CB-1 (15/11/2026, `docs/codes-barres.md`)
        private readonly LoggerInterface $logger,
    ) {
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->emProvider = $emProvider;
    }

    #[Route('/admin/barcode-management', name: 'admin_barcode_management')]
    public function index(Request $request): Response
    {
        $em = $this->emProvider->getEntityManager();
        $products = $em->getRepository(Product::class)->findAll();

        if ($request->isMethod('POST')) {
            $productId = $request->request->get('product_id');
            $copies    = (int) $request->request->get('copies', 1);
            $printType = $request->request->get('printType'); // 'html' ou 'pdf'

            $product = $em->getRepository(Product::class)->find($productId);
            if (!$product) {
                $this->addFlash('error', 'Produit introuvable.');
                return $this->redirectToRoute('admin_barcode_management');
            }

            if ($printType === 'html') {
                return $this->redirectToRoute('admin_print_barcode', [
                    'id'     => $product->getId(),
                    'copies' => $copies,
                ]);
            } else {
                return $this->redirectToRoute('admin_pdf_print_barcode', [
                    'id'     => $product->getId(),
                    'copies' => $copies,
                ]);
            }
        }

        return $this->render('admin/barcode_management/index.html.twig', [
            'products' => $products,
            'missing' => $this->barcodes->missingCount(),
            'bulkLimit' => CatalogBarcodeService::BULK_LIMIT,
        ]);
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
        if ($run['written'] === 0) {
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

    #[Route('/admin/barcode-image/{id}', name: 'admin_barcode_image', methods: ['GET'])]
    public function barcodeImage(int $id): Response
    {
        $em = $this->emProvider->getEntityManager();
        $product = $em->getRepository(Product::class)->find($id);
        if (!$product || !$product->getBarcode()) {
            throw $this->createNotFoundException('Produit ou code barre non trouvé.');
        }

        $generator = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        
        return new Response($barcodeData, 200, ['Content-Type' => 'image/png']);
    }

    #[Route('/admin/print-barcode/{id}/{copies}', name: 'admin_print_barcode')]
    public function printBarcode(int $id, int $copies): Response
    {
        // Borne le nombre d'étiquettes : un nombre démesuré bloquerait le serveur (rendu HTML/PDF)
        $copies = max(1, min($copies, self::MAX_COPIES));

        $em = $this->emProvider->getEntityManager();
        $product = $em->getRepository(Product::class)->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé.');
        }

        if (!$product->getBarcode()) {
            $this->addFlash('error', 'Ce produit n\'a pas de code barre.');
            return $this->redirectToRoute('admin_barcode_management');
        }

        $generator   = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        $barcodeImage = base64_encode($barcodeData);

        return $this->render('admin/barcode_management/print.html.twig', [
            'product'      => $product,
            'copies'       => $copies,
            'barcodeImage' => $barcodeImage,
        ]);
    }

    #[Route('/admin/pdf/print-barcode/{id}/{copies}', name: 'admin_pdf_print_barcode')]
    public function pdfPrintBarcode(int $id, int $copies): Response
    {
        // Borne le nombre d'étiquettes : un nombre démesuré bloquerait le serveur (rendu HTML/PDF)
        $copies = max(1, min($copies, self::MAX_COPIES));

        $em = $this->emProvider->getEntityManager();
        $product = $em->getRepository(Product::class)->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé.');
        }

        if (!$product->getBarcode()) {
            $this->addFlash('error', 'Ce produit n\'a pas de code barre.');
            return $this->redirectToRoute('admin_barcode_management');
        }

        $generator   = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        $barcodeImage = base64_encode($barcodeData);

        $html = $this->renderView('admin/barcode_management/pdf_print.html.twig', [
            'product'      => $product,
            'copies'       => $copies,
            'barcodeImage' => $barcodeImage,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);

        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->stream("barcode.pdf", ["Attachment" => false]),
            200,
            ['Content-Type' => 'application/pdf']
        );
    }

    #[Route('/admin/barcode-management/search', name: 'admin_barcode_management_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $em = $this->emProvider->getEntityManager();
        $search = $request->query->get('search');

        if (!$search) {
            $products = $em->getRepository(Product::class)->findAll();
        } else {
            $qb = $em->getRepository(Product::class)->createQueryBuilder('p');
            $expr = $qb->expr()->orX(
                'p.id = :id',
                'p.name LIKE :name'
            );
            $qb->where($expr)
               ->setParameter('id', (int) $search)
               ->setParameter('name', '%' . $search . '%');

            $products = $qb->getQuery()->getResult();
        }

        return $this->render('admin/barcode_management/index.html.twig', [
            'products' => $products,
            'missing' => $this->barcodes->missingCount(),
            'bulkLimit' => CatalogBarcodeService::BULK_LIMIT,
        ]);
    }
}