#!/bin/bash
# Script: migrate_all_v2_book_print_order.sh
# Applique la table mv_book_print_order sur toutes les bases de données V2 (hors master)

CONTAINER="symfony_db_v2"
PGUSER="postgres"

# Liste de toutes les bases V2 (hors master, postgres, template0, template1)
DATABASES=(
    "db_memoiresvivantes"
    "db_esgboost"
    "db_karaandb"
    "db_lintendantprive"
    "db_boussoleesg"
    "gmasuite"
    "app_v2_db"
)

SCHEMA_SQL="
CREATE TABLE IF NOT EXISTS mv_book_print_order (
    id UUID PRIMARY KEY,
    book_id UUID NOT NULL,
    user_id INT NOT NULL,
    lulu_print_job_id VARCHAR(100) DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'draft',
    recipient_name VARCHAR(255) NOT NULL,
    street1 VARCHAR(255) NOT NULL,
    street2 VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) DEFAULT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country_code VARCHAR(2) NOT NULL DEFAULT 'FR',
    phone_number VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    shipping_level VARCHAR(50) NOT NULL DEFAULT 'MAIL',
    quantity INT NOT NULL DEFAULT 1,
    print_cost NUMERIC(10, 2) DEFAULT NULL,
    shipping_cost NUMERIC(10, 2) DEFAULT NULL,
    tax_cost NUMERIC(10, 2) DEFAULT NULL,
    total_cost NUMERIC(10, 2) DEFAULT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    carrier_name VARCHAR(100) DEFAULT NULL,
    tracking_number VARCHAR(255) DEFAULT NULL,
    tracking_url VARCHAR(500) DEFAULT NULL,
    interior_pdf_url VARCHAR(500) DEFAULT NULL,
    cover_pdf_url VARCHAR(500) DEFAULT NULL,
    lulu_raw_response JSONB DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    shipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    CONSTRAINT fk_mv_book_print_order_book FOREIGN KEY (book_id) REFERENCES mv_book (id) ON DELETE CASCADE,
    CONSTRAINT fk_mv_book_print_order_user FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_book_id ON mv_book_print_order (book_id);
CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_user_id ON mv_book_print_order (user_id);
CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_lulu_job ON mv_book_print_order (lulu_print_job_id);
CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_status ON mv_book_print_order (status);
"

for DB in "${DATABASES[@]}"; do
    echo "=================================================="
    echo "⏳ Application de la table mv_book_print_order sur : $DB ..."
    docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$SCHEMA_SQL" > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Table mv_book_print_order prête sur : $DB"
    else
        echo "❌ Erreur ou dépendance manquante sur : $DB (tentative sans contraintes strictes si mv_book absent)"
        # Si mv_book n'existe pas sur ce tenant, on crée la table sans FK pour préserver l'intégrité globale
        FALLBACK_SQL="
        CREATE TABLE IF NOT EXISTS mv_book_print_order (
            id UUID PRIMARY KEY,
            book_id UUID NOT NULL,
            user_id INT NOT NULL,
            lulu_print_job_id VARCHAR(100) DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'draft',
            recipient_name VARCHAR(255) NOT NULL,
            street1 VARCHAR(255) NOT NULL,
            street2 VARCHAR(255) DEFAULT NULL,
            city VARCHAR(100) NOT NULL,
            state VARCHAR(100) DEFAULT NULL,
            postal_code VARCHAR(20) NOT NULL,
            country_code VARCHAR(2) NOT NULL DEFAULT 'FR',
            phone_number VARCHAR(50) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            shipping_level VARCHAR(50) NOT NULL DEFAULT 'MAIL',
            quantity INT NOT NULL DEFAULT 1,
            print_cost NUMERIC(10, 2) DEFAULT NULL,
            shipping_cost NUMERIC(10, 2) DEFAULT NULL,
            tax_cost NUMERIC(10, 2) DEFAULT NULL,
            total_cost NUMERIC(10, 2) DEFAULT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
            carrier_name VARCHAR(100) DEFAULT NULL,
            tracking_number VARCHAR(255) DEFAULT NULL,
            tracking_url VARCHAR(500) DEFAULT NULL,
            interior_pdf_url VARCHAR(500) DEFAULT NULL,
            cover_pdf_url VARCHAR(500) DEFAULT NULL,
            lulu_raw_response JSONB DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
            shipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_book_id ON mv_book_print_order (book_id);
        CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_user_id ON mv_book_print_order (user_id);
        CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_lulu_job ON mv_book_print_order (lulu_print_job_id);
        CREATE INDEX IF NOT EXISTS idx_mv_book_print_order_status ON mv_book_print_order (status);
        "
        docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$FALLBACK_SQL" > /dev/null 2>&1
        if [ $? -eq 0 ]; then
            echo "✅ Table mv_book_print_order créée avec succès sur : $DB"
        else
            echo "❌ Échec sur : $DB"
        fi
    fi
done

echo "=================================================="
echo "🎉 Migration mv_book_print_order terminée sur toutes les bases V2 !"
