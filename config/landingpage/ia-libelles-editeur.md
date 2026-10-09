# Libellés de l'éditeur pour l'assistant IA

Chemins exacts des réglages de l'éditeur des landing pages, à citer tels quels (étapes séparées par « → »). Ne jamais indiquer un réglage absent de cette liste.

Conventions :
- **Panneau** : panneau latéral de l'éditeur. Il s'ouvre par le bouton « ⚙️ Éditeur » (en bas à droite de la page, administrateur connecté) ou par « ⚙️ Réglages » dans la barre au-dessus d'une section.
- **Barre de la section** : barre d'outils affichée au-dessus de chaque section sur la page, en mode édition.
- **Barre du bloc** : petite barre flottante du bloc sélectionné sur la page.
- Les cartes du panneau d'une section sont dans « Section N » → « Éditeur visuel ». Ci-dessous, « Panneau → Section N → » est abrégé en « Section → ».
- Les réglages d'un bloc n'apparaissent qu'une fois le bloc sélectionné (clic sur le bloc dans la page, ou dans « 🗂 Calques »).

## Structure des pages et des sections

- Ajouter une page (onglet du menu) : Panneau → « ＋ Ajouter un onglet »
- Supprimer l'onglet affiché : Panneau → « × » à côté de « ＋ Ajouter un onglet »
- Nom de l'onglet dans le menu : Panneau → « Titre de l'onglet (Français) » / « Titre de l'onglet (Anglais) »
- Page hors du menu (ouverte par un lien ou un bouton) : Panneau → décocher « Afficher dans le menu du site »
- Ajouter une section à la page : Panneau → « ＋ Ajouter une section » (en bas de la liste des sections)
- Monter, descendre, supprimer une section : Panneau → en-tête « Section N » → « ↑ », « ↓ », « × »
- Nommer une section (pour la retrouver et la désigner dans la console IA) : Panneau → Section N → « Nom de la section (facultatif) »
- Changer la famille d'une section (repart d'une composition neuve) : Panneau → Section N → « Type de composant »
- Annuler, rétablir : Section → « ↩ » / « ↪ » (ou Ctrl/⌘ + Z, Ctrl + Y)
- Aperçu ordinateur / téléphone de la section seule : Section → « 👁 Aperçu »
- Ajouter un bloc : Barre de la section → « Bloc », « Titre », « Texte », « Bouton », « Image », « Badge », « Ligne », « Chiffre », « Vidéo », ou « ＋ Plus » (menu du site, langue, formulaire, carrousel, intégration, « Étoiles (note) », « Compte à rebours »…)
- Étoiles (bloc « Étoiles (note) ») : réglages « Valeur (note) », « Nombre de symboles », « Symbole » (Étoile, Cœur), « Symboles vides », « Afficher la valeur en chiffres » ; valeur modifiable par la fenêtre « Contenu »
- Compte à rebours (bloc « Compte à rebours ») : réglages « Date de fin », « Présentation » (Cases ; Sur une ligne ; Date en toutes lettres), « Afficher les secondes », « Texte une fois terminé » ; libellé avant le décompte et texte de fin par la fenêtre « Contenu » ; la date peut venir des données (« Contenus liés » → « Compte à rebours (fin de promotion) »)
- Texte barré (prix régulier en promotion) : Panneau → Avancé → « Barré (prix régulier en promotion) »
- Diaporama de cartes (bloc en disposition « Diaporama ») : « Cartes par vue » (Panneau → Général), « Cartes par vue sur mobile » (onglet Mobile)
- Ajouter un ensemble prêt à l'emploi (titre + texte, carte, chiffres clés, scène au défilement…) : Barre de la section → « 🧩 Composants »
- Dupliquer, passer devant, passer derrière, supprimer un bloc : Barre du bloc
- Liste des blocs de la section, pour en sélectionner un : Section → « 🗂 Calques »
- Organisation des blocs dans un conteneur (libre, pile, rangée, grille, diaporama, scène au défilement) : bloc conteneur sélectionné → « Disposition des enfants »
- Composition brute (JSON) : Section → « Configuration JSON »
- Enregistrer les changements de mise en page : Panneau → « 💾 Sauvegarder » (en haut)

