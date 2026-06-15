<?php

namespace App\Form;

use App\Entity\SocialNetwork;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SocialNetworkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', ChoiceType::class, [
                'label' => 'Réseau Social',
                'choices' => [
                    'Facebook' => 'facebook',
                    'Instagram' => 'instagram',
                    'LinkedIn' => 'linkedin',
                    'Twitter' => 'twitter',
                    'YouTube' => 'youtube',
                    'TikTok' => 'tiktok',
                ],
                'required' => true,
            ])
            ->add('url', TextType::class, [
                'label' => 'Lien / URL',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SocialNetwork::class,
        ]);
    }
}
