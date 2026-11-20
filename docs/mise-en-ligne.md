# Mettre en ligne : la bascule

Le code du dépôt est monté en direct dans le conteneur de l'application : ce qui est dans l'arbre en ligne (`/home/…/app_v2`, branche
`dev`) est servi aussitôt. Une mise en ligne ne se fait donc jamais par un `git pull` dans cet arbre suivi d'un `cache:clear` : pendant
la reconstruction du cache, les sites rendraient des erreurs. Elle se fait par **bascule** : le cache du nouveau code est construit à
côté, puis le code avance et le cache est échangé en une opération atomique. L'ancien cache reste sur le disque : c'est le retour arrière.

Outil : `ops/bascule/bascule.sh` (il ne pousse rien et n'applique aucune migration). Cette fiche est la procédure ; l'outil en porte
les gardes. Règles voisines : `docs/architecture.md`, « Points de montage du conteneur » et « Répéter une mise en ligne sur une copie ».

## 1. Avant de commencer

- **Une seule mise en ligne à la fois.** L'outil tient un verrou (`var/bascule.lock`) ; une session d'agent vérifie en plus qu'aucune
  autre instance d'elle-même ne tourne (après une reprise ou une compaction).
- **Le lot est committé**, par chemins précis (`git add` d'un dossier entier interdit), sur une branche qui part d'`origin/dev`.
- **La suite complète est verte sur ce commit**, jouée dans un worktree, jamais dans l'arbre en ligne ; un seul phpunit à la fois sur
  la machine : tout lancement de phpunit, de suite ou de mutations passe par `flock /tmp/phpunit-app_v2.lock …` (verrou commun aux
  sessions qui partagent la machine). Une tâche qui attend encore ce verrou peut être retirée ; une tâche dont le `docker exec` a démarré
  ne s'arrête pas en tuant le lanceur (phpunit continuerait dans le conteneur, hors verrou) : on la laisse finir.
- **Contrôles proportionnés au risque** (règles du propriétaire, 15/11/2026). Lot à risque (sécurité, argent, données des clients,
  comptes et droits, espace des agences, matériel) : relecture indépendante, mutations, photo avant et après. Lot de texte, d'affichage
  ou de petite logique sans risque : tests ciblés, photo, sans relecture indépendante ni mutations. Dans les deux cas : **une seule
  suite complète par mise en ligne, sur la pointe finale** ; les petits commits et les petits lots se regroupent avant de la lancer,
  pour ne payer qu'une suite et qu'une bascule. Les mutations d'un lot déjà relu peuvent se jouer après la bascule : une survivante
  devient un test du lot suivant.
- **L'accord de mise en ligne est donné** (propriétaire, ou chef de projet par délégation).
- **Réglages de l'outil** : `var/bascule.conf` (modèle `ops/bascule/bascule.conf.exemple`) et `var/bascule.lectures` (modèle
  `ops/bascule/bascule.lectures.exemple`). Le dossier `var/` est hors dépôt : aucune adresse, aucun nom de site, aucun identifiant de
  compte n'entre dans le dépôt.

## 2. Déroulé

Depuis l'arbre en ligne. `<n>` est un numéro de cache jamais utilisé (le suivant du dernier `var/swap_stage<n>`). Un numéro ne sert
qu'à UN `build` : si la pointe change après un `build` (commit ajouté, repose), on reconstruit sous le numéro suivant, l'outil refuse
de réécrire un cache.

| Étape | Commande | Ce qu'on attend |
|---|---|---|
| 1. Jeton de contrôle | `ops/bascule/bascule.sh jeton` | « jeton écrit » (jamais affiché) |
| 1 bis. Paquets, si `composer.lock` change | `ops/bascule/bascule.sh vendor <commit> <n>` | « vendor préparé : N paquet(s), N au composer.lock, 0 écart » (§ 2 bis) |
| 2. Cache du nouveau code | `ops/bascule/bascule.sh build <commit> <n>` | gardes passées, conteneur de services « OK », schéma « OK », nombre de routes ; si `composer.lock` change : « public/bundles préparé : …, 0 lien ; manquants au manifeste d'EasyAdmin : aucun » |
| 3. Photo « avant » | `ops/bascule/bascule.sh photo <nom>-avant` | aucune réponse 5xx ; tous les sites en 200 / 200 / 401 |
| 4. Migration, si le lot en a une | `scripts/migrate_all_v2_<sujet>.sh` | toutes les bases, **avant** l'échange du code (§ 3) |
| 5. Bascule | `ops/bascule/bascule.sh switch <commit> <n>` | « bascule en 0,x s », worker des courriels vivant, lecture authentifiée 200, 2 clés |
| 6. Journal | `ops/bascule/bascule.sh logs` | 0 erreur depuis la bascule, aucun 5xx au serveur web |
| 7. Photo « après » | `ops/bascule/bascule.sh photo <nom>-apres` | aucune réponse 5xx ; tous les sites en 200 / 200 / 401 |
| 8. Comparaison | `ops/bascule/bascule.sh comparer <nom>-avant <nom>-apres` | seules les différences attendues du lot (§ 4) |
| 9. Pousser | `git push origin dev` | seulement après une comparaison propre |
| 10. Fin | `ops/bascule/bascule.sh fin` | jeton de contrôle supprimé |

Puis : compte rendu **court** (5 à 10 lignes : ce qui change, chiffres clés, ce qui reste à faire, décisions à prendre ; le détail va
dans la fiche du lot), et les suites propres au lot (minuteur à installer, worker à relancer, droits à accorder). Une commande
`systemctl enable` ou `disable` n'est jamais lancée par une session : elle est donnée au propriétaire.

### 2 bis. Un `composer.lock` changé : l'étape `vendor` (20/11/2026, préparée au lot TACHES-0, livrée au train 122 avec Symfony 7.4)

La construction du cache monte le `vendor/` en lecture seule. Un paquet ajouté ou changé doit donc être prêt AVANT `build`, sans toucher
au vendor en ligne.
- `vendor <commit> <n>` copie le vendor en ligne dans `var/vendor_stage<n>`, dans un conteneur provisoire de la même image, en root
  comme lui. Il y lance `composer install --no-interaction --no-scripts --no-plugins` avec les options d'aujourd'hui : paquets de dev
  compris, car `APP_ENV=dev` charge DebugBundle, WebProfilerBundle et MakerBundle et les essais lancent phpunit depuis ce vendor ;
  classmap optimisée du `composer.json`. Il vérifie ensuite que le vendor préparé correspond au `composer.lock` du commit, paquet par
  paquet. Le code du commit est monté en lecture seule (la classmap de son `src/`).
- `build` refuse un `composer.lock` changé sans vendor préparé, et construit le cache avec ce vendor.
- `build` prépare aussi `public/bundles` (ignoré par git) pour ce vendor : `assets:install` en COPIE, jamais en liens, dans
  l'environnement du site, posé dans `var/bundles_stage<n>`. Il vérifie que chaque fichier du manifeste d'EasyAdmin du vendor préparé
  y est. Raison (train 123, constat de la relecture des pertes) : EasyAdmin lit son manifeste dans le vendor et demande des fichiers à
  empreinte (`app.<empreinte>.js`) ; un vendor changé avec l'ancien `public/bundles` laisse tout `/admin` sans style ni scripts (404),
  et `composer install --no-scripts` ne lance pas `assets:install`.
- `switch` échange d'abord le vendor puis `public/bundles` (échanges atomiques des deux dossiers, en root : aucun instant sans vendor
  ni sans fichiers publics), puis le code et le cache. Si le second échange échoue, le vendor est remis aussitôt. L'ancien vendor
  reste dans `var/vendor_stage<n>`, l'ancien `public/bundles` dans `var/bundles_stage<n>`.
