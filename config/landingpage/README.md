# Compositions « réglables » des landing pages

`landingpage-reglable.schema.json` est une **copie exacte** du JSON Schema (draft 2020-12) du frontend
`Izzipro_next/docs/landingpage-reglable.schema.json`, généré par `npm run schema:reglable`.
Ne jamais le modifier à la main côté backend : le frontend est la référence.

Utilisé par `App\Services\LandingPageSettingsService\ReglableCompositionValidator` (bibliothèque
`opis/json-schema` 2.x) à chaque PUT de `/api/landingpage-settings`, sur :

- `tabs[].sections[].reglableConfig` des sections `componentTypeKey = "typeReglable"` ;
- `navbar.reglableConfig`, `footer.reglableConfig` ;
- `reglablePresets[].config`.

Règles vérifiées en code (non exprimables en JSON Schema) : identifiants uniques, `parentId` = `null` ou id
d'un bloc `container` de la composition, pas de boucle, 8 niveaux de parents au plus, `x + w ≤ 100`,
`y + h ≤ 100`, parenthèses équilibrées des dégradés.

Les `pattern` du schéma sont des expressions JavaScript (norme JSON Schema) ; le validateur traduit en mémoire
`\uXXXX` en `\x{XXXX}` pour PHP (PCRE). Seule nuance restante : `\s` de PHP ne couvre pas les espaces Unicode
(espace insécable…) que JavaScript accepte dans un dégradé.

Erreur : HTTP 422 `{ "error", "message", "errors": [{ "path", "message" }] }`, chemin au format du frontend,
par exemple `tabs[0].sections[2].reglableConfig.blocks[3].fontColor`. Rien n'est enregistré.

## Mettre à jour le schéma quand le frontend le change

1. Côté frontend : `npm run schema:reglable` (le test du frontend vérifie que le fichier est à jour).
2. Copier `docs/landingpage-reglable.schema.json` du frontend ici, **à l'identique**, sous le même nom.
3. Si le frontend a ajouté des règles non exprimables en schéma (`validateCanvas` dans `canvasConfig.js`),
   les reporter dans `ReglableCompositionValidator`.
4. Vérifier que les compositions en production restent valides, puis lancer les tests :
   ```
   docker exec symfony_app_v2 php bin/console app:landingpage:check-reglable
   docker exec -w /var/www -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 \
       php vendor/bin/phpunit tests/Functional/LandingPage
   ```
   La commande liste, pour chaque site, les compositions que le nouveau schéma refuserait. À corriger
   (depuis l'éditeur ou par le frontend) **avant** de déployer le schéma, sinon ces sites ne pourront plus
   enregistrer leur page sans corriger d'abord les erreurs signalées.
5. Mettre à jour `tests/Fixtures/landingpage/production-compositions.json` si de nouvelles compositions
   représentatives existent (commande ci-dessus avec `--export`).
6. Committer le schéma avec les éventuelles adaptations. Aucune migration de base : le stockage JSON est inchangé.

## Assistant IA de l'éditeur (fichiers de référence)

- `landingpage-ia-catalogue.json` : **copie exacte** du catalogue du frontend (`Izzipro_next/docs/landingpage-ia-catalogue.json`,
  généré par `npm run ia:catalogue`). Pour chaque famille (`componentKey`) : types de blocs autorisés (`tools`), champs liables
  (`sectionFields`, `boundTools`, `list`) et modèles de référence (`presets`). Lu par `App\Services\LandingAiService\LandingAiCatalogue`.
- `ia-assistant-jeu-essai.md` : jeu d'essai (cas R1–R10, C1–C8, P1–P3 et vérifications V1–V6), rejoué par
  `php bin/console app:landingpage-ai:eval --tenant=<tenant de test> [--mode=edit|create|all] [--case=R1]` (sans HTTP ni quota, aucune écriture ;
  rapport JSON dans `var/landing-ai-eval/`).

### Mettre à jour le catalogue quand le frontend le change

1. Côté frontend : `npm run ia:catalogue`.
2. Copier `docs/landingpage-ia-catalogue.json` ici, **à l'identique**, sous le même nom (et `docs/ia-assistant-jeu-essai.md`
   s'il a changé).
3. Vérifier que tous les modèles du catalogue passent le contrat des compositions (un modèle refusé serait un mauvais exemple
   pour l'IA) et lancer les tests :
   ```
   docker exec -w /var/www -e SYMFONY_DEPRECATIONS_HELPER=disabled symfony_app_v2 \
       php vendor/bin/phpunit tests/Functional/LandingPage
   ```
4. Rejouer le jeu d'essai (`app:landingpage-ai:eval`) et comparer le rapport au précédent avant de déployer.

### Fonctionnement (étapes 1 et 2 : retouche et création)

- `POST /api/landingpage-ai/compose` (ROLE_ADMIN du tenant) :
  - **edit** `{ mode: "edit", componentKey, composition, prompt, locale?, media? }` : l'IA renvoie des opérations
    (`update`, `add`, `remove`, `section`) appliquées par le serveur sur la composition envoyée. `set` **fusionne**
    récursivement les objets imbriqués (mobile, repeat, bindings, translations…) et **remplace** les tableaux (links,
    images, iconCycle…) ; `unset` accepte des chemins pointés (`"mobile.w"`, `"bindings.offer"`).
  - **create** `{ mode: "create", componentKey, dataType?, prompt, locale?, media? }` : l'IA compose une section complète
    et choisit la donnée affichée (`dataType`) parmi les données du site pour cette famille (présentations, groupes,
    bannières statiques, vidéos, intégrations, liens multiples, recherches, offres d'emploi, catégories de marques ;
    `null` pour les familles sans donnée). Le `dataType` envoyé sert de donnée par défaut ; la réponse contient le
    `dataType` retenu.
  - La proposition passe par `ReglableCompositionValidator`, les types de la famille et la liste des médias autorisés
    (médias du site, médias fournis avec la demande, adresses http(s) écrites dans la demande et, en création, images
    des modèles de la famille), avec 3 essais au total (erreurs renvoyées au modèle). Les réglages du site ne sont
    jamais enregistrés par cet endpoint. `translations` n'est rempli que si la demande le demande explicitement.
- `GET /api/landingpage-ai/usage` : crédits du mois et 50 dernières demandes.
- Quota : 100 crédits par mois (fuseau America/Toronto), modifiable par tenant en SQL :
  `INSERT INTO ai_credit_setting (monthly_credits) VALUES (200)` (ou `UPDATE` si la ligne existe). Coût : retouche 1,
  création 3, page ou images 10. Limite : 5 demandes par minute et par tenant.
- Variables : `ANTHROPIC_API_KEY_LANDING` (clé dédiée), `LANDING_AI_MODEL_EDIT` (défaut `claude-sonnet-5`),
  `LANDING_AI_MODEL_PAGE` (défaut `claude-opus-5-5`, étape 3).
- Tables par tenant : `ai_usage` (historique, supprimé après 90 jours, jamais de composition ni de réponse du modèle) et
  `ai_credit_setting` — `scripts/migrate_all_v2_landing_ai.sh` / migration `Version20260928200000`.
