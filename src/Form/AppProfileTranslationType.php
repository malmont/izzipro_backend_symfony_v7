<?php

namespace App\Form;

use App\Entity\AppProfileTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Traduction d'un profil d'application dans EasyAdmin (même langues que la fiche entreprise) */
class AppProfileTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('language', ChoiceType::class, ['label' => 'Langue', 'choices' => ['Français' => 'fr', 'English' => 'en'], 'required' => true])
            ->add('appName', TextType::class, ['label' => 'Nom affiché', 'required' => false])
            ->add('tagline', TextType::class, ['label' => 'Accroche', 'required' => false])
            ->add('apropos', TextareaType::class, ['label' => 'À propos', 'required' => false])
            ->add('privacyPolicy', TextareaType::class, ['label' => 'Politique de confidentialité', 'required' => false])
            ->add('conditionOfUse', TextareaType::class, ['label' => 'Conditions d\'utilisation', 'required' => false])
            ->add('legalNotice', TextareaType::class, ['label' => 'Mentions légales', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AppProfileTranslation::class]);
    }
}
