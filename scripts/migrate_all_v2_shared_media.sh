#!/bin/bash
# Script: migrate_all_v2_shared_media.sh
# Crée la table shared_media pour le partage de documents et médias sécurisés sur toutes les bases V2

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
    "db_mv_test_booktypes"
)

SQL="
CREATE TABLE IF NOT EXISTS shared_media (
    id SERIAL PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    media_type VARCHAR(20) NOT NULL DEFAULT 'document',
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'public',
    access_key VARCHAR(64) DEFAULT NULL,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    download_count INT NOT NULL DEFAULT 0,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_shared_media_access_key ON shared_media (access_key);
CREATE INDEX IF NOT EXISTS idx_shared_media_visibility ON shared_media (visibility);
CREATE INDEX IF NOT EXISTS idx_shared_media_type ON shared_media (media_type);
CREATE INDEX IF NOT EXISTS idx_shared_media_created_at ON shared_media (created_at);
"

for DB in "${DATABASES[@]}"; do
    echo "=================================================="
    echo "⏳ Application de la table shared_media sur : $DB ..."
    docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$SQL" > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Table shared_media prête sur : $DB"
    else
        echo "❌ Erreur sur : $DB"
    fi
done

echo "=================================================="
echo "🎉 Toutes les bases de données sont migrées pour la table shared_media !"
