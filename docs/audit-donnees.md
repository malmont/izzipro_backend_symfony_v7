# Audit DONNÉES : réversibilité d'un site, export et effacement des données d'un client final

> 18/11/2026, session « prevention des pertes backend », à la demande de « new easymakemoney ». Lecture seule : code et
> docs de `dev` en ca235dff. Aucune base, aucun `.env`, aucun site n'a été lu. Aucun code, aucun contact extérieur.
> Les règles du § 1 sont **indicatives**, à faire valider par un juriste avant tout lot.
>
> Mémoires Vivantes et Living Memory sont un projet à part (décision du propriétaire, 16/11/2026) : leurs constats sont
> notés ici, mais aucun lot proposé ne les touche sans sa demande (§ 4, DON-MV).

## 0. Synthèse

1. **Aucun export complet d'un site.** Il n'existe ni commande, ni route, ni procédure de fin de contrat. Ce qui sort
   aujourd'hui tient en quatre morceaux :
   - l'archive annuelle de la caisse ;
   - les exports comptables de la caisse ;
   - le CSV des achats de la gestion ;
   - le CSV des formulaires.

   Les clients web, les commandes web, le catalogue, les pages, les réglages et les fichiers n'ont aucun export.
2. **Aucun « télécharger mes données » pour un client final.** Seule la fiche de caisse a un export complet
   (`GET /api/pos/customers/{id}/export`), et il est réservé au responsable. Un compte web ne peut lire ses données que
   route par route : profil, commandes, adresses, abonnements, appartenances.
3. **L'effacement d'un compte existe et il est bon dans l'ensemble.** Le client ou l'administrateur peut le lancer. Il
   anonymise le compte en gardant les pièces légales, supprime le client chez Stripe et supprime jetons, codes et
   appartenances (`PersonalDataEraser`). Il **laisse** cependant :
   - la fiche de caisse rattachée ;
   - le n° de permis des commandes invitées ;
   - les contacts ;
   - le journal des contenus ;
   - les données chez les tiers autres que Stripe.
4. **Une donnée personnelle s'écrit en clair dans un journal qu'on ne peut plus effacer.** Le nom tapé dans la recherche
   de clients de la caisse est écrit tel quel au journal de caisse chaîné et inaltérable
   (`PosCustomerService.php:317`, `query => $logged`). Le courriel et le téléphone, eux, sont masqués (l. 283 et 292).
   C'est le seul écart à corriger vite : une petite correction, pour l'avenir. Les entrées déjà écrites restent.
5. **Les durées de conservation ne sont pas bornées partout.**
   - Les journaux de l'application n'ont pas de rotation : `type: stream`, `config/packages/monolog.yaml:11-40`, et
     `var/log/dev.log` pèse environ 300 Mo le 18/11/2026. On y trouve des identifiants de compte et des adresses IP.
   - Le minuteur de l'anonymisation des tickets d'assistance n'est pas installé (`docs/assistance.md`).
   - Les sauvegardes sont gardées 14 jours, sur le même disque. Aucune procédure n'efface de nouveau un compte après une
     restauration.

## 1. Cadre (indicatif)

- **RGPD (Union européenne, sites établis en France)** :
  - droit d'accès (art. 15), droit à la portabilité dans un format structuré et lisible par machine (art. 20) et droit
    à l'effacement (art. 17) ;
  - réponse dans le mois ;
  - l'effacement cède devant une obligation légale de conservation : pièces comptables, journal de caisse (10 ans en
    France, `RetentionPolicy`).
- **Loi 25 (Québec)** :
  - droit d'accès et de rectification ; portabilité depuis le 22/09/2024, dans un format technologique structuré et
    couramment utilisé ;
  - réponse dans les 30 jours ;
  - destruction ou anonymisation une fois la fin atteinte, sous réserve des durées légales ;
  - un responsable de la protection des renseignements personnels par entreprise.
- **Rôles** : chaque commerçant est **responsable** des données de ses clients, et Arkanoa est son **sous-traitant**. Le
  commerçant répond à la demande ; nous devons lui en donner les moyens, en libre-service.
- **Réversibilité d'un site** :
  - elle relève d'abord du contrat (CGV de la plateforme) ;
  - pour les clients établis dans l'Union, le règlement européen sur les données (« Data Act ») impose aux services de
    traitement de données, logiciels en ligne compris, de permettre le changement de fournisseur et l'export des
    données. Les frais de changement disparaissent au 12/01/2027 ;
  - **la portée exacte pour la plateforme est à confirmer** par un juriste.

