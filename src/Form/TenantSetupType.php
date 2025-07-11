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

class TenantSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['label' => 'Identifiant unique du Tenant (ex: acme_corp)'])
            ->add('subdomain', TextType::class, ['label' => 'Sous-domaine souhaité (ex: acme)'])
            
            ->add('companyName', TextType::class, ['label' => 'Nom officiel de l\'entreprise'])
            ->add('companyLogo', FileType::class, ['label' => 'Logo de l\'entreprise', 'required' => false, 'mapped' => false])
            ->add('companyEmail', EmailType::class, ['label' => 'Email public de contact'])
            ->add('companyTva', TextType::class, ['label' => 'N° TVA Intracommunautaire', 'required' => false])
            ->add('companyEin', TextType::class, ['label' => 'N° d\'identification (EIN/SIREN)', 'required' => false])
            
            ->add('adminName', TextType::class, ['label' => 'Nom complet de l\'administrateur'])
            ->add('adminEmail', EmailType::class, ['label' => 'Email de l\'administrateur'])
            ->add('plainPassword', PasswordType::class, ['label' => 'Mot de passe'])
            
            ->add('gemsuiteToken', TextType::class, ['label' => 'Token GEM-SUITE (pour test)', 'required' => false])
            
            ->add('save', SubmitType::class, ['label' => 'Créer et Activer le Site']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TenantSetupDTO::class]);
    }
}