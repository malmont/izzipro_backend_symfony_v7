<?php

namespace App\Controller\Admin;

use App\Entity\SharedMedia;
use App\Services\MediaUrlResolver;
use App\Services\SharedMedia\SharedMediaTypes;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\File;

class SharedMediaCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

    private string $publicUploadDir;
    private string $privateUploadDir;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        private MediaUrlResolver $mediaUrlResolver,
        private RequestStack $requestStack,
        private AdminUrlGenerator $adminUrlGenerator,
        string $projectDir
    ) {
        parent::__construct($emProvider);
        $this->publicUploadDir = rtrim($projectDir, '/') . '/var/storage/public_bucket/assets/uploads/shared';
        $this->privateUploadDir = rtrim($projectDir, '/') . '/var/storage/private_media';
    }

    public static function getEntityFqcn(): string
    {
        return SharedMedia::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Fichier Partagé')
            ->setEntityLabelInPlural('Médiathèque & Partage de Fichiers')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['titre', 'originalFilename', 'description', 'accessKey'])
            // Actions en ligne : la liste est plus large que l'écran (colonne du lien de partage), elle défile donc
            // horizontalement et son cadre coupait le menu déroulant des actions (Modifier invisible)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $regenerateKey = Action::new('regenerateKey', 'Régénérer la clé', 'fas fa-sync-alt')
            ->linkToUrl(fn (SharedMedia $media) => $this->csrfActionUrl('regenerateAccessKeyAction', $media->getId()))
            ->setCssClass('btn btn-outline-warning btn-sm')
            ->displayIf(fn (SharedMedia $media) => $media->isPrivate());

        // Sur la liste : icônes seules (libellé en infobulle), pour ne pas élargir encore le tableau
        $icon = fn (string $icon, string $title) => fn (Action $action) => $action->setIcon($icon)->setLabel(false)->setHtmlAttributes(['title' => $title]);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $regenerateKey)
            ->add(Crud::PAGE_DETAIL, $regenerateKey)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, $icon('fas fa-eye', 'Consulter'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, $icon('fas fa-pen', 'Modifier'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, $icon('fas fa-trash', 'Supprimer'));
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = ($pageName === Crud::PAGE_NEW);
        $isIndex = ($pageName === Crud::PAGE_INDEX);
        $isDetail = ($pageName === Crud::PAGE_DETAIL);

        yield IdField::new('id', '#')->onlyOnIndex();

        // 1. Titre & Libellé
        yield TextField::new('titre', 'Titre du fichier')
            ->setHelp('Donnez un nom explicite (ex: "Brochure 2026", "Vidéo Démo", "Logo HD")');

        // 2. Mode de Visibilité (Public ou Privé par Clé)
        yield ChoiceField::new('visibility', 'Mode d\'accès')
            ->setChoices([
                '🟢 Public (Lien direct CDN / Web)' => SharedMedia::VISIBILITY_PUBLIC,
                '🔒 Privé (Lien sécurisé avec clé d\'accès)' => SharedMedia::VISIBILITY_PRIVATE,
            ])
            ->renderAsBadges([
                SharedMedia::VISIBILITY_PUBLIC => 'success',
                SharedMedia::VISIBILITY_PRIVATE => 'warning',
            ])
            ->setHelp('En mode Public, le document est accessible directement sans restriction. En mode Privé, il est stocké hors web et délivré uniquement avec la clé secrète.');

        // 3. Fichier physique à téléverser
        $fileConstraints = [
            new File([
                'maxSize' => '100M',
                'maxSizeMessage' => 'Le fichier est trop volumineux (max 100 Mo).',
                // Extension ET type réel du contenu : un fichier HTML renommé en .pdf est refusé
                'extensions' => SharedMediaTypes::uploadConstraint(),
                'extensionsMessage' => 'Format non accepté ou ne correspondant pas au contenu du fichier ({{ extension }}). Formats acceptés : {{ extensions }}.',
            ])
        ];

        yield TextField::new('mediaFile', $isNew ? 'Fichier à téléverser' : 'Remplacer le fichier (optionnel)')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => $isNew,
                'constraints' => $fileConstraints,
                'help' => 'Formats acceptés : PDF, Word, Excel, Images (JPG, PNG, WEBP, SVG), Vidéos (MP4, WEBM). Max 100 Mo.',
            ])
            ->onlyOnForms();

        // 4. Date d'expiration optionnelle pour les liens privés
        yield DateTimeField::new('expiresAt', 'Date d\'expiration (optionnel)')
            ->setHelp('Pour les fichiers privés : le lien deviendra automatiquement inactif après cette date.')
            ->hideOnIndex();

        // 5. Description / Remarques internes
        yield TextareaField::new('description', 'Description / Notes')
            ->setHelp('Notes internes sur l\'usage de ce document.')
            ->hideOnIndex();

        // 6. Colonnes informatives pour la Vue Liste (Index)
        // Colonnes calculées : chacune s'appuie sur une propriété réelle et toujours renseignée de l'entité, la valeur
        // affichée vient de formatValue. Sur une propriété inexistante (typeBadge, fileInfo…), EasyAdmin affiche
        // « Inaccessible » et n'appelle pas formatValue.
        if ($isIndex) {
            yield TextField::new('mediaType', 'Type')
                ->setSortable(false)
                ->onlyOnIndex()
                ->formatValue(function ($val, SharedMedia $media) {
                    if ($media->isImage()) {
                        return '<span class="badge bg-info text-white"><i class="fas fa-image mr-1"></i>Image</span>';
                    } elseif ($media->isVideo()) {
                        return '<span class="badge bg-danger text-white"><i class="fas fa-video mr-1"></i>Vidéo</span>';
                    } elseif ($media->isPdf()) {
                        return '<span class="badge bg-danger text-white"><i class="fas fa-file-pdf mr-1"></i>PDF</span>';
                    }
                    return '<span class="badge bg-secondary text-white"><i class="fas fa-file mr-1"></i>Document</span>';
                });

            yield TextField::new('filename', 'Fichier & Taille')
                ->setSortable(false)
                ->onlyOnIndex()
                ->formatValue(function ($val, SharedMedia $media) {
                    $orig = htmlspecialchars($media->getOriginalFilename() ?: $media->getFilename(), ENT_QUOTES, 'UTF-8');
                    $size = $media->getFormattedFileSize();
                    return sprintf('<div><strong>%s</strong></div><small class="text-muted">%s</small>', $orig, $size);
                });

            yield TextField::new('visibility', 'Lien de Partage (1-Clic)')
                ->setSortable(false)
                ->onlyOnIndex()
                ->formatValue(function ($val, SharedMedia $media) {
                    $request = $this->requestStack->getCurrentRequest();
                    $fallbackHost = $request ? $request->getSchemeAndHttpHost() : null;
                    $url = $this->mediaUrlResolver->resolveSharedMediaUrl($media, $fallbackHost);
                    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

                    $keyBadge = $media->isPrivate() 
                        ? '<span class="badge bg-warning text-dark ml-1" title="Protégé par clé d\'accès"><i class="fas fa-key"></i> Clé active</span>'
                        : '<span class="badge bg-light text-success ml-1"><i class="fas fa-globe"></i> Direct</span>';

                    return sprintf('
                        <div class="d-flex align-items-center" style="max-width: 320px;">
                            <input type="text" class="form-control form-control-sm mr-2" value="%s" readonly style="font-size: 11px; background: #f8f9fa;" onclick="this.select();">
                            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm mr-1" title="Copier le lien" onclick="navigator.clipboard.writeText(\'%s\'); this.innerHTML=\'<i class=\"fas fa-check text-success\"></i>\'; setTimeout(() => this.innerHTML=\'<i class=\"fas fa-copy\"></i>\', 2000);">
                                <i class="fas fa-copy"></i>
                            </button>
                            <a href="%s" target="_blank" class="btn btn-sm btn-light shadow-sm" title="Ouvrir dans un nouvel onglet">
                                <i class="fas fa-external-link-alt text-primary"></i>
                            </a>
                        </div>
                        <div class="mt-1 small">%s</div>
                    ', $escapedUrl, $escapedUrl, $escapedUrl, $keyBadge);
                });

            // nombre entier : un TextField le refuse (« can't be converted into a string »), la liste ne s'ouvrait plus
            yield IntegerField::new('downloadCount', 'Vues')
                ->onlyOnIndex()
                ->formatValue(fn ($val) => sprintf('<span class="badge bg-light text-dark font-weight-bold"><i class="fas fa-eye text-muted mr-1"></i>%d</span>', (int) $val));

            yield DateTimeField::new('createdAt', 'Créé le')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->onlyOnIndex();
        }

        // 7. Vue Détail complète
        if ($isDetail) {
            yield TextField::new('originalFilename', 'Nom d\'origine');
            yield TextField::new('mimeType', 'Type MIME');
            yield TextField::new('mediaType', 'Taille')->formatValue(fn ($val, SharedMedia $media) => $media->getFormattedFileSize());
            yield IntegerField::new('downloadCount', 'Nombre de consultations');

            yield TextField::new('accessKey', 'Clé d\'accès privée')
                ->formatValue(function ($val, SharedMedia $media) {
                    if (!$media->isPrivate()) {
                        return '<span class="text-muted">Non applicable (fichier public)</span>';
                    }
                    return sprintf('<code>%s</code> <small class="text-muted">(Token sécurisé de 64 caractères)</small>', htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8'));
                });

            yield TextField::new('visibility', 'Lien de partage complet')
                ->formatValue(function ($val, SharedMedia $media) {
                    $request = $this->requestStack->getCurrentRequest();
                    $fallbackHost = $request ? $request->getSchemeAndHttpHost() : null;
                    $url = $this->mediaUrlResolver->resolveSharedMediaUrl($media, $fallbackHost);
                    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

                    return sprintf('
                        <div class="input-group mb-3" style="max-width: 650px;">
                            <input type="text" class="form-control" value="%s" readonly id="shareUrlDetailInput">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button" onclick="navigator.clipboard.writeText(\'%s\'); this.innerText=\'Copié !\'; setTimeout(() => this.innerText=\'Copier\', 2000);">Copier</button>
                                <a href="%s" target="_blank" class="btn btn-outline-secondary">Ouvrir</a>
                            </div>
                        </div>
                    ', $escapedUrl, $escapedUrl, $escapedUrl);
                });

            yield TextField::new('filename', 'Extraits de code pour intégration')
                ->formatValue(function ($val, SharedMedia $media) {
                    $request = $this->requestStack->getCurrentRequest();
                    $fallbackHost = $request ? $request->getSchemeAndHttpHost() : null;
                    $url = htmlspecialchars($this->mediaUrlResolver->resolveSharedMediaUrl($media, $fallbackHost), ENT_QUOTES, 'UTF-8');
                    $title = htmlspecialchars((string) $media->getTitre(), ENT_QUOTES, 'UTF-8');

                    $htmlSnippet = '';
                    if ($media->isImage()) {
                        $htmlSnippet = sprintf('&lt;img src="%s" alt="%s" style="max-width: 100%%; height: auto;" /&gt;', $url, $title);
                    } elseif ($media->isVideo()) {
                        $htmlSnippet = sprintf('&lt;video src="%s" controls style="max-width: 100%%;"&gt;Votre navigateur ne supporte pas la vidéo.&lt;/video&gt;', $url);
                    } else {
                        $htmlSnippet = sprintf('&lt;a href="%s" target="_blank" download&gt;Télécharger %s&lt;/a&gt;', $url, $title);
                    }

                    $mdSnippet = sprintf('[%s](%s)', $title, $url);

                    return sprintf('
                        <div class="p-3 bg-light rounded border" style="max-width: 750px;">
                            <div class="mb-2"><strong>Balise HTML prête à l\'emploi :</strong></div>
                            <pre class="bg-white p-2 border rounded"><code>%s</code></pre>
                            <div class="mt-2 mb-1"><strong>Lien Markdown :</strong></div>
                            <pre class="bg-white p-2 border rounded"><code>%s</code></pre>
                        </div>
                    ', $htmlSnippet, $mdSnippet);
                });
        }
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof SharedMedia) {
            $this->handleFileUpload($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof SharedMedia) {
            $this->handleFileUpload($entityInstance);
            $entityInstance->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof SharedMedia) {
            $this->removePhysicalFile($entityInstance);
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    /**
     * Action EasyAdmin pour régénérer la clé d'accès d'un fichier privé.
     */
    public function regenerateAccessKeyAction(AdminContext $context): RedirectResponse
    {
        if (!$this->isCsrfActionValid($context, 'regenerateAccessKeyAction')) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');
            return $this->redirect($this->adminUrlGenerator->setAction(Crud::PAGE_INDEX)->generateUrl());
        }

        $media = $context->getEntity()->getInstance();
        if (!$media instanceof SharedMedia) {
            $this->addFlash('danger', 'Document introuvable.');
            return $this->redirect($this->adminUrlGenerator->setAction(Crud::PAGE_INDEX)->generateUrl());
        }

        if (!$media->isPrivate()) {
            $this->addFlash('warning', 'La régénération de clé ne s\'applique qu\'aux documents privés.');
            return $this->redirect($this->adminUrlGenerator->setAction(Crud::PAGE_INDEX)->generateUrl());
        }

        $oldKey = $media->getAccessKey();
        $newKey = $media->regenerateAccessKey();
        $media->setUpdatedAt(new \DateTimeImmutable());

        $em = $this->emProvider->getEntityManager();
        $em->flush();

        $this->addFlash('success', '🔑 Nouvelle clé d\'accès générée avec succès ! L\'ancien lien privé est désormais révoqué et inactif.');

        return $this->redirect($context->getReferrer() ?: $this->adminUrlGenerator->setAction(Crud::PAGE_INDEX)->generateUrl());
    }

    /**
     * Traite l'upload, la validation et le stockage sécurisé du fichier.
     */
    private function handleFileUpload(SharedMedia $media): void
    {
        $request = $this->getContext()?->getRequest();
        if (!$request) {
            return;
        }

        $files = $request->files->all();
        $formData = $files['SharedMedia'] ?? [];
        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $formData['mediaFile'] ?? null;

        // 1. Déploiement d'un nouveau fichier téléversé
        if ($uploadedFile instanceof UploadedFile && $uploadedFile->isValid()) {
            $originalName = $uploadedFile->getClientOriginalName();
            $extension = strtolower($uploadedFile->getClientOriginalExtension() ?: (string) $uploadedFile->guessExtension());

            // Sécurité (en plus de la contrainte du formulaire) : extension autorisée ET type réel cohérent
            if (!SharedMediaTypes::isAllowed($extension, $uploadedFile->getMimeType())) {
                throw new \InvalidArgumentException(sprintf('Le fichier ".%s" (%s) n\'est pas autorisé.', $extension, $uploadedFile->getMimeType()));
            }

            // Détection du mediaType
            $mediaType = $this->detectMediaType($extension, $uploadedFile->getMimeType());
            $media->setMediaType($mediaType);
            $media->setOriginalFilename($originalName);
            // Type normalisé d'après l'extension validée (jamais le type détecté, qui pourrait être text/html)
            $media->setMimeType(SharedMediaTypes::servedMimeType('fichier.' . $extension));
            $media->setFileSize($uploadedFile->getSize());

            // Si c'est privé et sans clé, on la génère
            if ($media->isPrivate() && empty($media->getAccessKey())) {
                $media->regenerateAccessKey();
            }

            // Génération d'un nom unique sécurisé (anti-path traversal)
            $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $safeBase = substr($safeBase ?: 'file', 0, 30);
            $newFilename = sprintf('%s_%s.%s', $safeBase, bin2hex(random_bytes(8)), $extension);

            $targetDir = $media->isPrivate() ? $this->privateUploadDir : $this->publicUploadDir;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Supprimer l'ancien fichier s'il existait
            $this->removePhysicalFile($media);

            // Déplacement sécurisé vers le répertoire cible
            $uploadedFile->move($targetDir, $newFilename);
            $media->setFilename($newFilename);

        } elseif ($media->getId() !== null && $media->getFilename()) {
            // 2. Gestion du basculement Public <-> Privé d'un fichier existant sans ré-upload
            $this->syncStorageLocationOnVisibilityChange($media);
        }
    }

    /**
     * Déplace le fichier physique si la visibilité (Public / Privé) a été modifiée.
     */
    private function syncStorageLocationOnVisibilityChange(SharedMedia $media): void
    {
        $filename = basename((string) $media->getFilename());
        $publicPath = $this->publicUploadDir . '/' . $filename;
        $privatePath = $this->privateUploadDir . '/' . $filename;

        if ($media->isPrivate()) {
            // Le média est devenu privé : s'il est dans le bucket public, on le déplace vers private_media
            if (is_file($publicPath)) {
                if (!is_dir($this->privateUploadDir)) {
                    mkdir($this->privateUploadDir, 0755, true);
                }
                rename($publicPath, $privatePath);
            }
            if (empty($media->getAccessKey())) {
                $media->regenerateAccessKey();
            }
        } else {
            // Le média est devenu public : s'il est dans private_media, on le déplace vers le bucket public
            if (is_file($privatePath)) {
                if (!is_dir($this->publicUploadDir)) {
                    mkdir($this->publicUploadDir, 0755, true);
                }
                rename($privatePath, $publicPath);
            }
        }
    }

    /**
     * Supprime le fichier physique du disque lors d'un remplacement ou suppression.
     */
    private function removePhysicalFile(SharedMedia $media): void
    {
        $filename = basename((string) $media->getFilename());
        if (empty($filename)) {
            return;
        }

        $publicPath = $this->publicUploadDir . '/' . $filename;
        if (is_file($publicPath)) {
            @unlink($publicPath);
        }

        $privatePath = $this->privateUploadDir . '/' . $filename;
        if (is_file($privatePath)) {
            @unlink($privatePath);
        }
    }

    private function detectMediaType(string $extension, ?string $mimeType): string
    {
        $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        $videoExts = ['mp4', 'webm', 'mov'];

        if (in_array($extension, $imageExts, true) || str_starts_with((string) $mimeType, 'image/')) {
            return SharedMedia::TYPE_IMAGE;
        }

        if (in_array($extension, $videoExts, true) || str_starts_with((string) $mimeType, 'video/')) {
            return SharedMedia::TYPE_VIDEO;
        }

        return SharedMedia::TYPE_DOCUMENT;
    }
}
