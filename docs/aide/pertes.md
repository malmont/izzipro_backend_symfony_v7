---
key: pertes
title: Prévention des pertes : suivre les articles et lire les constats
summary: Les pièces à puce, le tapis du comptoir, le boîtier, ce que dit un constat et ce qu'il ne dit pas.
screen: pertes
steps: []
settings: [module.loss_prevention]
---
# Prévention des pertes

Le module regarde ce que la caisse a déjà enregistré et ce que vos appareils ont lu. Il **signale**, il n'accuse pas : chaque
constat se vérifie, et la décision vous revient. Il ne bloque jamais une vente.

## Ce que vous suivez

1. **Une pièce** est un exemplaire d'un article, avec son numéro. Vous créez les pièces à la réception d'une commande ou pour le
   stock déjà en rayon.
2. **Une puce** est collée sur la pièce et lui est associée d'un geste (téléphone, lecteur de bureau).
3. Un article est **suivi** tant qu'au moins une de ses pièces à puce n'est pas vendue.

## Le boîtier et le tapis du comptoir

1. **Le boîtier** est la petite machine de la boutique : branchez-le, puis « Ajouter un boîtier » et scannez (ou tapez) le code
   de son étiquette. Il se relie à votre site, à lui seul.
2. **Le tapis** se branche sur le boîtier et se pose sur le comptoir. Reliez-le à **son poste de caisse** dans la fiche du boîtier.
3. Sur un site à plusieurs postes, **rattachez une tablette à chaque poste** (clés d'activation de la caisse) : sans cela, le
   contrôle avant paiement est arrêté pour les caissiers de ce poste. Le tableau de santé vous le dit.
4. Le **tableau de santé** montre chaque appareil : en ordre, en attente, en panne, avec le conseil qui convient.

## L'imprimante à puce (niveau pro)

1. Elle se branche sur le boîtier. Elle **imprime l'étiquette et écrit la puce d'un même geste** : la pièce reçoit sa puce sans
   geste de plus.
2. Dans « Impressions de puces », choisissez les pièces et l'imprimante, avec ou sans le prix. Le boîtier vient chercher le
   travail de lui-même.
3. Chaque étiquette est **relue** après l'écriture. Une puce relue est posée sur sa pièce ; sinon la ligne dit pourquoi (puce
   illisible, plus d'étiquettes…) et la pièce reste sans puce : relancez un travail pour elle.
4. Une pièce qui porte déjà une puce demande une confirmation avant d'en recevoir une de plus.
5. Si l'écran dit que l'écriture des puces n'est pas encore activée sur le serveur, contactez la plateforme : ce n'est pas un
   réglage de votre site.

## Au comptoir

1. Le caissier **pose les articles suivis sur le tapis** avant le paiement. Si vous avez allumé **l'alerte en caisse** (Réglages du
   module, éteinte au départ), la caisse lui dit ceux qui n'ont pas été lus ; il les pose et relit, ou il continue quand même : la
   vente n'est jamais bloquée. Le bilan du soir compte, poste par poste, les alertes montrées et les « continuer quand même »,
   jamais par personne.
2. Après la vente, le module relie chaque article vendu à la pièce lue sur le tapis : la pièce devient « Vendue ».
3. Une pièce qui n'a pas été posée peut être **rattrapée à la sortie**, si un lecteur y est installé.
4. **Retour d'un article** : posez la pièce rendue sur le tapis **avant de valider le retour**. Elle passe à « Retournée », de
   nouveau vendable. Pour la revendre, reposez-la sur le tapis.
5. Une vente annulée rend ses pièces. Ressaisie aussitôt, elle les retrouve.

## Les constats

1. Un constat dit **ce qui s'est passé, où et quand**, avec ses preuves. Il ne dit jamais pourquoi.
2. « **Articles suivis vendus sans lecture** » compte, sur un poste et une journée, les articles suivis vendus sans que leur
   pièce soit lue. Il ne désigne personne : regardez d'abord si le tapis était branché et utilisé.
3. Vous **décidez** de la suite à donner au constat (à suivre, expliqué, classé sans suite, confirmé). Votre décision est gardée,
   avec sa date.
4. Les seuils se règlent règle par règle, avec un **aperçu** sur les trente derniers jours avant d'enregistrer.

## L'alerte à la sortie et le portique

Quand un article suivi passe la sortie (ou la porte arrière) sans vente reliée, **une alerte part sur votre téléphone deux minutes
après** : l'article, la zone et l'heure, jamais une personne. Trois par jour au plus ; les suivantes restent dans la liste des alertes.
Hors des heures d'ouverture aussi, sauf si vous coupez la nuit dans les réglages. Une alerte est **à vérifier** : ne retenez ni
n'accusez jamais un client sur une alerte.

Le **signal sonore du portique** est un réglage, éteint au départ. Allumé, et avec un avertisseur branché sur le boîtier, le portique
sonne quand un article suivi passe sans être payé. **Limite** : un article vendu mais non lu au comptoir fera sonner un client honnête,
comme un antivol oublié en caisse ; l'alerte en caisse réduit ce risque.

## L'avis de l'IA

Sur la fiche d'un constat, **« Demander l'avis »** (1 unité IA) donne un avis indicatif : ce que disent les preuves, ce qui pèse pour
ou contre, et les explications sans faute. **Le pourcentage est calculé par la plateforme**, pas par l'IA : c'est la part des constats
semblables de votre boutique que vous avez confirmés sur 12 mois, donnée à partir de 20 constats tranchés. La décision vous appartient :
l'avis ne propose jamais de suite et ne nomme personne. Il n'existe pas pour les règles qui portent sur une personne.

**Données envoyées à l'IA** : la règle, le genre des preuves, le temps écoulé entre elles (en minutes, sans date ni heure) et leurs
nombres (montants, quantités, durées). Jamais une personne : ni nom, ni rôle, ni compte, ni motif saisi à la caisse, ni qui était en
poste. Aucune image ni vidéo.

## Ce qui est gardé, et combien de temps

1. Les **faits** (lectures, ventes reliées, retours) et les **preuves** d'un constat ne se modifient pas.
2. Les **présences sur le tapis** fermées depuis plus de 90 jours s'effacent d'elles-mêmes ; la durée se règle (de 30 à 730 jours).
3. Le **mode exercice** fabrique des constats fictifs pour apprendre l'écran, puis les efface. Aucune donnée réelle n'y entre.
