<?php
// src/Form/Type/SpecificationsType.php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\SluggerInterface;

class SpecificationsType extends AbstractType
{
    private SluggerInterface $slugger;

    public function __construct(SluggerInterface $slugger)
    {
        $this->slugger = $slugger;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $template = $options['specifications_template'];
        if (empty($template)) {
            return;
        }

        $nameMapping = [];

        foreach ($template as $specName) {
            // ✅ Crée un nom de champ sûr, sans espaces ni caractères spéciaux.
            $fieldName = str_replace('-', '_', $this->slugger->slug($specName)->lower()->toString());
            $nameMapping[$fieldName] = $specName;

            $builder->add($fieldName, TextType::class, [
                // ✅ Utilise le nom original et complet comme étiquette visible.
                'label' => $specName,
                'required' => false,
            ]);
        }

        // ✅ Ajoute un transformateur pour faire correspondre les noms de champs
        // (ex: 'puissant_moteur') avec les clés de l'entité (ex: 'Puissant moteur').
        $builder->addModelTransformer(new CallbackTransformer(
            // Transforme les données de l'entité vers le formulaire
            function ($dataAsArray) use ($nameMapping) {
                $formData = [];
                if (is_array($dataAsArray)) {
                    foreach ($nameMapping as $fieldName => $specName) {
                        $formData[$fieldName] = $dataAsArray[$specName] ?? null;
                    }
                }
                return $formData;
            },
            // Transforme les données soumises par le formulaire vers l'entité
            function ($dataFromForm) use ($nameMapping) {
                $entityData = [];
                if (is_array($dataFromForm)) {
                    foreach ($dataFromForm as $fieldName => $value) {
                        if (isset($nameMapping[$fieldName])) {
                            $entityData[$nameMapping[$fieldName]] = $value;
                        }
                    }
                }
                return $entityData;
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);

        $resolver->setRequired('specifications_template');
        $resolver->setAllowedTypes('specifications_template', 'array');
    }
}
