<?php
namespace App\Form;

use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrderItemsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('productVariant', EntityType::class, [
                'class' => ProductVariant::class,
                'choice_label' => function (ProductVariant $variant) {
                    $product = $variant->getProduct()?->getName() ?? 'Produit inconnu';
                    $size = $variant->getSize() ?? 'Taille ?';
                    $color = $variant->getColor() ?? 'Couleur ?';
                    $id= $variant->getId();
                    return sprintf('%s - %s - %s (ID: %s)', $product, $size, $color, $id);
                },
                'label' => 'Produit',
            ])
        
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'required' => true, 
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => OrderItems::class,
        ]);
    }
}
