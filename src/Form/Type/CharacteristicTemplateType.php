<?php
// src/Form/Type/CharacteristicTemplateType.php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class CharacteristicTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la caractéristique',
                'attr' => ['placeholder' => 'Ex: Couleur']
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type de champ',
                'choices' => [
                    'Texte' => 'text',
                    'Nombre' => 'number',
                    'Liste déroulante' => 'select',
                ]
            ])
            ->add('options', TextType::class, [
                'label' => 'Options (pour liste déroulante)',
                'required' => false,
                'help' => 'Séparez les options par une virgule. Ex: Rouge,Bleu,Vert',
            ]);
    }
}