- `retour` les remet par les mêmes échanges, quand le `composer.lock` revient en arrière.
- Après `switch` et `retour`, le contrôle des fichiers d'EasyAdmin demande au serveur web chaque fichier du manifeste du vendor EN
  LIGNE (celui que lisent les pages `/admin`) : un seul hors 200, retour arrière immédiat. Une page `/admin` anonyme renvoie à la page
  de connexion, qui ne charge pas ces fichiers : le contrôle passe donc par le manifeste.
- `essai-echange` : essai à blanc des deux échanges dans le conteneur en ligne, sur des dossiers d'essai placés comme les vrais
  (bascule, retour, second échange en échec avec vendor remis), puis le contrôle des fichiers d'EasyAdmin. À lancer avant toute
  bascule qui change le `composer.lock`.
- Après la bascule, les workers repartent sur le nouveau vendor : `switch` les arrête déjà (`messenger:stop-workers`).
- **Contrôles de l'outil après `switch` et `retour` (lot CACHE-ETAT, 20/11/2026, leçons du train 123)** :
  - FPM rechargé par l'outil (`kill -USR2 1` par le shell : l'image n'a pas d'exécutable `kill`), avec ses preuves : aucun processus
    de pool repris d'avant, version du Kernel sur disque ;
  - contrôle g : chaque conteneur de worker (hors planificateur) doit avoir DÉMARRÉ APRÈS l'heure de bascule ; sinon `docker restart`
    des retardataires (cas d'un signal d'arrêt sous un ancien préfixe), puis battement de moins de 60 s pour les 7 files, lu sur la clé
    la plus jeune ; un échec = retour arrière. Le planificateur (8e worker) est dit à part, « arrêté » tant que son conteneur
    n'existe pas. `ops/bascule/bascule.sh workers` fait le même contrôle en lecture seule.

