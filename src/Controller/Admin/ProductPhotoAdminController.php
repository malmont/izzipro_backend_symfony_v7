<?php

namespace App\Controller\Admin;

use App\Entity\Categories;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\ProductAiService\ProductSheetDrafter;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\MediaUseCase\UploadMediaUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Créer un produit depuis une photo » (back-office, 07/11/2026) : étape 1, photos et indications ; étape 2, la fiche rédigée par l'IA à
 * corriger (prix, stock, catégorie, canaux, modes, traduction automatique) ; création puis ouverture de la fiche produit.
 */
#[IsGranted('ROLE_ADMIN')]
class ProductPhotoAdminController extends AbstractController
{
    private const CSRF = 'admin_product_photo';
    private const SESSION = 'admin_product_photo_draft';

    public function __construct(
        private readonly ProductSheetDrafter $drafter,
        private readonly ProductSheetCreator $creator,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UploadMediaUseCase $upload,
        private readonly LandingAiQuotaService $quota,
        private readonly AdminUrlGenerator $urls,
        private readonly ?EmailLocale $siteLocale = null,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    #[Route('/admin/produits/depuis-photo', name: 'admin_product_photo', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $categories = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(Categories::class)->findBy([], ['name' => 'ASC']) as $c) {
            $categories[(int) $c->getId()] = (string) $c->getName();
        }
        $language = $this->siteLocale?->language(null) ?? 'fr';
        $charge = !$this->isGranted('ROLE_SUPER_ADMIN');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

                return $this->redirect($this->selfUrl());
            }
            $step = (string) $request->request->get('step', 'draft');
            if ($step === 'draft') {
                $files = $request->files->get('photos');
                $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile)) : []);
                if ($files === []) {
                    $this->addFlash('danger', 'Ajoutez au moins une photo.');

                    return $this->redirect($this->selfUrl());
                }
                $photos = array_map(fn (UploadedFile $f) => ['data' => (string) file_get_contents($f->getPathname()), 'mime' => (string) ($f->getMimeType() ?: $f->getClientMimeType())], $files);
                try {
                    $result = $this->drafter->draft($photos, trim((string) $request->request->get('comment', '')), array_values($categories), $language, $this->getUser()?->getUserIdentifier(), $charge, $this->currency?->code());
                } catch (LandingAiException $e) {
                    $this->addFlash('danger', $e->getMessage());

                    return $this->redirect($this->selfUrl());
                }
                $pictures = [];
                foreach ($files as $f) {
                    try {
                        $pictures[] = $this->upload->execute($f, $result['draft']['name'], false, $request->getSchemeAndHttpHost())->url;
                    } catch (HttpException $e) {
                        $this->addFlash('warning', 'Une photo n\'a pas pu être déposée : ' . $e->getMessage());
                    }
                }
                $draft = $result['draft'] + ['pictures' => $pictures, 'categoryId' => (int) $request->request->get('categoryId', 0) ?: (array_search($result['draft']['categoryName'] ?? '', $categories, true) ?: null),
                    'channels' => ['web' => $request->request->get('channel_web', '1') === '1', 'pos' => $request->request->get('channel_pos') === '1', 'landing' => $request->request->get('channel_landing') === '1'],
                    'modes' => ['sale' => $request->request->get('mode_sale', '1') === '1', 'rental' => $request->request->get('mode_rental') === '1', 'subscription' => $request->request->get('mode_subscription') === '1']];
                $session->set(self::SESSION, $draft);
                $this->addFlash('success', 'Fiche rédigée par l\'IA : corrigez-la, fixez le prix puis créez le produit.');

                return $this->redirect($this->selfUrl());
            }
            if ($step === 'create') {
                $draft = (array) $session->get(self::SESSION, []);
                $input = [
                    'name' => (string) $request->request->get('name', ''), 'description' => (string) $request->request->get('description', ''), 'moreInformations' => (string) $request->request->get('moreInformations', ''),
                    'tags' => (string) $request->request->get('tags', ''), 'language' => $language, 'priceCents' => (int) round(((float) str_replace(',', '.', (string) $request->request->get('price', '0'))) * 100),
                    'stockQuantity' => (int) $request->request->get('stock', 0), 'categoryIds' => array_values(array_filter([(int) $request->request->get('categoryId', 0)])), 'pictures' => (array) ($draft['pictures'] ?? []),
                    'channels' => ['web' => $request->request->get('channel_web') === '1', 'pos' => $request->request->get('channel_pos') === '1', 'landing' => $request->request->get('channel_landing') === '1'],
                    'modes' => ['sale' => $request->request->get('mode_sale') === '1', 'rental' => $request->request->get('mode_rental') === '1', 'subscription' => $request->request->get('mode_subscription') === '1'],
                    'barcode' => (string) $request->request->get('barcode', ''),
                ];
                $translated = [];
                if ($request->request->get('translate') === '1' && trim($input['name']) !== '') {
                    try {
                        $t = $this->drafter->translate(['name' => $input['name'], 'description' => $input['description'], 'moreInformations' => $input['moreInformations'], 'tags' => $input['tags']], $language, array_values(array_diff(array_keys(ProductSheetDrafter::LANGUAGES), [$language])), $this->getUser()?->getUserIdentifier(), $charge);
                        $input['translations'] = $t['translations'];
                        $translated = array_keys($t['translations']);
                    } catch (LandingAiException $e) {
                        $this->addFlash('warning', 'Traduction impossible (' . $e->getMessage() . ') : le produit est créé dans la langue du site seulement.');
                    }
                }
                $result = $this->creator->create($input, $this->getUser()?->getUserIdentifier());
                if ($result['status'] !== 201) {
                    $this->addFlash('danger', $result['body']['message']);
                    $session->set(self::SESSION, array_merge($draft, ['name' => $input['name'], 'description' => $input['description'], 'moreInformations' => $input['moreInformations'], 'tags' => $input['tags'], 'suggestedPriceCents' => $input['priceCents'] ?: null, 'categoryId' => $input['categoryIds'][0] ?? null, 'channels' => $input['channels'], 'modes' => $input['modes']]));

                    return $this->redirect($this->selfUrl());
                }
                $session->remove(self::SESSION);
                $this->addFlash('success', sprintf('Produit « %s » créé (code %s)%s. Vérifiez la fiche, les variantes (tailles, couleurs) et le poids d\'expédition.', $input['name'], $result['body']['code'], $translated !== [] ? ', traduit en ' . implode(', ', $translated) : ''));

                return $this->redirect($this->urls->unsetAll()->setController(ProductCrudController::class)->setAction(Action::EDIT)->setEntityId($result['body']['productId'])->generateUrl());
            }
            if ($step === 'reset') {
                $session->remove(self::SESSION);

                return $this->redirect($this->selfUrl());
            }
        }
        $draft = $session->get(self::SESSION);

        return $this->render('admin/produits/depuis_photo.html.twig', ['draft' => is_array($draft) ? $draft : null, 'categories' => $categories, 'language' => $language, 'otherLanguages' => array_values(array_diff(array_keys(ProductSheetDrafter::LANGUAGES), [$language])),
            'credits' => $this->quota->credits(), 'currency' => $this->currency?->code() ?? 'CAD', 'csrfToken' => self::CSRF, 'charge' => $charge, 'costDraft' => ProductSheetDrafter::COST_DRAFT, 'costTranslate' => ProductSheetDrafter::COST_TRANSLATE]);
    }

    private function selfUrl(): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_product_photo')->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_product_photo');
        }
    }
}
