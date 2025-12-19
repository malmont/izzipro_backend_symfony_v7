<?php

namespace App\Form;

use App\Dto\BookingSetupRequest; 
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enableBooking', CheckboxType::class, [
                'label' => 'Activer le mode Location / Réservation',
                'required' => false,
                'attr' => ['class' => 'form-check-input toggle-booking'],
                'label_attr' => ['class' => 'form-check-label fw-bold fs-5']
            ])
            ->add('granularity', ChoiceType::class, [
                'label' => 'Unité de temps (Grille)',
                'choices' => [
                    'À la journée / Nuitée' => 'days',
                    'À l\'heure' => 'hours',
                    'À la demi-heure (30 min)' => 'minutes_30',
                    'Au quart d\'heure (15 min)' => 'minutes_15',
                ],
                'attr' => ['class' => 'form-select'],
                'row_attr' => ['class' => 'mb-3 booking-field']
            ])
            ->add('stockQuantity', IntegerType::class, [
                'label' => 'Quantité disponible',
                'attr' => ['class' => 'form-control', 'min' => 0],
                'row_attr' => ['class' => 'mb-3 booking-field']
            ])
            ->add('minDuration', IntegerType::class, [
                'label' => 'Durée Minimum',
                'attr' => ['class' => 'form-control', 'min' => 1],
                'row_attr' => ['class' => 'mb-3 booking-field']
            ])
            ->add('bufferTime', IntegerType::class, [
                'label' => 'Temps de battement (minutes)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0],
                'row_attr' => ['class' => 'mb-3 booking-field']
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Enregistrer les modifications',
                'attr' => ['class' => 'btn btn-primary w-100 mt-4 fw-bold']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingSetupRequest::class,
        ]);
    }
}