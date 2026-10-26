# Mise en production de la caisse (lot M1)

Ouvrir un vrai magasin sans surprise : paiements réels, terminal réel, surveillance. Contrat technique : `docs/caisse.md`,
section « Paiements de caisse en production (lot M1) ». Rien ici ne s'applique à la boutique en ligne, dont la configuration
Stripe, les webhooks et les abonnements restent inchangés.

## 1. Liste de contrôle d'ouverture d'un magasin

Cocher dans l'ordre, sur le site du magasin (jamais sur demo, qui reste en test).

1. **Clé d'activation et poste rattaché** : créer le poste (EasyAdmin, « Postes de caisse »), puis une clé d'activation de
   l'application de caisse rattachée à ce poste (« Clés des applications ») ; activer la tablette ; vérifier que
   `GET /api/pos/terminal` rend le bon `cashRegisterId`.
2. **Coupures et fond de caisse** : coupures de la devise, fond d'ouverture suggéré et fond conservé à la fermeture (fiche
   Entreprise, panneaux de la caisse) ; ouvrir puis fermer une session à blanc (rapport Z vérifié).
3. **Taxes et numéros TPS / TVQ** : adresse complète de l'entreprise (province), numéros de taxe renseignés, ticket de test :
   taxes et numéros imprimés corrects.
4. **En-tête et pied du ticket** : raison sociale, adresse, mentions légales (politique de retour, Loi 96 : français),
   affichage des numéros de taxe ; ticket numérique (courriel, QR) si utilisé.
5. **Compte Connect et mode réel** (page « Paiements de caisse (Stripe) ») :
   - clés réelles de la caisse configurées sur le serveur (section 3) ;
   - « Inscription Stripe du compte réel », le commerçant complète le parcours Stripe (identité, compte bancaire) ;
   - au retour, le tableau doit montrer **encaissement : oui** et **virements : oui**, aucune pièce demandée ;
   - « Passer en réel » ; le bandeau affiche **Paiements de caisse : RÉEL** ; journal `STRIPE_MODE_CHANGED`.
6. **Appairage du lecteur** (WisePOS E, S700) : lecteur branché et en ligne, code à trois mots affiché dans ses réglages ;
   fiche du poste, « code d'appairage », enregistrer ; « Terminal activé » coché. L'emplacement Terminal est créé à l'adresse
   du magasin au premier appairage (ou par le bouton de la page Stripe). Tap to Pay et lecteur Bluetooth : réglages du site
   (« Tap to Pay » / « lecteur Bluetooth » autorisés) et mode du terminal du poste.
7. **Paiement réel de 1 $ puis remboursement** : vente d'un article à 1 $ payée au lecteur avec une vraie carte ; vérifier le
   ticket, le paiement dans le tableau de bord Stripe du compte connecté, la commission de la plateforme ; annuler la vente
   (remboursement carte, Interac présenté sur le lecteur) ; vérifier le remboursement chez Stripe et le rapport X.
8. **Surveillance** : `GET /api/pos/health` (administrateur) à l'état `ok` ou `info` ; alertes activées pour le contact du
   site (fiche Entreprise, panneau « Paiements de caisse et surveillance (caisse) ») ; `POS_ALERT_PLATFORM_EMAIL` configurée.
9. **Réglages de conservation** : durée du pays (Canada 6 ans) ; garde de modification en mode « observation » pendant la
   recette ; **passage à « blocage » : décision du propriétaire après la recette** (fiche Entreprise, panneau « Conservation des
   données de vente (caisse) »), puis vérifier qu'une modification d'une vente archivée est refusée dans EasyAdmin.
10. **Archive annuelle conservée hors serveur** : à chaque clôture d'exercice, archive annuelle (EasyAdmin, « Archive
    annuelle (caisse) ») téléchargée et conservée hors du serveur (empreinte SHA-256 notée).
11. **Sauvegardes** : sauvegarde quotidienne vérifiée (section 5) ; copie hors serveur en place avant le premier client.
12. **Serveur en production** : passage du mode développement au mode production avant le premier client (section 4).

## 2. Réconciliation planifiée (installée le 26/10/2026)

