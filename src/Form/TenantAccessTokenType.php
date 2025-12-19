<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class TenantAccessTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('token', PasswordType::class, [
                'label' => 'Clé API du Magasin',
                'attr' => [
                    'placeholder' => 'Collez le token ici...', 
                    'class' => 'form-control form-control-lg',
                    'autocomplete' => 'off'
                ],
                'constraints' => [new NotBlank(['message' => 'Le token est obligatoire.'])],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Accéder à la configuration',
                'attr' => ['class' => 'btn btn-primary w-100 btn-lg mt-4 fw-bold']
            ]);
    }
}