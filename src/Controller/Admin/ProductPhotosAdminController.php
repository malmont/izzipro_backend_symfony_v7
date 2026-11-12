<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\ProductAiService\ProductAiBulkService;
use App\Services\ProductAiService\ProductImageGeneratorInterface;
use App\Services\ProductAiService\ProductPhotoService;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Photos » d'un produit (07/11/2026) : galerie, dépôt de plusieurs photos, photo principale, suppression, et génération par l'IA
 * avec options (vue : face, dos, détail, porté ; environnement : studio, rue, intérieur, nature ; consigne libre). Administrateurs du site.
 */
#[IsGranted('ROLE_ADMIN')]
class ProductPhotosAdminController extends AbstractController
{
    private const CSRF = 'admin_product_photos';
    public const VIEWS = ['face' => 'De face', 'back' => 'De dos', 'detail' => 'Détail (matière, étiquette)', 'worn' => 'Porté par une personne', 'packshot' => 'Packshot, produit seul'];
    public const ENVIRONMENTS = ['studio' => 'Studio, fond blanc', 'street' => 'Rue, lumière naturelle', 'indoor' => 'Intérieur chaleureux', 'nature' => 'Nature, plein air'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ProductPhotoService $photos, private readonly ProductAiBulkService $ai, private readonly ProductImageGeneratorInterface $images, private readonly LandingAiQuotaService $quota, private readonly AdminUrlGenerator $urls, private readonly ?\App\Services\MediaUrlResolver $media = null, private readonly ?\App\Services\Http\ClientSafeError $safeError = null)
    {
    }

    #[Route('/admin/produits/{id}/photos', name: 'admin_product_photos', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id, Request $request): Response
    {
        $product = $this->product($id);
        $host = $this->media?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();

        return $this->render('admin/produits/photos.html.twig', [
            'product' => $product, 'productId' => $id, 'gallery' => $this->photos->gallery($product, $host), 'views' => self::VIEWS, 'environments' => self::ENVIRONMENTS,
            'imagesAvailable' => $this->images->isConfigured(), 'credits' => $this->quota->credits(), 'charge' => !$this->isGranted('ROLE_SUPER_ADMIN'), 'imageCost' => ProductImageGeneratorInterface::COST,
            'csrfToken' => self::CSRF, 'backUrl' => $this->urls->unsetAll()->setController(ProductCrudController::class)->setAction(Action::EDIT)->setEntityId($id)->generateUrl(), 'maxFiles' => ProductPhotoService::MAX_FILES,
        ]);
    }

    #[Route('/admin/produits/{id}/photos/{action}', name: 'admin_product_photos_act', requirements: ['id' => '\d+', 'action' => 'ajouter|principale|supprimer|generer'], methods: ['POST'])]
    public function act(int $id, string $action, Request $request): Response
    {
        $product = $this->product($id);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

            return $this->redirect($this->selfUrl($id));
        }
        $by = $this->getUser()?->getUserIdentifier();
        switch ($action) {
            case 'ajouter':
                $files = $request->files->get('photos');
                $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? array_values($files) : []);
                $result = $this->photos->add($product, $files, $by);
                $this->addFlash($result['added'] > 0 ? 'success' : 'warning', sprintf('%d photo(s) ajoutée(s).%s', $result['added'], $result['errors'] !== [] ? ' Refusées : ' . implode(' ; ', $result['errors']) : ''));
                break;
            case 'principale':
                $this->addFlash($this->photos->setMain($product, (int) $request->request->get('pictureId')) ? 'success' : 'danger', 'Photo principale mise à jour.');
                break;
            case 'supprimer':
                $this->addFlash($this->photos->remove($product, (int) $request->request->get('pictureId')) ? 'success' : 'danger', 'Photo retirée de la galerie.');
                break;
            case 'generer':
                try {
                    if (!$this->images->isConfigured()) {
                        throw new \RuntimeException('Les images générées seront disponibles dès que la plateforme aura branché son fournisseur d\'images : aucun crédit décompté.');
                    }
                    $view = (string) $request->request->get('view', 'packshot');
                    $environment = (string) $request->request->get('environment', 'studio');
                    $note = $this->ai->generateProductImage($product, $view === 'worn' ? 'mannequin' : 'packshot', $by, !$this->isGranted('ROLE_SUPER_ADMIN'), ['view' => isset(self::VIEWS[$view]) ? $view : 'packshot', 'environment' => isset(self::ENVIRONMENTS[$environment]) ? $environment : 'studio', 'comment' => mb_substr(trim((string) $request->request->get('comment', '')), 0, 500)]);
                    $this->addFlash('success', 'Image générée : ' . $note . '. Elle est dans la galerie ; faites-en la photo principale si elle vous plaît.');
                } catch (LandingAiException $e) {
                    $this->addFlash($e->getStatusCode() === 402 ? 'warning' : 'danger', $e->getMessage());
                } catch (\Throwable $e) {
                    $this->addFlash('warning', $this->safeError->message($e, 'EasyAdmin : image générée d\'un article')); // lot ERREURS-1 : texte neutre pour une panne imprévue
                }
                break;
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
            return $this->urls->unsetAll()->setRoute('admin_product_photos', ['id' => $id])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_product_photos', ['id' => $id]);
        }
    }
}
