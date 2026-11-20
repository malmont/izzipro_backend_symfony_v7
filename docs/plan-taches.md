# Plan TACHES : les tâches planifiées de la plateforme (20/11/2026, PLAN SEULEMENT, aucun code avant l'accord du propriétaire)

Demande du propriétaire, relayée par le chef. Une seule place pour voir, lancer, mettre en pause et surveiller tout ce qui tourne tout seul,
sans `sudo` pour une tâche nouvelle. Décision du propriétaire : **Symfony Scheduler** (`symfony/scheduler` 6.4), aucun outil externe.

## 1. Ce qui tourne aujourd'hui (inventaire du 19/11/2026, lecture seule)

| # | Tâche | Où | Rythme | Ce qu'elle fait |
|---|---|---|---|---|
| 1 | abo-periodes | systemd → conteneur | :25 chaque heure | droits d'abonnement : réémission, report, perte |
| 2 | entitlements-sweep | systemd → conteneur | 06:10 UTC | échéances des droits d'usage |
| 3 | form-submissions-purge | systemd → conteneur | 03:40 | purge des envois de formulaires |
| 4 | gestion-notifications | systemd → conteneur | :05 chaque heure | passage horaire : notifications, courriels retenus, instantanés de stock, résumé de la semaine, rattrapage des sites demandés (AG-2, TYPE-1), purges des imports et des rapports |
| 5 | memoires-invitations-renew | systemd → conteneur | :20 chaque heure | liens d'invitation aux chapitres |
| 6 | pertes-analyse | systemd → conteneur | :08 :23 :38 :53 | analyse de la prévention des pertes |
| 7 | platform-incidents-digest | systemd → conteneur | 07:00 UTC | courriel du jour des incidents, purge |
| 8 | platform-offers-reconcile | systemd → conteneur | 05:40 UTC | réconciliation complète des offres avec Stripe (`--all`) |
| 9 | platform-offers-retry | systemd → conteneur | toutes les 15 min | file de reprise des droits vendus (même commande, sans `--all`) |
| 10 | pos-terminal-reconcile | systemd → conteneur | toutes les 5 min | paiements des terminaux de caisse |
| 11 | shipping-track | systemd → conteneur | toutes les 6 h | suivi des expéditions |
| 12 | tech-metrics | systemd → SERVEUR | toutes les 5 min | mesures du tableau de bord technique (script de l'hôte, puis commande) |
| 13 | group-transfers-resume | systemd → conteneur | toutes les 10 min | reprise des transferts G-5 |
| 14 | site-weekly-digest | fichier seulement | — | NON installé (doublon du résumé fait par le n° 4) |
| — | backup-daily | systemd → SERVEUR, hors dépôt | 04:00 UTC | sauvegarde des bases et de Mémoires Vivantes |
| — | nettoyage Docker | /etc/cron.d | — | NE TOURNE PAS : aucun service cron installé |
| — | 7 workers Messenger | Docker, restart=always | continu | files async, email, esg, gestion_ai, landing_ai, media, pdf |
| — | app:support:retention | code | — | jamais branchée (anonymisation des demandes d'assistance de plus de 3 ans) |

Hors serveur : rien de planifié côté Next (vérifié par le chef) ; Stripe et RevenueCat font leurs propres relances (à noter, pas à nous).

## 2. Les lots

### TACHES-0 : le composant (petit, d'abord)
- `composer require symfony/scheduler:^6.4` (décision du propriétaire), dans un worktree, suite complète ; rien d'autre.
- Le transport `scheduler_default` de Messenger, consommé par un **8e worker** sur le modèle de celui des courriels :
  conteneur `symfony_messenger_worker_scheduler_v2`, `restart=always`, `--time-limit=900`, battement Redis comme le worker des courriels.
- Le worker démarre avec une liste VIDE : rien ne change tant qu'aucune tâche n'y est inscrite.

### TACHES-1 : le catalogue, le journal, le verrou
- **Catalogue d'ACTIONS défini dans le code** (jamais une commande libre) : chaque action a un code, un libellé fr/en, la commande qu'elle
  lance, ses paramètres permis (et leur type), sa portée permise (un site, tous les sites, base maître, serveur), son rythme par défaut,
  et le fait d'être une tâche du SYSTÈME (non supprimable) ou non.
- **Table maître `scheduled_task`** : la tâche (action, paramètres, portée, rythme simple ou cron, état actif / en pause + motif + qui +
  quand, origine système ou écran) ; **`scheduled_task_run`** : début, durée, résultat (ok, échec, sauté), erreur courte SANS donnée
  personnelle (classe et code), qui l'a lancée (planificateur ou « Lancer maintenant » par un compte).
- **Verrou** par tâche (`symfony/lock` sur Redis, ou verrou consultatif de la base maître) : une tâche ne tourne jamais deux fois en même
  temps ; un passage qui trouve le verrou est noté « sauté ».
- Le planificateur lit `scheduled_task` (un `ScheduleProvider` qui relit la table) : une tâche créée depuis l'écran est prise au
  passage suivant, sans redémarrage ni sudo.

### TACHES-2 : la page « Tâches planifiées » (super administrateur, EasyAdmin)
- Liste : rythme, dernier et prochain passage, durée, voyant (vert ; orange en retard ; rouge en échec) ; bandeau « planificateur arrêté
  depuis… » (battement du worker absent).
- « Lancer maintenant » (confirmation, au journal), « Pause » (MOTIF exigé), « Reprendre », détail d'un échec.
- Créer une tâche : une ACTION du catalogue, son rythme (simple, ou cron pour les cas avancés), sa portée et ses paramètres permis par
  l'action ; supprimer une tâche créée depuis l'écran (son journal est gardé) ; une tâche du système ne se supprime pas.
- L'état des **7 workers** (et du 8e) par battement, sur le modèle du worker des courriels.
- Chaque geste est audité (qui, quoi, quand).

### TACHES-3 : les tâches du SERVEUR, surveillées (elles restent en systemd)
- backup-daily, le nettoyage Docker, `ops/tech-metrics-host.sh` ne peuvent pas tourner dans le conteneur : ils restent en systemd.
- Chacun écrit son passage (une ligne : nom, début, fin, résultat) dans un fichier lu par l'application, ou par une commande qui l'enregistre
  dans `scheduled_task_run` ; la page les montre comme les autres, en lecture.
- **Sauvegarde : alerte si elle n'a pas réussi depuis plus de 26 h** (la plus importante) ; et, quand la copie HORS du serveur existera
  (lot des clés et services tiers), la même alerte sur la copie externe et un **essai de restauration mensuel**.
- **Nettoyage Docker** passé en minuteur systemd, fichiers dans `ops/systemd/`, installation autorisée par le propriétaire :
  `docker image prune -af --filter until=168h` chaque jour (7 jours au lieu de 24 h), l'image d'avant un changement d'IMAGE étiquetée
  « keep » et exclue ; `docker builder prune -f --keep-storage 5GB` chaque semaine. Le retour arrière d'une bascule n'a pas besoin
  d'image (code par git, cache échangé ; « bascule.sh menage » garde les 3 derniers caches) ; seul un retour après un changement d'image
  (PHP, Dockerfile) demanderait l'image d'avant, d'où l'étiquette.

### TACHES-4 : les alertes
- Deux échecs de suite, ou une tâche en retard (prochain passage dépassé d'un seuil par tâche) → un incident de la plateforme (INC) et un
  courriel vers l'adresse d'alerte de la plateforme (réglage de la page Plateforme).
- Une alerte par tâche et par épisode (pas une par passage).

### TACHES-5 : la migration des tâches, une par une
- Pour chacune des tâches 1 à 11 et 13 : inscription au planificateur EN PAUSE → un passage lancé à la main et vérifié → reprise au
  planificateur → l'ancien minuteur systemd désactivé (commande sudo autorisée par le propriétaire) → un passage planifié vérifié.
  L'ancien n'est désactivé qu'après un passage vérifié du nouveau.
- **Offers reconcile et retry** : DEUX tâches, sur UNE action à paramètre (`file` ou `complet`), avec des voyants et des alertes
  séparés (décision du chef).
- **site-weekly-digest** : le fichier en double est supprimé ; le résumé de la semaine reste dans gestion-notifications.
- **app:support:retention** : branchée chaque mois, d'abord en SIMULATION avec un rapport, `--apply` seulement sur accord du propriétaire
  (obligation RGPD, durée de 3 ans à la liste de l'avocat).
- Ordre proposé, du moins risqué au plus risqué : form-submissions-purge, platform-incidents-digest, memoires-invitations-renew,
  shipping-track, entitlements-sweep, abo-periodes, group-transfers-resume, pertes-analyse, pos-terminal-reconcile,
  gestion-notifications, offers retry puis reconcile.

### TACHES-6 : le volet IA (IA-C, super administrateur)
- Lire l'état des tâches et des workers ; expliquer un échec (d'après l'erreur courte et le journal) ; PROPOSER une relance, une pause
  (avec motif) ou une création (une action du catalogue). Toujours confirmé par le propriétaire ; jamais une commande libre.

## 3. Catalogue d'actions du départ
Les 12 tâches du conteneur ci-dessus (une action chacune ; offers : une action à paramètre), `support.retention` (paramètre `apply`,
faux par défaut), `tech.metrics` (portée serveur, surveillance seulement), `server.backup` et `server.docker-prune` (portée serveur,
surveillance seulement, aucun lancement depuis l'écran).

## 4. Garde-fous
- Le mode démo ou réel de la plateforme est respecté (une action qui change de comportement en réel le dit).
- Une action Stripe en réel garde sa confirmation (« Lancer maintenant » d'une réconciliation complète : double confirmation).
- Une tâche de portée « tous les sites » lit la liste des sites dans la base maître à chaque passage (jamais une liste figée).
- Aucune donnée personnelle dans le journal des passages.

## 5. Risques
0. **La sauvegarde n'est pas une vraie sauvegarde** (vérifié le 20/11/2026, lecture seule) : `backup.sh` ne fait que `pg_dump`, `tar` et
   `find`, sans aucun envoi ailleurs ; les copies vont dans `/home/arkanoa/backups/data` (14 jours), sur le MÊME disque que le serveur.
   Un disque ou un serveur perdu emporte les bases et leurs sauvegardes. **Décision du propriétaire (20/11/2026)** : la copie HORS du
   serveur fait partie du lot de branchement des clés et services tiers, avant la campagne QA. TACHES-3 la surveillera dès qu'elle
   existera : alerte après 26 h sans copie externe réussie, essai de restauration mensuel. Le même dossier garde d'anciennes copies de
   `.env` en clair : décision du propriétaire attendue, ne pas y toucher.
1. Un seul worker du planificateur : s'il s'arrête, TOUTES les tâches s'arrêtent → bandeau, battement, alerte (TACHES-4) ; pendant la
   migration, l'ancien minuteur reste jusqu'au passage vérifié du nouveau.
2. Deux exécutions pendant la bascule d'une tâche (ancien et nouveau) → le verrou de TACHES-1 en évite le chevauchement.
3. Une tâche longue retient le worker → le verrou par tâche ; les tâches lourdes (gestion-notifications) gardent leur rythme horaire.
4. Le paquet `symfony/scheduler` change `composer.lock` : un train à part, suite complète.
5. L'ancienne v1 (arrêtée le 19/11, `restart=no`) : ses conteneurs et son volume `app_db_data` restent ; leur suppression est une
   décision du propriétaire, après une sauvegarde de sa base. Elle ne consommait plus rien d'utile (aucune requête en 24 h).

## 6. Questions au propriétaire
1. Le 8e worker (le planificateur) : d'accord pour un conteneur de plus, sur le modèle des 7 autres ?
2. Seuils de retard : une valeur par défaut (2 fois le rythme) et un réglage par tâche, ou une valeur fixe ?
3. La page « Tâches planifiées » : super administrateur seulement, ou aussi visible (en lecture) par l'administrateur d'un site pour ses
   tâches à lui (aucune aujourd'hui) ?
4. Le nettoyage Docker : rythme quotidien des images (24 h) et hebdomadaire du cache de construction, comme le cron d'origine ?
5. `support.retention --apply` : à quelle date le premier passage réel, une fois le rapport de simulation lu ?
6. La sauvegarde (hors dépôt) : la ranger dans `ops/` (sans secret) pour qu'elle soit versionnée, ou la laisser hors dépôt et seulement
   surveillée ?
