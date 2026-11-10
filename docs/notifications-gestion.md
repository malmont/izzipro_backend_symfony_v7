# Notifications sur téléphone — application de gestion (lot N1, 11/11/2026)

Contrat validé par l'application (« new easymakemoney »). Seulement l'urgent, prolongement des vignettes d'accueil
(`docs/vignettes-gestion.md`) : une notification par jour du magasin au plus et par gérant, chaque type coupable par gérant, toucher la
notification ouvre l'écran de la vignette. **Sans Firebase pour l'instant** : l'envoi passe par `PushSenderInterface`, aujourd'hui
`NotConfiguredPushSender` (rien ne part, le journal note `not_configured`) ; une classe Firebase (FCM HTTP v1, qui relaie vers APNs) la
remplacera quand le propriétaire aura créé le projet Firebase et ses clés, sans changer le contrat.

Code : `Services/NotificationService/` (`PushSenderInterface`, `NotConfiguredPushSender`, `PushDeviceService`),
`Services/GestionService/GestionNotificationService`, `UseCase/NotificationUseCase/PushDeviceUseCase`,
`UseCase/GestionUseCase/GestionNotificationUseCase`, `Controller/NotificationController/PushDeviceController`,
`Controller/GestionController/GestionNotificationController`, commande `app:gestion:notifications:envoyer`
(`Command/GestionNotificationsSendCommand`), timer `ops/systemd/gestion-notifications.{service,timer}`. Tables `push_device`,
`notification_preference`, `notification_log` (entités du même nom, `CacheTags::NO_CACHE_READ`). Textes `translations/gestion.{fr,en}.yaml`
`notifications.*`. Migration `Version20261206100000`, script `scripts/migrate_all_v2_gestion_notifications.sh`. Test :
`tests/Functional/Gestion/GestionNotificationsApiTest.php` (envoi simulé `Tests\Fake\FakePushSender`).

## 1. Appareils (générique : gestion aujourd'hui, e-commerce et caisse plus tard)

| Route | Rôle |
|---|---|
| `POST /api/notifications/appareils { token, platform, deviceName?, locale?, appVersion? }` | connecté ; 201 à la création, 200 si le jeton est connu (rattaché à l'utilisateur connecté, réactivé) ; rend `{ id, platform, deviceName, locale, appVersion, createdAt, lastSeenAt, active }` |
| `DELETE /api/notifications/appareils/{token}` | 204, idempotent (déconnexion) ; ne retire que l'appareil de l'utilisateur connecté |

- `platform` : `admin` (gestion, réservé à `ROLE_ADMIN`), `pos` (caisse, réservé à `ROLE_USER_POS`, `ROLE_POS_MANAGER`, `ROLE_ADMIN`), `mobile`
  (tout utilisateur connecté) ; sinon 403 `PLATFORM_FORBIDDEN` ; `locale` `fr` | `en`.
  422 `VALIDATION_FAILED` (`token` `REQUIRED` / `TOO_LONG`, `platform` `REQUIRED` / `INVALID_PLATFORM`, `locale` `INVALID_LOCALE`).
- Un jeton est rattaché au **site** où il est inscrit (`X-Tenant-Host`, base du site) : le même téléphone sur deux sites a deux inscriptions
  indépendantes, un retrait ne touche que le site appelant.
- Un jeton refusé par le fournisseur (appareil désinstallé) est désactivé de lui-même (`disabled_reason` `invalid_token`).

## 2. Préférences et journal (`ROLE_ADMIN`)

- `GET /api/gestion/notifications/preferences?locale=` → `{ kinds: [{ kind, label, enabled, available }] }` ; tout est actif par défaut.
- `PATCH /api/gestion/notifications/preferences { kinds: { low_stock: false, … } }` → même forme ; 422 `kinds` `MUST_BE_OBJECT`,
  `kinds.<x>` `UNKNOWN_KIND` / `MUST_BE_BOOLEAN` (rien n'est écrit).
- Types : `low_stock`, `duplicate_invoice` (disponibles) ; `cash_critical`, `trip_budget` (`available: false` jusqu'à B1, coupables dès
  maintenant).
- `GET /api/gestion/notifications/journal?limit=20` (100 au plus) → `[{ id, kind, title, body, action: { screen, params }, status, sentAt }]`
  du gérant connecté ; `status` `sent` | `not_configured` | `failed` | `no_device`.

## 3. Règles

- Une notification par jour du magasin au plus et par gérant ; toutes les tentatives comptent sauf `no_device`.
- Destinataires : utilisateurs `ROLE_ADMIN` (ou super admin) du site avec un appareil `admin` actif et le type activé.
- Charge utile : `{ title, body, data: { kind, screen, params } }` (`params` en JSON) ; textes dans la langue de l'appareil, sinon du site.

## 4. Déclenchement

- **`low_stock`** : passage quotidien à **10 h du magasin** ; `app:gestion:notifications:envoyer` (tous les sites ou `--tenant=` ;
  `--force` ignore l'heure) lancée chaque heure par le timer (`*:05 UTC`), chaque site traité à son heure locale. Variantes de la vignette
  de stock bas avec un seuil plus strict : **moins de 7 jours** de couverture (`HomeTilesService::lowStock(7)`) ; rien si toutes les variantes
  concernées ont déjà été notifiées à ce gérant dans les 7 derniers jours. Écran `stock.low` `{ variantIds }`.
- **`duplicate_invoice`** : à la fin de la lecture d'une facture reconnue comme déjà saisie (`extraction.duplicate`, worker `gestion_ai`), à
  celui qui l'a envoyée, **entre 8 h et 20 h du magasin**. Écran `documents.review` `{ documentId }`. Un échec d'envoi ne touche jamais la lecture.
- `cash_critical`, `trip_budget` : avec B1.

Installation du timer sur l'hôte (propriétaire) :

```
sudo cp /home/arkanoa/app_v2/ops/systemd/gestion-notifications.{service,timer} /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now gestion-notifications.timer
```

## 5. Branchement Firebase (plus tard)

Prérequis du propriétaire : projet Firebase, clé de compte de service posée en secret sur le serveur (jamais dans le dépôt), clé APNs du compte
développeur Apple déposée dans la console Firebase. Côté backend : une classe `PushSenderInterface` en FCM HTTP v1 (réponse `UNREGISTERED`
→ `invalid_token`), substituée à `NotConfiguredPushSender`. Côté application : jeton Firebase de l'appareil envoyé à chaque connexion.
