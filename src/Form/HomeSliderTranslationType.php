<?php
namespace App\Form;

use App\Entity\HomeSliderTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HomeSliderTranslationType extends AbstractType
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
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'required' => true,
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'required' => true,
            ])
            ->add('buttonMessage', TextType::class, [
                'label' => 'Message du bouton',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HomeSliderTranslation::class,
        ]);
    }
}