## Style de la section

Carte Section → « 📐 Section : dimensions & fond » :
- « Couleur de fond », « Image de fond (URL) », « Vidéo de fond », « Image de fond fixe au défilement »
- « Filtre sombre fond (%) », « Voile sur l'image », « Cadrage de l'image »
- « Hauteur min. (% écran) », « Hauteur min. mobile (%) »
- « Largeur maximale (px) », « Largeur du contenu (px) », « Marge intérieure (px) », « Espacement (px) »
- « Disposition de la section » (« Libre (positions en %) » ou « Pile verticale »)
- « Ancre de la section » (lien vers la section depuis un bouton ou le menu)
- Barre de navigation seulement : « Barre superposée au début de la page (fond transparent sur un héros) », « Fond après défilement », « Couleur du texte après défilement », « Fond flouté (verre) après défilement »

Style d'un bloc (bloc sélectionné) :
- Couleurs, taille, alignement : carte du bloc (« … sélectionné ») → « Couleur de fond », « Couleur texte », « Taille texte (px) », « Alignement », « Hauteur (px) », « Couleur bordure »
- Police, interligne, ombre, survol, animation d'apparition : carte « 🎨 Style avancé » → « Police », « Interligne », « Espacement lettres (em) », « Ombre », « Ombre du texte », « Effet au survol », « Opacité (%) », « Apparition (à l'écran) », « Délai d'apparition (s) », « Durée d'apparition (s) »
- Un mot en couleur, dans une autre police ou plus grand : « 🎨 Style avancé » → « Mot mis en valeur », « Couleur du mot », « Police du mot », « Taille du mot (× texte) »
- Gras, italique, couleur ou lien sur une partie du texte : double-clic sur le texte dans la page, sélectionner les mots, barre de mise en forme (B, I, U, S, couleurs, 🔗)
- Il n'y a pas de police commune à tout le site : la police se règle bloc par bloc (« 🎨 Style avancé » → « Police »).

