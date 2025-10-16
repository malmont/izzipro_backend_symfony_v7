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

            
            ->add('gemsuiteToken', PasswordType::class, ['label' => 'Token GEM-SUITE', 'required' => false])
            
            ->add('save', SubmitType::class, ['label' => 'Créer et Activer le Site']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TenantSetupDTO::class]);
    }
}