## 2. Ce qui existe

### 2.1 Export d'un site

| Export | Route ou outil | Rôle | Périmètre |
|---|---|---|---|
| Archive annuelle de la caisse | `GET /api/pos/archive/{year}`, `/admin/pos/archive` (`PosArchiveService`) | responsable ou administrateur | ZIP : Z, journal de caisse et contrôle du chaînage, ventes, taxes, paiements, tickets, cartes-cadeaux, PDF de synthèse, `SHA256SUMS`. Caisse seulement, une année à la fois |
| Exports comptables (F14) | `GET /api/pos/exports/accounting`, `…/summary.csv`, `…/entries.csv`, `/admin/pos/exports` | responsable ou administrateur | Caisse seulement |
| Achats de la gestion | `GET /api/gestion/exports/achats.csv` | administrateur | Une période |
| Formulaires reçus | `GET /api/form-submissions/export` | administrateur | Formulaires |
| Sauvegarde quotidienne | Minuteur de l'hôte à 04:00 UTC, **hors dépôt** (`ops/tech-metrics-host.sh:11-13` le surveille) | propriétaire | Dump de chaque base et médias de Mémoires Vivantes, 14 jours, même disque (`docs/mise-en-production-caisse.md:137-138`) |
| Copie hors serveur (restic) | Procédure écrite, **non installée** (`docs/mise-en-production-caisse.md:132-191`) | propriétaire | — |

Les fichiers se rangent ainsi (`docs/architecture.md`, stockage) :
- `var/storage/public_bucket/` : public, servi par `/uploads/` ;
- `var/storage/private_media/` : privé, servi par `/media/secure/{clé}` ;
- `var/storage/esg/` : la Boussole ;
- `var/storage/private_media/gestion-documents/<site>/` : les documents de la gestion.

Le public n'est pas rangé par site, à vérifier : isoler les fichiers d'un site demandera un inventaire par les tables.

### 2.2 Export des données d'un client final

- **Fiche de caisse** : `GET /api/pos/customers/{id}/export`, réservé au responsable (`PosCustomerService::export`,
  `PosCustomerService.php:565`). Contenu :
  - identité, fidélité et points ;
  - historique des consentements ;
  - ventes, retours, cartes-cadeaux, avoirs ;
  - commandes web du compte rattaché.

  Il est journalisé (`docs/caisse.md`).
- **Compte web** : des lectures séparées, sans export groupé :
  - `GET /api/profile`, `/api/orders`, `/api/orders/{id}`, `/api/adresses` ;
  - `GET /api/subscriptions`, `/api/account/memberships`.
- **Administration** :
  - les invités d'un import (`/api/site/imports/{id}/customers`) ;
  - l'archive des commandes importées (`/admin/clients/{id}/commandes-avant`) ;
  - un utilisateur de Living Memory (`/api/memoires/admin/mobile-users/{userId}`).

### 2.3 Effacement

- **Par le client** :
  - `DELETE /api/account` (`AccountApiController.php:27`, `DeleteAccountUseCase`), avec les garde-fous de
    `AccountDeletionGuard` : compte interne refusé, plafond, abonnement actif ou réservation à venir (409), mot de passe
    ou code OTP ;
  - courriel de confirmation, trace `account.deleted` au journal de sécurité.
- **Par l'administrateur** : `/admin/users/{id}/effacer` (`CustomerAccountEraseAdminController`), mêmes effets, jamais
  sur un compte interne.
- **Effacement commun** (`PersonalDataEraser.php:38-100`) :
  - le compte est anonymisé : courriel `supprime-<id>@compte-supprime.invalid`, nom, mot de passe et rôles remplacés ;
  - les adresses sont anonymisées, le pays restant pour les taxes ;
  - avis, réservations et infolettre sont traités ;
  - jetons, OTP, PIN de caisse, sessions de paiement et appartenances sont supprimés ;
  - `import_order.user_id` est mis à NULL ;
  - le client Stripe est supprimé chez Stripe.
- **Fiche de caisse** : `POST /api/pos/customers/{id}/erase`, avec validation d'un responsable. Anonymisation, points
  annulés, consentements retirés, ventes gardées.
