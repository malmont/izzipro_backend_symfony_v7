---
key: configuration
title: Configuration : Stripe, taxes, courriels, avis
summary: Les quatre réglages qui demandent de la méthode, avec leurs étapes.
screen: configuration
steps: [boutique.taxes, boutique.stripe, site.email_sender, boutique.reviews, boutique.subscriptions_stripe]
settings: [commerce.tax_provider, stripe.online, email.smtp, reviews.enabled]
---
# Configuration

La page **Configuration** regroupe quatre cheminements. Une coche verte : fait ; une croix rouge : à faire ; un sablier : la plateforme s'en occupe.

## Paiements en ligne (Stripe)

Vos clients paient par carte ; Stripe encaisse pour la plateforme et vous reverse les fonds. Cliquez **« Connecter »** sur l'écran Stripe : Stripe vous demande votre identité et votre compte bancaire. Renseignez d'abord l'adresse de l'entreprise, elle fixe le pays du compte. Puis passez une commande d'essai (en mode test : carte 4242 4242 4242 4242).

## Taxes

La règle : la taxe du lieu de livraison. Deux modes : la **table des taxes** du site (vos taux par pays et région, gratuite, suffisante pour un ou quelques pays ; remplie automatiquement à la création du site d'après votre adresse) ou **Stripe Tax** (calcul par Stripe, prix hors taxes obligatoires, immatriculations à déclarer chez Stripe).

## Courriels

Sans rien régler, la plateforme envoie pour vous. Indiquez l'expéditeur affiché (pris sur votre courriel d'entreprise). Pour partir de votre propre boîte, saisissez votre serveur SMTP. Vérifiez que votre domaine publie SPF et DMARC, puis envoyez-vous un **courriel d'essai**.

## Avis clients

Qui peut écrire (acheteurs vérifiés), modération avant publication, politique affichée. Les avis négatifs se publient aussi : la loi l'exige.
