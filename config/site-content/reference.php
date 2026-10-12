<?php

/*
 * Contenu d'exemple du site de référence (gmasuite, modèle de tout nouveau site) : écrit par
 * app:site-content:build-reference, copié ensuite dans les sites (création, POST /api/site-content/seed).
 *
 * Contenu neutre, en français et en anglais ; images sans aucun texte (dégradé et formes, générées par la commande
 * sous assets/uploads/<dossier>/exemple-*.jpg, communes à tous les sites). Une fiche : colonnes communes de la table,
 * puis 'fr' et 'en' (colonnes traduites ; le français est aussi écrit sur la fiche). Prix en cents.
 * Pas de formule d'abonnement, de commande, d'avis ni de client ; rien sur l'entreprise ni les réglages du site.
 */

$p = fn (string ...$paragraphs) => implode('', array_map(fn ($t) => "<p>$t</p>", $paragraphs));

return [
    // Dossier => fichier => [largeur, hauteur, couleur du haut, couleur du bas]
    'images' => [
        'slider' => [
            'exemple-presentation-mission.jpg' => [1600, 1067, '#1d4ed8', '#1e3a8a'],
            'exemple-presentation-savoir-faire.jpg' => [900, 1200, '#0f766e', '#134e4a'],
            'exemple-presentation-equipe.jpg' => [1000, 1000, '#7c3aed', '#4c1d95'],
            'exemple-engagement-qualite.jpg' => [1000, 1000, '#0284c7', '#0c4a6e'],
            'exemple-engagement-proximite.jpg' => [1000, 1000, '#d97706', '#78350f'],
            'exemple-engagement-durabilite.jpg' => [1000, 1000, '#15803d', '#14532d'],
            'exemple-etape-1.jpg' => [900, 1200, '#4f46e5', '#312e81'],
            'exemple-etape-2.jpg' => [900, 1200, '#0891b2', '#164e63'],
            'exemple-etape-3.jpg' => [900, 1200, '#db2777', '#831843'],
            'exemple-banniere-1.jpg' => [1920, 900, '#1d4ed8', '#172554'],
            'exemple-banniere-2.jpg' => [1920, 900, '#0f766e', '#042f2e'],
            'exemple-banniere-3.jpg' => [1920, 900, '#ea580c', '#7c2d12'],
            'exemple-banniere-statique.jpg' => [1920, 700, '#334155', '#0f172a'],
            'exemple-video.jpg' => [1920, 1080, '#6d28d9', '#1e1b4b'],
            'exemple-accueil-1.jpg' => [1920, 900, '#2563eb', '#1e3a8a'],
            'exemple-accueil-2.jpg' => [1920, 900, '#0d9488', '#134e4a'],
            'exemple-accueil-3.jpg' => [1920, 900, '#c2410c', '#7c2d12'],
        ],
        'email-logos' => [
            'exemple-service-conseil.jpg' => [1200, 800, '#1d4ed8', '#1e3a8a'],
            'exemple-service-conception.jpg' => [1200, 800, '#7c3aed', '#4c1d95'],
            'exemple-service-accompagnement.jpg' => [1200, 800, '#0f766e', '#134e4a'],
            'exemple-service-formation.jpg' => [1200, 800, '#d97706', '#78350f'],
            'exemple-marque-1.jpg' => [400, 400, '#475569', '#1e293b'],
            'exemple-marque-2.jpg' => [400, 400, '#0369a1', '#0c4a6e'],
            'exemple-marque-3.jpg' => [400, 400, '#047857', '#064e3b'],
            'exemple-marque-4.jpg' => [400, 400, '#b45309', '#78350f'],
            'exemple-marque-5.jpg' => [400, 400, '#be123c', '#881337'],
            'exemple-marque-6.jpg' => [400, 400, '#6d28d9', '#4c1d95'],
        ],
        'explore' => [
            'exemple-carte-1.jpg' => [900, 1200, '#2563eb', '#1e3a8a'],
            'exemple-carte-2.jpg' => [900, 1200, '#9333ea', '#581c87'],
            'exemple-carte-3.jpg' => [900, 1200, '#0f766e', '#134e4a'],
        ],
        'categories' => [
            'exemple-categorie-vetements.jpg' => [1200, 800, '#1d4ed8', '#1e3a8a'],
            'exemple-categorie-accessoires.jpg' => [1200, 800, '#0f766e', '#134e4a'],
            'exemple-categorie-maison.jpg' => [1200, 800, '#b45309', '#78350f'],
        ],
        'products' => [
            'exemple-tshirt.jpg' => [1000, 1000, '#2563eb', '#1e3a8a'],
            'exemple-tshirt-2.jpg' => [1000, 1000, '#3b82f6', '#1e40af'],
            'exemple-sweat.jpg' => [1000, 1000, '#4338ca', '#312e81'],
            'exemple-veste.jpg' => [1000, 1000, '#334155', '#0f172a'],
            'exemple-casquette.jpg' => [1000, 1000, '#0f766e', '#134e4a'],
            'exemple-sac.jpg' => [1000, 1000, '#b45309', '#78350f'],
            'exemple-sac-2.jpg' => [1000, 1000, '#c2410c', '#7c2d12'],
            'exemple-sac-3.jpg' => [1000, 1000, '#d97706', '#92400e'],
            'exemple-gourde.jpg' => [1000, 1000, '#0e7490', '#164e63'],
            'exemple-bougie.jpg' => [1000, 1000, '#be185d', '#831843'],
            'exemple-cafe.jpg' => [1000, 1000, '#78350f', '#451a03'],
        ],
        'customization' => [
            'exemple-gourde-1.jpg' => [1000, 1000, '#0e7490', '#164e63'],
            'exemple-gourde-2.jpg' => [1000, 1000, '#0f766e', '#134e4a'],
            'exemple-gourde-3.jpg' => [1000, 1000, '#0369a1', '#0c4a6e'],
            'exemple-gourde-4.jpg' => [1000, 1000, '#047857', '#064e3b'],
            'exemple-gourde-5.jpg' => [1000, 1000, '#1d4ed8', '#172554'],
            'exemple-gourde-6.jpg' => [1000, 1000, '#15803d', '#14532d'],
        ],
    ],

    // ---------------------------------------------------------------- Landing page
    'presentations' => [
        'mission' => [
            'image' => 'exemple-presentation-mission.jpg', 'lien_bouton' => '#contact',
            'fr' => ['titre' => 'Notre mission', 'texte' => $p('Nous aidons nos clients à concrétiser leurs projets avec des solutions simples, durables et pensées pour eux.', 'Remplacez ce texte par la présentation de votre entreprise : ce que vous faites, pour qui, et ce qui vous distingue.'), 'texte_bouton' => 'Nous contacter'],
            'en' => ['titre' => 'Our mission', 'texte' => $p('We help our clients bring their projects to life with simple, lasting solutions designed around them.', 'Replace this text with your company\'s introduction: what you do, for whom, and what sets you apart.'), 'texte_bouton' => 'Contact us'],
        ],
        'savoir-faire' => [
            'image' => 'exemple-presentation-savoir-faire.jpg', 'lien_bouton' => '#services',
            'fr' => ['titre' => 'Un savoir-faire reconnu', 'texte' => $p('Depuis plus de dix ans, notre équipe met son expérience au service de chaque projet, du premier échange à la livraison.'), 'texte_bouton' => 'Découvrir nos services'],
            'en' => ['titre' => 'Recognized expertise', 'texte' => $p('For over ten years, our team has brought its experience to every project, from the first conversation to delivery.'), 'texte_bouton' => 'Discover our services'],
        ],
        'equipe' => [
            'image' => 'exemple-presentation-equipe.jpg', 'lien_bouton' => '#contact',
            'fr' => ['titre' => 'Une équipe à votre écoute', 'texte' => $p('Des interlocuteurs disponibles, qui prennent le temps de comprendre vos besoins et vous répondent rapidement.'), 'texte_bouton' => 'Prendre rendez-vous'],
            'en' => ['titre' => 'A team that listens', 'texte' => $p('Available contacts who take the time to understand your needs and get back to you quickly.'), 'texte_bouton' => 'Book a meeting'],
        ],
        'qualite' => [
            'image' => 'exemple-engagement-qualite.jpg',
            'fr' => ['titre' => 'Qualité', 'texte' => $p('Des matériaux choisis avec soin et un contrôle à chaque étape.')],
            'en' => ['titre' => 'Quality', 'texte' => $p('Carefully chosen materials and checks at every step.')],
        ],
        'proximite' => [
            'image' => 'exemple-engagement-proximite.jpg',
            'fr' => ['titre' => 'Proximité', 'texte' => $p('Une équipe locale, joignable et réactive.')],
            'en' => ['titre' => 'Proximity', 'texte' => $p('A local team that is easy to reach and responsive.')],
        ],
        'durabilite' => [
            'image' => 'exemple-engagement-durabilite.jpg',
            'fr' => ['titre' => 'Durabilité', 'texte' => $p('Des choix responsables pour des résultats qui durent.')],
            'en' => ['titre' => 'Sustainability', 'texte' => $p('Responsible choices for results that last.')],
        ],
        'etape-1' => [
            'image' => 'exemple-etape-1.jpg',
            'fr' => ['titre' => '1. Premier échange', 'texte' => $p('Vous nous présentez votre projet, nous posons les bonnes questions.')],
            'en' => ['titre' => '1. First conversation', 'texte' => $p('You tell us about your project, we ask the right questions.')],
        ],
        'etape-2' => [
            'image' => 'exemple-etape-2.jpg',
            'fr' => ['titre' => '2. Proposition', 'texte' => $p('Nous vous remettons une proposition claire, avec un prix et un délai.')],
            'en' => ['titre' => '2. Proposal', 'texte' => $p('We send you a clear proposal, with a price and a timeline.')],
        ],
        'etape-3' => [
            'image' => 'exemple-etape-3.jpg',
            'fr' => ['titre' => '3. Réalisation', 'texte' => $p('Nous réalisons le projet et vous tenons informé à chaque étape.')],
            'en' => ['titre' => '3. Delivery', 'texte' => $p('We carry out the project and keep you informed at every step.')],
        ],
    ],
    'presentation-groups' => [
        'engagements' => [
            'presentations' => ['qualite', 'proximite', 'durabilite'],
            'fr' => ['titre' => 'Nos engagements', 'texte' => $p('Trois principes guident notre travail au quotidien.')],
            'en' => ['titre' => 'Our commitments'],
        ],
        'etapes' => [
            'presentations' => ['etape-1', 'etape-2', 'etape-3'],
            'fr' => ['titre' => 'Comment ça marche', 'texte' => $p('Un accompagnement simple, en trois étapes.')],
            'en' => ['titre' => 'How it works'],
        ],
    ],
    'baniere-statiques' => [
        'contact' => [
            'image_de_fond' => 'exemple-banniere-statique.jpg', 'color_background' => '#1e293b',
            'fr' => ['titre' => 'Prêt à démarrer votre projet ?', 'texte' => $p('Parlons-en : la première rencontre est sans engagement.'), 'texte_bouton' => 'Nous contacter'],
            'en' => ['titre' => 'Ready to start your project?', 'texte' => $p('Let\'s talk: the first meeting comes with no commitment.'), 'texte_bouton' => 'Contact us'],
        ],
    ],
    'bannieres' => [
        'bienvenue' => [
            'image_de_fond' => 'exemple-banniere-1.jpg',
            'fr' => ['titre' => 'Bienvenue chez nous', 'texte' => 'Des solutions sur mesure, pensées pour vous.'],
            'en' => ['titre' => 'Welcome', 'texte' => 'Tailored solutions, designed for you.'],
        ],
        'nouveautes' => [
            'image_de_fond' => 'exemple-banniere-2.jpg',
            'fr' => ['titre' => 'Découvrez nos nouveautés', 'texte' => 'De nouvelles offres pour accompagner vos projets.'],
            'en' => ['titre' => 'Discover what\'s new', 'texte' => 'New offers to support your projects.'],
        ],
        'contact' => [
            'image_de_fond' => 'exemple-banniere-3.jpg',
            'fr' => ['titre' => 'Une question ?', 'texte' => 'Notre équipe vous répond sous 24 heures.'],
            'en' => ['titre' => 'Any questions?', 'texte' => 'Our team gets back to you within 24 hours.'],
        ],
    ],
    'videos' => [
        'univers' => [
            'image_de_fond' => 'exemple-video.jpg', 'lien_video' => null, 'lien_bouton' => '#contact',
            'fr' => ['titre' => 'Découvrez notre univers', 'description' => $p('Ajoutez ici la vidéo qui présente votre activité : l\'image reste affichée tant qu\'aucune vidéo n\'est choisie.'), 'texte_bouton' => 'Nous contacter'],
            'en' => ['titre' => 'Discover our world', 'description' => $p('Add the video that presents your business here: this image is shown until a video is chosen.'), 'texte_bouton' => 'Contact us'],
        ],
    ],
    'service-offers' => [
        'conseil' => [
            'photo_service' => 'exemple-service-conseil.jpg', 'logo' => null,
            'fr' => ['titre' => 'Conseil', 'titre_commentaire' => 'Faire les bons choix', 'descriptions' => $p('Un regard extérieur pour clarifier vos besoins et définir la meilleure approche.')],
            'en' => ['titre' => 'Consulting', 'titre_commentaire' => 'Making the right choices', 'descriptions' => $p('An outside view to clarify your needs and define the best approach.')],
        ],
        'conception' => [
            'photo_service' => 'exemple-service-conception.jpg', 'logo' => null,
            'fr' => ['titre' => 'Conception', 'titre_commentaire' => 'Des solutions sur mesure', 'descriptions' => $p('Nous imaginons avec vous une solution adaptée à votre budget et à vos délais.')],
            'en' => ['titre' => 'Design', 'titre_commentaire' => 'Tailored solutions', 'descriptions' => $p('Together we design a solution that fits your budget and timeline.')],
        ],
        'accompagnement' => [
            'photo_service' => 'exemple-service-accompagnement.jpg', 'logo' => null,
            'fr' => ['titre' => 'Accompagnement', 'titre_commentaire' => 'À vos côtés', 'descriptions' => $p('Un suivi régulier, du lancement jusqu\'aux résultats.')],
            'en' => ['titre' => 'Support', 'titre_commentaire' => 'By your side', 'descriptions' => $p('Regular follow-up, from launch to results.')],
        ],
        'formation' => [
            'photo_service' => 'exemple-service-formation.jpg', 'logo' => null,
            'fr' => ['titre' => 'Formation', 'titre_commentaire' => 'Gagner en autonomie', 'descriptions' => $p('Des ateliers pratiques pour que vos équipes maîtrisent leurs outils.')],
            'en' => ['titre' => 'Training', 'titre_commentaire' => 'Becoming independent', 'descriptions' => $p('Hands-on workshops so your teams master their tools.')],
        ],
    ],
    // Marques : une catégorie de marques (reprise par son nom à la copie)
    'marque-categories' => [
        'partenaires' => ['fr' => ['nom' => 'Partenaires'], 'en' => ['nom' => 'Partners']],
    ],
    'marques' => [
        'atelier-nord' => ['titre' => 'Atelier Nord', 'logos_marques' => 'exemple-marque-1.jpg', 'categories' => ['partenaires']],
        'maison-lumen' => ['titre' => 'Maison Lumen', 'logos_marques' => 'exemple-marque-2.jpg', 'categories' => ['partenaires']],
        'studio-alto' => ['titre' => 'Studio Alto', 'logos_marques' => 'exemple-marque-3.jpg', 'categories' => ['partenaires']],
        'groupe-ardoise' => ['titre' => 'Groupe Ardoise', 'logos_marques' => 'exemple-marque-4.jpg', 'categories' => ['partenaires']],
        'verdure' => ['titre' => 'Verdure & Co', 'logos_marques' => 'exemple-marque-5.jpg', 'categories' => ['partenaires']],
        'horizon-labs' => ['titre' => 'Horizon Labs', 'logos_marques' => 'exemple-marque-6.jpg', 'categories' => ['partenaires']],
    ],
    // Cartes « explorer » : landing page et boutique
    'explore-cards' => [
        'nouveautes' => [
            'is_different' => false, 'link' => '/catalogue', 'image_path' => 'exemple-carte-1.jpg',
            'fr' => ['standard_title' => 'Nouveautés', 'description' => 'Les dernières arrivées de la saison.'],
            'en' => ['standard_title' => 'New arrivals', 'description' => 'The latest arrivals of the season.'],
        ],
        'cadeaux' => [
            'is_different' => false, 'link' => '/catalogue', 'image_path' => 'exemple-carte-2.jpg',
            'fr' => ['standard_title' => 'Idées cadeaux', 'description' => 'Des attentions qui font plaisir.'],
            'en' => ['standard_title' => 'Gift ideas', 'description' => 'Thoughtful gifts people love.'],
        ],
        'histoire' => [
            'is_different' => false, 'link' => '#a-propos', 'image_path' => 'exemple-carte-3.jpg',
            'fr' => ['standard_title' => 'Notre histoire', 'description' => 'Qui nous sommes et ce qui nous anime.'],
            'en' => ['standard_title' => 'Our story', 'description' => 'Who we are and what drives us.'],
        ],
    ],

    // ---------------------------------------------------------------- Boutique
    'categories' => [
        'vetements' => [
            'image' => 'exemple-categorie-vetements.jpg',
            'fr' => ['name' => 'Vêtements', 'description' => 'T-shirts, sweats et vestes de la collection.'],
            'en' => ['name' => 'Clothing', 'description' => 'T-shirts, sweatshirts and jackets from the collection.'],
        ],
        'accessoires' => [
            'image' => 'exemple-categorie-accessoires.jpg',
            'fr' => ['name' => 'Accessoires', 'description' => 'Casquettes, sacs et gourdes pour tous les jours.'],
            'en' => ['name' => 'Accessories', 'description' => 'Caps, bags and bottles for every day.'],
        ],
        'maison' => [
            'image' => 'exemple-categorie-maison.jpg',
            'fr' => ['name' => 'Maison & épicerie', 'description' => 'Bougies, café et petits plaisirs.'],
            'en' => ['name' => 'Home & pantry', 'description' => 'Candles, coffee and small treats.'],
        ],
    ],
    // Couleurs et tailles : reprises par leur code si le site les a déjà
    'colors' => [
        'noir' => ['code_hexa' => '#111827', 'fr' => 'Noir', 'en' => 'Black'],
        'blanc' => ['code_hexa' => '#f9fafb', 'fr' => 'Blanc', 'en' => 'White'],
        'bleu' => ['code_hexa' => '#1d4ed8', 'fr' => 'Bleu', 'en' => 'Blue'],
    ],
    'sizes' => [
        's' => ['fr' => 'S', 'en' => 'S'],
        'm' => ['fr' => 'M', 'en' => 'M'],
        'l' => ['fr' => 'L', 'en' => 'L'],
        'xl' => ['fr' => 'XL', 'en' => 'XL'],
    ],
    // Options de personnalisation : valeur => [fr, en, supplément en dollars, aperçu (couleur)]
    'options' => [
        'gravure' => ['fr' => 'Gravure', 'en' => 'Engraving', 'values' => [
            'gravure-sans' => ['Sans gravure', 'No engraving', 0, null],
            'gravure-prenom' => ['Prénom', 'First name', 5, null],
            'gravure-logo' => ['Logo', 'Logo', 12, null],
        ]],
        'bouchon' => ['fr' => 'Bouchon', 'en' => 'Cap', 'values' => [
            'bouchon-inox' => ['Inox', 'Stainless steel', 0, '#9ca3af'],
            'bouchon-bambou' => ['Bambou', 'Bamboo', 3, '#a16207'],
        ]],
    ],
    // Produits : variantes [couleur, taille, stock, prix propre en cents ou null] ; une variante sans couleur ni taille
    // pour un produit simple. Promotion datée : special_price, du 1er jour du mois courant à dans deux mois.
    'products' => [
        'tshirt' => [
            'price' => 3500, 'image' => 'exemple-tshirt.jpg', 'pictures' => ['exemple-tshirt.jpg', 'exemple-tshirt-2.jpg'],
            'categories' => ['vetements'], 'isbestseller' => true, 'isnewarrival' => true, 'isfeatured' => true,
            'variants' => [['noir', 's', 8, null], ['noir', 'm', 12, null], ['noir', 'l', 6, null], ['noir', 'xl', 3, 3900], ['blanc', 'm', 10, null], ['blanc', 'l', 0, null], ['bleu', 'm', 5, null]],
            'fr' => ['name' => 'T-shirt Horizon', 'description' => $p('T-shirt unisexe en coton biologique, coupe droite.'), 'moreinformations' => $p('Lavage à 30 °C. Coton biologique certifié.'), 'slug' => 't-shirt-horizon'],
            'en' => ['name' => 'Horizon T-shirt', 'description' => $p('Unisex organic cotton T-shirt, regular fit.'), 'moreinformations' => $p('Machine wash at 30 °C. Certified organic cotton.'), 'slug' => 'horizon-t-shirt'],
        ],
        'sweat' => [
            'price' => 6900, 'special_price' => 4900, 'promotion' => true, 'image' => 'exemple-sweat.jpg',
            'categories' => ['vetements'], 'isspecialoffer' => true,
            'variants' => [['noir', 'm', 4, null], ['noir', 'l', 5, null], ['bleu', 'l', 2, null]],
            'fr' => ['name' => 'Sweat Brise', 'description' => $p('Sweat à capuche molletonné, poche kangourou.'), 'slug' => 'sweat-brise'],
            'en' => ['name' => 'Breeze hoodie', 'description' => $p('Fleece hoodie with a kangaroo pocket.'), 'slug' => 'breeze-hoodie'],
        ],
        'veste' => [
            'price' => 11900, 'image' => 'exemple-veste.jpg', 'categories' => ['vetements'],
            'variants' => [['noir', 'm', 3, null], ['noir', 'l', 2, null]],
            'fr' => ['name' => 'Veste coupe-vent', 'description' => $p('Veste légère imperméable, capuche rangeable.'), 'slug' => 'veste-coupe-vent'],
            'en' => ['name' => 'Windbreaker jacket', 'description' => $p('Light waterproof jacket with a packable hood.'), 'slug' => 'windbreaker-jacket'],
        ],
        'casquette' => [
            'price' => 2900, 'image' => 'exemple-casquette.jpg', 'categories' => ['accessoires'], 'isbestseller' => true,
            'variants' => [[null, null, 34, null]],
            'fr' => ['name' => 'Casquette brodée', 'description' => $p('Casquette en coton biologique, taille ajustable.'), 'slug' => 'casquette-brodee'],
            'en' => ['name' => 'Embroidered cap', 'description' => $p('Organic cotton cap, adjustable size.'), 'slug' => 'embroidered-cap'],
        ],
        'sac' => [
            'price' => 14900, 'image' => 'exemple-sac.jpg', 'pictures' => ['exemple-sac.jpg', 'exemple-sac-2.jpg', 'exemple-sac-3.jpg'],
            'categories' => ['accessoires'], 'isfeatured' => true,
            'variants' => [[null, null, 9, null]],
            'fr' => ['name' => 'Sac de voyage Escale', 'description' => $p('Sac de 40 L en toile cirée, bandoulière amovible.'), 'slug' => 'sac-de-voyage-escale'],
            'en' => ['name' => 'Escale travel bag', 'description' => $p('40 L waxed canvas bag with a removable shoulder strap.'), 'slug' => 'escale-travel-bag'],
        ],
        'gourde' => [
            'price' => 3200, 'image' => 'exemple-gourde.jpg', 'categories' => ['accessoires'], 'isnewarrival' => true, 'customizable' => true,
            'variants' => [[null, null, 24, null]],
            // Combinaisons de personnalisation de la variante : [valeurs, image, stock]
            'combinations' => [
                [['gravure-sans', 'bouchon-inox'], 'exemple-gourde-1.jpg', 20],
                [['gravure-sans', 'bouchon-bambou'], 'exemple-gourde-2.jpg', 10],
                [['gravure-prenom', 'bouchon-inox'], 'exemple-gourde-3.jpg', 15],
                [['gravure-prenom', 'bouchon-bambou'], 'exemple-gourde-4.jpg', 8],
                [['gravure-logo', 'bouchon-inox'], 'exemple-gourde-5.jpg', 6],
                [['gravure-logo', 'bouchon-bambou'], 'exemple-gourde-6.jpg', 0],
            ],
            'fr' => ['name' => 'Gourde isotherme à graver', 'description' => $p('Gourde inox de 750 mL : gravure et bouchon au choix.'), 'slug' => 'gourde-isotherme-a-graver'],
            'en' => ['name' => 'Engravable insulated bottle', 'description' => $p('750 mL stainless steel bottle: choose your engraving and cap.'), 'slug' => 'engravable-insulated-bottle'],
        ],
        'bougie' => [
            'price' => 2400, 'image' => 'exemple-bougie.jpg', 'categories' => ['maison'],
            'variants' => [[null, null, 18, null]],
            'fr' => ['name' => 'Bougie parfumée', 'description' => $p('Bougie à la cire végétale, environ 40 heures de combustion.'), 'slug' => 'bougie-parfumee'],
            'en' => ['name' => 'Scented candle', 'description' => $p('Plant-based wax candle, about 40 hours of burn time.'), 'slug' => 'scented-candle'],
        ],
        'cafe' => [
            'price' => 1900, 'image' => 'exemple-cafe.jpg', 'categories' => ['maison'],
            'variants' => [[null, null, 25, null]],
            'fr' => ['name' => 'Café en grains, 500 g', 'description' => $p('Café de spécialité, torréfaction moyenne.'), 'slug' => 'cafe-en-grains-500-g'],
            'en' => ['name' => 'Whole bean coffee, 500 g', 'description' => $p('Specialty coffee, medium roast.'), 'slug' => 'whole-bean-coffee-500-g'],
        ],
    ],
    'homeslider' => [
        'collection' => [
            'image' => 'exemple-accueil-1.jpg', 'button_url' => '/catalogue', 'is_diplayed' => true,
            'fr' => ['title' => 'Nouvelle collection', 'description' => 'Des basiques en coton biologique, pensés pour durer.', 'button_message' => 'Découvrir'],
            'en' => ['title' => 'New collection', 'description' => 'Organic cotton essentials, made to last.', 'button_message' => 'Discover'],
        ],
        'cadeaux' => [
            'image' => 'exemple-accueil-2.jpg', 'button_url' => '/catalogue', 'is_diplayed' => true,
            'fr' => ['title' => 'Idées cadeaux', 'description' => 'Des objets personnalisables pour faire plaisir.', 'button_message' => 'Voir les idées'],
            'en' => ['title' => 'Gift ideas', 'description' => 'Personalized items to delight your loved ones.', 'button_message' => 'See ideas'],
        ],
        'soldes' => [
            'image' => 'exemple-accueil-3.jpg', 'button_url' => '/catalogue', 'is_diplayed' => true,
            'fr' => ['title' => 'Soldes de saison', 'description' => 'Jusqu\'à 30 % sur une sélection.', 'button_message' => 'J\'en profite'],
            'en' => ['title' => 'Seasonal sale', 'description' => 'Up to 30% off a selection.', 'button_message' => 'Shop the sale'],
        ],
    ],
    // Atouts : sans icône (le frontend affiche son pictogramme par défaut)
    'features' => [
        'livraison' => ['fr' => ['title' => 'Livraison rapide'], 'en' => ['title' => 'Fast delivery']],
        'paiement' => ['fr' => ['title' => 'Paiement sécurisé'], 'en' => ['title' => 'Secure payment']],
        'retours' => ['fr' => ['title' => 'Retours sous 30 jours'], 'en' => ['title' => '30-day returns']],
        'service' => ['fr' => ['title' => 'Service client à l\'écoute'], 'en' => ['title' => 'Friendly customer service']],
    ],
];