Timer systemd de l'hôte, toutes les 5 minutes jour et nuit : relit les paiements par terminal en attente (autorisations
expirées, paiements sans vente, remboursements Interac), écrit l'état daté de l'exécution, lance la surveillance et les alertes.
Pas deux exécutions en même temps : systemd ne relance pas le service encore actif, la commande prend un verrou fichier et un
verrou consultatif par base ; une exécution bloquée est arrêtée au bout de 4 minutes.

Fichiers versionnés : `ops/systemd/pos-terminal-reconcile.service`, `ops/systemd/pos-terminal-reconcile.timer`,
`ops/logrotate/pos-terminal-reconcile`.

```bash
sudo install -m 0644 ops/systemd/pos-terminal-reconcile.service ops/systemd/pos-terminal-reconcile.timer /etc/systemd/system/
sudo install -m 0644 ops/logrotate/pos-terminal-reconcile /etc/logrotate.d/pos-terminal-reconcile
sudo touch /var/log/pos-terminal-reconcile.log && sudo chmod 0640 /var/log/pos-terminal-reconcile.log && sudo chown root:adm /var/log/pos-terminal-reconcile.log
sudo systemctl daemon-reload
sudo systemctl enable --now pos-terminal-reconcile.timer
```

Contrôle et retour arrière :

```bash
systemctl list-timers pos-terminal-reconcile.timer
sudo tail /var/log/pos-terminal-reconcile.log
cat var/pos-terminal-reconcile.status.json            # dernière exécution, dernière complète, 50 dernières
sudo systemctl disable --now pos-terminal-reconcile.timer
```

Journal : `/var/log/pos-terminal-reconcile.log`, rotation quotidienne, 30 jours compressés. Le code étant monté en direct, un
changement de constructeur de service doit être suivi aussitôt d'un `cache:clear` (sinon l'exécution suivante échoue).

## 3. Clés Stripe réelles de la caisse : clés restreintes et rotation

Le propriétaire crée les clés lui-même dans le tableau de bord Stripe de la **plateforme**, en **mode réel** :
Développeurs, Clés API, « Créer une clé restreinte ». Nom suggéré : `caisse-production-AAAA-MM`.

Permissions (tout le reste sur « Aucun ») ; les libellés de l'interface Stripe peuvent varier légèrement :

| Ressource | Accès | Utilisée pour |
|---|---|---|
| PaymentIntents | Écriture | créer, relire, capturer, annuler un paiement au terminal |
| Charges | Lecture | marque et 4 derniers chiffres, reçu (charge du PaymentIntent) |
| Refunds | Écriture | rembourser, relire et lister les remboursements |
| Terminal : Readers | Écriture | appairer, lister, présenter un paiement ou un remboursement, annuler l'action du lecteur |
| Terminal : Locations | Écriture | créer l'emplacement du magasin |
| Terminal : Connection tokens | Écriture | jeton de connexion du kit Stripe Terminal (Tap to Pay, Bluetooth) |
| Connect : Accounts | Écriture | créer le compte Express réel, relire son état |
| Connect : Account links | Écriture | lien d'inscription Stripe |
| Application fees | Lecture | commission de la plateforme sur les paiements des comptes connectés |

La clé publique réelle (`pk_live_…`) n'est pas secrète mais se configure à côté. Installation (variables nouvelles : lues à
chaque requête, sans recréer le conteneur) :

```bash
# sur le serveur, dans /home/arkanoa/app_v2/.env (non versionné ; jamais dans le dépôt ni au journal)
STRIPE_LIVE_SECRET_KEY=<clé restreinte rk_live_…>
STRIPE_LIVE_PUBLIC_KEY=<pk_live_…>
POS_ALERT_PLATFORM_EMAIL=<adresse de l'administrateur de la plateforme>
```

Vérifier ensuite : page « Paiements de caisse (Stripe) », colonne « Clés serveur » du mode réel à « oui » ; puis le paiement
réel de 1 $ et son remboursement (section 1, point 7) : toute permission manquante y apparaît (erreur Stripe au journal).

