# Niveaux des courriels de suivi (lot NOTIF)

Contrat validé par le propriétaire le 15/11/2026. But : qu'un courriel de suivi ne soit jamais perçu comme du spam, surtout sur un site
d'essai ou qui démarre.

Code : `src/Services/Notification/`. Tests : `tests/Functional/Notification/`. Notifications sur téléphone : `docs/notifications-gestion.md`.

Lot NOTIF-1 (codé le 15/11/2026) : classement, niveaux du site et du compte, décision d'envoi, courriels retenus et leur sortie par sujet,
plafond, routes, EasyAdmin. Lot NOTIF-2 (à venir) : résumé de la semaine du niveau « Essentiel », courriels des droits d'un site,
niveaux appliqués au téléphone.

## 1. Trois familles de courriels

`NotificationCatalog::KINDS` classe **chaque** courriel que le serveur envoie.

| Famille | Ce que c'est | Sort |
|---|---|---|
| `indispensable` | la réponse à une action de la personne, ou un document qu'elle attend ; **tout courriel adressé à un client d'un site** | part toujours ; jamais filtré, retardé ni compté |
| `urgent` | un fait qui demande d'agir vite | part tout de suite à partir du niveau « Essentiel » ; compte dans le plafond, jamais retenu |
| `followup` (suivi) | une information sur l'activité, rangée dans un sujet | la seule famille que les niveaux retiennent ou regroupent |
| `internal` | courriel de l'équipe de la plateforme | hors des niveaux d'un site |

Indispensables : code de connexion et codes de confirmation, vérification de l'adresse, mot de passe oublié, avis « adresse modifiée »
et « compte supprimé », invitations (compte Mémoires Vivantes, agent d'assistance, chapitre, avis d'invitation épuisée), courriels au
client d'une boutique (confirmation, expédition, remboursement, annulation, retour, reçu, abonnement, reçu de caisse), courriels au
client d'un service (réservation, accusés d'un contact ou d'un formulaire, lien de règlement et facture d'un livre, réponse de
l'assistance), messages écrits par un client (contact, formulaire à étapes, financement), clé d'activation, courriel d'essai, courriel
d'exercice de la prévention des pertes, droit acheté sur la boutique de la plateforme.

