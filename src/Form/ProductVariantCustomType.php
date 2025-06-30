<?php

namespace App\Form;

use App\Entity\Color;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\ProductOptionValue;
use App\Entity\Size;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductVariantCustomType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $options['tenant_em'];

        $builder
            ->add('product', EntityType::class, [
                'class' => Product::class,
                'label' => 'Produit',
                'em' => $tenantEm, 
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('p')->orderBy('p.name', 'ASC');
                },
                'choice_label' => 'name',
            ])
            ->add('color', EntityType::class, [
                'class' => Color::class,
                'label' => 'Couleur',
                'em' => $tenantEm,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')->orderBy('c.name', 'ASC');
                },
                'choice_label' => 'name',
                'required' => false, 
                'placeholder' => 'Aucune', 
            ])
            ->add('size', EntityType::class, [
                'class' => Size::class,
                'label' => 'Taille',
                'em' => $tenantEm,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('s')->orderBy('s.name', 'ASC');
                },
                'choice_label' => 'name',
                'required' => false, 
                'placeholder' => 'Aucune', 
            ])
            ->add('stockQuantity', IntegerType::class, [
                'label' => 'Quantité en stock',
            ])
            ->add('optionValues', EntityType::class, [
            'class' => ProductOptionValue::class,
            'label' => 'Valeurs de cette variante',
            'help' => 'Sélectionnez la combinaison exacte de valeurs pour cette variante.',
            'em' => $tenantEm,
            'query_builder' => function (EntityRepository $er) {
                return $er->createQueryBuilder('pov')
                    ->join('pov.productOption', 'po')
                    ->orderBy('po.name', 'ASC')
                    ->addOrderBy('pov.value', 'ASC');
            },
            'multiple' => true,
            'expanded' => true,
            'by_reference' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductVariant::class,
        ]);

        // On rend l'option 'tenant_em' obligatoire quand on crée ce formulaire
        $resolver->setRequired('tenant_em');
    }
}