**Rotation** (au moins une fois par an, et aussitôt en cas de doute) :
1. créer la nouvelle clé restreinte avec les mêmes permissions ;
2. remplacer la valeur dans `.env` du serveur ; une variable déjà présente dans l'environnement du conteneur exige de le recréer :
   `docker compose up -d --no-deps --force-recreate symfony_app_v2` (et les workers s'ils lisent la clé) ;
3. vérifier : `GET /api/pos/health` (`stripe.keys.live` à `true`, compte lisible), un paiement réel de 1 $ remboursé ;
4. révoquer l'ancienne clé dans Stripe (ou la faire expirer après 24 h avec « Faire tourner la clé ») ;
5. noter la date de rotation. Les secrets de webhook ne concernent pas la caisse.

## 4. Passage du serveur en production (avant le premier client)

Le serveur tourne en `APP_ENV=dev` avec `APP_DEBUG=0` (choix du propriétaire). Avant le premier client, passer en
`APP_ENV=prod` (décision et créneau du propriétaire), avec retour arrière prêt. À vérifier :

- **Configuration prod présente** : blocs `when@prod` (Doctrine : proxies générés au préchauffage, cache des requêtes et des
  résultats dans Redis ; Monolog : journaux de production) ; aucune route de débogage exposée ; pages d'erreur sans trace.
- **Variables** : `APP_ENV=prod` dans l'environnement du conteneur (recréation), `APP_SECRET` propre à la production,
  `.env` complet ; optionnel `composer dump-env prod`.
- **Dépendances** : `composer install --no-dev --optimize-autoloader` (ou vérifier qu'aucun code de production n'utilise un
  paquet de développement), puis `cache:clear` et `cache:warmup` en prod.
- **Workers Messenger et timer** : redémarrer les workers (`messenger:stop-workers`, aucune génération en cours) pour qu'ils
  tournent en prod ; le timer lance `bin/console` dans le conteneur : il hérite de l'environnement.
- **Tests** : la suite garde son environnement `test` ; la lancer avant et après.
- **Recette** sur chaque site : connexion, boutique (paiement en test sur demo), vente de caisse, éditeur de landing page et
  assistant IA, Mémoires Vivantes, EasyAdmin ; `GET /api/pos/health` ; journaux sans erreur nouvelle.
- **Retour arrière** : remettre `APP_ENV=dev`, recréer le conteneur, `cache:clear`.

## 5. Recommandations soumises au propriétaire (non appliquées)

- **Sauvegardes hors serveur chiffrées** : les sauvegardes quotidiennes (environ 9 Mo par jour, 14 jours) restent sur le même
  disque. Proposition : `restic` (chiffrement côté serveur, déduplication) vers un stockage objet au Canada (Loi 25), par exemple
  OVHcloud Object Storage (Beauharnois) ; volume inférieur à 1 Go sur un an, coût estimé inférieur à 1 $ par mois (à confirmer
  sur la grille du fournisseur). Mot de passe du dépôt restic conservé hors serveur (gestionnaire de mots de passe du propriétaire).
  Lancement à la suite de `backup-daily.service` ; `restic check` hebdomadaire ; **restauration testée chaque mois** : restauration
  du dernier vidage d'une base dans un conteneur PostgreSQL temporaire et requête de contrôle (nombre de commandes), résultat au
  journal. Archive annuelle de la caisse copiée dans le même dépôt.
- **Surveillance externe** : (a) Healthchecks.io (gratuit jusqu'à 20 contrôles) : le service du timer appelle une URL de
  contrôle à chaque exécution réussie ; alerte si aucun appel pendant 15 minutes, même si le serveur est arrêté. (b) UptimeRobot
  (gratuit, toutes les 5 minutes) sur une route de surveillance dédiée en lecture seule, protégée par un jeton
  (`POS_HEALTH_TOKEN`, en-tête `X-Health-Token`), qui ne rend que `{status, reconcileStale}` agrégés pour tous les sites, sans
  détail ni donnée de paiement ; alerte par courriel ou SMS si `status` est `critical` ou si la route ne répond plus. Route et
  jeton à développer après accord.
- **Clés** : clés réelles restreintes (section 3), rotation annuelle, accès au tableau de bord Stripe en double authentification.
- **Courriels d'alerte** : vérifier SPF et DKIM du domaine expéditeur de `MAILER_DSN`.
