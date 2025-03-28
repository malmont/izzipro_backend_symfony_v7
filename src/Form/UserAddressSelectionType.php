<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Adress;
use App\Repository\AdressRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserAddressSelectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // Champ pour sélectionner un utilisateur
        $builder->add('user', EntityType::class, [
            'class' => User::class,
            'choice_label' => 'username',
            'placeholder' => 'Sélectionnez un utilisateur',
            'mapped' => false,
        ]);

        // Pré-remplissage du champ adresse si l'option "user" est fournie (ex. en édition)
        $initialAddresses = [];
        if (!empty($options['user']) && $options['user'] instanceof User) {
            // On suppose que User->getAdresses() retourne une Collection d'adresses
            $initialAddresses = $options['user']->getAdresses()->toArray();
        }

        $builder->add('address', EntityType::class, [
            'class' => Adress::class,
            'choices' => $initialAddresses,
            'choice_label' => function (Adress $adress) {
                $street  = $adress->getAddress();
                $zipcode = $adress->getCodepostal();
                $city    = $adress->getCity();
                return sprintf('%s, %s %s', $street, $zipcode, $city);
            },
            'placeholder' => 'Sélectionnez une adresse',
            'mapped' => false,
        ]);

        // Écouteur : lors de la soumission du champ "user", on met à jour le champ "address"
        $builder->get('user')->addEventListener(FormEvents::POST_SUBMIT, function(FormEvent $event) {
            $form = $event->getForm()->getParent();
            $user = $event->getForm()->getData();
            $this->updateAddressField($form, $user);
        });
    }
    
    private function updateAddressField($form, $user)
    {
        $form->add('address', EntityType::class, [
            'class' => Adress::class,
            'choice_label' => function (Adress $adress) {
                $street  = $adress->getAddress();
                $zipcode = $adress->getCodepostal();
                $city    = $adress->getCity();
                return sprintf('%s, %s %s', $street, $zipcode, $city);
            },
            'placeholder' => 'Sélectionnez une adresse',
            'mapped' => false,
            'query_builder' => function (AdressRepository $repo) use ($user) {
                return $repo->createQueryBuilder('a')
                    ->andWhere('a.userAdress = :user')
                    ->setParameter('user', $user);
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        // Vous pouvez passer une option "user" pour pré-filtrer les adresses
        $resolver->setDefaults([
            'data_class' => null,
            'user' => null,
        ]);
    }
    
    public function getBlockPrefix(): string
    {
        return 'user_address_selection';
    }
}
