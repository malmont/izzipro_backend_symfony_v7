<?php
namespace App\Controller\Admin;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BarcodeManagementController extends AbstractController
{
    private AdminUrlGenerator $adminUrlGenerator;
    private EntityManagerInterface $em;

    public function __construct(AdminUrlGenerator $adminUrlGenerator, EntityManagerInterface $em)
    {
        $this->adminUrlGenerator = $adminUrlGenerator;
        $this->em = $em;
    }

    /**
     * @Route("/admin/barcode-management", name="admin_barcode_management")
     */
    public function index(Request $request): Response
{
    $products = $this->em->getRepository(Product::class)->findAll();

    if ($request->isMethod('POST')) {
        $productId = $request->request->get('product_id');
        $copies    = (int) $request->request->get('copies', 1);
        $printType = $request->request->get('printType'); // 'html' ou 'pdf'

        $product = $this->em->getRepository(Product::class)->find($productId);
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

    /**
     * Route dédiée à la génération de l'image du code-barres
     *
     * @Route("/admin/barcode-image/{id}", name="admin_barcode_image", methods={"GET"})
     */
    public function barcodeImage(int $id): Response
    {
        $product = $this->em->getRepository(Product::class)->find($id);
        if (!$product || !$product->getBarcode()) {
            throw $this->createNotFoundException('Produit ou code barre non trouvé.');
        }
        // Génération du code-barres en PNG (TYPE_CODE_128 dans cet exemple)
        $generator = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        
        return new Response($barcodeData, 200, ['Content-Type' => 'image/png']);
    }

    /**
     * Route d'aperçu et d'impression HTML du code-barres
     *
     * @Route("/admin/print-barcode/{id}/{copies}", name="admin_print_barcode")
     */
    public function printBarcode(int $id, int $copies): Response
    {
        $product = $this->em->getRepository(Product::class)->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé.');
        }

        if (!$product->getBarcode()) {
            $this->addFlash('error', 'Ce produit n\'a pas de code barre.');
            return $this->redirectToRoute('admin_barcode_management');
        }

        // Génération de l'image du code barre pour l'aperçu (encodée en base64)
        $generator   = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        $barcodeImage = base64_encode($barcodeData);

        return $this->render('admin/barcode_management/print.html.twig', [
            'product'      => $product,
            'copies'       => $copies,
            'barcodeImage' => $barcodeImage,
        ]);
    }

    /**
     * Route pour générer le PDF du code-barres
     *
     * @Route("/admin/pdf/print-barcode/{id}/{copies}", name="admin_pdf_print_barcode")
     */
    public function pdfPrintBarcode(int $id, int $copies): Response
    {
        $product = $this->em->getRepository(Product::class)->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé.');
        }

        if (!$product->getBarcode()) {
            $this->addFlash('error', 'Ce produit n\'a pas de code barre.');
            return $this->redirectToRoute('admin_barcode_management');
        }

        // Génération de l'image du code barre (base64)
        $generator   = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        $barcodeImage = base64_encode($barcodeData);

        // Rendu du template PDF
        $html = $this->renderView('admin/barcode_management/pdf_print.html.twig', [
            'product'      => $product,
            'copies'       => $copies,
            'barcodeImage' => $barcodeImage,
        ]);

        // Configuration de Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);

        // Définissez la taille de la page et l'orientation
        // Exemple : ici nous utilisons un format A4, mais vous pouvez personnaliser
        $dompdf->setPaper('A4', 'portrait');

        $dompdf->render();

        // Renvoie du PDF au navigateur (avec "Attachment" à false pour affichage en ligne)
        return new Response(
            $dompdf->stream("barcode.pdf", ["Attachment" => false]),
            200,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
 * @Route("/admin/barcode-management/search", name="admin_barcode_management_search", methods={"GET"})
 */
public function search(Request $request): Response
{
    $search = $request->query->get('search');

    // Si aucune valeur n'est fournie, on récupère tous les produits
    if (!$search) {
        $products = $this->em->getRepository(Product::class)->findAll();
    } else {
        // Utilisation du QueryBuilder pour filtrer par id ou par nom
        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p');
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
