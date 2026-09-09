#!/bin/bash
# Script: migrate_all_v2_memoires.sh
# Applique la table mv_question et les 68 questions sur toutes les bases de données V2 (sauf master)

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
CREATE TABLE IF NOT EXISTS mv_question (
    id SERIAL PRIMARY KEY,
    book_type VARCHAR(50) NOT NULL DEFAULT 'individuel',
    theme VARCHAR(100) NOT NULL,
    question_text TEXT NOT NULL,
    tip TEXT DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_mv_question_theme_order ON mv_question (theme, display_order);
CREATE INDEX IF NOT EXISTS idx_mv_question_book_type ON mv_question (book_type);
"

for DB in "${DATABASES[@]}"; do
    echo "=================================================="
    echo "⏳ Application de la table mv_question sur : $DB ..."
    docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$SCHEMA_SQL" > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Table mv_question prête sur : $DB"
    else
        echo "❌ Erreur de création sur : $DB"
    fi

    # Copie des questions depuis db_memoiresvivantes si la table est vide
    COUNT=$(docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -t -c "SELECT COUNT(*) FROM mv_question;" 2>/dev/null | tr -d '[:space:]')
    if [ "$COUNT" = "0" ]; then
        echo "📥 Peuple les 68 questions sur $DB ..."
        docker exec "$CONTAINER" psql -U "$PGUSER" -d db_memoiresvivantes -c "COPY (SELECT book_type, theme, question_text, tip, display_order, is_active, created_at FROM mv_question ORDER BY id ASC) TO STDOUT;" \
        | docker exec -i "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "COPY mv_question (book_type, theme, question_text, tip, display_order, is_active, created_at) FROM STDIN;" > /dev/null 2>&1
        NEW_COUNT=$(docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -t -c "SELECT COUNT(*) FROM mv_question;" 2>/dev/null | tr -d '[:space:]')
        echo "✅ $NEW_COUNT questions importées sur $DB"
    else
        echo "ℹ️ $DB contient déjà $COUNT questions."
    fi
done

echo "=================================================="
echo "🎉 Migration Mémoires Vivantes terminée sur toutes les bases V2 (hors master) !"
