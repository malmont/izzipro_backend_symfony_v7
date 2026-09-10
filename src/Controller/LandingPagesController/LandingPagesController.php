<?php

namespace App\Controller\LandingPagesController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;



class LandingPagesController extends AbstractController
{
    #[Route('/api/components-config', name: 'api_components_config', methods: ['GET'])]
    public function getComponentsConfig(): JsonResponse
    {
        $componentsConfig = [
            [
                "type" => "PresentationGroup", // Nouvelle clé unique
                "name" => "Groupe de Présentations",
                "description" => "Affiche un groupe de plusieurs présentations.",
                "api_data_endpoint" => "/api/presentation-groups",
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "Carousel",
                "name" => "Carrousel de Produits",
                "description" => "Affiche une liste de produits (ex: meilleures ventes).",
                "api_data_endpoint" => null, 
                "data_id_field" => "key",
                "data_label_field" => "name",
                "is_data_selectable" => true
            ],
            [
                "type" => "Baniere",
                "name" => "Bannière Héros",
                "description" => "Affiche une grande bannière avec image et texte.",
                "api_data_endpoint" => "/bannieres",
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "BaniereStatique",
                "name" => "Bannière Statique",
                "description" => "Affiche une seule bannière figée.",
                "api_data_endpoint" => "/baniere-statiques",
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "Marque",
                "name" => "Affichage de Marques par Catégorie",
                "description" => "Affiche les logos des marques appartenant à une catégorie.",
                "api_data_endpoint" => "/categories-marque", 
                "data_id_field" => "id",
                "data_label_field" => "nom", 
                "is_data_selectable" => true
            ],
            [
                "type" => "Contact",
                "name" => "Formulaire de Contact",
                "description" => "Affiche un formulaire de contact.",
                "is_data_selectable" => false 
            ],
            [
                "type" => "Candidature",
                "name" => "Formulaire de Candidature",
                "description" => "Affiche une offre d'emploi ou un formulaire de candidature spontanée.",
                "api_data_endpoint" => "/emplois",
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "MultiLien",
                "name" => "Liens Multiples",
                "description" => "Affiche un groupe de liens personnalisables.",
                "api_data_endpoint" => "/multiliens", 
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "Service",
                "name" => "Offres de Services",
                "description" => "Présente les services offerts par l'entreprise.",
                "api_data_endpoint" => "/service-offers", 
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "Video",
                "name" => "Lecteur Vidéo",
                "description" => "Intègre une vidéo spécifique.",
                "api_data_endpoint" => "/videos",
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
            "type" => "Embed",
            "name" => "Contenu Intégré (Embed)",
            "description" => "Affiche une page externe dans un iframe.",
            "api_data_endpoint" => "/api/embeds",
            "data_id_field" => "id",
            "data_label_field" => "titre",
            "is_data_selectable" => true
            ],
            [
                "type" => "Recherche",
                "name" => "Barre de Recherche",
                "description" => "Affiche une barre de recherche.",
                "is_data_selectable" => false
            ],
            [
                "type" => "APropos",
                "name" => "Section À Propos",
                "description" => "Affiche le texte 'À Propos' de l'entreprise.",
                "is_data_selectable" => false 
            ],
            [
                "type" => "Presentation",
                "name" => "Section de Présentation",
                "description" => "Affiche une image à côté d'un texte et d'un bouton.",
                "api_data_endpoint" => "/presentations",
                "data_id_field" => "id",
                "data_label_field" => "titre", 
                "is_data_selectable" => true
            ],
            [
                "type" => "Reservation",
                "name" => "Module de Réservation",
                "description" => "Module de prise de rendez-vous et réservation de prestations.",
                "is_data_selectable" => true,
                "api_data_endpoint" => "/api/reservations/services",
                "data_id_field" => "id",
                "data_label_field" => "label"
            ]
        ];

        return $this->json($componentsConfig);
    }
}
