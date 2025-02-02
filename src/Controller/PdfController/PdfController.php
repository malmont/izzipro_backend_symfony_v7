<?php
// src/Controller/PdfController.php
namespace App\PdfController\Controller;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PdfController extends AbstractController
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * @Route("/pdf/print-barcode/{id}/{copies}", name="pdf_print_barcode")
     */
    public function printBarcodePdf(int $id, int $copies): Response
    {
        // Récupérer le produit par son ID
        $product = $this->em->getRepository(Product::class)->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé.');
        }

        // Vous pouvez ici générer votre image du code barre (par exemple via Picqer)
        // Exemple :
        // $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
        // $barcodeData = $generator->getBarcode($product->getBarcode(), $generator::TYPE_CODE_128);
        // $barcodeImage = base64_encode($barcodeData);
        //
        // Pour cet exemple, nous supposerons que votre template reçoit déjà
        // la variable "barcodeImage".

        // Rendu du template PDF (créez le fichier templates/pdf/print_barcode.html.twig)
        $html = $this->renderView('pdf/print_barcode.html.twig', [
            'product'      => $product,
            'copies'       => $copies,
            // 'barcodeImage' => $barcodeImage,  // Décommentez si vous générez l'image
        ]);

        // Configuration de Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);

        // Définir la taille et l'orientation du papier
        // Par exemple, pour un format A4 en portrait :
        $dompdf->setPaper('A4', 'portrait');

        // Pour un format personnalisé (par exemple 50mm x 30mm) :
        // $dompdf->setPaper([0, 0, 50, 30], 'portrait');
        // Remarque : les dimensions ici sont en points (1 point = 0.3528 mm)
        // Vous pouvez convertir vos dimensions en points si nécessaire.

        // Générer le PDF à partir du HTML
        $dompdf->render();

        // Renvoyer le PDF au navigateur
        return new Response(
            $dompdf->stream("barcode.pdf", ["Attachment" => false]),
            200,
            ['Content-Type' => 'application/pdf']
        );
    }
}
