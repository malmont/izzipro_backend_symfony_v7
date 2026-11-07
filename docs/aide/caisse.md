---
key: caisse
title: Caisse : ouvrir le magasin
summary: La liste d'ouverture, dans l'ordre, de la clé d'activation au premier paiement réel.
screen: pos
steps: [pos.key, pos.register, pos.cashiers, pos.receipt, pos.terminal, pos.alerts, pos.retention, pos.denominations, pos.reader, pos.first_sale]
settings: [module.pos, stripe.pos]
---
# Caisse

La page **Caisse : réglages et cheminement** suit la liste d'ouverture du magasin.

1. **Poste de caisse** puis **clé d'activation** rattachée au poste : saisissez la clé dans l'application sur la tablette.
2. **Un caissier** au moins : un compte utilisateur avec le rôle caissier (ou responsable).
3. **Coupures de la devise** et fonds de caisse suggérés : pour le comptage à l'ouverture et à la clôture.
4. **Numéros de taxe** et **en-tête du ticket** : raison sociale, adresse, mentions, numéros de taxe imprimés.
5. **Paiements par carte** : en mode test, les cartes sont simulées. Pour encaisser réellement : inscription Stripe du magasin, appairage du lecteur sur la fiche du poste, puis « Passer en réel ».
6. **Vente d'essai** : ouvrez une session, vendez un article, clôturez (rapport Z).
7. **Alertes** par courriel et **conservation** des données de vente selon votre pays.

## Au quotidien

Retours et échanges, remises, cartes-cadeaux, fidélité, hors ligne : tout se règle sur la fiche Entreprise, panneaux de la caisse. Les ventes au comptoir se gèrent depuis la caisse, pas depuis les commandes du site.
