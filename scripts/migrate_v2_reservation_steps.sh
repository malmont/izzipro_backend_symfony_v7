#!/bin/bash
# Script: migrate_v2_reservation_steps.sh
# Ajoute les colonnes book_id, chapter_id, step_number, total_steps, forfait_name à la table reservation sur les bases V2

CONTAINER="symfony_db_v2"
PGUSER="postgres"

DATABASES=(
    "db_lintendantprive"
    "db_esgboost"
    "db_karaandb"
    "db_memoiresvivantes"
    "db_boussoleesg"
    "gmasuite"
    "app_v2_db"
)

SCHEMA_SQL="
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS book_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS chapter_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS step_number INT DEFAULT NULL;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS total_steps INT DEFAULT NULL;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS forfait_name VARCHAR(255) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_reservation_book_id ON reservation (book_id);
"

for DB in "${DATABASES[@]}"; do
    echo "=================================================="
    echo "⏳ Ajout des colonnes étapes/livres sur : $DB ..."
    docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$SCHEMA_SQL" > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Colonnes ajoutées avec succès sur : $DB"
    else
        echo "❌ Erreur sur : $DB"
    fi
done

echo "=================================================="
echo "🎉 Migration des étapes de réservation terminée sur toutes les bases V2 !"
