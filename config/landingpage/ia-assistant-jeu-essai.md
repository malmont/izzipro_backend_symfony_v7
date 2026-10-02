# Assistant IA : jeu d'essai

Correction du 28/09/2026 : R4 et R9 partaient de modèles sans colonne Navigation ni badge (signalé par l'évaluation de l'étape 1) ; leurs départs sont corrigés.

Cas rejoués à chaque changement de prompt ou de modèle (conception : `ia-assistant-conception.md`). Les compositions de départ sont des modèles réels (`<famille>/<modèle>`), ou des sections des sites (copies locales, jamais d'écriture en production).

## Vérifications automatiques, communes à tous les cas

- **V1** : `validateCanvas` renvoie `[]` et le JSON Schema accepte la composition.
- **V2** : seulement les types de blocs de la famille (`family.tools`).
- **V3** : aucune URL ni clé de média absente de la liste fournie (médias du site et médias de la demande).
- **V4** : les liaisons (`bindings`) existantes sont conservées, sauf demande contraire.
- **V5** : aucun prix, chiffre ou nom propre absent des données ou de la demande.
- **V6** (retouches) : les blocs non visés par la demande sont identiques, au même identifiant.

## Étape 1 : retoucher

| # | Départ | Demande | Vérifications propres | Critère humain |
|---|---|---|---|---|
| R1 | groupe T (Arkanoa) | « Rends la section plus aérée. » | `rootGap` et `gap` des conteneurs augmentés ; aucun texte modifié | Plus d'air, même hiérarchie |
| R2 | groupe V | « Cartes des formules sur 2 colonnes. » | liste `columns` = 2 ; `mobile.columns` inchangé | Grille correcte |
| R3 | présentation J (Mémoires Vivantes) | « Passe aux couleurs vert sapin et or de la marque. » | couleurs uniquement parmi celles du site ; textes et liaisons inchangés | Cohérent avec le site |
| R4 | footer D (`footer/footer-type-d`, colonne « Navigation » : `d-nav-titre`) | « Sur mobile, centre tout et masque la colonne Navigation. » | `mobile.align` = center ; colonne `mobile.hidden` ; grand écran inchangé | Mobile propre |
| R5 | navbar G | « Mets le bouton Démarrer un projet en dégradé. » | bouton : `background` dégradé valide ; autres blocs identiques | Lisible |
| R6 | groupe S | « Réécris le titre et l'introduction, ton plus chaleureux. » | seuls `text` du titre et de l'intro changent ; liaisons intactes | Ton juste, pas de faits inventés |
| R7 | présentation I | « Traduis tous les textes en anglais. » | chaque texte non lié a `translations.en` ; textes de base inchangés | Traduction naturelle |
| R8 | groupe R (Mémoires Vivantes) | « Ajoute une animation d'apparition échelonnée aux cartes. » | `repeat.stagger` > 0 ou `animation` sur la carte ; valeurs du contrat | Effet discret |
| R9 | vidéo A (`video/video-type-a`, badge `a-badge` « ✦ Immersion Visuelle ») | « Supprime le badge et agrandis le titre. » | bloc badge retiré ; `size` du titre augmenté ; aucun enfant orphelin | Correct |
| R10 | groupe V | « Ajoute un filtre sombre sur l'image de fond. » (sans image de fond) | aucune image inventée ; réponse qui explique qu'il n'y a pas d'image | Refus clair et utile |

## Étape 2 : créer

| # | Famille, site | Demande | Vérifications propres | Critère humain |
|---|---|---|---|---|
| C1 | Services (Arkanoa) | « Grille de mes services avec prix et bouton Réserver. » | liste `repeat.source` = services ; `item.title` et `item.price` liés ; bouton `action` = reservation, `offer` lié à `item.title` | Prête à publier |
| C2 | Groupe (Mémoires Vivantes) | « Section qui présente mes forfaits. » | `dataType` choisi parmi les groupes du site (donnée par défaut) ; liaisons `item.*` | Bonne donnée choisie |
| C3 | Présentation (L'Intendant) | « Héros avec cette vidéo : https://…/intro.mp4, titre et bouton de contact. » | vidéo avec exactement cette URL, en bloc vidéo **ou** en fond de section (`bgVideo`, comme le modèle « héros vidéo ») ; bouton `#contact` ou `action` = contact | Héros crédible |
| C4 | Présentation (ESG Boost) | « Section À propos avec la photo de l'équipe » + clé de média fournie | bloc image `mediaKey` = la clé fournie | Mise en page soignée |
| C5 | Groupe (Arkanoa) | « Comme la section Tarifs, mais pour le Branding. » | structure proche du modèle V ; nouveaux textes ; aucun prix inventé | Cohérent |
| C6 | Contact | « Formulaire de contact avec coordonnées à gauche. » | bloc `form` ; liaisons email et téléphone | Utile |
| C7 | Navbar | « Barre transparente sur le héros, menu burger sur mobile. » | `overlayTop` ; `navMobile` burger ; liens liés aux onglets du site | Correct |
| C8 | Groupe | « Enregistre ce résultat comme modèle "Cartes premium". » | action de l'éditeur, sans objet pour le backend : bouton « 💾 Enregistrer comme modèle » de la proposition (modèle personnel de la bonne famille, composition valide) | — |

## Étape 3 : composer

| # | Entrée | Demande | Vérifications propres | Critère humain |
|---|---|---|---|---|
| P1 | charte : 2 couleurs, 1 police, logo | « Page d'accueil d'un cabinet de conseil : héros, services, témoignages, contact. » | 4 sections valides ; couleurs et police de la charte seulement | Page cohérente |
| P2 | capture d'écran d'un site modèle | « Reproduis cette section. » | composition valide ; structure en rangées et colonnes proche de la capture | Ressemblance reconnaissable |
| P3 | page générée en P1 (ou toute section) | auto-critique : bouton « 🔍 Relecture visuelle » (captures ordinateur et mobile envoyées en retouche avec images) | au moins une amélioration mesurable (contraste, espacement) sans casser V1 à V6 | Meilleure qu'avant |

## Mesures relevées à chaque passage

Taux de V1 à V6 réussies, nombre d'essais de correction, jetons (entrée, cache, sortie), durée, et coût estimé par mode. Ces mesures servent à ajuster les crédits (1 / 3 / 10) avant le lancement.
