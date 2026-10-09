<?php

namespace App\Services\BoutiqueDemoService;

/**
 * Données de la boutique de démonstration (site « demo » seulement) : un produit par cas à tester côté frontend
 * (§ 10 de docs/boutique-reglable-backend-demandes.md). Montants en cents (comme en base), sauf priceDelta des
 * options (dollars, règle de CustomizationFactoryService). Les dates relatives sont calculées à chaque passage.
 */
final class BoutiqueDemoCatalog
{
    /** Préfixe des codes produits et des fichiers d'images : repère des données de démonstration */
    public const CODE_PREFIX = 'DEMO-';
    public const IMAGE_PREFIX = 'demo-boutique-';

    /** Données de référence du tunnel de commande (mêmes identifiants que les autres sites : le frontend en code certains en dur) */
    public const REFERENCE = [
        'order_source' => [[1, 'Ecommerce'], [2, 'Pos'], [3, 'Mobile_app']],
        'order_type' => [[1, 'AchatClient', 'BuyCustomer'], [2, 'RetourClient', 'CustomerReturn']],
        'status_commande' => [[1, 'Incomplete', 'Incomplete'], [2, 'En cours', 'In progress'], [3, 'Complétée', 'Completed'],
            [4, 'En cours de préparation', 'In preparation'], [5, 'En cours de livraison', 'On delivery'], [6, 'Livrée', 'Delivered'], [7, 'Annulation', 'Cancellation']],
        'status_payment' => [[1, 'En cours', 'In progress'], [2, 'Complété', 'Completed'], [3, 'Echoué', 'Failed']],
        'payment_method' => [[1, 'carte de crédit', 'credit card'], [2, 'Espèces', 'Cash'], [3, 'Paypal', 'Paypal']],
        'payment_type' => [[1, 'RemboursementClient'], [2, 'PaiementClient']],
        'movement_type' => [[1, 'Entrant'], [2, 'Sortant'], [3, 'Return'], [4, 'Ajustement']],
    ];

    /**
     * [id, nom, taux, type, pays, régions] : taxes canadiennes au 09/10/2026 (TPS 5 % hors provinces à TVH ; TVQ ; TVH
     * 13 % ON, 14 % NS, 15 % NB, NL, PE ; TVP BC 7 %, SK 6 %, MB 7 %). Remplacées à chaque passage sur demo (données de démonstration).
     */
    public const TAXES = [
        [1, 'TPS', 0.05, 'Fédérale', 'CA', 'QC,BC,AB,SK,MB,YT,NT,NU'],
        [2, 'TVQ', 0.09975, 'provinciale', 'CA', 'QC'],
        [3, 'TVH', 0.13, 'harmonisée', 'CA', 'ON'],
        [4, 'TVH', 0.14, 'harmonisée', 'CA', 'NS'],
        [5, 'TVH', 0.15, 'harmonisée', 'CA', 'NB,NL,PE'],
        [6, 'TVP', 0.07, 'provinciale', 'CA', 'BC,MB'],
        [7, 'TVP', 0.06, 'provinciale', 'CA', 'SK'],
    ];

    /**
     * Transporteurs à prix fixe (sans compte EasyPost). L'identifiant 6 est le transporteur gratuit, que le frontend
     * envoie encore en dur pour une commande de location seule (§ 5 d).
     *
     * @var list<array{id: int, fr: array{0: string, 1: string}, en: array{0: string, 1: string}, price: int}>
     */
    public const CARRIERS = [
        ['id' => 1, 'fr' => ['Livraison standard', 'Postes Canada, 3 à 7 jours ouvrables'], 'en' => ['Standard shipping', 'Canada Post, 3 to 7 business days'], 'price' => 1500, 'days' => '3 à 7 jours ouvrables'],
        ['id' => 2, 'fr' => ['Livraison express', 'Messagerie, 1 à 2 jours ouvrables'], 'en' => ['Express shipping', 'Courier, 1 to 2 business days'], 'price' => 2900, 'days' => '1 à 2 jours ouvrables'],
        ['id' => 6, 'fr' => ['Livraison gratuite', 'Retrait en boutique ou livraison offerte'], 'en' => ['Free shipping', 'In-store pickup or free delivery'], 'price' => 0, 'days' => 'Retrait sous 24 h'],
    ];

