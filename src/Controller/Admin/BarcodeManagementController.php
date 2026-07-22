<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Services\TenantEntityManagerProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
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
        TenantEntityManagerProvider $emProvider
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
        ]);
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
        ]);
    }
}