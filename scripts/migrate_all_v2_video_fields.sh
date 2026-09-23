#!/bin/bash
# Script: migrate_all_v2_video_fields.sh
# Ajoute les champs description, texte_bouton et lien_bouton sur les tables video et video_translation sur toutes les bases V2

CONTAINER="symfony_db_v2"
PGUSER="postgres"

DATABASES=(
    "db_memoiresvivantes"
    "db_esgboost"
    "db_karaandb"
    "db_lintendantprive"
    "db_boussoleesg"
    "gmasuite"
    "app_v2_db"
)

SQL="
DO \$\$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'video') THEN
        ALTER TABLE video ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL;
        ALTER TABLE video ADD COLUMN IF NOT EXISTS texte_bouton VARCHAR(255) DEFAULT NULL;
        ALTER TABLE video ADD COLUMN IF NOT EXISTS lien_bouton VARCHAR(255) DEFAULT NULL;
    END IF;

    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'video_translation') THEN
        ALTER TABLE video_translation ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL;
        ALTER TABLE video_translation ADD COLUMN IF NOT EXISTS texte_bouton VARCHAR(255) DEFAULT NULL;
        ALTER TABLE video_translation ADD COLUMN IF NOT EXISTS lien_bouton VARCHAR(255) DEFAULT NULL;
    END IF;
END \$\$;
"

for DB in "${DATABASES[@]}"; do
    echo "=================================================="
    echo "⏳ Application de la migration sur : $DB ..."
    docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$SQL" > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Migration réussie sur : $DB"
    else
        echo "❌ Erreur sur : $DB"
    fi
done

echo "=================================================="
echo "🎉 Toutes les bases de données sont migrées et synchronisées pour l'entité Video !"
