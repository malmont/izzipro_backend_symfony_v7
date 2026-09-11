<?php

namespace App\Controller\Admin;

use App\Entity\Video;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use App\Form\VideoTranslationType;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class VideoCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Video::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Vidéo')
            ->setEntityLabelInPlural('Vidéos')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('titre', 'Titre de la vidéo');
        yield ImageField::new('imageDeFond', 'Miniature / Image de couverture')
            ->setBasePath('assets/uploads/slider/')
            ->setUploadDir('public/assets/uploads/slider/')
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setRequired(false);

        yield TextField::new('videoFile', 'Téléverser un fichier vidéo (MP4)')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'video/mp4,video/webm,video/quicktime'],
                'help' => '📁 Téléversez un fichier vidéo MP4 depuis votre ordinateur.'
            ])
            ->onlyOnForms();

        yield TextField::new('lienVideo', 'Ou Lien vidéo externe (YouTube, Vimeo, distant)')
            ->setRequired(false)
            ->setHelp('🔗 Si vous ne téléversez pas de fichier, collez ici un lien YouTube, Vimeo ou MP4.');

        yield CollectionField::new('translations', 'Traductions (optionnel)')
            ->setEntryType(VideoTranslationType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->processVideoFileUpload($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->processVideoFileUpload($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function processVideoFileUpload(mixed $entityInstance): void
    {
        if (!$entityInstance instanceof Video) {
            return;
        }

        $request = $this->getContext()?->getRequest();
        if (!$request) {
            return;
        }

        $files = $request->files->all();
        $videoForm = $files['Video'] ?? [];
        $uploadedFile = $videoForm['videoFile'] ?? null;

        if ($uploadedFile instanceof UploadedFile && $uploadedFile->isValid()) {
            $projectDir = $this->getParameter('kernel.project_dir');
            $uploadDir = $projectDir . '/public/assets/uploads/videos';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalFilename);
            $newFilename = $safeFilename . '-' . uniqid() . '.' . ($uploadedFile->guessExtension() ?: 'mp4');

            $uploadedFile->move($uploadDir, $newFilename);
            $entityInstance->setLienVideo('/assets/uploads/videos/' . $newFilename);
        }
    }
}