    /** clé => [fr, en, description fr, description en, location ?, couleur de l'image] */
    public const CATEGORIES = [
        'vetements' => ['Vêtements', 'Clothing', 'T-shirts, sweats et vestes de la collection.', 'T-shirts, sweatshirts and jackets.', false, '#1d4ed8'],
        'accessoires' => ['Accessoires', 'Accessories', 'Casquettes, sacs, gourdes et épicerie fine.', 'Caps, bags, bottles and fine food.', false, '#0f766e'],
        'nautique' => ['Location nautique', 'Water rentals', 'Kayaks, planches à pagaie et pontons à louer.', 'Kayaks, paddle boards and pontoons for rent.', true, '#0369a1'],
        'evenements' => ['Feux d\'artifice', 'Fireworks', 'Spectacles pyrotechniques aux dates de la saison.', 'Firework shows on the season dates.', true, '#7c3aed'],
        'vehicules' => ['Véhicules', 'Vehicles', 'Motomarines à vendre ou à louer.', 'Personal watercraft for sale or rent.', true, '#b91c1c'],
        'abonnements' => ['Abonnements', 'Subscriptions', 'Paniers et services livrés chaque semaine ou chaque mois.', 'Baskets and services delivered weekly or monthly.', false, '#15803d'],
    ];

    /**
     * Forfaits de location (cents) rattachés à une catégorie de location : [nom fr, nom en, catégorie, heure, demi-journée, jour, semaine, mois]
     */
    public const RENTAL_PACKS = [
        ['Tarif nautique', 'Water rate', 'nautique', 2500, 6000, 9500, 45000, 140000],
        ['Tarif spectacle', 'Show rate', 'evenements', null, null, 150000, null, null],
        ['Tarif motomarine', 'Watercraft rate', 'vehicules', 9500, 29500, 45000, 225000, null],
    ];

    /** Options : code => [nom fr, nom en, valeurs : code => [fr, en, priceDelta en dollars, couleur]] */
    public const OPTIONS = [
        'taille' => ['Taille', 'Size', ['s' => ['S', 'S', 0, null], 'm' => ['M', 'M', 0, null], 'l' => ['L', 'L', 0, null], 'xl' => ['XL', 'XL', 0, null]]],
        'couleur' => ['Couleur', 'Color', ['noir' => ['Noir', 'Black', 0, '#111827'], 'blanc' => ['Blanc', 'White', 0, '#f9fafb'], 'bleu' => ['Bleu', 'Blue', 0, '#1d4ed8']]],
        'gravure' => ['Gravure', 'Engraving', ['sans' => ['Sans gravure', 'No engraving', 0, null], 'prenom' => ['Prénom', 'First name', 5, null], 'logo' => ['Logo', 'Logo', 12, null]]],
        'bouchon' => ['Bouchon', 'Cap', ['inox' => ['Inox', 'Steel', 0, '#9ca3af'], 'bambou' => ['Bambou', 'Bamboo', 3, '#a16207']]],
    ];

    /** Tailles et couleurs (anciens champs size / color des variantes) : code => [fr, en, hexa] */
    public const SIZES = ['s' => ['S', 'S'], 'm' => ['M', 'M'], 'l' => ['L', 'L'], 'xl' => ['XL', 'XL']];
    public const COLORS = ['noir' => ['Noir', 'Black', '#111827'], 'blanc' => ['Blanc', 'White', '#f9fafb'], 'bleu' => ['Bleu', 'Blue', '#1d4ed8']];