Menu du site (bloc « Menu du site » sélectionné ; un clic sur un lien du menu le retrouve dans la liste) :
- Liens : « Liens du menu » → texte français et anglais, adresse, « ↑ » « ↓ » (ordre dans le menu), « ✕ » (retirer un lien), « Afficher dans le menu » (onglet du site), « Ouvrir la page », « ＋ Ajouter un lien » (section de la page #contact, autre page, site externe), « Ouvrir dans un nouvel onglet »
- Effet des liens : « Style des liens » → « Texte simple », « Souligné », « Pastille », « Bouton plein », « Bouton contour »
- Couleurs et forme : « Couleur du lien de la page affichée », « Couleur du texte au survol », « Couleur du surlignage » (souligné), « Fond des liens » (bouton plein), « Bordure des liens » (bouton contour), « Fond du lien de la page affichée », « Fond au survol », « Arrondi des liens (px) », « Taille du bouton (marge, px) », « Espacement des liens (px) », « Disposition des liens »
- Mobile : « Sur mobile » (burger, liens empilés, masqué), « Bouton burger », « Choix de la langue dans le tiroir mobile », « Fond du tiroir mobile », « Texte du tiroir mobile »

Sur mobile (bloc sélectionné) : carte « 📱 Sur mobile » → « Masquer sur mobile », « Taille texte mobile (px) », « Ordre sur mobile », « Alignement sur mobile », « Largeur mobile (%) », « Colonnes sur mobile », « Hauteur min. mobile (px) » ; « 📱 Voir l'aperçu mobile ».

## Données de la section

- Choisir la fiche affichée par la section (présentation, groupe, bannière, vidéo…) : Panneau → Section N → « Données à afficher »
- Modifier le texte, l'image ou le bouton d'un bloc lié aux données (écrit dans la fiche, partout où elle est affichée) : double-clic sur le bloc, ou « ✏️ » dans la Barre du bloc, ou bloc sélectionné → « ✏️ Modifier la donnée » ; fenêtre « Modifier … » (Version Français / Anglais, « 🕘 Historique »)
- Ajouter, retirer, réordonner les présentations d'un groupe : fenêtre « Modifier le groupe de présentations » → « ＋ Ajouter une présentation », « ↑ », « ↓ », « ✎ », « ✕ »
- Donner à un bloc un texte propre à cette section (ne plus afficher la fiche) : bloc sélectionné → « Détacher (contenu propre à la section) », ou dans la fenêtre de la fiche → « Texte propre à cette section »
- Relier un bloc à une donnée de la section : fenêtre « Contenu » du bloc → « Relier aux données »
- Ajouter un bloc déjà lié à un champ des données : Section → « 🔗 Blocs liés aux données »
- Texte, lien, image ou vidéo d'un bloc non lié : double-clic sur le bloc, ou bloc sélectionné → « ✏️ Modifier le contenu » ; fenêtre « Contenu » (textes français et anglais)
- Nombre d'éléments affichés d'une liste : bloc liste sélectionné → « Nombre d'éléments max »
- Textes légaux (mentions légales, conditions, confidentialité) : lien du pied de page vers la page → « Modifier ce texte » (choix de la version Français / Anglais, puis « Enregistrer »)

## Médiathèque

Dans toute fenêtre avec un champ image ou vidéo (« Contenu », « Modifier … ») :
- Envoyer un fichier : « ⬆ Téléverser une image » / « ⬆ Téléverser une vidéo », ou glisser le fichier sur l'aperçu
- Choisir un média déjà envoyé : « 🗂 Médiathèque » (recherche par titre, « Afficher plus »)
- Renommer ou supprimer un média : « 🗂 Médiathèque » → « ✎ » / « 🗑 » sur le média (suppression refusée tant qu'il sert)
- Vidéo pour une scène au défilement : cocher « Préparer pour une scène au défilement » avant l'envoi
- Retirer l'image ou la vidéo du champ : « Retirer »
- Fond de section : Section → « 📐 Section : dimensions & fond » → « Image de fond (URL) », « Vidéo de fond »

## Modèles

Modèles de site (site entier : navbar, footer, onglets) — Panneau → carte « 🏛️ Modèles de site » :
- Garder une version du site : « Mes modèles » → « 💾 Enregistrer ce site » (« Nom du modèle », « Description (facultatif) »)
- Appliquer, mettre à jour, télécharger, supprimer une version : « Mes modèles » → « Appliquer », « ⟳ », « ⬇ », « × »
- Repartir d'un modèle générique : « Prêts à l'emploi » → « Appliquer »
- Fichier : « ⬇ Télécharger ce site », « 📂 Charger un fichier »
- Annuler un modèle appliqué mais pas encore enregistré : « Revenir au site précédent »
- Un modèle appliqué ne s'enregistre qu'avec « 💾 Sauvegarder »

Modèles d'une section (composition de la section) :
- Remplacer la section par un modèle de sa famille : Barre de la section → « ✨ Modèles », ou Section → « ✨ Modèles »
- Enregistrer la section comme modèle : Section → « ✨ Modèles » → « Enregistrer comme modèle »

## Historique

- Toutes les modifications du site (fiches, textes légaux, réglages, modèles, médias ; 180 jours) : Panneau → carte « 🕘 Historique du site » → « Afficher » (filtre par type), « Détail », « ↺ Restaurer l'état d'avant »
- Historique d'une fiche : fenêtre « Modifier … » → « 🕘 Historique »
- Annuler un changement de mise en page non enregistré : Section → « ↩ », ou Ctrl/⌘ + Z

## Réglages du site

Panneau → « Paramètres Généraux » :
- Menu du site : « Navbar (menu du site) »
- Pied de page : « Footer (pied de page) »
- Page produit de la boutique : « Style/Type de la Page Produit »
- Référencement : « Référencement & SEO (Multi-Tenant) » → « Meta Title », « Meta Description », « Mots-clés SEO (séparés par des virgules) », « Image Open Graph (og:image) », « Google Site Verification (Search Console) », puis « 💾 Sauvegarder le SEO »

## Assistant IA

- Retoucher ou recréer une section : Section → « 🤖 Assistant IA » (« Retoucher », « Recréer », « 🔍 Relecture visuelle par l'IA »)
- Plusieurs sections d'un coup, ou une section désignée par son nom ou son numéro : bouton « ✨ Console IA » → « 🧩 Sections » (« Onglet », « Cible »)
- Prompt d'une vidéo de scène au défilement : « ✨ Console IA » → « 🎬 Prompt vidéo »

## Boutique réglable (panneau « Éditeur Boutique »)

Mêmes cartes et mêmes libellés que la landing page, plus :
- Pages du menu et pages système (fiche produit, personnalisation, catalogue, adresse, détail de commande, demande de financement, nouveau mot de passe, panier, paiement, confirmation, compte, connexion, inscription, mot de passe oublié) : Panneau → « Pages de la boutique » ; « ＋ Ajouter un onglet » y ajoute une page du menu
- Une page système a une adresse fixe, reste hors menu et exige ses blocs obligatoires ; tant qu'elle n'a aucune section, l'ancienne page s'affiche
- Charte du site (couleurs principale, d'accent, du texte, de fond ; polices des titres et du texte ; arrondi ; style des boutons) : Panneau → « Paramètres Généraux » → « Charte du site »
- Paiement sans compte, abonnements, devise : Panneau → « Paramètres Généraux » → « Commerce » → « Paiement sans compte (invité) », « Abonnements (paiement récurrent) », « Devise »
- Les modes de vente (achat, location, abonnement) s'activent en posant leurs composants sur la fiche produit : Barre de la section → « 🧩 Composants »
- Fiche produit (famille « Fiche produit (boutique) », page /produit/…) : modèles « Type A · Classique », « Type C · Options en liste déroulante », « Type D · Onglets », « Type E · Location », « Type F · Achat et location » : Barre de la section → « ✨ Modèles »
- Poser un mode de vente sur la fiche produit : Barre de la section → « 🧩 Composants » → « Mode · Achat », « Mode · Location », « Mode · Abonnement », « Sélecteur de mode », « Galerie du produit », « Fiche technique (véhicule) », « Partager », « Bouton Personnaliser »
- Mode de vente d'un groupe : bloc conteneur sélectionné → « Mode de vente du groupe » (Achat, Location, Abonnement, Aucun)
- Blocs du commerce (Barre de la section → « ＋ Plus ») : « Sélecteur de mode », « Prix », « Options (taille, couleur…) », « Quantité », « Ajouter au panier », « Galerie du produit », « Stock », « Pack tarifaire », « Calendrier de réservation », « Caution », « Fiche technique », « Partager », « Bouton Personnaliser » ; catalogue : « Catégories », « Pagination »
- Réglages d'un bloc du commerce (bloc sélectionné) : « Présentation » (sélecteur de mode, options, galerie, stock, formules, catégories), « Libellé », « Prix régulier barré en promotion », « Unité de vente après le prix », « Libellé de l'achat » / « … de la location » / « … de l'abonnement », couleurs « Couleur du mode actif », « Couleur de l'option choisie », « Couleur des créneaux choisis »…
- Personnalisation (famille « Personnalisation (boutique) », page /customization/…) : blocs « Groupes d’options (personnalisation) », « Aperçu personnalisé », « Prix personnalisé », « Ajouter au panier (personnalisé) » ; modèles « Type A · Aperçu à gauche, options à droite », « Type B · Aperçu en haut, options en pastilles » ; réglages : « Présentation des options » (Cartes, Pastilles), « Icônes des options », « Supplément de prix des options », « Couleur de l’option choisie », « Libellé avant le prix » ; texte du bouton : fenêtre « Contenu »
- Abonnement : fiche produit, groupe « Mode · Abonnement » de « 🧩 Composants » avec les blocs « Formules d’abonnement » et « Bouton S’abonner » ; page système « Souscription d’un abonnement » (/subscribe/…, famille « Abonnement (boutique) », bloc « Souscription (formule, adresse, paiement) », modèle « Type A · Souscription en une carte ») ; « Mes abonnements » dans la page Mon compte (onglet du modèle « Type A ») ; réglage « Abonnements (paiement récurrent) » et « Taxes » (Table du site, Stripe Tax) dans « Commerce »
- Données de la boutique modifiables depuis la page (double-clic sur un bloc lié) : fenêtre « Modifier le produit » (Nom, Description, Plus d’informations, Prix, Prix promotionnel, Promotion du, Promotion jusqu’au, Image principale, Galerie), « Modifier la catégorie » (Nom, Description, Image), « Modifier la diapositive » (Titre, Texte, Texte du bouton, Lien du bouton, Image), « Modifier la carte » (Titre, Sous-titre, Texte, Lien, Image, Vidéo) ; les options de personnalisation restent dans EasyAdmin
- Calendrier de réservation : champ « Passagers en plus » affiché quand le produit a un supplément par passager
- Calendrier de réservation (fiche produit, location à la journée) : réglage « Choix des dates (location à la journée) » : « Selon la formule (durée fixe) » (défaut) ou « Plage libre : date de début et date de fin » (le minimum de jours du produit s'applique)
- Catalogue (famille « Catalogue de produits (boutique) », page /catalogue) : catégorie affichée : Panneau → Section N → « Données à afficher » ; modèles « Type A · Catégories en pastilles, grille de produits », « Type B · Catégorie en liste déroulante, produits en lignes » ; la liste de produits est un conteneur « 📋 Liste » lié aux produits de la section, ses cartes se règlent comme tout gabarit
- Panier (famille « Panier (boutique) », page /cart) : blocs « Lignes du panier », « Totaux du panier », « Bouton Commander » ; modèles « Type A · Lignes à gauche, totaux à droite », « Type B · Une colonne » ; réglages : « Vignettes des produits », « Texte si le panier est vide », « Ligne « Livraison » », « Ligne « Taxes » », « Titre »
- Panneau de la boutique : cartes « 🏛️ Modèles de site » (« Mes modèles », fichier ; pas de modèle prêt à l'emploi pour la boutique) et « 🕘 Historique du site » (journal commun, ressource « Réglages de la boutique »), comme sur la landing page ; la devise du site se lit dans « Commerce » (« Devise du site ») et se change dans la fiche entreprise
- Console IA de la boutique : même pastille « ✨ Console IA » que la landing page (en mode édition) ; « Onglet » propose aussi les pages système ; « Familles des nouvelles sections » propose les familles de la boutique et les familles communes
- Accueil de la boutique (onglet « Accueil », familles « Diaporama de l’accueil (boutique) », « Cartes explorer (boutique) », « Catégories (boutique) », « Atouts (boutique) », « Infolettre (boutique) », plus « Carrousel de produits (boutique) ») : modèles « Type A · Diaporama pleine largeur », « Type B · Diaporama encadré, texte à gauche », « Type A · Deux grandes cartes », « Type B · Bandeaux image et texte alternés », « Type A · Pastilles rondes », « Type B · Cartes image avec nom en bas », « Type A · Quatre atouts en cartes », « Type B · Bandeau sombre, atouts en ligne », « Type A · Carte sombre centrée », « Type B · Bandeau clair, titre à gauche » ; bloc « Infolettre (boutique) » (texte du bouton : fenêtre « Contenu »)
- Carrousel de produits (famille « Carrousel de produits (boutique) », section « Carousel ») : une liste répétée de cartes composées ; sélection : bloc de la liste → « Sélection de produits » (Meilleures ventes (défaut), Nouveautés, Offres spéciales (promotions en cours), En vedette, Accessoires) ; modèles « Type A · Carrousel de cartes, 4 par vue », « Type B · Grille, 4 colonnes », « Type C · Offres spéciales, compte à rebours », « Type D · Bandeau sombre, nouveautés, 3 par vue » ; outils de la carte (« Contenus liés ») : « Nom du produit », « Image du produit », « Prix », « Prix régulier (barré) », « Badge promotion », « Catégorie », « Bouton « Voir » », « Ajouter au panier », « Badge nouveauté », « Badge meilleure vente », « Mode de vente (achat, location, abonnement) », « Location « à partir de » », « Promotion jusqu’au (texte) », « Compte à rebours (fin de promotion) », « Étoiles (note du produit) » ; plus de « Style de carte » ni de « Produits affichés » (les anciennes cartes A à F n'existent plus)
- Navbar et pied de page de la boutique : modèles « Boutique · Logo, menu, compte et panier », « Boutique · Sombre, menu à droite », « Boutique · Quatre colonnes et infolettre » dans « ✨ Modèles » de la navbar et du pied de page, en plus des modèles communs
- Badge du panier dans la navbar ou le footer : Barre de la section (navbar) → « ＋ Plus » → « Panier (badge) » ; réglage « Présentation » (icône et compteur, pastille, texte)
- Paiement (famille « Paiement (boutique) », page /CheckoutPage) : blocs « Identité (invité ou client) », « Adresse de livraison », « Permis de conduire (location) », « Livraison (transporteur) », « Récapitulatif de commande », « Paiement (Stripe) » ; modèles « Type A · Deux colonnes », « Type B · Étapes empilées » ; réglages : « Titre du bloc », « Couleur d'accent » ; texte du bouton de paiement : fenêtre « Contenu »
- Confirmation (famille « Confirmation de commande (boutique) », page /confirmation) : bloc « Confirmation de commande », modèle « Type A · Merci et récapitulatif »
- Les messages (réservation refusée, estimation impossible, commande en échec) s'affichent en bas à gauche de la page, à la place des anciennes fenêtres du navigateur
- Mon compte (famille « Mon compte (boutique) », page /dashboard) : blocs « Mes commandes », « Mes adresses », « Transporteurs », « Mon profil » ; modèles « Type A · Onglets », « Type B · Sections empilées » ; menu compte dans la navbar : Barre de la section (navbar) → « ＋ Plus » → « Menu compte (connexion / mon compte) »
- Adresse et détail de commande (famille « Mon compte (boutique) », pages /address/new, /address/edit/…, /order/…) : blocs « Formulaire d’adresse », « Détail de commande » ; modèles « Adresse · Formulaire (création et modification) », « Commande · Détail » ; réglage « Vignettes des articles » ; texte du bouton d’adresse : fenêtre « Contenu »
- Demande de financement (famille « Demande de financement (boutique) », page /financement ; bloc aussi proposé sur la fiche produit, extrait « Demande de financement » de « 🧩 Composants ») : bloc « Demande de financement » (assistant en 8 étapes) ; modèle « Type A · Assistant pas à pas » ; texte du bouton d’envoi : fenêtre « Contenu »
- Connexion, inscription, mot de passe oublié (famille « Authentification (boutique) », pages /login, /register, /forgot-password) : blocs « Connexion » (avec le code de connexion reçu par courriel), « Inscription », « Mot de passe oublié », « Nouveau mot de passe (lien reçu) », « Renvoyer la vérification » ; modèles « Connexion », « Inscription », « Mot de passe oublié », « Nouveau mot de passe » ; texte des boutons : fenêtre « Contenu »
