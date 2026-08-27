<?php

namespace App\Form;

use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOptionValue;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ProductCustomizationImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tenantEm = $options['tenant_em'];

        $builder
            ->add('numberOfPieces', IntegerType::class, [
                'label' => 'Stock',
                'attr' => ['placeholder' => 'Qté']
            ])
            ->add('optionValues', EntityType::class, [ 
                'class' => ProductOptionValue::class,
                'choice_label' => 'value', 
                
                // --- C'est ici que la magie opère ---
                'multiple' => true,  // Permet de choisir plusieurs options
                'expanded' => true,  // TRUE = Affiche des Cases à cocher (Checkboxes)
                // -----------------------------------
                
                'by_reference' => false, 
                'label' => 'Options',
                'em' => $tenantEm, 
                'query_builder' => fn($repo) => $repo->createQueryBuilder('pov')->orderBy('pov.value', 'ASC'),
                
                // Optionnel : Ajout d'une classe pour le style si besoin
                // 'attr' => ['class' => 'd-flex flex-wrap gap-3'] 
            ]);

        // 1. GESTION DE L'AFFICHAGE (Aperçu)
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $imageEntity = $event->getData();
            $form = $event->getForm();

            $helpMessage = 'Format JPG/PNG requis.';
            $hasImage = $imageEntity && $imageEntity->getImagePath();

            if ($hasImage) {
                $webPath = '/assets/uploads/products/' . $imageEntity->getImagePath();
                $previewHtml = sprintf('<div style="margin-bottom: 5px;"><img src="%s" style="max-height: 80px; border-radius: 4px;" /></div>', $webPath);
                $helpMessage = $previewHtml . '<span class="text-success">Image actuelle conservée.</span>';
            }

            $form->add('imagePath', FileType::class, [ 
                'label' => 'Image de la combinaison',
                'mapped' => false, 
                'required' => !$hasImage,
                'help' => $helpMessage,
                'help_html' => true,
            ]);
        });

        // 2. GESTION DE L'UPLOAD (Déplacement physique)
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            /** @var ProductCustomizationImage $entity */
            $entity = $event->getData();
            $form = $event->getForm();

            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('imagePath')->getData();

            if ($imageFile) {
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $slugger = new AsciiSlugger();
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                try {
                    $imageFile->move(
                        'assets/uploads/products', 
                        $newFilename
                    );
                } catch (\Exception $e) {
                }

                $entity->setImagePath($newFilename);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductCustomizationImage::class,
            'tenant_em' => null, 
        ]);
        $resolver->setRequired('tenant_em');
    }
}