    /**
     * Produits. Champs : code, fr/en [nom, description courte], more (HTML de « plus d'informations », fr), price
     * (cents), category, color (image), photos (nombre d'images de galerie), flags, special [cents, début, fin],
     * saleUnit, variants (liste : options, stock, price), customization (combinaisons), booking, vehicle, case (cas testé).
     *
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        $now = new \DateTimeImmutable('today');
        $season = [];
        foreach (['first saturday of june', 'last saturday of june', 'first saturday of july', 'third saturday of july', 'first saturday of august', 'third saturday of august'] as $rule) {
            $date = new \DateTimeImmutable($rule . ' next year');
            $season[] = $date->format('Y-m-d');
        }

        return [
            [
                'code' => 'CASQUETTE', 'case' => 'vente simple (une variante)', 'category' => 'accessoires', 'color' => '#0f766e',
                'fr' => ['Casquette brodée', 'Casquette en coton biologique, logo brodé, taille ajustable.'],
                'en' => ['Embroidered cap', 'Organic cotton cap, embroidered logo, adjustable size.'],
                'more' => '<p>Coton biologique certifié. Lavage à la main.</p>', 'price' => 2900,
                'flags' => ['isbestseller', 'isAccessory'], 'variants' => [['stock' => 40]],
            ],
            [
                'code' => 'TSHIRT', 'case' => 'vente avec variantes (taille, couleur, prix de variante)', 'category' => 'vetements', 'color' => '#1d4ed8',
                'fr' => ['T-shirt Horizon', 'T-shirt unisexe en coton peigné, coupe droite.'],
                'en' => ['Horizon T-shirt', 'Unisex combed cotton T-shirt, regular fit.'],
                'more' => '<p>180 g/m², coton peigné. <strong>Le XL coûte un peu plus cher.</strong></p>', 'price' => 3500,
                'flags' => ['isnewarrival', 'isfeatured', 'isbestseller'], 'photos' => 2,
                'variants' => [
                    ['options' => ['taille' => 's', 'couleur' => 'noir'], 'stock' => 8],
                    ['options' => ['taille' => 'm', 'couleur' => 'noir'], 'stock' => 12],
                    ['options' => ['taille' => 'l', 'couleur' => 'noir'], 'stock' => 6],
                    ['options' => ['taille' => 'xl', 'couleur' => 'noir'], 'stock' => 3, 'price' => 3900],
                    ['options' => ['taille' => 'm', 'couleur' => 'blanc'], 'stock' => 10],
                    ['options' => ['taille' => 'l', 'couleur' => 'blanc'], 'stock' => 0],
                    ['options' => ['taille' => 'm', 'couleur' => 'bleu'], 'stock' => 5],
                ],
            ],
            [
                'code' => 'SWEAT', 'case' => 'promotion en cours (dates)', 'category' => 'vetements', 'color' => '#4338ca',
                'fr' => ['Sweat Brise', 'Sweat à capuche molletonné, poche kangourou.'],
                'en' => ['Breeze hoodie', 'Fleece hoodie with kangaroo pocket.'],
                'more' => '<p>Promotion valable jusqu\'à la fin du mois.</p>', 'price' => 6900,
                'special' => [4900, $now->modify('-7 days'), $now->modify('last day of next month')->setTime(23, 59)],
                'flags' => ['isspecialoffer'], 'variants' => [['options' => ['taille' => 'm'], 'stock' => 7], ['options' => ['taille' => 'l'], 'stock' => 4]],
            ],
            [
                'code' => 'VESTE', 'case' => 'produit épuisé', 'category' => 'vetements', 'color' => '#334155',
                'fr' => ['Veste Coupe-vent', 'Veste légère imperméable, capuche rangeable.'],
                'en' => ['Windbreaker jacket', 'Light waterproof jacket, packable hood.'],
                'more' => '<p>Réassort prévu le mois prochain.</p>', 'price' => 11900,
                'variants' => [['options' => ['taille' => 'm'], 'stock' => 0], ['options' => ['taille' => 'l'], 'stock' => 0]],
            ],
            [
                'code' => 'SAC', 'case' => 'plusieurs photos', 'category' => 'accessoires', 'color' => '#9a3412',
                'fr' => ['Sac de voyage Escale', 'Sac 40 L en toile cirée, bandoulière amovible.'],
                'en' => ['Escale travel bag', '40 L waxed canvas bag, removable strap.'],
                'more' => '<ul><li>Toile cirée</li><li>Poche pour ordinateur</li><li>Garantie 5 ans</li></ul>', 'price' => 14900,
                'photos' => 5, 'flags' => ['isfeatured', 'isAccessory'], 'variants' => [['stock' => 9]],
            ],
            [
                'code' => 'CAFE', 'case' => 'unité de vente (kg) et promotion expirée', 'category' => 'accessoires', 'color' => '#78350f',
                'fr' => ['Café en grains du Pérou', 'Café de spécialité, torréfaction moyenne, vendu au kilo.'],
                'en' => ['Peruvian whole bean coffee', 'Specialty coffee, medium roast, sold by the kilo.'],
                'more' => '<p>Notes de chocolat et d\'agrumes.</p>', 'price' => 3800, 'saleUnit' => 'kg',
                'special' => [2900, $now->modify('-60 days'), $now->modify('-30 days')],
                'variants' => [['stock' => 25]],
            ],
            [
                'code' => 'GOURDE', 'case' => 'produit personnalisable (options à combinaisons)', 'category' => 'accessoires', 'color' => '#0e7490',
                'fr' => ['Gourde isotherme à graver', 'Gourde inox 750 mL, gravure et bouchon au choix.'],
                'en' => ['Engravable insulated bottle', '750 mL steel bottle, choice of engraving and cap.'],
                'more' => '<p>Garde le froid 24 h et le chaud 12 h.</p>', 'flags' => ['isnewarrival', 'isAccessory'], 'price' => 3200, 'customizable' => true,
                'variants' => [['stock' => 30, 'customization' => [
                    [['gravure' => 'sans', 'bouchon' => 'inox'], 20], [['gravure' => 'sans', 'bouchon' => 'bambou'], 10],
                    [['gravure' => 'prenom', 'bouchon' => 'inox'], 15], [['gravure' => 'prenom', 'bouchon' => 'bambou'], 8],
                    [['gravure' => 'logo', 'bouchon' => 'inox'], 6], [['gravure' => 'logo', 'bouchon' => 'bambou'], 0],
                ]]],
            ],
            [
                'code' => 'KAYAK', 'case' => 'location à l\'heure (heure, demi-journée)', 'category' => 'nautique', 'color' => '#0284c7', 'sale' => false, 'rental' => true,
                'fr' => ['Kayak de mer', 'Kayak simple, gilet et pagaie fournis. Location à l\'heure ou à la demi-journée.'],
                'en' => ['Sea kayak', 'Single kayak, life jacket and paddle included. Hourly or half-day rental.'],
                'more' => '<p>Départ depuis la plage. Débutants bienvenus.</p>', 'flags' => ['isbestseller'], 'price' => 2500, 'variants' => [['stock' => 6]],
                'booking' => ['granularity' => 'hours', 'stock' => 6, 'min' => 1, 'max' => 8, 'buffer' => 15, 'open' => ['09:00', '18:00'],
                    'halfDays' => [['label' => 'Matin', 'start' => '09:00', 'end' => '13:00'], ['label' => 'Après-midi', 'start' => '14:00', 'end' => '18:00']],
                    'lead' => 30, 'included' => ['Gilet de sauvetage', 'Pagaie', 'Sac étanche'], 'excluded' => ['Combinaison isotherme'],
                    'cancellation' => '<p>Annulation gratuite jusqu\'à 24 h avant le départ.</p>'],
            ],
            [
                'code' => 'PADDLE', 'case' => 'vente ET location (à la journée)', 'category' => 'nautique', 'color' => '#0891b2', 'sale' => true, 'rental' => true,
                'fr' => ['Planche à pagaie gonflable', 'Planche 10\'6" avec pompe et sac. À acheter ou à louer à la journée.'],
                'en' => ['Inflatable paddle board', '10\'6" board with pump and bag. Buy it or rent it by the day.'],
                'more' => '<p>Charge maximale 120 kg.</p>', 'flags' => ['isnewarrival'], 'price' => 69900, 'variants' => [['stock' => 4]],
                'booking' => ['granularity' => 'days', 'stock' => 4, 'min' => 1, 'max' => 14, 'buffer' => 60, 'open' => ['08:00', '19:00'], 'minDays' => 1,
                    'deposit' => 20000, 'included' => ['Pompe', 'Pagaie', 'Leash']],
            ],
            [
                'code' => 'PONTON', 'case' => 'location journée, semaine, mois, avec caution et passagers', 'category' => 'nautique', 'color' => '#1e3a8a', 'sale' => false, 'rental' => true,
                'fr' => ['Ponton 8 places', 'Ponton de 20 pi avec moteur 90 ch, permis d\'embarcation requis.'],
                'en' => ['8-seat pontoon', '20 ft pontoon with 90 hp engine, boating licence required.'],
                'more' => '<p>Essence en sus, facturée au retour.</p>', 'price' => 45000, 'variants' => [['stock' => 2]],
                'booking' => ['granularity' => 'days', 'stock' => 2, 'min' => 1, 'max' => 60, 'buffer' => 120, 'open' => ['09:00', '19:00'], 'minDays' => 2,
                    'deposit' => 150000, 'passenger' => 2500, 'lead' => 45, 'included' => ['Gilets pour 8', 'Glacière'], 'excluded' => ['Essence', 'Nourriture'],
                    'cancellation' => '<p>Aucun remboursement dans les 48 h précédant la location.</p>', 'notes' => '<p>Le plein est à la charge du client.</p>'],
            ],
            [
                'code' => 'SPECTACLE', 'case' => 'location aux dates autorisées avec créneau du soir', 'category' => 'evenements', 'color' => '#6d28d9', 'sale' => false, 'rental' => true,
                'fr' => ['Spectacle pyrotechnique', 'Feu d\'artifice de 12 minutes, artificier certifié, aux dates de la saison.'],
                'en' => ['Firework show', '12-minute show by a certified pyrotechnician, on the season dates.'],
                'more' => '<p>Montage l\'après-midi, tir à la tombée de la nuit.</p>', 'price' => 150000, 'variants' => [['stock' => 1]],
                'booking' => ['granularity' => 'days', 'stock' => 1, 'min' => 1, 'max' => 1, 'buffer' => 0, 'allowedDates' => $season,
                    'evening' => ['start' => '20:30', 'end' => '23:00'], 'deposit' => 50000,
                    'cancellation' => '<p>Report sans frais en cas de vent fort ou d\'interdiction de feu.</p>'],
            ],
            [
                'code' => 'SEADOO', 'case' => 'véhicule (location avec caution)', 'category' => 'vehicules', 'color' => '#b91c1c', 'sale' => false, 'rental' => true,
                'fr' => ['Motomarine Sea-Doo Spark', 'Motomarine 2 places, 90 ch. Location à l\'heure ou à la journée.'],
                'en' => ['Sea-Doo Spark watercraft', '2-seat watercraft, 90 hp. Hourly or daily rental.'],
                'more' => '<p>Permis d\'embarcation de plaisance obligatoire.</p>', 'flags' => ['isfeatured'], 'price' => 899900, 'variants' => [['stock' => 3]],
                'vehicle' => ['brand' => 'Sea-Doo', 'model' => 'Spark 2-up', 'year' => 2025, 'condition' => 'neuf', 'color' => 'Rouge', 'gasType' => 'Essence', 'transmission' => 'Automatique', 'enginePower' => '90 ch', 'hours' => 12],
                'booking' => ['granularity' => 'hours', 'stock' => 3, 'min' => 1, 'max' => 8, 'buffer' => 30, 'open' => ['09:00', '18:00'],
                    'halfDays' => [['label' => 'Matin', 'start' => '09:00', 'end' => '13:00'], ['label' => 'Après-midi', 'start' => '14:00', 'end' => '18:00']],
                    'deposit' => 500000, 'lead' => 45, 'included' => ['Gilets', 'Formation de 15 minutes'], 'excluded' => ['Essence']],
            ],
            [
                'code' => 'REMORQUE', 'case' => 'véhicule à vendre', 'category' => 'vehicules', 'color' => '#991b1b',
                'fr' => ['Remorque pour motomarine', 'Remorque galvanisée, une place, freins inclus.'],
                'en' => ['Watercraft trailer', 'Galvanized single trailer, brakes included.'],
                'more' => '<p>Immatriculation non comprise.</p>', 'price' => 289900, 'variants' => [['stock' => 2]],
                'vehicle' => ['brand' => 'Karavan', 'model' => 'PWC-1', 'year' => 2026, 'condition' => 'neuf', 'color' => 'Gris'],
            ],
            [
                'code' => 'PANIER', 'case' => 'formule d\'abonnement (module § 11 à construire)', 'category' => 'abonnements', 'color' => '#15803d', 'sale' => false, 'subscription' => true,
                'fr' => ['Panier bio de la semaine', 'Légumes et fruits de saison des fermes voisines, livrés chaque semaine.'],
                'en' => ['Weekly organic basket', 'Seasonal vegetables and fruit from nearby farms, delivered weekly.'],
                'more' => '<p>Sans engagement, annulable à la fin de chaque période.</p>', 'price' => 3500, 'variants' => [['stock' => 100]],
            ],
        ];
    }

    /** Formules d'abonnement de démonstration : code produit => [[noms fr/en, périodicité, N, prix cents, jours d'essai, engagement]] */
    public const SUBSCRIPTION_PLANS = [
        'PANIER' => [
            [['fr' => 'Panier hebdomadaire', 'en' => 'Weekly basket'], 'week', 1, 3500, 0, 0],
            [['fr' => 'Panier aux deux semaines', 'en' => 'Biweekly basket'], 'week', 2, 3900, 7, 0],
            [['fr' => 'Panier mensuel', 'en' => 'Monthly basket'], 'month', 1, 12900, 14, 3],
        ],
    ];

