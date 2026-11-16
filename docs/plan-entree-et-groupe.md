# Entrée dans un autre site (AG-3) et multi-boutiques (GROUPE) : plan commun du 16/11/2026

PLAN VALIDÉ par le propriétaire le 16/11/2026 (ses réponses au § 7). Rien de ce qui suit n'existe encore dans le serveur, sauf le lot
TYPE-1 (`docs/agences-et-import.md` § 9). Ordre décidé : TYPE-1, AG-3a, AG-3b, AG-3c, AG-3d, puis les lots G ; le contrat de chaque
sous-lot est validé par le chef de projet avant son code ; AG-3b, l'entrée dans un autre site, est relu en deux passes au moins. Il suit les
décisions du propriétaire relayées par le chef de projet le 16/11/2026 (un seul sélecteur décidé par le serveur ; l'agence ne
connaît jamais les chiffres de ses clients ; seul un groupe réunit les chiffres ; activation à deux niveaux ; pastilles calculées ;
type d'un site choisi à sa création). Il s'appuie sur une lecture du code à la pointe `acfe957a` : agences (`docs/agences-et-import.md`),
garde fermée par défaut de CONSTRUCTION (`docs/boutique-en-construction.md`), calculs de la gestion.

Contrainte du propriétaire : des lots simples, aucun gros chantier sur la structure des bases des sites.

## 1. Le modèle commun : un registre, des modes, un sélecteur

**Un seul sélecteur** sur les fronts, décidé par le serveur. Quatre modes, calculés depuis les liens réels de la base maître, jamais
depuis un champ « mode » tenu à la main :

| Mode | Lien qui le fonde | Sélecteur | Périmètre |
|---|---|---|---|
| Boutique seule | aucun | pas de sélecteur | comme aujourd'hui |
| Groupe | le site est le siège ou une boutique d'un groupe | ses boutiques, plus « Toutes les boutiques » | complet |
| Agence | le compte porte un rôle d'agence, le site est créé ou géré par elle | ses sites clients, plus « Tous mes clients » | construction et entretien |
| Super administrateur | compte de la plateforme | tous les sites, plus les écrans de la plateforme | complet |

**Le registre des écrans** est la pièce centrale, et elle n'existe pas : le code tient aujourd'hui quatre listes de codes d'écran
sans lien entre elles. Le registre range chaque écran sous un code, avec :

- son module (l'écran est caché quand le module est inactif) ;
- sa famille : construction, vente, clients, gestion, pertes, assistance, plateforme ;
- les routes de l'API qu'il utilise ;
- la section d'EasyAdmin qui lui correspond.

Un mode est une liste ouverte de familles et d'écrans. Ajouter un mode plus tard (comptable, biographe) ne demande qu'une liste.

**Une route de contextes**, `GET /api/me/contexts`, rend le mode, les sites accessibles, et pour chaque site : ses pastilles, son
état (ouvert ou en construction), ses modules actifs et les codes des écrans permis. Elle rend aussi l'entrée « tous » du mode
(« Toutes les boutiques », « Tous mes clients ») avec ses propres écrans. Le web, le mobile et EasyAdmin construisent leur menu de
cette liste, sans coder les règles d'un mode.

**Masquer est du confort, refuser est la sécurité.** Une garde du serveur, fermée par défaut, refuse toute route qui n'appartient
pas à un écran permis au mode de la requête. Un essai relit toutes les routes de l'application et toutes les sections d'EasyAdmin,
sur le modèle de l'essai de CONSTRUCTION. Volume à ranger : 970 routes, 110 écrans d'EasyAdmin, 32 pages.

**Les pastilles** de la page « Plateforme : sites » sortent des mêmes liens : « Site principal » (la seule marque enregistrée),
« Indépendant », « Agence X », « Géré par l'agence X », « Groupe X ». Un site peut en porter plusieurs ; la liste se filtre.

## 2. L'entrée dans un autre site, construite une fois (AG-3)

Aujourd'hui, un jeton ne vaut que sur son site, et le compte est relu dans la base de ce site : il n'existe aucun rôle entre sites.

Proposition : un **jeton d'entrée**.

1. Le compte, connecté sur son site d'attache, demande l'entrée : `POST /api/me/contexts/enter { site }`.
2. Le serveur vérifie le lien dans la base maître (agence et site géré, siège et boutique du groupe, ou super administrateur) et
   l'état du lien (agence non suspendue, association acceptée).
3. Il rend un jeton court, signé pour le site visé, qui porte l'origine (site, compte), le mode et rien d'autre. Aucun compte n'est
   créé dans la base du site visé, et aucun mot de passe n'y existe. Le jeton se renouvelle depuis le site d'attache seulement :
   retirer le rôle ou suspendre l'agence ferme toutes les entrées en un quart d'heure au plus.
4. Dans le site visé, la requête est vue comme un **opérateur extérieur** : la garde du mode s'applique, fermée par défaut.

Trace des deux côtés, visible du commerçant :

- dans la base maître : qui, de quel site, vers quel site, quand, dans quel mode ;
- dans le site visé : la page « Accès de la plateforme » devient « Accès extérieurs » (plateforme, agence, groupe), et chaque
  écriture faite en entrée porte son auteur au journal du site (« agence X, prénom »).

**EasyAdmin** (super administrateur et groupe d'abord, puis l'agence dans ses seules sections) : un laissez-passer signé, à usage
unique, placé dans l'adresse protégée, valable seulement depuis une session validée par le code à usage unique depuis moins de
12 heures, tracé dans « Accès extérieurs ». Deux manques à combler : la session ne note pas l'heure de cette validation, et elle
ne dure aujourd'hui qu'une heure.

## 3. Mode agence : construction et entretien, jamais un chiffre

Classement des sections pour le mode agence, validé par le propriétaire le 16/11/2026.

| Section | Classement | Remarque |
|---|---|---|
| Catalogue, variantes, catégories, photos, médias | ouverte | le coût d'achat reste caché : il donne la marge |
| Codes-barres et étiquettes | ouverte | |
| Import du catalogue | ouverte | |
| Import des clients | **fermée** | données personnelles du commerçant |
| Pages, site vitrine, menus, charte, apparence | ouverte | |
| Générateur IA (pages, boutique, fiche produit) | ouverte | tracé au nom de l'agence ; il consomme les unités IA du site du client |
| Conditions de vente et mentions légales | ouverte | |
| Transporteurs, frais de port | ouverte | |
| Taxes | ouverte | ligne « Taxes vérifiées par le commerçant » à l'ouverture, cochée par un administrateur du site seulement, écrite au journal ; elle avertit sans bloquer |
| Domaine, modules, réglages techniques, courriel expéditeur | ouverte | les modules dans la limite du forfait de l'agence |
| Ouverture : liste de contrôle, aperçu, « Ouvrir » | ouverte | |
| Paiement : connexion du compte Stripe | **fermée** | le commerçant connecte lui-même son compte ; le passage en réel reste au super administrateur |
| Commandes, ventes, caisse, reçus | **fermée** | |
| Clients, avis, infolettre, demandes de contact | **fermée** | |
| Gestion : statistiques, marge, trésorerie, bilans, budgets, console IA | **fermée** | |
| Prévention des pertes | **fermée** | |
| Assistance (tickets des clients) | **fermée** | |
| Mémoires Vivantes et Boussole (contenus) | **fermée** | livres et réponses : données personnelles |
| Comptes d'équipe du site | **fermée** | l'agence n'invite que l'administrateur |
| Stock : quantités | ouverte | jamais le coût d'achat |

« Tous mes clients » est un tableau technique, lu de la base maître et de l'état de chaque site, sans y entrer : ouvert ou en
construction, modules, invitations en attente, fins d'essai. Aucun chiffre d'affaires. Les incidents techniques viendront avec AG-6.

## 4. Mode groupe

**Activation à deux niveaux.**

1. Le module « Multi-boutiques » est une clé de module ordinaire : le super administrateur l'accorde dans EasyAdmin, une agence
   l'accorde à ses sites créés. Sans le module, l'application ne change pas : ni sélecteur, ni onglet de groupe.
2. L'administrateur du site choisit « Passer en multi-boutiques » : la fiche du groupe naît dans la base maître, et son site en
   est le siège.
3. Quitter le groupe ne supprime rien : chaque boutique redevient indépendante, avec ses données.

**Les boutiques du groupe se gèrent dans la gestion web, jamais dans EasyAdmin.** En mode « Toutes les boutiques », l'onglet
« Boutiques du groupe » propose deux gestes :

- **Créer une boutique** : la file et la fabrication d'AG-2, l'adresse vérifiée par la même route de disponibilité. Une case
  « Copier le catalogue du siège », cochée par défaut, lance le même outil de copie du catalogue (la copie dépend donc du lot G-4 :
  avant lui, la case n'apparaît pas). La boutique naît en construction et se rattache au groupe d'elle-même. Aucune invitation
  n'est obligatoire : le directeur du groupe y entre avec tous les droits, par le mécanisme d'entrée du § 2, sans compte à créer ;
  il peut y inviter un gérant.
- **Associer une boutique existante** : une invitation, que l'administrateur de cette boutique accepte lui-même.

La limite du nombre de boutiques vient du forfait du groupe ; le plafond technique est celui de l'agence (réglable par le super
administrateur).

**« Toutes les boutiques ».** Le front envoie le mode dans un en-tête de contexte. Le serveur revérifie à chaque requête le module
et les liens, puis interroge chaque boutique tour à tour et réunit les réponses, dans la même forme : un total, la liste des
boutiques avec leurs valeurs, une colonne « boutique » dans les listes. Tout est en lecture seule : le serveur refuse toute
écriture dans ce mode, sauf les routes des outils de groupe.

Ce qui rend cela faisable sans réécriture : les calculs de la gestion lisent le site courant à chaque appel, et le serveur change
déjà de site plusieurs fois dans un même traitement (les notifications horaires). Les pièges connus : revenir au site de départ quoi
qu'il arrive, et ne jamais appeler en mode groupe une lecture qui écrit (créer les périodes manquantes, figer un résultat, lancer
l'analyse des pertes, marquer un constat « vu »).

**Devises et fuseaux.** Aucune conversion de devises n'est fiable aujourd'hui dans le serveur. Proposition : au premier lot, un
total **par devise**, jamais d'addition de deux devises ; chaque boutique garde sa devise, son fuseau et son décalage dans la
réponse ; « aujourd'hui » est le jour de chaque boutique. Une conversion indicative, avec un taux daté et affiché, viendra ensuite.

**Routes qui savent fusionner**, et leur règle :

| Écran | Route | Règle de fusion | Classement |
|---|---|---|---|
| Ventes, marge, commandes, panier moyen | `GET /api/gestion/statistiques` (plage de dates) | réunir les lignes par jour puis recalculer les taux ; total par devise ; classement des boutiques | simple |
| Ma journée | `GET /api/gestion/journee` | compteurs additionnés ; ventes du jour de chaque boutique côte à côte | simple pour les compteurs |
| Stock : valeur, stock bas | indicateur `stockValue`, liste du stock bas | valeurs additionnées par devise ; liste avec une colonne boutique | simple |
| Constats ouverts des pertes | `GET /api/gestion/pertes?analyse=0` | compteurs par boutique, sans lancer l'analyse | simple |
| Assistance en attente | compteur de « Ma journée » | compteurs par boutique | simple |
| À recevoir, transferts en route | `GET /api/commandes/lignes/a-recevoir` | listes réunies, tri et pages refaits | plus coûteux (léger) |
| Trésorerie : solde et échéances | `GET /api/gestion/tresorerie/soldes`, `/mouvements` | soldes par devise, échéances réunies ; la projection attend | plus coûteux |
| Résultat et budget des périodes | `GET /api/periodes/{id}/resultat`, `/budget` | chaque site a son découpage : réunir par dates, pas par numéro de période | plus coûteux |
| Année de l'entreprise | `GET /api/gestion/statistiques/annee` | le mois de début d'exercice diffère d'un site à l'autre | plus coûteux |
| Bilan PDF du groupe | rapports | un nouveau gabarit, bâti sur les routes déjà fusionnées | plus coûteux |
| Résumé de la semaine du groupe | courriel | destinataires et heure d'envoi par site | à remettre |
| Console IA sur le groupe | console | fil, analyses et unités par site | à remettre |
| Vignettes d'accueil, locations | | liées au compte ou à l'article de chaque site | à remettre |

**Onglets.** En mode « Toutes les boutiques » disparaissent : créer une commande, réception, prix et promotions d'une boutique,
budget d'une période, réglages, sécurité et pertes en écriture, console IA, conversation, codes-barres, imports. Apparaissent :
transfert entre boutiques, copie du catalogue, prix ou promotion sur plusieurs boutiques, frais du siège, gestion du groupe
(boutiques, invitations).

**Outils de groupe**, chacun appliqué boutique par boutique, sans fusion des bases.

- **Copie du catalogue, en une fois.** Elle réutilise le plan et l'écriture de l'import (aperçu, confirmation, rejeu sans doublon,
  annulation 7 jours), avec une boutique du groupe pour source au lieu d'un fichier. Les images sont recopiées, jamais partagées.
  Le stock n'est pas copié. Elle garde, dans la base maître, la correspondance entre l'article source et sa copie.
- **Transfert léger.** La demande notifie la boutique source. L'envoi écrit un mouvement de stock sortant chez la source et crée
  chez la destination une commande « en route », au prix de revient, sans taxes, avec la boutique source pour fournisseur. La
  réception passe par la réception habituelle. L'annulation avant réception rend le stock à la source. Le transfert lui-même
  (état, quantités, coût, liens vers le mouvement et la commande) est gardé dans la base maître : les tables des sites ne changent
  pas. Il faut seulement un type de mouvement « Transfert » dans chaque boutique : une ligne de donnée, pas de structure.
- **Prix ou promotion sur plusieurs boutiques.** Aperçu par boutique, puis application par le chemin existant de chaque boutique,
  avec son annulation de 24 heures. Il s'appuie sur la correspondance d'articles.
- **Frais du siège.** Un montant mensuel saisi au siège et une clé de répartition (parts égales, au prorata des ventes, ou à la
  main) créent dans chaque boutique un mouvement prévu « frais fixes », celui qui existe déjà.

## 4 bis. Le type d'un site dès sa création (super administrateur)

Dans EasyAdmin comme dans le panneau du front, le super administrateur choisit un type à la création : normal, agence ou groupe.
C'est un raccourci qui enchaîne les gestes existants, pas un champ enregistré : le type reste porté par les liens de la base
maître, et les pastilles en découlent.

- **Normal** : la création habituelle.
- **Agence** : la création du site, puis celle de sa fiche d'agence avec ce site pour attache (nom, contact, case test, essai,
  nombre de sites).
- **Groupe** : la création du site, l'octroi du module « Multi-boutiques », puis la fiche du groupe avec ce site pour siège.

Une seule route sert les deux écrans, avec une seule logique et un seul journal. Comment une étape qui échoue est traitée :

1. La demande est une ligne de la base maître, écrite AVANT la première étape, avec la liste de ses étapes et l'état de chacune.
2. Chaque étape est rejouable sans doublon : elle regarde d'abord si son résultat existe déjà.
3. Si une étape échoue après la création du site, rien n'est défait en silence : la demande passe à « à compléter », avec l'étape
   en cause et son code d'erreur, et elle reste dans la liste des demandes du super administrateur.
4. « Reprendre » rejoue les seules étapes manquantes. « Abandonner » laisse un site normal, et le dit.
5. Le site créé porte, tant que la demande n'est pas finie, une pastille « Création à compléter » dans « Plateforme : sites ».

## 5. Les lots, dans l'ordre

| Lot | Contenu | Base maître | Bases des sites |
|---|---|---|---|
| AG-3a | registre des écrans ; `GET /api/me/contexts` pour les modes existants ; pastilles d'agence ; aucun droit changé | rien | rien |
| AG-3b | entrée par jeton, opérateur extérieur, garde du mode agence fermée par défaut, essai sur toutes les routes, trace des deux côtés, « Accès extérieurs » | journal des entrées | rien |
| AG-3c | EasyAdmin : laissez-passer, heure de validation du code, menu piloté par le registre (mode et modules), garde sur toutes les sections, tuile « Modules disponibles » | rien | rien |
| AG-3d | « Tous mes clients » ; ligne « Taxes vérifiées par le commerçant » | rien | une colonne sur la fiche du site (date de la vérification) |
| TYPE-1 | création d'un site avec son type (normal, agence), demande à étapes, « à compléter », « Reprendre » ; peut passer dès maintenant, il n'utilise que l'existant | demandes de création typée | rien |
| G-1 | type groupe, module « Multi-boutiques », passer en multi-boutiques, créer, associer sur invitation acceptée, quitter ; pastilles ; mode groupe dans les contextes et dans l'entrée ; type « groupe » à la création d'un site | groupes, boutiques du groupe, invitations, journal | une colonne booléenne du module, comme tout module |
| G-2 | « Toutes les boutiques », routes simples ; refus des écritures | rien | rien |
| G-3 | fusion des routes plus coûteuses : à recevoir, trésorerie, périodes, année | rien | rien |
| G-4 | copie du catalogue | correspondance d'articles | rien |
| G-5 | transfert léger | transferts et leurs lignes | un type de mouvement (donnée) |
| G-6 | prix ou promotion sur plusieurs boutiques | rien | rien |
| G-7 | frais du siège | frais et clé de répartition | rien |
| G-8 | bilan PDF du groupe ; conversion indicative des devises | taux datés | rien |

AG-3a à AG-3c construisent tout ce qui est partagé. GROUPE n'ajoute que le type groupe, le périmètre complet, la fusion des
réponses et les outils de groupe.

## 6. Risques

1. **Sécurité de l'entrée.** Un opérateur extérieur avec des droits d'administrateur dans le site d'un autre : c'est le lot le
   plus sensible. Relecture indépendante, mutations, essai sur toutes les routes, comme pour CONSTRUCTION.
2. **Le rangement des 970 routes et des 110 écrans.** Long et fastidieux, mais c'est lui qui rend tout le reste simple. Une route
   oubliée est fermée, jamais ouverte.
3. **Deux bases, aucune transaction commune.** Un transfert ou une copie peut s'arrêter à mi-chemin : chaque étape doit pouvoir se
   rejouer sans doublon, et un transfert à moitié fait doit se voir et se réparer.
4. **Changer de site en cours de requête.** Un oubli du retour au site de départ écrirait chez la mauvaise boutique. La boucle de
   fusion est écrite une fois, à un seul endroit, et le mode est en lecture seule.
5. **Temps de réponse.** Une requête interroge N bases, une par une. Supportable pour quelques boutiques ; au-delà il faudra un
   plafond ou un calcul préparé.
6. **Modules dans EasyAdmin.** Aujourd'hui aucune section ne dépend d'un module, et en mode « demo » de la plateforme les modules
   ne sont pas refusés du tout. Cacher et refuser par module change le comportement des sites existants : à annoncer.
7. **Le même article dans deux boutiques.** Aucun identifiant commun n'existe. La correspondance ne naît que d'une copie, d'un
   code-barres égal ou d'un choix à la main.

## 7. Réponses du propriétaire (16/11/2026)

1. Seuls les administrateurs du siège voient toutes les boutiques.
2. Une boutique ASSOCIÉE peut quitter le groupe sur décision de son administrateur. Une boutique CRÉÉE par le groupe ne le quitte que
   sur décision d'un administrateur du siège.
3. Les totaux sont donnés par devise d'abord ; la conversion indicative vient ensuite.
4. Le générateur IA est ouvert à l'agence, et tracé. La console IA lui reste fermée.
5. L'agence ne crée pas de comptes d'équipe chez son client.
6. L'agence voit les quantités en stock, jamais le coût.
7. La copie du catalogue reprend les prix et le coût.
8. Le transfert est validé par la boutique source.
9. Après 12 heures de session, EasyAdmin redemande le code à usage unique.
10. Un même site peut être dans un groupe ET géré par une agence.
11. Les administrateurs du siège entrent dans les boutiques créées par le groupe.

Le marquage « avant la migration » de l'an dernier dans la console IA et les bilans PDF (`docs/import-boutique.md` § 10.6) prend le
train d'AG-3a.

## 8. Pour « couvrir le max », sans gros chantier (retenu par le propriétaire)

- Un inventaire tournant de groupe : la liste réunie du stock d'un article dans toutes les boutiques (après la correspondance).
- ÉCARTÉ : un client reconnu dans toutes les boutiques du groupe (gros chantier : comptes par site).
- ÉCARTÉ : des cartes cadeaux et des avoirs valables dans tout le groupe (gros chantier).
- Un tableau des transferts en retard, et leur rappel par notification.
- La copie des réglages d'une boutique vers une autre (taxes, transporteurs, charte) : même mécanique que la copie du catalogue.
- Un mode « comptable » en lecture seule sur la gestion : une liste de plus dans le registre.
