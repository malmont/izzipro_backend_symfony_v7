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
                'choice_label' => function (ProductVariant $productVariant) {
                    return $productVariant->getProductName(); // Utilise la méthode pour récupérer le nom du produit
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