    /** Diapositives de l'accueil : [titre fr, titre en, texte fr, texte en, bouton fr, bouton en, lien, couleur] */
    public const SLIDES = [
        ['Nouvelle collection Horizon', 'New Horizon collection', 'Des basiques en coton biologique, pensés pour durer.', 'Organic cotton basics, made to last.', 'Découvrir', 'Discover', '/catalogue', '#1d4ed8'],
        ['Louez votre journée sur l\'eau', 'Rent your day on the water', 'Kayaks, planches et pontons à l\'heure ou à la journée.', 'Kayaks, boards and pontoons by the hour or day.', 'Réserver', 'Book now', '/catalogue', '#0369a1'],
        ['Soldes d\'automne', 'Fall sale', 'Jusqu\'à 30 % sur une sélection de vêtements.', 'Up to 30% off selected clothing.', 'J\'en profite', 'Shop the sale', '/catalogue', '#b45309'],
    ];

    /** Cartes « explorer » : [titre fr, titre en, sous-titre fr, sous-titre en, description fr, description en, lien, couleur] */
    public const CARDS = [
        ['Vêtements', 'Clothing', 'Collection Horizon', 'Horizon collection', 'Coton biologique et coupes intemporelles.', 'Organic cotton and timeless cuts.', '/catalogue', '#1d4ed8'],
        ['Sur l\'eau', 'On the water', 'Locations nautiques', 'Water rentals', 'Kayaks, planches et pontons à réserver en ligne.', 'Kayaks, boards and pontoons to book online.', '/catalogue', '#0369a1'],
        ['Cadeaux', 'Gifts', 'À personnaliser', 'Personalize it', 'Gourdes gravées et paniers offerts.', 'Engraved bottles and gift baskets.', '/catalogue', '#7c3aed'],
    ];

