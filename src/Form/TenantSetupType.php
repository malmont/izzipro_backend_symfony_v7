<?php
// src/Form/TenantSetupType.php

namespace App\Form;

use App\Dto\TenantSetupDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;


class TenantSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', HiddenType::class)
            ->add('subdomain', HiddenType::class) 
            ->add('subdomain_display', TextType::class, [
                'label' => 'Sous-domaine détecté',
                'mapped' => false, 
                'disabled' => true,
            ])
            ->add('secretKey', PasswordType::class, [
                'label' => 'Clé de sécurité de création',
                'help' => 'Clé secrète configurée dans le fichier .env (TENANT_CREATION_SECRET_KEY)',
                'required' => true,
            ])
            ->add('companyName', TextType::class, [
                'label' => "Nom de l'entreprise",
                'required' => true,
            ])
            ->add('companyEmail', EmailType::class, [
                'label' => "Email de contact",
                'required' => true,
            ])
            ->add('companyPhone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
            ])
            ->add('companyWebsite', TextType::class, [
                'label' => 'Site Web',
                'required' => false,
            ])
            ->add('companyEin', TextType::class, [
                'label' => 'SIRET / EIN / Immatriculation',
                'required' => false,
            ])
            ->add('companyTva', TextType::class, [
                'label' => 'N° TVA Intracommunautaire',
                'required' => false,
            ])
            ->add('street1', TextType::class, [
                'label' => 'Adresse (Rue, numéro)',
                'required' => true,
            ])
            ->add('street2', TextType::class, [
                'label' => "Complément d'adresse",
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'required' => true,
            ])
            ->add('zip', TextType::class, [
                'label' => 'Code postal',
                'required' => true,
            ])
            ->add('state', TextType::class, [
                'label' => 'Région / État',
                'required' => false,
            ])
            ->add('country', TextType::class, [
                'label' => 'Pays (ex: FR, CA, US)',
                'required' => true,
            ])
            ->add('save', SubmitType::class, ['label' => 'Créer et Activer le Site']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TenantSetupDTO::class]);
    }
}