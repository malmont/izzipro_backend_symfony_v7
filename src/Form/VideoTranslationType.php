<?php
namespace App\Form;

use App\Entity\VideoTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('language', ChoiceType::class, [
                'label' => 'Langue',
                'choices' => [
                    'Français' => 'fr',
                    'English' => 'en',
                ],
                'required' => true,
            ])
            ->add('titre', TextType::class, [
                'label' => 'Titre de la vidéo',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description / Texte (HTML ou multiligne)',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('texteBouton', TextType::class, [
                'label' => 'Texte du bouton (CTA)',
                'required' => false,
            ])
            ->add('lienBouton', TextType::class, [
                'label' => 'Lien du bouton (CTA)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VideoTranslation::class,
        ]);
    }
}