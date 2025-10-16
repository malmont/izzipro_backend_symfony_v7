<?php
// src/Form/StripeConnectionTokenType.php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

class StripeConnectionTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('token', PasswordType::class, [
                'label' => 'Votre Token de Sécurité GEM-SUITE',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Collez votre token ici',
                ],
            ])
            ->add('connect', SubmitType::class, [
                'label' => 'Identifier et Lancer la Connexion',
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
            ]);
    }
}