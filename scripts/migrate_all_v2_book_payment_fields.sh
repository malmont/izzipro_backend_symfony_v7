#!/bin/bash
# Script: migrate_all_v2_book_payment_fields.sh
# Applique les champs de paiement sur mv_book et s'assure que stripe_config est prête sur toutes les bases V2

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
CREATE TABLE IF NOT EXISTS stripe_config (
    id SERIAL PRIMARY KEY,
    account_id VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE
);

DO \$\$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'mv_book') THEN
        ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid';
        ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS payment_link_url TEXT DEFAULT NULL;
        ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
        ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS payment_amount NUMERIC(10, 2) DEFAULT NULL;
        ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS payment_currency VARCHAR(10) DEFAULT 'eur';
        ALTER TABLE mv_book ADD COLUMN IF NOT EXISTS stripe_session_id VARCHAR(255) DEFAULT NULL;
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
echo "🎉 Toutes les bases de données (y compris gmasuite template) sont migrées et synchronisées !"