Urgents : alertes de la surveillance des paiements de la caisse (`pos_health_alert`) ; abonnement du site à la plateforme
(`entitlement_urgent` : paiement en attente, accès suspendu, accès terminé, fin d'essai demain).

Suivi, par sujet (`NotificationCatalog::TOPICS`) :

| Sujet | Courriel |
|---|---|
| `orders` | nouvelle commande à expédier (`order_to_ship`) |
| `bookings` | nouvelle réservation ou séance programmée (`booking_new`). Une demande venue d'un **formulaire à étapes** passe par le même envoi mais reste un message écrit par un client : indispensable (`customer_message`), elle part toujours |
| `payments` | paiement reçu pour un livre (`book_payment_received`) |
| `loss` | résumé du jour de la prévention des pertes (`loss_digest`) |
| `entitlements` | essai commencé, droit accordé, fin d'essai dans 7 jours, accès rétabli, demande enregistrée ou refusée (`entitlement_followup`) |
| `support`, `management` | rien aujourd'hui par courriel |
| aucun | le point de la semaine (`weekly_digest`) : c'est le résumé lui-même |

## 2. Ce que fait chaque niveau

`NotificationLevel` : `none`, `essential`, `normal`, `all`.

| Niveau | Urgent | Suivi |
|---|---|---|
| `none` Aucune | ne part pas | ne part pas |
| `essential` Essentiel | part tout de suite | retenu pour le résumé de la semaine |
| `normal` Normal | part tout de suite | le premier du jour et du sujet part, les suivants attendent le courriel du lendemain |
| `all` Tout | part tout de suite | chaque événement part |

- **Rien avant 8 h du magasin** (`NotificationLevel::FROM_HOUR`) : un courriel de suivi né avant est retenu et sort le jour même, au
  premier passage à partir de 8 h. C'est la règle du résumé des pertes, devenue commune.
- **Plafond** (`NotificationLevel::DAILY_CAP`) : 3 courriels de notification (urgent + suivi) par jour du magasin et par destinataire, quel
  que soit le niveau. Au-delà, un courriel de suivi est retenu ; un urgent part quand même.
- **Un type coupé dans « Avancé »** (`notification_preference`) ne part à aucun niveau.

## 3. Le niveau du site et celui du compte

`NotificationLevelResolver`.

- **Site** : `entreprise.notification_level`, réglé par la plateforme (fiche du site d'EasyAdmin, super administrateur ; clé
  `site.notification_level` du registre des fonctionnalités : `value` = le réglage, `effective` = le niveau qui s'applique). Vide =
  **automatique** : `essential` pour un site d'essai, `normal` pour un site en activité.
- **Site en activité** : la plateforme est en mode « live » **et** le site a au moins un droit actif payé (source `subscription` ou
  `purchase`). Tout le reste est un site d'essai : plateforme en mode « demo » (`reason: platform_demo`), essai, droit offert ou accordé
  à la main (`reason: trial`). Tant que la plateforme est en mode « demo », tous les sites sont donc en `essential`.
- **Compte** : `"user".notification_level`, choisi par chaque compte dans l'application de gestion ; il remplace celui du site. Vide =
  comme le site.
- **Adresse de contact** : beaucoup de courriels de suivi partent à l'adresse de contact du site. Si elle est celle d'un compte de
  l'ÉQUIPE du site (administrateur, agent d'assistance ou propriétaire de la plateforme, vérifié, non supprimé), le niveau de ce compte
  s'applique ; sinon celui du site (`forAddress`). Un compte client à la même adresse ne règle rien.

## 4. La décision d'envoi et les courriels retenus

`NotificationGate` est le seul point de passage des courriels urgents et de suivi.

- `decide(kind, email, now)` rend `send`, `hold` ou `drop`, sans rien écrire.
- `deliver(kind, email, titre, action, envoi)` décide, envoie ou retient, et écrit au journal. Un courriel indispensable ou interne
  est envoyé tel quel, sans rien compter ni écrire. **Une panne de la décision ne fait jamais perdre un courriel** : si la décision ou
  l'écriture de la retenue lève une erreur (base, colonne absente), le courriel part comme avant les niveaux ; un courriel de trop
  vaut mieux qu'un courriel perdu.
- `decide(..., ignoreHours: true)` : sans la règle des 8 h, pour un lancement à la main (`app:pertes:analyser --courriel-maintenant`).
- Le journal est `notification_log`, `channel = 'email'` (les lignes du téléphone sont `push` ; les requêtes du téléphone ne lisent que
  `push` : un courriel ne consomme jamais la notification du jour du téléphone). États d'un courriel : `sent`, `held` (retenu), `kept`
  (gardé, jamais envoyé), `failed`. `user_id` vaut 0 quand l'adresse n'est celle d'aucun compte.

Un courriel retenu n'est jamais perdu. Sa ligne dit pourquoi il attend (`subject.reason`) :

| Raison | Quand | Il sort |
|---|---|---|
| `weekly` | niveau « Essentiel » | dans le résumé de la semaine (lot NOTIF-2) |
| `before_hours` | avant 8 h du magasin | le jour même, à partir de 8 h |
| `topic_done` | niveau « Normal », le sujet a déjà eu son courriel du jour | le lendemain |
| `daily_cap` | plafond du jour atteint | le lendemain |
| `with_backlog` | des retenus plus anciens du même sujet attendent | avec eux, au prochain passage |
| `send_failed` | l'envoi a échoué alors que d'autres destinataires étaient servis (résumé des pertes) | au prochain passage |

`NotificationSummaryService::releaseDue()` sort les retenus : appelé à chaque passage horaire de `app:gestion:notifications:envoyer`
(minuteur `gestion-notifications.timer`), pour tout site. À partir de 8 h du magasin, pour chaque destinataire et chaque sujet qui a des
retenus, **un** courriel réunit tous les retenus du sujet (`emails/notification_summary.html.twig`), dans le respect des mêmes règles
que les autres courriels : le plafond du jour, et « un par sujet et par jour » au niveau « Normal ». Ce sont ces deux règles qui disent
quand un retenu sort (tableau ci-dessus). Les sujets sortent du plus ancien retenu au plus récent : quand le plafond arrête la sortie,
ce n'est jamais toujours le même sujet qui attend. Le courriel sorti compte pour **un** dans le plafond, quel que soit le nombre de
lignes qu'il porte (`released_at`). Les lignes passent à `sent` ; elles sont verrouillées le temps de l'envoi, et un second passage ne
les retrouve plus : rien ne part deux fois. Un envoi qui échoue les laisse `held`. Niveau « Aucune », ou type coupé depuis la retenue :
la ligne passe à `kept`. Niveau « Essentiel » : rien ne sort ici.

Limites connues : trois courriels urgents partis avant le premier passage du jour épuisent le plafond de ce jour-là (les retenus
sortent le lendemain) ; deux envois simultanés peuvent dépasser le plafond d'une unité.

**Conservation** : le journal garde des adresses et des titres (qui peuvent nommer un client). Au même passage, les lignes de courriel
parties, gardées ou en échec depuis plus de 90 jours sont effacées (`RETENTION_DAYS`) ; une ligne retenue n'est jamais effacée. Un site
neuf, copie du site de référence, part d'un journal vide (`TenantConnectionManager`).

Le lien du courriel passe par `AdminSpaceUrl` (`docs/liens-espace-administration.md`) quand le sujet a un écran connu (`loss`,
`support`) ; sinon le courriel part sans lien.

## 5. Routes

Aucune route nouvelle : les deux routes de préférences de la gestion s'enrichissent (`ROLE_ADMIN`).

`GET /api/gestion/notifications/preferences` rend, avant `kinds` :

```
"level": {
  "value": "essential",          le niveau qui s'applique à ce compte
  "source": "site",              "user" si le compte a choisi
  "userLevel": null,             none | essential | normal | all | null (comme le site)
  "siteLevel": { "value": "essential", "mode": "auto" | "manual", "reason": "platform_demo" | "trial" | "active" | null, "label": "Essentiel", "reasonLabel": "site d'essai" },
  "dailyCap": 3,
  "heldCount": 0,                courriels retenus en attente pour ce compte
  "siteChoiceLabel": "Comme le site",
  "choices": [ { "key": "none", "label": "Aucune", "description": "…" }, … les quatre, dans l'ordre ]
}
```

Chaque élément de `kinds` gagne `family` (`urgent` | `followup`) et `subject`. Un type de plus : `weekly_digest` (courriel, actif par
défaut), le point de la semaine, qui se coupe indépendamment du niveau.

`PATCH /api/gestion/notifications/preferences` accepte `{ "level": "normal" }`, `{ "level": null }` (comme le site) et `{ "kinds": { … } }`,
ensemble ou séparément ; rend la forme du `GET`. Refus : 422 `VALIDATION_FAILED`, `fields.level = INVALID_VALUE`.

`GET /api/gestion/notifications/journal` rend aussi les courriels du compte, avec `channel` (`push` | `email`), `topic` et leur état.

## 6. Ce que le lot NOTIF-1 ne fait pas encore

- **Résumé de la semaine** : au niveau « Essentiel » les courriels de suivi sont retenus (`weekly`) et attendent le lot NOTIF-2. D'ici
  là ils ne partent pas ; ils se lisent dans le journal de l'application.
- **Point de la semaine** : son minuteur (`site-weekly-digest.timer`) est **suspendu par le propriétaire depuis le 15/11/2026**. À la
  mise en ligne de NOTIF-2, le propriétaire le remet en marche : `sudo systemctl enable --now site-weekly-digest.timer`.
- **Courriels des droits d'un site** (`EntitlementChangedHandler`) : classés, mais envoyés comme avant. Ce gestionnaire écrit pour un
  site qui n'est pas le site courant ; la décision d'envoi lit le site courant. À brancher au lot NOTIF-2.
- **Téléphone** : la règle « une notification par jour » ne change pas ; les niveaux s'y appliqueront au lot NOTIF-2.
- **Courriel de suivi sans sujet** (le point de la semaine) : la sortie des retenus ne traite que les lignes qui ont un sujet. Sans
  effet aujourd'hui (aucun n'est branché) ; à régler avec le point de la semaine.