- **Conservation légale** : `RetentionPolicy` (CA 6 ans, US 7 ans, FR et DROM 10 ans, 10 ans par défaut,
  `purge => none`), garde `RetentionGuardSubscriber` (409 `RETENTION_LOCKED`), journal de caisse inaltérable.
- **Purges existantes** :
  - formulaires : `app:form-submissions:purge`, 12 mois réglables ;
  - invités par import non acceptés : 90 jours ;
  - `notification_log` du courriel : 90 jours ;
  - rapports PDF de la gestion : 90 jours ;
  - documents non confirmés : 30 jours ;
  - tickets d'assistance fermés : 3 ans, mais `app:support:retention` est en simulation par défaut et **sans
    minuteur**.
- **Mémoires Vivantes** : `DELETE /api/memoires/mobile/account` supprime livres, audio, photos et PDF. Un livre lié à
  une impression ou à un achat garde un titre neutre.

## 3. Écarts classés

### 3.1 Obligatoires

| # | Écart | Pourquoi |
|---|---|---|
| D1 | **Export des données d'un client** dans un format structuré, lancé par le client (« Télécharger mes données ») et par l'administrateur pour le compte web. La fiche de caisse rattachée y entre | RGPD art. 15 et 20, Loi 25 (portabilité). Aujourd'hui, seule la fiche de caisse a un export, et il n'est pas offert au client |
| D2 | **Ne plus écrire le nom tapé en clair** au journal de caisse inaltérable : le masquer ou n'écrire que sa forme, comme le courriel | Donnée qu'on ne peut plus effacer ensuite. Petite correction |
| D3 | **Compléter l'effacement** : n° de permis des commandes invitées (`Order.php:156-159`), fiche de caisse rattachée (sur décision, § 5), contacts, `notification_log` des autres canaux | Données laissées après un effacement |
| D4 | **Durées de conservation bornées** : rotation des journaux de l'application (identifiants et IP), durée du journal des contenus, minuteur de l'assistance | Principe de limitation de la conservation |

### 3.2 Utiles

| # | Écart | Pourquoi |
|---|---|---|
| U1 | **Export complet d'un site** : catalogue, clients, commandes, caisse, gestion, pages, réglages, fichiers ; manifeste et empreintes | Réversibilité contractuelle et Data Act pour les clients de l'Union ; argument de vente face à Shopify |
| U2 | **Fin de contrat** : export, puis suppression de la base et des fichiers d'un site, avec une preuve, et le sort des sauvegardes | Aucune procédure aujourd'hui |
| U3 | **Effacement chez les tiers** : RevenueCat, Lulu, EasyPost (rien dans `src/`) ; et registre des sous-traitants (OpenAI, Anthropic, Stripe, Google, Firebase) | Le commerçant doit pouvoir les nommer |
| U4 | **Preuve du consentement aux conditions** gardée après effacement (version, date), sans identité | La supprimer avec l'appartenance efface la preuve |
| U5 | **Ré-effacement après restauration** d'une sauvegarde | Un compte effacé reviendrait |

### 3.3 Plus tard

- Purge des ventes à l'échéance légale (6 ou 10 ans) : choix assumé de ne pas purger (`purge => none`), à revoir
  avant 2032.
- Export au format d'une autre plateforme (CSV Shopify inverse de l'import IMP-1).

## 4. Découpage proposé