**Repère `{{unique}}`** (16/11/2026) : dans le corps d'une lecture `POST`, il devient une valeur neuve à chaque photo. Une route limitée
par adresse (« mot de passe oublié » : 3 par heure) ne bute ainsi pas sur les passages de la photo elle-même.

**Une photo ne doit rien écrire.** Avant d'ajouter une lecture à `var/bascule.lectures`, vérifier que la route ne fait que lire :
`GET /api/pos/receipts?orderId=` compte une réimpression, `GET /api/gestion/pertes` lance une analyse à la lecture.

**Priorité : les pages publiques.** Après chaque bascule, on regarde d'abord les pages publiques de tous les sites (l'outil le fait
dans chaque photo) : un site vitrine qui ne répond plus est l'incident le plus grave.

## 3. Migration

- Une migration de livraison est **additive et idempotente** (tables ou colonnes nouvelles, `IF NOT EXISTS`) : l'ancien code tourne
  sans gêne sur le nouveau schéma. C'est ce qui permet de l'appliquer **avant** l'échange du code, et de ne pas la défaire lors d'un
  retour arrière.
- Elle est appliquée par son script `scripts/migrate_all_v2_<sujet>.sh` (dossier hors dépôt) à **toutes** les bases : celles des
  sites, `gmasuite` (le modèle des nouveaux sites) et le modèle des tests. Le modèle des tests la reçoit dès le développement.
- Si le nouveau code lit une colonne nouvelle à chaque requête (garde des modules, fiche du site), l'ordre inverse casse tous les
  sites : la migration d'abord, toujours.
- **Garde des scripts de production** (17/11/2026, demande du chef après un script maître glissé dans une chaîne d'essais) : chaque
  script de `scripts/` source `scripts/lib/garde-production.sh` en tête. Les bases de TEST passent sans rien demander (modèle des tests,
  sites et base maître des tests). Toute autre cible exige la variable `CONFIRMER_PRODUCTION`, posée À LA MAIN, égale à la cible tapée en
  entier, sinon le script s'arrête avant toute écriture (code 3) :
  - script maître (`migrate_master_*`) : le nom de la base maître visée (`MASTER_DB`, par défaut `app_v2_db`) ;
  - script de sites sans argument : `toutes` ;
  - script de sites avec des arguments : les arguments, séparés par des virgules, dans l'ordre.
  Exemple : `CONFIRMER_PRODUCTION='db_demo,gmasuite' scripts/migrate_all_v2_x.sh db_demo gmasuite`. Une chaîne d'essais ou de mutations
  ne pose jamais cette variable et n'appelle que des scripts et des bases de test. Copie des scripts d'avant la garde :
  `var/sauvegardes/scripts-avant-garde-20261117.tgz`.

## 4. Photo avant / après

- La photo lit le **site de contrôle** (le site de test), avec et sans jeton, et les pages publiques de **tous** les sites. Elle fait
  des GET, et des **vérifications de refus** : une requête d'écriture dont on attend le refus (connexion refusée, écriture sans jeton),
  pour garder une protection sous contrôle à chaque bascule. Jamais une écriture qui réussit. Mesuré le 14/11/2026 : ces refus
  n'écrivent rien dans la base ; ceux d'une route soumise à la limite de débit consomment un compteur temporaire dans Redis. Chaque
  refus de ce genre vise donc **un faux compte à lui** (`…@example.invalid`), et la photo part de l'adresse interne de la machine, que
  n'emprunte aucun trafic réel (à revérifier dans le journal du serveur web si l'hébergement change) : aucun compteur ne peut gêner
  un vrai utilisateur. Plus de dix photos en un quart d'heure feraient répondre 429 à ces refus : une fausse différence, pas une panne.