Ces deux dettes sont écrites dans `NotificationCatalogGuardTest::NOT_YET_GATED`.

## 7. Ajouter un courriel

1. Le classer dans `NotificationCatalog::KINDS` (famille, sujet).
2. Inscrire le fichier qui l'envoie dans `NotificationCatalog::SENDERS`.
3. S'il est urgent ou de suivi, l'envoyer par `NotificationGate::deliver()`.

Le garde-fou `NotificationCatalogGuardTest` balaie `src/` : un fichier qui envoie un courriel sans être inscrit, ou un courriel urgent ou
de suivi qui ne passe pas par la décision d'envoi, fait échouer la suite.

## 8. Tests

Les sites de test sont au niveau « Tout » (`tests/bootstrap.php`), sans plafond ni heure (`config/services_test.yaml`), pour que la
suite ne dépende ni du nombre de tests ni de l'heure. `NotificationLevelsTest` règle le niveau lui-même et monte la décision d'envoi
avec les vraies valeurs ; il vérifie aussi que seules ces deux valeurs sont relevées, et seulement dans le fichier de test.

## 9. Base

Migration `Version20270106100000`, script `scripts/migrate_all_v2_notif.sh` : `entreprise.notification_level`,
`"user".notification_level`, `notification_log.channel` (défaut `push`), `recipient`, `topic`, `released_at`, un index. Additive ; à
appliquer à toutes les bases **avant** l'échange du code (l'entité `User` lit la colonne nouvelle à chaque requête).
