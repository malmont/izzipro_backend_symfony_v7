<?php
namespace App\Form;

use App\Entity\EntrepriseTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EntrepriseTranslationType extends AbstractType
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
            ->add('conditionOfUse', TextareaType::class, [
                'label' => 'Conditions d\'utilisation',
                'required' => false,
            ])
            ->add('LegalNotice', TextareaType::class, [
                'label' => 'Mentions légales',
                'required' => false,
            ])
            ->add('privacyPolicy', TextareaType::class, [
                'label' => 'Politique de confidentialité',
                'required' => false,
            ])
            ->add('Apropos', TextareaType::class, [
                'label' => 'À propos',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EntrepriseTranslation::class,
        ]);
    }
}