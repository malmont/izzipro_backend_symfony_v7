<?php

namespace App\Form;

use App\Entity\ProductTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\JsonType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class ProductTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', ChoiceType::class, [
                'label' => 'Langue',
                'required' => true,
                'choices' => [
                    'Français' => 'fr',
                    'English' => 'en',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom du produit (traduit)',
                'required' => true,
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug (traduit)',
                'required' => true,
                'help' => 'Sera automatiquement généré à partir du nom, mais peut être modifié.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description (traduit)',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('moreinformations', TextareaType::class, [
                'label' => 'Informations supplémentaires (traduit)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('tags', TextType::class, [
                'label' => 'Mots-clés (traduit, séparés par des virgules)',
                'required' => false,
                'help' => 'Ex: t-shirt, coton, bleu',
            ])
            ->add('specifications', TextareaType::class, [
                'label' => 'Spécifications (JSON, traduit)',
                'required' => false,
                'attr' => ['rows' => 7, 'placeholder' => '{"Couleur": "Bleu", "Matière": "Coton"}'],
                'help' => 'Entrez les spécifications au format JSON (ex: {"taille": "L", "couleur": "rouge"})',
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (isset($data['name']) && empty($data['slug'])) {
                $data['slug'] = $this->slugify($data['name']);
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductTranslation::class,
        ]);
    }

    private function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = strtolower($text);
        $text = preg_replace('~-+~', '-', $text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }
}