| Lot | Contenu | Couvre | Dépend de |
|---|---|---|---|
| **DON-0** (décisions, sans code) | Réponses du § 5 ; avis d'un juriste sur le Data Act et sur les durées | tout | rien |
| **DON-1** Journal de caisse | Le nom tapé dans la recherche n'est plus écrit : on garde seulement le genre de la recherche, sa longueur et le nombre de résultats. Rien d'autre ne change | D2 | rien (petit, à faire en premier) |
| **DON-2** Export d'un client | Un assembleur unique `PersonalDataExporter`, symétrique de `PersonalDataEraser`, par blocs déclarés : profil, adresses, commandes et leurs taxes, abonnements, réservations, avis, infolettre, appartenances et conditions, fiche de caisse rattachée (réemploi de `PosCustomerService::export`). ZIP JSON par la file, lien privé de 7 jours, un par jour, journal. Routes `POST /api/account/export` (client) et `/admin/users/{id}/exporter` (administrateur). Garde-fou : toute table qui porte `user_id` ou un courriel est classée « exportée » ou « exclue avec motif », comme le classement des courriels | D1 | DON-0 (fiche de caisse) |
| **DON-3** Effacement complété | N° de permis, contacts, `notification_log` des autres canaux, fiche de caisse rattachée (si décidé), preuve des conditions gardée sans identité. Même garde-fou de classement que DON-2, pour l'effacement | D3, U4 | DON-0 |
| **DON-4** Durées | Rotation des journaux (réglage de l'hôte : `logrotate` ou `rotating_file`, par le propriétaire), durée du journal des contenus, minuteur de l'assistance, tableau des durées dans la doc | D4 | DON-0 (durées) |
| **DON-5** Export d'un site | Commande et route du super administrateur et de l'administrateur, par la file et le worker `media`. Un ZIP par domaine : catalogue (CSV et JSON), clients, commandes web, caisse (archives annuelles existantes), gestion, pages et réglages, fichiers du site retrouvés par les tables. Manifeste, `SHA256SUMS`, lien privé, journal. Aucune donnée d'un autre site | U1 | DON-2 (assembleur), inventaire des fichiers |
| **DON-6** Fin de contrat | Suppression de la base et des fichiers d'un site après export, sous double confirmation du super administrateur. Preuve (empreinte du manifeste, date). Sauvegardes laissées à expirer, et ré-effacement après restauration écrit | U2, U5 | DON-5 |
| **DON-7** Tiers | Suppression chez RevenueCat et EasyPost (Lulu : à étudier), registre des sous-traitants dans la doc, à remettre au commerçant | U3 | DON-0 |
| **DON-MV** (sur demande du propriétaire seulement) | Livres, audio et photos de Living Memory dans l'export d'un client ; fichiers de Mémoires Vivantes déplacés du stockage public au stockage privé | D1, sécurité | décision du propriétaire (projet à part) |

**Ordre recommandé** :
1. DON-1 tout de suite : petit, sans décision, il évite d'accumuler des données qu'on ne pourra plus effacer.
2. Puis DON-0, et ensuite DON-2 et DON-3 ensemble : ils partagent leur garde-fou de classement des tables.
3. DON-4 en même temps, côté hôte.
4. DON-5 et DON-6 avant le premier client d'agence, ou avant le 12/01/2027 si le Data Act s'applique.

**Non-régression** :
- l'effacement garde ses garde-fous, réponses et codes d'erreur ;
- l'export de la fiche de caisse ne change pas ;
- aucun lot n'écrit dans le journal de caisse, sauf DON-1, qui y écrit **moins** ;
- les ventes et les pièces légales restent gardées ;
- l'export d'un site ne lit que la base de ce site (`switchTenant`) ;
- la photo de la bascule montre les routes nouvelles seulement.

## 5. Questions au propriétaire (et au juriste)

1. **Fiche de caisse rattachée** : l'effacer en même temps que le compte web, ou la laisser au commerçant (elle est sa
   donnée de caisse) ? Je recommande de l'effacer, sauf si le client l'a créée en caisse séparément.
2. **Export d'un client** : le client le lance-t-il seul, ou passe-t-il par le commerçant ? Je recommande le
   libre-service, avec une copie au commerçant dans son journal.
3. **Réversibilité** : export complet compris dans l'offre, ou payant ? Quel délai après la fin du contrat avant la
   suppression (proposé : 30 jours) ?
4. **Durées** : journaux de l'application et de sécurité (proposé : 12 mois), journal des contenus, contacts,
   sauvegardes (14 jours aujourd'hui, 24 mois avec restic : à concilier avec l'effacement).
5. **Entrées déjà écrites** au journal de caisse avec un nom en clair : on les laisse (inaltérables). Faut-il le dire
   dans la politique de confidentialité ?
6. **N° de permis des commandes invitées** : le garder comme pièce justificative ou l'effacer ?
7. **Sous-traitants** : qui tient le registre, et faut-il un avenant de sous-traitance type pour les commerçants ?
8. **Mémoires Vivantes** : ouvrir DON-MV, ou le laisser au projet à part ?

## 6. Ce que cet audit n'a pas fait

- Aucune base n'a été lue : la présence réelle de données dans chaque table n'est pas relevée.
- Les applications (Next, posflutter, Flutter) n'ont pas été relues : leur stockage local n'est pas couvert.
- Le minuteur de sauvegarde et son script sont hors du dépôt : leur contenu exact n'a pas été vu.
