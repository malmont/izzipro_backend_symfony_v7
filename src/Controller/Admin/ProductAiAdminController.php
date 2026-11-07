<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\ProductAiService\ProductAiBulkService;
use App\Services\ProductAiService\ProductImageGeneratorInterface;
use App\Services\ProductAiService\ProductSheetDrafter;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « IA » pour un produit existant (07/11/2026), depuis sa fiche EasyAdmin : rédiger la description depuis ses photos, traduire en
 * français et en anglais, produire une image (packshot sans mannequin, ou mannequin) depuis une photo existante. Chaque bouton annonce
 * son coût et les crédits restants ; administrateurs du site ; retour sur la fiche produit.
 */
#[IsGranted('ROLE_ADMIN')]
class ProductAiAdminController extends AbstractController
{
    private const CSRF = 'admin_product_ai';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ProductAiBulkService $ai, private readonly ProductImageGeneratorInterface $images, private readonly LandingAiQuotaService $quota, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/produits/{id}/ia', name: 'admin_product_ai', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id): Response
    {
        $product = $this->product($id);
        $translations = [];
        foreach ($product->getTranslations() as $t) {
            $translations[] = (string) $t->getLocale();
        }
        $pictures = count($product->getPictures()) + (trim((string) $product->getImage()) !== '' ? 1 : 0);
        $aiPictures = count(array_filter($product->getPictures()->toArray(), fn ($p) => $p->getSource() === 'ai'));

        return $this->render('admin/produits/ia.html.twig', [
            'product' => $product, 'productId' => $id, 'hasDescription' => mb_strlen(trim(strip_tags((string) $product->getDescription()))) >= 40, 'translations' => $translations, 'pictures' => $pictures, 'aiPictures' => $aiPictures,
            'imagesAvailable' => $this->images->isConfigured(), 'credits' => $this->quota->credits(), 'charge' => !$this->isGranted('ROLE_SUPER_ADMIN'),
            'costs' => ['describe' => ProductSheetDrafter::COST_DRAFT, 'translate' => ProductSheetDrafter::COST_TRANSLATE, 'image' => ProductImageGeneratorInterface::COST],
            'csrfToken' => self::CSRF, 'backUrl' => $this->editUrl($id),
        ]);
    }

    #[Route('/admin/produits/{id}/ia/{action}', name: 'admin_product_ai_run', requirements: ['id' => '\d+', 'action' => 'decrire|traduire|image'], methods: ['POST'])]
    public function run(int $id, string $action, Request $request): Response
    {
        $product = $this->product($id);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

            return $this->redirect($this->selfUrl($id));
        }
        $user = $this->getUser()?->getUserIdentifier();
        $charge = !$this->isGranted('ROLE_SUPER_ADMIN');
        try {
            $note = match ($action) {
                'decrire' => $this->ai->describeProduct($product, true, $user, $charge),
                'traduire' => $this->ai->translateProduct($product, $request->request->get('overwrite') === '1', $user, $charge),
                'image' => $this->images->isConfigured() ? $this->ai->generateProductImage($product, (string) $request->request->get('style', 'packshot') === 'mannequin' ? 'mannequin' : 'packshot', $user, $charge) : throw new \RuntimeException('Les images générées seront disponibles dès que la plateforme aura branché son fournisseur d\'images : aucun crédit décompté.'),
            };
            $this->addFlash('success', sprintf('%s : %s. %s', ['decrire' => 'Description rédigée depuis les photos', 'traduire' => 'Fiche traduite', 'image' => 'Image générée'][$action], $note, $action === 'image' ? 'Vérifiez l\'image dans la galerie du produit.' : 'Relisez et ajustez sur la fiche.'));

            return $this->redirect($this->editUrl($id));
        } catch (LandingAiException $e) {
            $this->addFlash($e->getStatusCode() === 402 ? 'warning' : 'danger', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirect($this->selfUrl($id));
    }

    private function product(int $id): Product
    {
        $product = $this->emProvider->getEntityManager()->getRepository(Product::class)->find($id);
        if (!$product instanceof Product) {
            throw $this->createNotFoundException('Produit introuvable.');
        }

        return $product;
    }

    private function selfUrl(int $id): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_product_ai', ['id' => $id])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_product_ai', ['id' => $id]);
        }
    }

    private function editUrl(int $id): string
    {
        return $this->urls->unsetAll()->setController(ProductCrudController::class)->setAction(Action::EDIT)->setEntityId($id)->generateUrl();
    }
}
