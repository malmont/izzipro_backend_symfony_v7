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