- Toute requête, même une lecture, compte une visite dans la fréquentation du site de contrôle : c'est une raison de plus de ne
  photographier que le site de test.
- **Aucune lecture qui écrit** dans `var/bascule.lectures` : la réimpression d'un reçu de caisse, un export comptable, un rapport X ou
  les statistiques des caissiers s'inscrivent au journal de caisse, qui est inaltérable ; la première lecture de certains réglages crée
  leur ligne ; une question à une IA coûte une unité.
- Avant de basculer, on écrit ce qu'on attend : « tout identique », ou la liste des différences du lot (route nouvelle : 404 → 200 ;
  champ ajouté). La comparaison ne doit montrer **que** cela. Les valeurs qui bougent seules (heure, compteur de visites) s'écartent
  par le motif donné à `comparer`.
- **À la moindre différence inattendue ou réponse 5xx : retour arrière et arrêt.** On ne pousse pas, on ne passe pas au lot suivant,
  on prévient.

## 5. Retour arrière

`ops/bascule/bascule.sh retour <commit d'avant> <n>` : le code revient au commit d'avant et le cache d'avant, resté dans
`var/swap_stage<n>/dev`, est échangé de nouveau. La commande exacte est affichée par `switch`. Rien n'a été poussé à ce stade.
Pour revenir en avant ensuite : `switch <commit> <n>` avec le même numéro (le cache remis en avant est resté dans `var/swap_stage<n>`).

Répété sur la production le 14/11/2026, de l'étape 60 à l'étape 59 puis retour à 60 (aucun fichier servi ne changeait) : retour en
0,31 s, remise en 0,11 s, photos identiques aux trois temps, les pages publiques de tous les sites en 200 du début à la fin.
Deux choses apprises :
- si le lot avait déjà été poussé, l'arbre en ligne se retrouve en retrait d'`origin/dev` après le retour : c'est permis. L'outil
  exige seulement que le commit à mettre en ligne contienne ce qui est en ligne **et** ce qui est poussé, et il signale le retrait ;
- un retour à un commit d'avant l'entrée de l'outil au dépôt le retire de l'arbre en ligne : le lancer alors depuis un worktree, avec
  `BASCULE_TREE=<arbre en ligne>`.
Si l'authentification reste en échec après le retour (401 partout) : un point de montage a été remplacé, il ne revient qu'au
redémarrage du conteneur (`docs/architecture.md`, « Points de montage du conteneur »).