    /**
     * Avis de démonstration (09/10/2026), publiés : [code produit, prénom, nom, note, titre, texte, il y a N jours,
     * réponse du commerçant ou null, langue]. Des notes basses comprises : un site honnête les montre aussi. Auteurs
     * fictifs (comptes @example.invalid, sans achat : pas de badge « Achat vérifié ») ; le client de démonstration
     * reçoit en plus un avis vérifié sur chaque produit qu'il a réellement commandé.
     */
    public const REVIEWS = [
        ['CASQUETTE', 'Sophie', 'Lavoie', 5, 'Broderie impeccable', 'La broderie est nette et le tissu respire bien. Je la porte tous les jours au chalet.', 42, null, 'fr'],
        ['CASQUETTE', 'Marc', 'Tremblay', 4, 'Taille un peu juste', "Belle finition, mais elle taille petit : prenez une taille au-dessus si vous hésitez.", 30, "Merci Marc ! Nous avons ajouté un guide des tailles sur la fiche.", 'fr'],
        ['CASQUETTE', 'Julie', 'Roy', 2, 'Couleur différente', "Le bleu est plus foncé que sur la photo. La qualité est correcte, mais je suis déçue de la teinte.", 18, "Désolés Julie : nous avons refait les photos en lumière du jour. Écrivez-nous pour un échange sans frais.", 'fr'],
        ['GOURDE', 'Nadia', 'Bouchard', 5, 'Gravure superbe', "Gravure précise de nos initiales, livrée en quatre jours. L'eau reste fraîche toute la journée.", 25, null, 'fr'],
        ['GOURDE', 'Thomas', 'Gagnon', 4, null, "Garde le froid plus de 12 heures. Le bouchon est un peu dur à dévisser au début.", 12, null, 'fr'],
        ['KAYAK', 'Étienne', 'Pelletier', 5, 'Sortie magique', "Kayak stable et léger, idéal pour une première sortie en mer. L'équipe nous a bien expliqué les consignes.", 35, "Merci Étienne, au plaisir de vous revoir cet été !", 'fr'],
        ['KAYAK', 'Claire', 'Morin', 3, 'Bien, mais attente', "Le kayak était parfait, mais nous avons attendu 30 minutes au comptoir à l'heure du départ.", 9, null, 'fr'],
        ['CAFE', 'Antoine', 'Côté', 5, 'Mon café du matin', "Arômes de chocolat et de fruits rouges, mouture parfaite pour mon espresso.", 50, null, 'fr'],
        ['CAFE', 'Isabelle', 'Girard', 4, null, "Très bon café, un peu acide à mon goût en filtre, excellent en piston.", 21, null, 'fr'],
        ['SWEAT', 'Lucas', 'Fortin', 4, 'Chaud et doux', "Coupe agréable, molleton épais. Les manches sont un peu longues.", 28, null, 'fr'],
        ['SWEAT', 'Chloé', 'Bergeron', 1, 'Bouloches après lavage', "Après deux lavages à froid, des bouloches sont apparues sur les manches.", 7, "Chloé, merci du signalement : nous vous envoyons un remplacement et avons transmis le lot à notre fournisseur.", 'fr'],
        ['PANIER', 'Mélanie', 'Caron', 5, 'Des légumes ultra frais', "Le panier arrive chaque mardi, toujours varié. La gestion de l'abonnement en ligne est simple.", 15, null, 'fr'],
        ['SAC', 'Emily', 'Walker', 5, 'Great travel bag', "Fits perfectly in the cabin, sturdy zippers and lots of pockets. Used it for a two-week trip.", 20, "Thanks Emily, enjoy your next trip!", 'en'],
    ];

    /** Politique des avis affichée sous la liste (réglages du site de démonstration) */
    public const REVIEW_POLICY = [
        'fr' => "Avis vérifiés : seuls nos clients ayant commandé le produit peuvent écrire. Nous publions tous les avis, positifs comme négatifs ; nous retirons seulement les propos injurieux, les données personnelles et les avis hors sujet.",
        'en' => "Verified reviews: only customers who ordered the product can write one. We publish every review, positive or negative; we only remove abusive language, personal data and off-topic reviews.",
    ];
}
