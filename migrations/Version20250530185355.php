<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250530185355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE "Cart_id_seq" INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE address_entreprise_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE admin_settings_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE adress_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE caisse_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE carrier_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE cart_details_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE cash_details_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE categories_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE collection_picture_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE collection_statistiques_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE collections_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE color_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE commande_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE commande_statistiques_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE contact_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE easy_post_configuration_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE email_configuration_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE entreprise_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE explore_card_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE feature_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE fournisseur_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE frais_de_port_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE google_places_config_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE home_slider_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE inventory_movements_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE movement_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE note_de_frais_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE "order_id_seq" INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE order_items_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE order_source_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE order_tax_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE order_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE otp_code_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE packaging_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE parcel_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE payment_method_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE payment_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE payments_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_shipping_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE product_variant_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE refresh_tokens_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE related_product_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE reset_password_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE reviews_product_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE shipping_class_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE shipping_label_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE shipping_order_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE size_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE square_config_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE status_commande_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE status_payment_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE style_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE tax_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE transaction_caisse_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE transaction_type_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE transporteur_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE type_cash_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE type_fournisseur_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE type_note_de_frais_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE "user_id_seq" INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "Cart" (id INT NOT NULL, user_cart_id INT NOT NULL, reference VARCHAR(255) NOT NULL, fullname VARCHAR(255) NOT NULL, carriername VARCHAR(255) NOT NULL, carrierprice DOUBLE PRECISION NOT NULL, deleveryaddress TEXT NOT NULL, ispaid BOOLEAN NOT NULL, moreinformations TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, quantity INT NOT NULL, sub_total_ht DOUBLE PRECISION NOT NULL, taxe DOUBLE PRECISION NOT NULL, sub_total_ttc DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_AB91278942D8D3B5 ON "Cart" (user_cart_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE address_entreprise (id INT NOT NULL, entreprise_id INT NOT NULL, street1 VARCHAR(255) NOT NULL, street2 VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, state VARCHAR(255) NOT NULL, zip VARCHAR(255) NOT NULL, country VARCHAR(255) NOT NULL, phone VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_758472FEA4AEAFEA ON address_entreprise (entreprise_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_settings (id INT NOT NULL, navbar_component VARCHAR(255) NOT NULL, style_choice VARCHAR(255) NOT NULL, theme_choice VARCHAR(255) NOT NULL, section1_component VARCHAR(255) DEFAULT NULL, type_component_section1 VARCHAR(255) DEFAULT NULL, select_type_product_fetch VARCHAR(255) DEFAULT NULL, section2_component VARCHAR(255) DEFAULT NULL, type_component_section2 VARCHAR(255) DEFAULT NULL, section3_component VARCHAR(255) DEFAULT NULL, type_component_section3 VARCHAR(255) DEFAULT NULL, section4_component VARCHAR(255) DEFAULT NULL, type_component_section4 VARCHAR(255) DEFAULT NULL, select_type_product_fetch_section2 VARCHAR(255) DEFAULT NULL, select_type_product_fetch_section3 VARCHAR(255) DEFAULT NULL, select_type_product_fetch_section4 VARCHAR(255) DEFAULT NULL, section5_component VARCHAR(255) DEFAULT NULL, type_component_section5 VARCHAR(255) DEFAULT NULL, section6_component VARCHAR(255) DEFAULT NULL, type_component_section6 VARCHAR(255) DEFAULT NULL, section7_component VARCHAR(255) DEFAULT NULL, type_component_section7 VARCHAR(255) DEFAULT NULL, select_type_product_fetch_section5 VARCHAR(255) DEFAULT NULL, select_type_product_fetch_section6 VARCHAR(255) DEFAULT NULL, select_type_product_fetch_section7 VARCHAR(255) DEFAULT NULL, type_category_card VARCHAR(255) DEFAULT NULL, details_product_card_component VARCHAR(255) DEFAULT NULL, cart_item_card_component VARCHAR(255) DEFAULT NULL, total_card_component VARCHAR(255) DEFAULT NULL, checkout_card_component VARCHAR(255) DEFAULT NULL, account_dashboard_component VARCHAR(255) DEFAULT NULL, order_list_card_component VARCHAR(255) DEFAULT NULL, adress_list_card_component VARCHAR(255) DEFAULT NULL, carrier_list_card_component VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE adress (id INT NOT NULL, user_adress_id INT DEFAULT NULL, firstname VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, fullname VARCHAR(255) NOT NULL, company VARCHAR(255) DEFAULT NULL, address TEXT NOT NULL, complement TEXT DEFAULT NULL, phone VARCHAR(20) NOT NULL, city VARCHAR(255) NOT NULL, codepostal VARCHAR(10) NOT NULL, country VARCHAR(255) NOT NULL, province VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_5CECC7BE84667448 ON adress (user_adress_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE caisse (id INT NOT NULL, amount_total DOUBLE PRECISION NOT NULL, created_at DATE DEFAULT NULL, is_open BOOLEAN DEFAULT false NOT NULL, fon_de_caisse DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE carrier (id INT NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, price DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, update_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, carrier_account_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE cart_details (id INT NOT NULL, carts_id INT NOT NULL, productname VARCHAR(255) NOT NULL, producprice DOUBLE PRECISION NOT NULL, quantity INT NOT NULL, sub_total_ht DOUBLE PRECISION NOT NULL, taxe DOUBLE PRECISION NOT NULL, sub_total_ttc DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_89FCC38DBCB5C6F5 ON cart_details (carts_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE cash_details (id INT NOT NULL, type_cash_id INT NOT NULL, transaction_caisse_id INT NOT NULL, nombre_items INT NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_2CCA19D6155B63DD ON cash_details (type_cash_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_2CCA19D6649365FC ON cash_details (transaction_caisse_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE categories (id INT NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, image VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE collection_picture (id INT NOT NULL, image_url VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE collection_statistiques (id INT NOT NULL, collection_id INT DEFAULT NULL, general_budget DOUBLE PRECISION NOT NULL, used_budget DOUBLE PRECISION NOT NULL, remaining_budget DOUBLE PRECISION NOT NULL, total_item_cost DOUBLE PRECISION NOT NULL, total_shipping_cost DOUBLE PRECISION NOT NULL, total_expense_cost DOUBLE PRECISION NOT NULL, order_count INT NOT NULL, item_count INT NOT NULL, model_count INT NOT NULL, stock_value DOUBLE PRECISION NOT NULL, margin DOUBLE PRECISION NOT NULL, taux_marge DOUBLE PRECISION NOT NULL, taux_marque DOUBLE PRECISION NOT NULL, average_multiplier DOUBLE PRECISION NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, duration_days INT NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_2939A94C514956FD ON collection_statistiques (collection_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE collections (id INT NOT NULL, user_collections_id INT DEFAULT NULL, photo_collections_id INT DEFAULT NULL, budget_collection DOUBLE PRECISION NOT NULL, start_date_collection TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_date_collection TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, del BOOLEAN NOT NULL, nom_collection VARCHAR(255) NOT NULL, is_closed BOOLEAN DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_D325D3EE4FA9A58B ON collections (user_collections_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_D325D3EE3C1F0C8F ON collections (photo_collections_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE color (id INT NOT NULL, name VARCHAR(255) NOT NULL, code_hexa VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commande (id INT NOT NULL, collections_id INT DEFAULT NULL, fournisseur_id INT DEFAULT NULL, commandepictures_id INT DEFAULT NULL, budget DOUBLE PRECISION NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) DEFAULT NULL, is_closed BOOLEAN DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_6EEAA67D242C7AD2 ON commande (collections_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_6EEAA67D670C757F ON commande (fournisseur_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_6EEAA67D47C88A1B ON commande (commandepictures_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commande_statistiques (id INT NOT NULL, commande_id INT DEFAULT NULL, transporteur_id INT DEFAULT NULL, average_multiplier DOUBLE PRECISION DEFAULT NULL, general_budget DOUBLE PRECISION DEFAULT NULL, used_budget DOUBLE PRECISION DEFAULT NULL, remaining_budget DOUBLE PRECISION DEFAULT NULL, total_item_cost DOUBLE PRECISION DEFAULT NULL, total_frais_de_port DOUBLE PRECISION DEFAULT NULL, item_count INT DEFAULT NULL, model_count INT DEFAULT NULL, stock_value DOUBLE PRECISION DEFAULT NULL, marge DOUBLE PRECISION DEFAULT NULL, taux_marge DOUBLE PRECISION DEFAULT NULL, taux_marque DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_89E6102D82EA2E54 ON commande_statistiques (commande_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_89E6102D97C86FA4 ON commande_statistiques (transporteur_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE contact (id INT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_read BOOLEAN DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE easy_post_configuration (id INT NOT NULL, easypost_api_key_sandbox VARCHAR(255) DEFAULT NULL, easypost_api_key_prod VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE email_configuration (id INT NOT NULL, from_email VARCHAR(255) NOT NULL, from_name VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, signature TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE entreprise (id INT NOT NULL, name VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, tel VARCHAR(255) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, ein VARCHAR(255) NOT NULL, tva_intracommunautaire VARCHAR(255) NOT NULL, condition_of_use TEXT DEFAULT NULL, legal_notice TEXT DEFAULT NULL, privacy_policy TEXT DEFAULT NULL, adress VARCHAR(255) DEFAULT NULL, apropos TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE explore_card (id INT NOT NULL, is_different BOOLEAN NOT NULL, standard_title TEXT DEFAULT NULL, different_title TEXT DEFAULT NULL, description TEXT DEFAULT NULL, link VARCHAR(255) DEFAULT NULL, image_path VARCHAR(255) DEFAULT NULL, video_path VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE feature (id INT NOT NULL, title VARCHAR(100) NOT NULL, iconpath VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE fournisseur (id INT NOT NULL, type_fournisseur_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, ville VARCHAR(255) DEFAULT NULL, pays VARCHAR(255) DEFAULT NULL, tel VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_369ECA3231CF5CEB ON fournisseur (type_fournisseur_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE frais_de_port (id INT NOT NULL, commande_id INT DEFAULT NULL, transporteur_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, facture VARCHAR(255) DEFAULT NULL, tracknumber VARCHAR(255) DEFAULT NULL, price DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_6FC5088782EA2E54 ON frais_de_port (commande_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_6FC5088797C86FA4 ON frais_de_port (transporteur_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE google_places_config (id INT NOT NULL, google_api_key_test VARCHAR(255) DEFAULT NULL, google_api_key_prod_encrypted VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE home_slider (id INT NOT NULL, title VARCHAR(255) NOT NULL, description VARCHAR(255) NOT NULL, button_message VARCHAR(255) NOT NULL, button_url VARCHAR(255) NOT NULL, image VARCHAR(255) NOT NULL, is_diplayed BOOLEAN DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_movements (id INT NOT NULL, product_variant_id INT DEFAULT NULL, movement_type_id INT DEFAULT NULL, quantity INT NOT NULL, movement_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stock_before_movement INT DEFAULT NULL, stock_after_movement DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_BF9F9C49A80EF684 ON inventory_movements (product_variant_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_BF9F9C49EA4ED04A ON inventory_movements (movement_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE movement_type (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE note_de_frais (id INT NOT NULL, collection_id INT DEFAULT NULL, type_note_de_frais_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, montant DOUBLE PRECISION NOT NULL, date DATE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E6ECCF53514956FD ON note_de_frais (collection_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E6ECCF53BAFAB15C ON note_de_frais (type_note_de_frais_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "order" (id INT NOT NULL, user_id_id INT DEFAULT NULL, shipping_adress_id INT DEFAULT NULL, order_source_id INT DEFAULT NULL, carrier_id INT DEFAULT NULL, status_id INT NOT NULL, order_type_id INT DEFAULT NULL, reference VARCHAR(255) NOT NULL, order_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, total_amount DOUBLE PRECISION NOT NULL, sub_total DOUBLE PRECISION DEFAULT NULL, total_tax DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_F52993989D86650F ON "order" (user_id_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_F5299398C273A89B ON "order" (shipping_adress_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_F529939829BB6799 ON "order" (order_source_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_F529939821DFC797 ON "order" (carrier_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_F52993986BF700BD ON "order" (status_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_F5299398333625D8 ON "order" (order_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_items (id INT NOT NULL, order_associated_id INT DEFAULT NULL, product_variant_id INT DEFAULT NULL, quantity INT NOT NULL, unit_price DOUBLE PRECISION NOT NULL, total_price DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_62809DB0E24BFBC ON order_items (order_associated_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_62809DB0A80EF684 ON order_items (product_variant_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_source (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_tax (id INT NOT NULL, order_tax_id INT DEFAULT NULL, tax_id INT DEFAULT NULL, amount DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_CDDAF5167127340D ON order_tax (order_tax_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_CDDAF516B2A824D8 ON order_tax (tax_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE order_type (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE otp_code (id INT NOT NULL, user_otp_id INT DEFAULT NULL, code VARCHAR(255) DEFAULT NULL, expiration TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_93FE2319D35FFDA0 ON otp_code (user_otp_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE packaging_type (id INT NOT NULL, name VARCHAR(100) NOT NULL, inner_length DOUBLE PRECISION NOT NULL, inner_width DOUBLE PRECISION NOT NULL, inner_height DOUBLE PRECISION NOT NULL, max_weight DOUBLE PRECISION NOT NULL, volumetric_divisor INT NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE parcel (id INT NOT NULL, shipping_order_id INT DEFAULT NULL, index INT DEFAULT NULL, weight DOUBLE PRECISION DEFAULT NULL, length DOUBLE PRECISION DEFAULT NULL, width DOUBLE PRECISION DEFAULT NULL, height DOUBLE PRECISION DEFAULT NULL, price DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C99B5D6011702397 ON parcel (shipping_order_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_method (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_type (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE payments (id INT NOT NULL, order_payment_id INT DEFAULT NULL, payment_method_id INT DEFAULT NULL, statut_payment_id INT DEFAULT NULL, payment_type_id INT DEFAULT NULL, amount DOUBLE PRECISION NOT NULL, payment_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, square_payment_id VARCHAR(255) DEFAULT NULL, square_order_id VARCHAR(255) DEFAULT NULL, square_receipt_url VARCHAR(255) DEFAULT NULL, square_status VARCHAR(50) DEFAULT NULL, square_card_brand VARCHAR(50) DEFAULT NULL, square_last4 VARCHAR(4) DEFAULT NULL, square_risk_level VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_65D29B32B7195EEE ON payments (order_payment_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_65D29B325AA1164F ON payments (payment_method_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_65D29B327108AEB2 ON payments (statut_payment_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_65D29B32DC058279 ON payments (payment_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product (id INT NOT NULL, style_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, moreinformations TEXT NOT NULL, price DOUBLE PRECISION NOT NULL, isbestseller BOOLEAN NOT NULL, isnewarrival BOOLEAN DEFAULT NULL, isfeatured BOOLEAN DEFAULT NULL, isspecialoffer BOOLEAN DEFAULT NULL, image VARCHAR(255) NOT NULL, quantity INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tags TEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, purchase_price DOUBLE PRECISION DEFAULT NULL, coefficient_multiplier DOUBLE PRECISION DEFAULT NULL, barcode VARCHAR(255) DEFAULT NULL, freeze_quantity INT DEFAULT NULL, is_accessory BOOLEAN DEFAULT NULL, is_web BOOLEAN DEFAULT NULL, is_pos BOOLEAN DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_D34A04ADBACD6074 ON product (style_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_D34A04AD82EA2E54 ON product (commande_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN product.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_categories (product_id INT NOT NULL, categories_id INT NOT NULL, PRIMARY KEY(product_id, categories_id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_A99419434584665A ON product_categories (product_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_A9941943A21214B7 ON product_categories (categories_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_shipping (id INT NOT NULL, product_id INT DEFAULT NULL, shipping_class_entity_id INT DEFAULT NULL, weight DOUBLE PRECISION NOT NULL, length DOUBLE PRECISION NOT NULL, width DOUBLE PRECISION DEFAULT NULL, height DOUBLE PRECISION DEFAULT NULL, shipping_class VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_E6AC7DB34584665A ON product_shipping (product_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E6AC7DB3335924EB ON product_shipping (shipping_class_entity_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE product_variant (id INT NOT NULL, color_id INT DEFAULT NULL, size_id INT DEFAULT NULL, product_id INT DEFAULT NULL, stock_quantity INT NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_209AA41D7ADA1FB5 ON product_variant (color_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_209AA41D498DA827 ON product_variant (size_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_209AA41D4584665A ON product_variant (product_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE refresh_tokens (id INT NOT NULL, refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_9BACE7E1C74F2195 ON refresh_tokens (refresh_token)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE related_product (id INT NOT NULL, product_id INT NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_EC53CE084584665A ON related_product (product_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE reset_password_request (id INT NOT NULL, user_id INT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_7CE748AA76ED395 ON reset_password_request (user_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN reset_password_request.requested_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN reset_password_request.expires_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE reviews_product (id INT NOT NULL, user_review_id INT NOT NULL, product_reviews_id INT NOT NULL, note INT NOT NULL, comment TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E0851D6C3ECE1B7F ON reviews_product (user_review_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E0851D6C13F58654 ON reviews_product (product_reviews_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE shipping_class (id INT NOT NULL, name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE shipping_label (id INT NOT NULL, parcel_id INT DEFAULT NULL, label_url VARCHAR(255) DEFAULT NULL, tracking_code VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_E0388D52465E670C ON shipping_label (parcel_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE shipping_order (id INT NOT NULL, odershipping_id INT DEFAULT NULL, carrier_account_id VARCHAR(50) DEFAULT NULL, service VARCHAR(50) DEFAULT NULL, total_price DOUBLE PRECISION DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_1BB64E22ACB9B199 ON shipping_order (odershipping_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE size (id INT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE square_config (id INT NOT NULL, access_token VARCHAR(255) NOT NULL, application_id VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, location_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE status_commande (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE status_payment (id INT NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE style (id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE tax (id INT NOT NULL, name VARCHAR(255) NOT NULL, rate DOUBLE PRECISION NOT NULL, type VARCHAR(255) NOT NULL, province VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_caisse (id INT NOT NULL, caisse_id INT NOT NULL, user_caisse_id INT NOT NULL, order_caisse_id INT DEFAULT NULL, payment_id INT DEFAULT NULL, transaction_type_id INT NOT NULL, transaction_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amount DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C4DE218827B4FEBF ON transaction_caisse (caisse_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C4DE21882754735B ON transaction_caisse (user_caisse_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C4DE21889B338547 ON transaction_caisse (order_caisse_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C4DE21884C3A3BB ON transaction_caisse (payment_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_C4DE2188B3E6B071 ON transaction_caisse (transaction_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE transaction_type (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE transporteur (id INT NOT NULL, name VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, contact VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE type_cash (id INT NOT NULL, name VARCHAR(255) DEFAULT NULL, value DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE type_fournisseur (id INT NOT NULL, name VARCHAR(255) NOT NULL, photo VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE type_note_de_frais (id INT NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, firstname VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, is_verified BOOLEAN NOT NULL, verification_token VARCHAR(255) DEFAULT NULL, reset_token VARCHAR(255) DEFAULT NULL, reset_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, otp_enabled BOOLEAN DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messenger_messages.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messenger_messages.available_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messenger_messages.delivered_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
                BEGIN
                    PERFORM pg_notify('messenger_messages', NEW.queue_name::text);
                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql;
        SQL);
        $this->addSql(<<<'SQL'
            DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages;
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "Cart" ADD CONSTRAINT FK_AB91278942D8D3B5 FOREIGN KEY (user_cart_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE address_entreprise ADD CONSTRAINT FK_758472FEA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE adress ADD CONSTRAINT FK_5CECC7BE84667448 FOREIGN KEY (user_adress_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cart_details ADD CONSTRAINT FK_89FCC38DBCB5C6F5 FOREIGN KEY (carts_id) REFERENCES "Cart" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_details ADD CONSTRAINT FK_2CCA19D6155B63DD FOREIGN KEY (type_cash_id) REFERENCES type_cash (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_details ADD CONSTRAINT FK_2CCA19D6649365FC FOREIGN KEY (transaction_caisse_id) REFERENCES transaction_caisse (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE collection_statistiques ADD CONSTRAINT FK_2939A94C514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE4FA9A58B FOREIGN KEY (user_collections_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE3C1F0C8F FOREIGN KEY (photo_collections_id) REFERENCES collection_picture (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D242C7AD2 FOREIGN KEY (collections_id) REFERENCES collections (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67D47C88A1B FOREIGN KEY (commandepictures_id) REFERENCES collection_picture (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande_statistiques ADD CONSTRAINT FK_89E6102D82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande_statistiques ADD CONSTRAINT FK_89E6102D97C86FA4 FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA3231CF5CEB FOREIGN KEY (type_fournisseur_id) REFERENCES type_fournisseur (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE frais_de_port ADD CONSTRAINT FK_6FC5088782EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE frais_de_port ADD CONSTRAINT FK_6FC5088797C86FA4 FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movements ADD CONSTRAINT FK_BF9F9C49A80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movements ADD CONSTRAINT FK_BF9F9C49EA4ED04A FOREIGN KEY (movement_type_id) REFERENCES movement_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE note_de_frais ADD CONSTRAINT FK_E6ECCF53514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE note_de_frais ADD CONSTRAINT FK_E6ECCF53BAFAB15C FOREIGN KEY (type_note_de_frais_id) REFERENCES type_note_de_frais (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" ADD CONSTRAINT FK_F52993989D86650F FOREIGN KEY (user_id_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" ADD CONSTRAINT FK_F5299398C273A89B FOREIGN KEY (shipping_adress_id) REFERENCES adress (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" ADD CONSTRAINT FK_F529939829BB6799 FOREIGN KEY (order_source_id) REFERENCES order_source (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" ADD CONSTRAINT FK_F529939821DFC797 FOREIGN KEY (carrier_id) REFERENCES carrier (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" ADD CONSTRAINT FK_F52993986BF700BD FOREIGN KEY (status_id) REFERENCES status_commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" ADD CONSTRAINT FK_F5299398333625D8 FOREIGN KEY (order_type_id) REFERENCES order_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_items ADD CONSTRAINT FK_62809DB0E24BFBC FOREIGN KEY (order_associated_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_items ADD CONSTRAINT FK_62809DB0A80EF684 FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_tax ADD CONSTRAINT FK_CDDAF5167127340D FOREIGN KEY (order_tax_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_tax ADD CONSTRAINT FK_CDDAF516B2A824D8 FOREIGN KEY (tax_id) REFERENCES tax (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE otp_code ADD CONSTRAINT FK_93FE2319D35FFDA0 FOREIGN KEY (user_otp_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE parcel ADD CONSTRAINT FK_C99B5D6011702397 FOREIGN KEY (shipping_order_id) REFERENCES shipping_order (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT FK_65D29B32B7195EEE FOREIGN KEY (order_payment_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT FK_65D29B325AA1164F FOREIGN KEY (payment_method_id) REFERENCES payment_method (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT FK_65D29B327108AEB2 FOREIGN KEY (statut_payment_id) REFERENCES status_payment (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT FK_65D29B32DC058279 FOREIGN KEY (payment_type_id) REFERENCES payment_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ADD CONSTRAINT FK_D34A04ADBACD6074 FOREIGN KEY (style_id) REFERENCES style (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product ADD CONSTRAINT FK_D34A04AD82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_categories ADD CONSTRAINT FK_A99419434584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_categories ADD CONSTRAINT FK_A9941943A21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_shipping ADD CONSTRAINT FK_E6AC7DB34584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_shipping ADD CONSTRAINT FK_E6AC7DB3335924EB FOREIGN KEY (shipping_class_entity_id) REFERENCES shipping_class (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D7ADA1FB5 FOREIGN KEY (color_id) REFERENCES color (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D498DA827 FOREIGN KEY (size_id) REFERENCES size (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE related_product ADD CONSTRAINT FK_EC53CE084584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reviews_product ADD CONSTRAINT FK_E0851D6C3ECE1B7F FOREIGN KEY (user_review_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reviews_product ADD CONSTRAINT FK_E0851D6C13F58654 FOREIGN KEY (product_reviews_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE shipping_label ADD CONSTRAINT FK_E0388D52465E670C FOREIGN KEY (parcel_id) REFERENCES parcel (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE shipping_order ADD CONSTRAINT FK_1BB64E22ACB9B199 FOREIGN KEY (odershipping_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE218827B4FEBF FOREIGN KEY (caisse_id) REFERENCES caisse (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE21882754735B FOREIGN KEY (user_caisse_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE21889B338547 FOREIGN KEY (order_caisse_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE21884C3A3BB FOREIGN KEY (payment_id) REFERENCES payments (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse ADD CONSTRAINT FK_C4DE2188B3E6B071 FOREIGN KEY (transaction_type_id) REFERENCES transaction_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE "Cart_id_seq" CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE address_entreprise_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE admin_settings_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE adress_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE caisse_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE carrier_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE cart_details_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE cash_details_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE categories_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE collection_picture_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE collection_statistiques_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE collections_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE color_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE commande_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE commande_statistiques_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE contact_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE easy_post_configuration_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE email_configuration_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE entreprise_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE explore_card_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE feature_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE fournisseur_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE frais_de_port_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE google_places_config_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE home_slider_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE inventory_movements_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE movement_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE note_de_frais_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE "order_id_seq" CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE order_items_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE order_source_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE order_tax_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE order_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE otp_code_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE packaging_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE parcel_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE payment_method_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE payment_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE payments_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_shipping_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE product_variant_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE refresh_tokens_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE related_product_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE reset_password_request_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE reviews_product_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE shipping_class_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE shipping_label_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE shipping_order_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE size_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE square_config_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE status_commande_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE status_payment_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE style_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE tax_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE transaction_caisse_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE transaction_type_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE transporteur_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE type_cash_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE type_fournisseur_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE type_note_de_frais_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE "user_id_seq" CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "Cart" DROP CONSTRAINT FK_AB91278942D8D3B5
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE address_entreprise DROP CONSTRAINT FK_758472FEA4AEAFEA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE adress DROP CONSTRAINT FK_5CECC7BE84667448
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cart_details DROP CONSTRAINT FK_89FCC38DBCB5C6F5
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_details DROP CONSTRAINT FK_2CCA19D6155B63DD
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cash_details DROP CONSTRAINT FK_2CCA19D6649365FC
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE collection_statistiques DROP CONSTRAINT FK_2939A94C514956FD
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE collections DROP CONSTRAINT FK_D325D3EE4FA9A58B
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE collections DROP CONSTRAINT FK_D325D3EE3C1F0C8F
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP CONSTRAINT FK_6EEAA67D242C7AD2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP CONSTRAINT FK_6EEAA67D670C757F
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande DROP CONSTRAINT FK_6EEAA67D47C88A1B
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande_statistiques DROP CONSTRAINT FK_89E6102D82EA2E54
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commande_statistiques DROP CONSTRAINT FK_89E6102D97C86FA4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE fournisseur DROP CONSTRAINT FK_369ECA3231CF5CEB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE frais_de_port DROP CONSTRAINT FK_6FC5088782EA2E54
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE frais_de_port DROP CONSTRAINT FK_6FC5088797C86FA4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movements DROP CONSTRAINT FK_BF9F9C49A80EF684
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movements DROP CONSTRAINT FK_BF9F9C49EA4ED04A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE note_de_frais DROP CONSTRAINT FK_E6ECCF53514956FD
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE note_de_frais DROP CONSTRAINT FK_E6ECCF53BAFAB15C
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" DROP CONSTRAINT FK_F52993989D86650F
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" DROP CONSTRAINT FK_F5299398C273A89B
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" DROP CONSTRAINT FK_F529939829BB6799
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" DROP CONSTRAINT FK_F529939821DFC797
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" DROP CONSTRAINT FK_F52993986BF700BD
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE "order" DROP CONSTRAINT FK_F5299398333625D8
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_items DROP CONSTRAINT FK_62809DB0E24BFBC
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_items DROP CONSTRAINT FK_62809DB0A80EF684
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_tax DROP CONSTRAINT FK_CDDAF5167127340D
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_tax DROP CONSTRAINT FK_CDDAF516B2A824D8
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE otp_code DROP CONSTRAINT FK_93FE2319D35FFDA0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE parcel DROP CONSTRAINT FK_C99B5D6011702397
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP CONSTRAINT FK_65D29B32B7195EEE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP CONSTRAINT FK_65D29B325AA1164F
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP CONSTRAINT FK_65D29B327108AEB2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payments DROP CONSTRAINT FK_65D29B32DC058279
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product DROP CONSTRAINT FK_D34A04ADBACD6074
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product DROP CONSTRAINT FK_D34A04AD82EA2E54
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_categories DROP CONSTRAINT FK_A99419434584665A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_categories DROP CONSTRAINT FK_A9941943A21214B7
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_shipping DROP CONSTRAINT FK_E6AC7DB34584665A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_shipping DROP CONSTRAINT FK_E6AC7DB3335924EB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D7ADA1FB5
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D498DA827
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D4584665A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE related_product DROP CONSTRAINT FK_EC53CE084584665A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reset_password_request DROP CONSTRAINT FK_7CE748AA76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reviews_product DROP CONSTRAINT FK_E0851D6C3ECE1B7F
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reviews_product DROP CONSTRAINT FK_E0851D6C13F58654
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE shipping_label DROP CONSTRAINT FK_E0388D52465E670C
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE shipping_order DROP CONSTRAINT FK_1BB64E22ACB9B199
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE218827B4FEBF
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE21882754735B
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE21889B338547
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE21884C3A3BB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE transaction_caisse DROP CONSTRAINT FK_C4DE2188B3E6B071
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE "Cart"
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE address_entreprise
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE admin_settings
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE adress
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE caisse
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE carrier
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE cart_details
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE cash_details
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE categories
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE collection_picture
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE collection_statistiques
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE collections
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE color
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE commande
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE commande_statistiques
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE contact
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE easy_post_configuration
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE email_configuration
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE entreprise
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE explore_card
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE feature
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE fournisseur
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE frais_de_port
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE google_places_config
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE home_slider
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE inventory_movements
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE movement_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE note_de_frais
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE "order"
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE order_items
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE order_source
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE order_tax
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE order_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE otp_code
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE packaging_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE parcel
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE payment_method
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE payment_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE payments
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_categories
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_shipping
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE product_variant
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE refresh_tokens
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE related_product
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE reset_password_request
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE reviews_product
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE shipping_class
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE shipping_label
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE shipping_order
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE size
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE square_config
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE status_commande
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE status_payment
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE style
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE tax
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE transaction_caisse
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE transaction_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE transporteur
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE type_cash
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE type_fournisseur
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE type_note_de_frais
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE "user"
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE messenger_messages
        SQL);
    }
}