**Retour à un commit d'avant le mode exercice de la prévention des pertes (lot EX-1) : purger d'abord les exercices.** Le code d'avant
EX-1 ne connaît pas la marque `loss_finding.exercise` : un constat d'exercice resté en base y deviendrait un vrai constat (synthèse,
liste, vignette, courriel du jour) et ne pourrait plus être effacé. AVANT l'échange du code, avec le code d'EX-1 encore en ligne :
```
docker exec -w /var/www symfony_app_v2 php bin/console app:pertes:exercices:effacer --all --dry-run   # compte, site par site
docker exec -w /var/www symfony_app_v2 php bin/console app:pertes:exercices:effacer --all             # efface ; doit finir en succès
```
La commande n'efface que des constats d'exercice (la base refuse elle-même tout le reste) ; elle sort en échec si un site est en
erreur : ne pas revenir en arrière tant qu'elle n'a pas réussi partout. La migration `Version20270105100000` peut rester en place
(l'ancien code ignore la colonne) ; si on la défait, son `down()` purge lui aussi les exercices avant de retirer la colonne. Détail :
`docs/prevention-pertes.md`, § 8, « Retour arrière ».

## 6. Ce que l'outil garde, et pourquoi

| Garde | Raison |
|---|---|
| Arbre en ligne propre ; le commit contient ce qui est en ligne et ce qui est poussé (`origin/dev`) ; avance rapide seulement | une autre session a pu pousser ou mettre en ligne entre-temps |
| Livraison qui touche `config/jwt`, `jwt/`, `docker/`, `.env`, ou qui contient un lien symbolique : refusée | incident du 13/11/2026 : un lien `config/jwt` a remplacé le point de montage des clés, authentification coupée 2 minutes sur tous les sites |
| Numéro de cache neuf, nom de photo neuf : l'outil ne supprime rien en chemin | on ne perd ni un retour arrière ni une photo « avant » |
| Cache construit par un conteneur provisoire, code monté en lecture seule | le cache en ligne n'est pas touché avant l'échange |
| Deux secondes d'attente, puis lien vers le conteneur de services sortant posé avant l'échange | une requête ou un worker démarrés sur l'ancien cache lisent encore ses fichiers quelques instants : sans le lien, erreur critique au journal |
| Échange atomique des deux dossiers | aucun instant sans cache |
| Workers arrêtés puis attendus (le worker des courriels doit battre après son redémarrage) | un worker garde l'ancien code en mémoire |
| Lecture authentifiée et compte des clés juste après | une page publique à 200 ne dit rien des clés des jetons |
| Jeton de contrôle dans `var/`, lisible du seul compte du serveur web, jamais affiché, supprimé à la fin | aucun secret dans un journal ni dans le dépôt |
| Règle, hors de l'outil : le secret de l'application (`APP_SECRET`) ne se change jamais sans le plan des boîtiers de la prévention des pertes | depuis le lot L3-a, les clés des boîtiers, les machines inscrites et leurs jetons en dépendent : le changer les rend tous inconnus, sur tous les sites (`docs/prevention-pertes.md` § 16.13) |

## 7. Anciens caches

Chaque bascule laisse un dossier `var/swap_stage<n>` (environ 60 Mo). `ops/bascule/bascule.sh menage` les liste ;
`menage --oui` supprime tout sauf les trois derniers et ceux vers lesquels le cache en ligne a encore un lien. Le dernier est le retour
arrière de la mise en ligne en cours : on ne le supprime jamais à la main.

## 8. Rotation de la clé des jetons (JWT-ROTATION, contrat validé le 20/11/2026, REPORTÉE)

Décision du propriétaire : reportée au passage de la production sur Kubernetes (dépôt privé, serveur actuel de développement). Ce jour-là :
- les nouvelles clés et une NOUVELLE phrase de passe vont dans les Secrets de Kubernetes, jamais dans git ;
- `jwt/private.pem` et `jwt/public.pem` (suivis par git depuis b6faf9f8) sortent du suivi (`git rm --cached`, `/jwt/` dans `.gitignore`) ;
  une bascule vers un commit qui retire un fichier suivi l'EFFACE de l'arbre en ligne : sauvegarde de `jwt/` par l'outil juste avant, et
  exception étroite et écrite dans la garde des montages (retrait de ces deux fichiers seulement, permis si le cache construit ne les lit
  plus) ;
- ordre sans coupure : la nouvelle paire est préparée à part (`jwt/v2/`, non suivi), les chemins lexik changent dans la configuration
  (échange atomique du cache), le retour arrière remet l'ancien cache qui lit les anciens fichiers ; le retrait du suivi vient dans un
  second train, après le délai de retour ;
- la phrase de passe est dans l'environnement du conteneur (`env_file`, lu à sa CRÉATION) : modifier `.env` n'a aucun effet sans recréer
  les conteneurs ;
- conséquences : tous les jetons d'accès deviennent invalides ; les jetons de rafraîchissement (en base) en redonnent un. Vérifié dans le
  code des applications le 20/11/2026 : la caisse et MAUI rafraîchissent puis rejouent une fois, sans boucle ; le web à vérifier ce jour-là ;
- contrôles : connexion, rafraîchissement, lecture authentifiée, ancien jeton refusé en 401, les workers, `git ls-files jwt/` vide ;
- l'ancienne image Docker (qui contient `.env` et la clé) est supprimée après le délai de retour, sur approbation du propriétaire.

## 9. Image de l'application (PHP, lot PHP84, 20/11/2026)

L'image ne fournit que PHP, ses extensions et sa configuration (`docker/php/php.ini`, minimal : erreurs jamais affichées et
journalisées, `expose_php` coupé, limites d'avant) : le code, le vendor, `.env` et `jwt/` sont montés. Elle ne doit JAMAIS
contenir de secret : elle sera poussée dans un registre (cible Kubernetes). Toute construction passe par l'outil, jamais par
`docker build .` dans l'arbre en ligne, et chaque image est contrôlée (`ops/image/verifier-image.sh` : familles de secrets
partout sous `/var/www`, `/root`, `/home`, variables d'environnement de l'image, réglages PHP en CLI et en FPM, version).

