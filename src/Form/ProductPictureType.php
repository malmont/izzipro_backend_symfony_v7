<?php

namespace App\Form;

use App\Entity\ProductPicture;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\FileUploadType;

class ProductPictureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imageUrl', FileUploadType::class, [
                'label' => 'Image',
                'upload_dir' => 'public/assets/uploads/products/',
                'allow_delete' => true,
                'attr' => ['accept' => 'image/*'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductPicture::class,
        ]);
    }
}