Changer d'image (commandes approuvées par le propriétaire, lancées par le chaperon) :
1. Garder les images en service pour le retour : `docker tag app_v2-app:latest app_v2-app:retour-<date>` et
   `docker tag app_v2-worker-media:latest app_v2-worker-media:retour-<date>`.
2. Construire depuis le COMMIT, sous une étiquette neuve (jamais `latest` : l'outil la refuse) :
   `ops/image/construire-image.sh app_v2-app:<version> <commit> <version PHP>`. L'image n'est gardée que si elle est conforme.
3. `docker tag app_v2-app:<version> app_v2-app:latest`, puis reconstruire l'image du worker `media` (elle part de `latest`) et
   la contrôler : `docker build -t app_v2-worker-media:<version> docker/worker-media && ops/image/verifier-image.sh
   app_v2-worker-media:<version> <version PHP> && docker tag app_v2-worker-media:<version> app_v2-worker-media:latest`.
4. Vérifier qu'aucune génération IA n'est en cours (statut de FPM, files Messenger), puis recréer le site et les 8 workers,
   sans toucher à la base, à Redis ni à nginx : `docker compose -p app_v2 up -d --no-deps --no-build --force-recreate app
   messenger_worker messenger_worker_esg messenger_worker_landing messenger_worker_media messenger_worker_pdf
   messenger_worker_email messenger_worker_gestion messenger_worker_scheduler`. Recréer un conteneur relit `.env`.
5. Recharger nginx (il résout `app` à son démarrage, et `app` recréé change d'adresse) :
   `docker exec symfony_nginx_v2 nginx -t && docker exec symfony_nginx_v2 nginx -s reload`.
6. Contrôles : landing pages publiques d'abord, `/api/health`, connexion, `/admin` ; `php -v` et `php -m` du conteneur ;
   statut de FPM ; les 8 workers démarrés après l'heure de bascule, sans redémarrage en boucle ; `messenger:stats` ;
   502 / 504 du journal de nginx sur 10 minutes.

Essai à blanc du retour, avant : la même ligne `docker compose … up` avec `--dry-run` montre l'image prise par chaque conteneur.
Retour : `docker tag app_v2-app:retour-<date> app_v2-app:latest`, idem pour `app_v2-worker-media`, puis les étapes 4 et 5.
Tant que le retour est possible, le code doit rester compatible avec l'ancienne image (au lot PHP84 : `composer.json` passe à
PHP 8.4 dans un SECOND train, sinon `vendor/composer/platform_check.php` refuserait l'ancien PHP).

Après le délai de retour : supprimer les anciennes images (celles d'avant le 20/11/2026 contiennent `.env` et la clé JWT) et
le cache de construction qui garde leurs couches (`docker builder prune`), sur approbation du propriétaire. Ne jamais pousser
dans un registre une image qui n'a pas passé `verifier-image.sh`.
