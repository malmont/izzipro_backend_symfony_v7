--
-- PostgreSQL database dump
--

-- Dumped from database version 15.10 (Debian 15.10-1.pgdg120+1)
-- Dumped by pg_dump version 15.10 (Debian 15.10-1.pgdg120+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: notify_messenger_messages(); Type: FUNCTION; Schema: public; Owner: symfony
--

CREATE FUNCTION public.notify_messenger_messages() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                PERFORM pg_notify('messenger_messages', NEW.queue_name::text);
                RETURN NEW;
            END;
        $$;


ALTER FUNCTION public.notify_messenger_messages() OWNER TO symfony;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: Cart; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public."Cart" (
    id integer NOT NULL,
    user_cart_id integer NOT NULL,
    reference character varying(255) NOT NULL,
    fullname character varying(255) NOT NULL,
    carriername character varying(255) NOT NULL,
    carrierprice double precision NOT NULL,
    deleveryaddress text NOT NULL,
    ispaid boolean NOT NULL,
    moreinformations text,
    created_at timestamp(0) without time zone NOT NULL,
    quantity integer NOT NULL,
    sub_total_ht double precision NOT NULL,
    taxe double precision NOT NULL,
    sub_total_ttc double precision NOT NULL
);


ALTER TABLE public."Cart" OWNER TO symfony;

--
-- Name: Cart_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public."Cart_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public."Cart_id_seq" OWNER TO symfony;

--
-- Name: admin_settings; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.admin_settings (
    id integer NOT NULL,
    navbar_component character varying(255) NOT NULL,
    style_choice character varying(255) NOT NULL,
    theme_choice character varying(255) NOT NULL,
    section1_component character varying(255) DEFAULT NULL::character varying,
    type_component_section1 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch character varying(255) DEFAULT NULL::character varying,
    section2_component character varying(255) DEFAULT NULL::character varying,
    type_component_section2 character varying(255) DEFAULT NULL::character varying,
    section3_component character varying(255) DEFAULT NULL::character varying,
    type_component_section3 character varying(255) DEFAULT NULL::character varying,
    section4_component character varying(255) DEFAULT NULL::character varying,
    type_component_section4 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch_section2 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch_section3 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch_section4 character varying(255) DEFAULT NULL::character varying,
    section5_component character varying(255) DEFAULT NULL::character varying,
    type_component_section5 character varying(255) DEFAULT NULL::character varying,
    section6_component character varying(255) DEFAULT NULL::character varying,
    type_component_section6 character varying(255) DEFAULT NULL::character varying,
    section7_component character varying(255) DEFAULT NULL::character varying,
    type_component_section7 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch_section5 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch_section6 character varying(255) DEFAULT NULL::character varying,
    select_type_product_fetch_section7 character varying(255) DEFAULT NULL::character varying,
    type_category_card character varying(255) DEFAULT NULL::character varying,
    details_product_card_component character varying(255) DEFAULT NULL::character varying,
    cart_item_card_component character varying(255) DEFAULT NULL::character varying,
    total_card_component character varying(255) DEFAULT NULL::character varying,
    checkout_card_component character varying(255) DEFAULT NULL::character varying,
    account_dashboard_component character varying(255) DEFAULT NULL::character varying,
    order_list_card_component character varying(255) DEFAULT NULL::character varying,
    adress_list_card_component character varying(255) DEFAULT NULL::character varying,
    carrier_list_card_component character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.admin_settings OWNER TO symfony;

--
-- Name: admin_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.admin_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.admin_settings_id_seq OWNER TO symfony;

--
-- Name: adress; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.adress (
    id integer NOT NULL,
    user_adress_id integer NOT NULL,
    firstname character varying(255) NOT NULL,
    lastname character varying(255) NOT NULL,
    fullname character varying(255) NOT NULL,
    company character varying(255) DEFAULT NULL::character varying,
    address text NOT NULL,
    complement text,
    phone integer NOT NULL,
    city character varying(255) NOT NULL,
    codepostal integer NOT NULL,
    country character varying(255) NOT NULL
);


ALTER TABLE public.adress OWNER TO symfony;

--
-- Name: adress_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.adress_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.adress_id_seq OWNER TO symfony;

--
-- Name: caisse; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.caisse (
    id integer NOT NULL,
    amount_total double precision NOT NULL,
    created_at date,
    is_open boolean DEFAULT false NOT NULL,
    fon_de_caisse double precision
);


ALTER TABLE public.caisse OWNER TO symfony;

--
-- Name: caisse_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.caisse_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.caisse_id_seq OWNER TO symfony;

--
-- Name: carrier; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.carrier (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text NOT NULL,
    price double precision NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    update_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    photo character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.carrier OWNER TO symfony;

--
-- Name: carrier_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.carrier_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.carrier_id_seq OWNER TO symfony;

--
-- Name: cart_details; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.cart_details (
    id integer NOT NULL,
    carts_id integer NOT NULL,
    productname character varying(255) NOT NULL,
    producprice double precision NOT NULL,
    quantity integer NOT NULL,
    sub_total_ht double precision NOT NULL,
    taxe double precision NOT NULL,
    sub_total_ttc double precision NOT NULL
);


ALTER TABLE public.cart_details OWNER TO symfony;

--
-- Name: cart_details_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.cart_details_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.cart_details_id_seq OWNER TO symfony;

--
-- Name: cash_details; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.cash_details (
    id integer NOT NULL,
    type_cash_id integer NOT NULL,
    transaction_caisse_id integer NOT NULL,
    nombre_items integer NOT NULL
);


ALTER TABLE public.cash_details OWNER TO symfony;

--
-- Name: cash_details_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.cash_details_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.cash_details_id_seq OWNER TO symfony;

--
-- Name: categories; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.categories (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    image character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.categories OWNER TO symfony;

--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.categories_id_seq OWNER TO symfony;

--
-- Name: collection_statistiques; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.collection_statistiques (
    id integer NOT NULL,
    collection_id integer,
    general_budget double precision NOT NULL,
    used_budget double precision NOT NULL,
    remaining_budget double precision NOT NULL,
    total_item_cost double precision NOT NULL,
    total_shipping_cost double precision NOT NULL,
    total_expense_cost double precision NOT NULL,
    order_count integer NOT NULL,
    item_count integer NOT NULL,
    model_count integer NOT NULL,
    stock_value double precision NOT NULL,
    margin double precision NOT NULL,
    taux_marge double precision NOT NULL,
    taux_marque double precision NOT NULL,
    average_multiplier double precision NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    duration_days integer NOT NULL
);


ALTER TABLE public.collection_statistiques OWNER TO symfony;

--
-- Name: collection_statistiques_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.collection_statistiques_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.collection_statistiques_id_seq OWNER TO symfony;

--
-- Name: collections; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.collections (
    id integer NOT NULL,
    user_collections_id integer,
    budget_collection double precision NOT NULL,
    start_date_collection timestamp(0) without time zone NOT NULL,
    end_date_collection timestamp(0) without time zone NOT NULL,
    del boolean NOT NULL,
    nom_collection character varying(255) NOT NULL,
    photo_collection character varying(255) NOT NULL,
    is_closed boolean
);


ALTER TABLE public.collections OWNER TO symfony;

--
-- Name: collections_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.collections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.collections_id_seq OWNER TO symfony;

--
-- Name: color; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.color (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    code_hexa character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.color OWNER TO symfony;

--
-- Name: color_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.color_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.color_id_seq OWNER TO symfony;

--
-- Name: commande; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.commande (
    id integer NOT NULL,
    collections_id integer,
    fournisseur_id integer,
    budget double precision NOT NULL,
    date timestamp(0) without time zone NOT NULL,
    name character varying(255) DEFAULT NULL::character varying,
    photo character varying(255) DEFAULT NULL::character varying,
    is_closed boolean
);


ALTER TABLE public.commande OWNER TO symfony;

--
-- Name: commande_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.commande_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.commande_id_seq OWNER TO symfony;

--
-- Name: commande_statistiques; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.commande_statistiques (
    id integer NOT NULL,
    commande_id integer,
    transporteur_id integer,
    average_multiplier double precision,
    general_budget double precision,
    used_budget double precision,
    remaining_budget double precision,
    total_item_cost double precision,
    total_frais_de_port double precision,
    item_count integer,
    model_count integer,
    stock_value double precision,
    marge double precision,
    taux_marge double precision,
    taux_marque double precision
);


ALTER TABLE public.commande_statistiques OWNER TO symfony;

--
-- Name: commande_statistiques_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.commande_statistiques_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.commande_statistiques_id_seq OWNER TO symfony;

--
-- Name: contact; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.contact (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    phone character varying(255) NOT NULL,
    subject character varying(255) NOT NULL,
    content text NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    is_read boolean
);


ALTER TABLE public.contact OWNER TO symfony;

--
-- Name: contact_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.contact_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.contact_id_seq OWNER TO symfony;

--
-- Name: doctrine_migration_versions; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.doctrine_migration_versions (
    version character varying(191) NOT NULL,
    executed_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    execution_time integer
);


ALTER TABLE public.doctrine_migration_versions OWNER TO symfony;

--
-- Name: fournisseur; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.fournisseur (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    photo character varying(255) DEFAULT NULL::character varying,
    adresse character varying(255) DEFAULT NULL::character varying,
    ville character varying(255) DEFAULT NULL::character varying,
    pays character varying(255) DEFAULT NULL::character varying,
    tel character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.fournisseur OWNER TO symfony;

--
-- Name: fournisseur_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.fournisseur_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.fournisseur_id_seq OWNER TO symfony;

--
-- Name: frais_de_port; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.frais_de_port (
    id integer NOT NULL,
    commande_id integer,
    transporteur_id integer,
    name character varying(255) NOT NULL,
    facture character varying(255) DEFAULT NULL::character varying,
    image character varying(255) DEFAULT NULL::character varying,
    tracknumber character varying(255) DEFAULT NULL::character varying,
    price double precision
);


ALTER TABLE public.frais_de_port OWNER TO symfony;

--
-- Name: frais_de_port_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.frais_de_port_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.frais_de_port_id_seq OWNER TO symfony;

--
-- Name: home_slider; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.home_slider (
    id integer NOT NULL,
    title character varying(255) NOT NULL,
    description character varying(255) NOT NULL,
    button_message character varying(255) NOT NULL,
    button_url character varying(255) NOT NULL,
    image character varying(255) NOT NULL,
    is_diplayed boolean
);


ALTER TABLE public.home_slider OWNER TO symfony;

--
-- Name: home_slider_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.home_slider_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.home_slider_id_seq OWNER TO symfony;

--
-- Name: inventory_movements; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.inventory_movements (
    id integer NOT NULL,
    product_variant_id integer,
    movement_type_id integer,
    quantity integer NOT NULL,
    movement_date timestamp(0) without time zone NOT NULL,
    stock_before_movement integer,
    stock_after_movement double precision
);


ALTER TABLE public.inventory_movements OWNER TO symfony;

--
-- Name: inventory_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.inventory_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.inventory_movements_id_seq OWNER TO symfony;

--
-- Name: messenger_messages; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.messenger_messages (
    id bigint NOT NULL,
    body text NOT NULL,
    headers text NOT NULL,
    queue_name character varying(190) NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    available_at timestamp(0) without time zone NOT NULL,
    delivered_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone
);


ALTER TABLE public.messenger_messages OWNER TO symfony;

--
-- Name: COLUMN messenger_messages.created_at; Type: COMMENT; Schema: public; Owner: symfony
--

COMMENT ON COLUMN public.messenger_messages.created_at IS '(DC2Type:datetime_immutable)';


--
-- Name: COLUMN messenger_messages.available_at; Type: COMMENT; Schema: public; Owner: symfony
--

COMMENT ON COLUMN public.messenger_messages.available_at IS '(DC2Type:datetime_immutable)';


--
-- Name: COLUMN messenger_messages.delivered_at; Type: COMMENT; Schema: public; Owner: symfony
--

COMMENT ON COLUMN public.messenger_messages.delivered_at IS '(DC2Type:datetime_immutable)';


--
-- Name: messenger_messages_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.messenger_messages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.messenger_messages_id_seq OWNER TO symfony;

--
-- Name: messenger_messages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: symfony
--

ALTER SEQUENCE public.messenger_messages_id_seq OWNED BY public.messenger_messages.id;


--
-- Name: movement_type; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.movement_type (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.movement_type OWNER TO symfony;

--
-- Name: movement_type_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.movement_type_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.movement_type_id_seq OWNER TO symfony;

--
-- Name: note_de_frais; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.note_de_frais (
    id integer NOT NULL,
    collection_id integer,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying,
    image_ndf character varying(255) NOT NULL,
    montant double precision NOT NULL,
    date date NOT NULL
);


ALTER TABLE public.note_de_frais OWNER TO symfony;

--
-- Name: note_de_frais_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.note_de_frais_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.note_de_frais_id_seq OWNER TO symfony;

--
-- Name: order; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public."order" (
    id integer NOT NULL,
    user_id_id integer,
    shipping_adress_id integer,
    order_source_id integer,
    carrier_id integer,
    status_id integer NOT NULL,
    order_type_id integer,
    reference character varying(255) NOT NULL,
    order_date timestamp(0) without time zone NOT NULL,
    status_updated_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    total_amount double precision NOT NULL,
    sub_total double precision,
    total_tax double precision
);


ALTER TABLE public."order" OWNER TO symfony;

--
-- Name: order_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.order_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.order_id_seq OWNER TO symfony;

--
-- Name: order_items; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.order_items (
    id integer NOT NULL,
    order_associated_id integer,
    product_variant_id integer,
    quantity integer NOT NULL,
    unit_price double precision NOT NULL,
    total_price double precision NOT NULL
);


ALTER TABLE public.order_items OWNER TO symfony;

--
-- Name: order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.order_items_id_seq OWNER TO symfony;

--
-- Name: order_source; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.order_source (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.order_source OWNER TO symfony;

--
-- Name: order_source_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.order_source_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.order_source_id_seq OWNER TO symfony;

--
-- Name: order_tax; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.order_tax (
    id integer NOT NULL,
    order_tax_id integer,
    tax_id integer,
    amount double precision NOT NULL
);


ALTER TABLE public.order_tax OWNER TO symfony;

--
-- Name: order_tax_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.order_tax_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.order_tax_id_seq OWNER TO symfony;

--
-- Name: order_type; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.order_type (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.order_type OWNER TO symfony;

--
-- Name: order_type_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.order_type_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.order_type_id_seq OWNER TO symfony;

--
-- Name: payment_method; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.payment_method (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.payment_method OWNER TO symfony;

--
-- Name: payment_method_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.payment_method_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.payment_method_id_seq OWNER TO symfony;

--
-- Name: payment_type; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.payment_type (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.payment_type OWNER TO symfony;

--
-- Name: payment_type_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.payment_type_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.payment_type_id_seq OWNER TO symfony;

--
-- Name: payments; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.payments (
    id integer NOT NULL,
    order_payment_id integer,
    payment_method_id integer,
    statut_payment_id integer,
    payment_type_id integer,
    amount double precision NOT NULL,
    payment_date timestamp(0) without time zone NOT NULL,
    square_payment_id character varying(255) DEFAULT NULL::character varying,
    square_order_id character varying(255) DEFAULT NULL::character varying,
    square_receipt_url character varying(255) DEFAULT NULL::character varying,
    square_status character varying(50) DEFAULT NULL::character varying,
    square_card_brand character varying(50) DEFAULT NULL::character varying,
    square_last4 character varying(4) DEFAULT NULL::character varying,
    square_risk_level character varying(50) DEFAULT NULL::character varying
);


ALTER TABLE public.payments OWNER TO symfony;

--
-- Name: payments_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.payments_id_seq OWNER TO symfony;

--
-- Name: product; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.product (
    id integer NOT NULL,
    style_id integer,
    commande_id integer,
    name character varying(255) NOT NULL,
    description text NOT NULL,
    moreinformations text NOT NULL,
    price double precision NOT NULL,
    isbestseller boolean NOT NULL,
    isnewarrival boolean,
    isfeatured boolean,
    isspecialoffer boolean,
    image character varying(255) NOT NULL,
    quantity integer NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    tags text,
    slug character varying(255) NOT NULL,
    purchase_price double precision,
    coefficient_multiplier double precision,
    barcode character varying(255) DEFAULT NULL::character varying,
    freeze_quantity integer,
    is_accessory boolean
);


ALTER TABLE public.product OWNER TO symfony;

--
-- Name: COLUMN product.created_at; Type: COMMENT; Schema: public; Owner: symfony
--

COMMENT ON COLUMN public.product.created_at IS '(DC2Type:datetime_immutable)';


--
-- Name: product_categories; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.product_categories (
    product_id integer NOT NULL,
    categories_id integer NOT NULL
);


ALTER TABLE public.product_categories OWNER TO symfony;

--
-- Name: product_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.product_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.product_id_seq OWNER TO symfony;

--
-- Name: product_variant; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.product_variant (
    id integer NOT NULL,
    color_id integer,
    size_id integer,
    product_id integer,
    stock_quantity integer NOT NULL
);


ALTER TABLE public.product_variant OWNER TO symfony;

--
-- Name: product_variant_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.product_variant_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.product_variant_id_seq OWNER TO symfony;

--
-- Name: refresh_tokens; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.refresh_tokens (
    id integer NOT NULL,
    refresh_token character varying(128) NOT NULL,
    username character varying(255) NOT NULL,
    valid timestamp(0) without time zone NOT NULL
);


ALTER TABLE public.refresh_tokens OWNER TO symfony;

--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.refresh_tokens_id_seq OWNER TO symfony;

--
-- Name: related_product; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.related_product (
    id integer NOT NULL,
    product_id integer NOT NULL
);


ALTER TABLE public.related_product OWNER TO symfony;

--
-- Name: related_product_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.related_product_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.related_product_id_seq OWNER TO symfony;

--
-- Name: reset_password_request; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.reset_password_request (
    id integer NOT NULL,
    user_id integer NOT NULL,
    selector character varying(20) NOT NULL,
    hashed_token character varying(100) NOT NULL,
    requested_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL
);


ALTER TABLE public.reset_password_request OWNER TO symfony;

--
-- Name: COLUMN reset_password_request.requested_at; Type: COMMENT; Schema: public; Owner: symfony
--

COMMENT ON COLUMN public.reset_password_request.requested_at IS '(DC2Type:datetime_immutable)';


--
-- Name: COLUMN reset_password_request.expires_at; Type: COMMENT; Schema: public; Owner: symfony
--

COMMENT ON COLUMN public.reset_password_request.expires_at IS '(DC2Type:datetime_immutable)';


--
-- Name: reset_password_request_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.reset_password_request_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.reset_password_request_id_seq OWNER TO symfony;

--
-- Name: reviews_product; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.reviews_product (
    id integer NOT NULL,
    user_review_id integer NOT NULL,
    product_reviews_id integer NOT NULL,
    note integer NOT NULL,
    comment text
);


ALTER TABLE public.reviews_product OWNER TO symfony;

--
-- Name: reviews_product_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.reviews_product_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.reviews_product_id_seq OWNER TO symfony;

--
-- Name: size; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.size (
    id integer NOT NULL,
    name character varying(255) NOT NULL
);


ALTER TABLE public.size OWNER TO symfony;

--
-- Name: size_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.size_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.size_id_seq OWNER TO symfony;

--
-- Name: square_config; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.square_config (
    id integer NOT NULL,
    access_token character varying(255) NOT NULL,
    application_id character varying(255) NOT NULL,
    is_active boolean NOT NULL,
    location_id character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.square_config OWNER TO symfony;

--
-- Name: square_config_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.square_config_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.square_config_id_seq OWNER TO symfony;

--
-- Name: status_commande; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.status_commande (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.status_commande OWNER TO symfony;

--
-- Name: status_commande_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.status_commande_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.status_commande_id_seq OWNER TO symfony;

--
-- Name: status_payment; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.status_payment (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description text
);


ALTER TABLE public.status_payment OWNER TO symfony;

--
-- Name: status_payment_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.status_payment_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.status_payment_id_seq OWNER TO symfony;

--
-- Name: style; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.style (
    id integer NOT NULL,
    name character varying(255) NOT NULL
);


ALTER TABLE public.style OWNER TO symfony;

--
-- Name: style_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.style_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.style_id_seq OWNER TO symfony;

--
-- Name: tax; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.tax (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    rate double precision NOT NULL,
    type character varying(255) NOT NULL,
    province character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.tax OWNER TO symfony;

--
-- Name: tax_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.tax_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.tax_id_seq OWNER TO symfony;

--
-- Name: transaction_caisse; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.transaction_caisse (
    id integer NOT NULL,
    caisse_id integer NOT NULL,
    user_caisse_id integer NOT NULL,
    order_caisse_id integer,
    payment_id integer,
    transaction_type_id integer NOT NULL,
    transaction_date timestamp(0) without time zone NOT NULL,
    amount double precision NOT NULL
);


ALTER TABLE public.transaction_caisse OWNER TO symfony;

--
-- Name: transaction_caisse_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.transaction_caisse_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.transaction_caisse_id_seq OWNER TO symfony;

--
-- Name: transaction_type; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.transaction_type (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.transaction_type OWNER TO symfony;

--
-- Name: transaction_type_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.transaction_type_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.transaction_type_id_seq OWNER TO symfony;

--
-- Name: transporteur; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.transporteur (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    logo character varying(255) DEFAULT NULL::character varying,
    contact character varying(255) DEFAULT NULL::character varying
);


ALTER TABLE public.transporteur OWNER TO symfony;

--
-- Name: transporteur_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.transporteur_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.transporteur_id_seq OWNER TO symfony;

--
-- Name: type_cash; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public.type_cash (
    id integer NOT NULL,
    name character varying(255) DEFAULT NULL::character varying,
    value double precision
);


ALTER TABLE public.type_cash OWNER TO symfony;

--
-- Name: type_cash_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.type_cash_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.type_cash_id_seq OWNER TO symfony;

--
-- Name: user_id_seq; Type: SEQUENCE; Schema: public; Owner: symfony
--

CREATE SEQUENCE public.user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.user_id_seq OWNER TO symfony;

--
-- Name: user; Type: TABLE; Schema: public; Owner: symfony
--

CREATE TABLE public."user" (
    id integer DEFAULT nextval('public.user_id_seq'::regclass) NOT NULL,
    email character varying(180) NOT NULL,
    roles json NOT NULL,
    password character varying(255) NOT NULL,
    username character varying(255) NOT NULL,
    firstname character varying(255) NOT NULL,
    lastname character varying(255) NOT NULL,
    is_verified boolean NOT NULL
);


ALTER TABLE public."user" OWNER TO symfony;

--
-- Name: messenger_messages id; Type: DEFAULT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.messenger_messages ALTER COLUMN id SET DEFAULT nextval('public.messenger_messages_id_seq'::regclass);


--
-- Data for Name: Cart; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public."Cart" (id, user_cart_id, reference, fullname, carriername, carrierprice, deleveryaddress, ispaid, moreinformations, created_at, quantity, sub_total_ht, taxe, sub_total_ttc) FROM stdin;
\.


--
-- Data for Name: admin_settings; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.admin_settings (id, navbar_component, style_choice, theme_choice, section1_component, type_component_section1, select_type_product_fetch, section2_component, type_component_section2, section3_component, type_component_section3, section4_component, type_component_section4, select_type_product_fetch_section2, select_type_product_fetch_section3, select_type_product_fetch_section4, section5_component, type_component_section5, section6_component, type_component_section6, section7_component, type_component_section7, select_type_product_fetch_section5, select_type_product_fetch_section6, select_type_product_fetch_section7, type_category_card, details_product_card_component, cart_item_card_component, total_card_component, checkout_card_component, account_dashboard_component, order_list_card_component, adress_list_card_component, carrier_list_card_component) FROM stdin;
1	typeC	style7	theme4	typeE	typeD	typeIsfeatured	typeA	typeA	typeB	typeA	typeB	typeE	typeSpecialoffers	typeIsfeatured	typeNewarrivals	typeB	typeC	typeD	typeE	typeC	typeD	bestsellers	bestsellers	bestsellers	typeF	typeE	typeA	typeA	typeB	typeE	typeC	typeE	typeE
\.


--
-- Data for Name: adress; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.adress (id, user_adress_id, firstname, lastname, fullname, company, address, complement, phone, city, codepostal, country) FROM stdin;
\.


--
-- Data for Name: caisse; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.caisse (id, amount_total, created_at, is_open, fon_de_caisse) FROM stdin;
\.


--
-- Data for Name: carrier; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.carrier (id, name, description, price, created_at, update_at, photo) FROM stdin;
1	Colissimo	Fast delivey in 2 weeks	999	2025-01-11 17:46:01	\N	4dda058fd1e527f886a3a35d488f3e6c3c199985.png
2	Fast Express Vip	International carrier delivey 72 Hours	1999	2025-01-11 17:46:30	\N	3c17f5d26ee73980d8d7bbee47620b205d1e8b99.png
3	Dhl	Fast delivey	3999	2025-01-11 17:47:09	\N	b16b9dde60ecc63ef50a3d7fd1bdae91fd23c2ea.png
4	Ups	Fast delivey	3999	2025-01-11 17:47:29	\N	627704cf966e3fb2d228f3c374f84a402b67e9bc.png
5	Fedex	Fast delivey	3999	2025-01-11 17:47:45	\N	0cf413b0eabe293be81df2fc3c6f24e3e0c75aeb.png
6	Free	Low delivrery	0	2025-01-11 17:48:02	\N	fde25a60003a012d21140813156434137a7ffa96.png
7	Not Carrier	For shop	0	2025-01-11 17:48:23	\N	f0978db9bc864a46a4942fd654d9f6fa8cf0f900.png
\.


--
-- Data for Name: cart_details; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.cart_details (id, carts_id, productname, producprice, quantity, sub_total_ht, taxe, sub_total_ttc) FROM stdin;
\.


--
-- Data for Name: cash_details; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.cash_details (id, type_cash_id, transaction_caisse_id, nombre_items) FROM stdin;
\.


--
-- Data for Name: categories; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.categories (id, name, description, image) FROM stdin;
1	Top	<div>Top sexy</div>	ca288e5fe91776e23c84f548ad6cd0d928fb7017.png
2	Robe	<div>Robe sexy</div>	e7f496596c131ff4b70f049292ca52d71637b900.png
3	Jeans	<div>Jeans sexy</div>	39e73cd270598d9e5df71359eed4fe0e8dd71beb.png
4	Combinaison	<div>Combinaison</div>	58e3f58c4a02f44e8c88e62f017f035fbb9a5e3b.png
5	All	<div>All</div>	7542269cc518fb30e2e6745566b5ba41db571900.png
6	Accéssoires	<div>Accéssoires</div>	62828e4b63a67dc9554a01320e1cb48370f0a7a9.jpg
\.


--
-- Data for Name: collection_statistiques; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.collection_statistiques (id, collection_id, general_budget, used_budget, remaining_budget, total_item_cost, total_shipping_cost, total_expense_cost, order_count, item_count, model_count, stock_value, margin, taux_marge, taux_marque, average_multiplier, start_date, end_date, duration_days) FROM stdin;
\.


--
-- Data for Name: collections; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.collections (id, user_collections_id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, photo_collection, is_closed) FROM stdin;
\.


--
-- Data for Name: color; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.color (id, name, code_hexa) FROM stdin;
1	Noir	#000000
2	Blanc	#ffffff
3	Rouge	#000000
4	Bleu	#0432ff
5	Vert	#00f900
6	Violet	#6633ff
7	Marron	#aa7941
8	Orange	#ff9200
9	Rose	#ff3399
10	Motif	#000000
11	Beige	#cc9933
\.


--
-- Data for Name: commande; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.commande (id, collections_id, fournisseur_id, budget, date, name, photo, is_closed) FROM stdin;
\.


--
-- Data for Name: commande_statistiques; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.commande_statistiques (id, commande_id, transporteur_id, average_multiplier, general_budget, used_budget, remaining_budget, total_item_cost, total_frais_de_port, item_count, model_count, stock_value, marge, taux_marge, taux_marque) FROM stdin;
\.


--
-- Data for Name: contact; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.contact (id, name, email, phone, subject, content, created_at, is_read) FROM stdin;
\.


--
-- Data for Name: doctrine_migration_versions; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.doctrine_migration_versions (version, executed_at, execution_time) FROM stdin;
DoctrineMigrations\\Version20250110220941	2025-01-10 22:09:41	388
DoctrineMigrations\\Version20250111004031	2025-01-11 00:40:31	2
DoctrineMigrations\\Version20250111011354	2025-01-11 01:13:55	13
DoctrineMigrations\\Version20250111012347	2025-01-11 01:23:47	2
DoctrineMigrations\\Version20250111154535	2025-01-11 15:45:36	2
DoctrineMigrations\\Version20250111161730	2025-01-11 16:17:30	1
DoctrineMigrations\\Version20250111162342	2025-01-11 16:23:43	1
DoctrineMigrations\\Version20250111163254	2025-01-11 16:32:54	1
DoctrineMigrations\\Version20250111163731	2025-01-11 16:37:31	1
DoctrineMigrations\\Version20250111191417	2025-01-25 18:01:38	2
DoctrineMigrations\\Version20250118154036	2025-01-25 18:01:38	10
DoctrineMigrations\\Version20250118165038	2025-01-25 18:01:38	0
DoctrineMigrations\\Version20250118170808	2025-01-25 18:01:38	0
DoctrineMigrations\\Version20250118181308	2025-01-25 18:01:38	4
DoctrineMigrations\\Version20250205003601	2025-02-05 00:55:01	10
\.


--
-- Data for Name: fournisseur; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.fournisseur (id, name, photo, adresse, ville, pays, tel) FROM stdin;
\.


--
-- Data for Name: frais_de_port; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.frais_de_port (id, commande_id, transporteur_id, name, facture, image, tracknumber, price) FROM stdin;
\.


--
-- Data for Name: home_slider; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.home_slider (id, title, description, button_message, button_url, image, is_diplayed) FROM stdin;
1	Woman Fashion	Get up to 50% off today only!	Shop Now	https://backend-strapi.online/jeesign/product/combinaison	94fc5356a2cd04058aa9cf115384c15c4291ca44.jpg	t
2	Woman Fashion	Get up to 50% off today only!	Shop Now	https://backend-strapi.online/jeesign/product/combinaison	2b37fc8c8db364f6733e6242c9bf1ed9bca7371f.jpg	t
\.


--
-- Data for Name: inventory_movements; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.inventory_movements (id, product_variant_id, movement_type_id, quantity, movement_date, stock_before_movement, stock_after_movement) FROM stdin;
1	1	1	10	2025-02-02 23:20:55	0	10
\.


--
-- Data for Name: messenger_messages; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.messenger_messages (id, body, headers, queue_name, created_at, available_at, delivered_at) FROM stdin;
\.


--
-- Data for Name: movement_type; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.movement_type (id, name, description) FROM stdin;
1	Entrant	\N
2	Sortant	\N
3	Return	\N
4	Ajustement	\N
\.


--
-- Data for Name: note_de_frais; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.note_de_frais (id, collection_id, name, description, image_ndf, montant, date) FROM stdin;
\.


--
-- Data for Name: order; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public."order" (id, user_id_id, shipping_adress_id, order_source_id, carrier_id, status_id, order_type_id, reference, order_date, status_updated_at, total_amount, sub_total, total_tax) FROM stdin;
\.


--
-- Data for Name: order_items; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.order_items (id, order_associated_id, product_variant_id, quantity, unit_price, total_price) FROM stdin;
\.


--
-- Data for Name: order_source; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.order_source (id, name, description) FROM stdin;
1	Ecommerce	\N
2	Pos	\N
3	Mobile_app	\N
\.


--
-- Data for Name: order_tax; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.order_tax (id, order_tax_id, tax_id, amount) FROM stdin;
\.


--
-- Data for Name: order_type; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.order_type (id, name, description) FROM stdin;
1	AchatClient	\N
2	RetourClient	\N
\.


--
-- Data for Name: payment_method; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.payment_method (id, name, description) FROM stdin;
1	credit_card	\N
2	Cash	\N
3	Paypal	\N
\.


--
-- Data for Name: payment_type; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.payment_type (id, name, description) FROM stdin;
1	RemboursementClient	\N
2	PaiementClient	\N
\.


--
-- Data for Name: payments; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.payments (id, order_payment_id, payment_method_id, statut_payment_id, payment_type_id, amount, payment_date, square_payment_id, square_order_id, square_receipt_url, square_status, square_card_brand, square_last4, square_risk_level) FROM stdin;
\.


--
-- Data for Name: product; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.product (id, style_id, commande_id, name, description, moreinformations, price, isbestseller, isnewarrival, isfeatured, isspecialoffer, image, quantity, created_at, tags, slug, purchase_price, coefficient_multiplier, barcode, freeze_quantity, is_accessory) FROM stdin;
1	1	\N	Feel A Way Shirt - Tan/Multi	<div>Feel A Way Shirt - Tan/Multi</div>	<div>Feel A Way Shirt - Tan/Multi</div>	3000	t	t	t	f	8a6bff1ca2b570ff314aff6bf533f2e3025c4f5b.webp	10	2025-01-25 18:03:48	Feel A Way Shirt - Tan/Multi	feel-a-way-shirt-tanmulti	1000	3	\N	\N	f
2	5	\N	Watch Me Bloom Shirt - Pink/combo	<div>Watch Me Bloom Shirt - Pink/combo</div>	<div>Watch Me Bloom Shirt - Pink/combo</div>	3600	t	f	t	t	e7177b9220bea82eb0c1d49def3b714ffadec5a6.png	0	2025-01-25 18:05:00	Watch Me Bloom Shirt - Pink/combo	watch-me-bloom-shirt-pinkcombo	1200	3	\N	\N	f
\.


--
-- Data for Name: product_categories; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.product_categories (product_id, categories_id) FROM stdin;
1	1
2	2
\.


--
-- Data for Name: product_variant; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.product_variant (id, color_id, size_id, product_id, stock_quantity) FROM stdin;
1	\N	\N	1	10
\.


--
-- Data for Name: refresh_tokens; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.refresh_tokens (id, refresh_token, username, valid) FROM stdin;
1	pxMeM1l3oPJq0aQK6d2eJ7/sfw7vwufzzDThSLtUQ7GK+bPdo5zj7q0uAs+NHNQnpYOimYbq5mYGErPxoahEbA==	michel.almont@gmail.com	2025-02-14 02:07:26
\.


--
-- Data for Name: related_product; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.related_product (id, product_id) FROM stdin;
\.


--
-- Data for Name: reset_password_request; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.reset_password_request (id, user_id, selector, hashed_token, requested_at, expires_at) FROM stdin;
\.


--
-- Data for Name: reviews_product; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.reviews_product (id, user_review_id, product_reviews_id, note, comment) FROM stdin;
\.


--
-- Data for Name: size; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.size (id, name) FROM stdin;
1	Small
2	Medium
3	Large
4	ExtraLarge
\.


--
-- Data for Name: square_config; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.square_config (id, access_token, application_id, is_active, location_id) FROM stdin;
\.


--
-- Data for Name: status_commande; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.status_commande (id, name, description) FROM stdin;
1	Incomplete	\N
2	En cours	\N
3	Complétée	\N
4	En cours de préparation	\N
5	En cours de livraison	\N
6	Livrée	\N
7	Annulation	\N
\.


--
-- Data for Name: status_payment; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.status_payment (id, name, description) FROM stdin;
1	En cours	\N
2	Complété	\N
3	Echoué	\N
\.


--
-- Data for Name: style; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.style (id, name) FROM stdin;
1	Fluide
2	Classique
3	Extensible
4	Léger
5	Fashion
6	Boheme
7	Urbain
8	Moulant
\.


--
-- Data for Name: tax; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.tax (id, name, rate, type, province) FROM stdin;
1	TPS	0.05	Fédérale	Qebec
2	TVQ	0.1	provinciale	Quebec
\.


--
-- Data for Name: transaction_caisse; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.transaction_caisse (id, caisse_id, user_caisse_id, order_caisse_id, payment_id, transaction_type_id, transaction_date, amount) FROM stdin;
\.


--
-- Data for Name: transaction_type; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.transaction_type (id, name, description) FROM stdin;
\.


--
-- Data for Name: transporteur; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.transporteur (id, name, logo, contact) FROM stdin;
\.


--
-- Data for Name: type_cash; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public.type_cash (id, name, value) FROM stdin;
1	billet de 100$	10000
2	billet de 50$	5000
3	billet de 10$	1000
4	billet de 5$	500
5	Pièce 2$	200
6	Pièce 1$	100
7	Pièce 25¢	25
8	Pièce 10¢	10
9	Pièce 5¢	5
10	Pièce 1¢	1
\.


--
-- Data for Name: user; Type: TABLE DATA; Schema: public; Owner: symfony
--

COPY public."user" (id, email, roles, password, username, firstname, lastname, is_verified) FROM stdin;
1	michel.almont@gmail.com	["ROLE_ADMIN"]	$2y$13$Sslv8gKFAL4gltm6rynmbOZn209Nmg4vf8QIlrWUqNOh/55F2cS16	michel.almont@gmail.com	Admin	User	t
2	admin@izzibackend.com	["ROLE_ADMIN"]	$2y$13$J7dIxf0xP8D6hkzqsfaq8uYTBVGLGCm1sy30OFXLRZD3SYwXoCzHa	admin@izzibackend.com	Admin	User	t
\.


--
-- Name: Cart_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public."Cart_id_seq"', 1, false);


--
-- Name: admin_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.admin_settings_id_seq', 1, true);


--
-- Name: adress_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.adress_id_seq', 1, false);


--
-- Name: caisse_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.caisse_id_seq', 1, false);


--
-- Name: carrier_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.carrier_id_seq', 7, true);


--
-- Name: cart_details_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.cart_details_id_seq', 1, false);


--
-- Name: cash_details_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.cash_details_id_seq', 1, false);


--
-- Name: categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.categories_id_seq', 6, true);


--
-- Name: collection_statistiques_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.collection_statistiques_id_seq', 1, false);


--
-- Name: collections_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.collections_id_seq', 1, false);


--
-- Name: color_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.color_id_seq', 11, true);


--
-- Name: commande_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.commande_id_seq', 1, false);


--
-- Name: commande_statistiques_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.commande_statistiques_id_seq', 1, false);


--
-- Name: contact_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.contact_id_seq', 1, false);


--
-- Name: fournisseur_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.fournisseur_id_seq', 1, false);


--
-- Name: frais_de_port_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.frais_de_port_id_seq', 1, false);


--
-- Name: home_slider_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.home_slider_id_seq', 2, true);


--
-- Name: inventory_movements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.inventory_movements_id_seq', 1, true);


--
-- Name: messenger_messages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.messenger_messages_id_seq', 1, false);


--
-- Name: movement_type_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.movement_type_id_seq', 4, true);


--
-- Name: note_de_frais_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.note_de_frais_id_seq', 1, false);


--
-- Name: order_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.order_id_seq', 1, false);


--
-- Name: order_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.order_items_id_seq', 1, false);


--
-- Name: order_source_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.order_source_id_seq', 3, true);


--
-- Name: order_tax_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.order_tax_id_seq', 1, false);


--
-- Name: order_type_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.order_type_id_seq', 2, true);


--
-- Name: payment_method_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.payment_method_id_seq', 3, true);


--
-- Name: payment_type_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.payment_type_id_seq', 2, true);


--
-- Name: payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.payments_id_seq', 1, false);


--
-- Name: product_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.product_id_seq', 2, true);


--
-- Name: product_variant_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.product_variant_id_seq', 1, true);


--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.refresh_tokens_id_seq', 1, true);


--
-- Name: related_product_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.related_product_id_seq', 1, false);


--
-- Name: reset_password_request_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.reset_password_request_id_seq', 1, false);


--
-- Name: reviews_product_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.reviews_product_id_seq', 1, false);


--
-- Name: size_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.size_id_seq', 4, true);


--
-- Name: square_config_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.square_config_id_seq', 1, false);


--
-- Name: status_commande_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.status_commande_id_seq', 7, true);


--
-- Name: status_payment_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.status_payment_id_seq', 3, true);


--
-- Name: style_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.style_id_seq', 8, true);


--
-- Name: tax_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.tax_id_seq', 2, true);


--
-- Name: transaction_caisse_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.transaction_caisse_id_seq', 1, false);


--
-- Name: transaction_type_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.transaction_type_id_seq', 1, false);


--
-- Name: transporteur_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.transporteur_id_seq', 1, false);


--
-- Name: type_cash_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.type_cash_id_seq', 10, true);


--
-- Name: user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: symfony
--

SELECT pg_catalog.setval('public.user_id_seq', 2, true);


--
-- Name: Cart Cart_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."Cart"
    ADD CONSTRAINT "Cart_pkey" PRIMARY KEY (id);


--
-- Name: admin_settings admin_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.admin_settings
    ADD CONSTRAINT admin_settings_pkey PRIMARY KEY (id);


--
-- Name: adress adress_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.adress
    ADD CONSTRAINT adress_pkey PRIMARY KEY (id);


--
-- Name: caisse caisse_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.caisse
    ADD CONSTRAINT caisse_pkey PRIMARY KEY (id);


--
-- Name: carrier carrier_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.carrier
    ADD CONSTRAINT carrier_pkey PRIMARY KEY (id);


--
-- Name: cart_details cart_details_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.cart_details
    ADD CONSTRAINT cart_details_pkey PRIMARY KEY (id);


--
-- Name: cash_details cash_details_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.cash_details
    ADD CONSTRAINT cash_details_pkey PRIMARY KEY (id);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: collection_statistiques collection_statistiques_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.collection_statistiques
    ADD CONSTRAINT collection_statistiques_pkey PRIMARY KEY (id);


--
-- Name: collections collections_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT collections_pkey PRIMARY KEY (id);


--
-- Name: color color_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.color
    ADD CONSTRAINT color_pkey PRIMARY KEY (id);


--
-- Name: commande commande_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.commande
    ADD CONSTRAINT commande_pkey PRIMARY KEY (id);


--
-- Name: commande_statistiques commande_statistiques_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.commande_statistiques
    ADD CONSTRAINT commande_statistiques_pkey PRIMARY KEY (id);


--
-- Name: contact contact_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.contact
    ADD CONSTRAINT contact_pkey PRIMARY KEY (id);


--
-- Name: doctrine_migration_versions doctrine_migration_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.doctrine_migration_versions
    ADD CONSTRAINT doctrine_migration_versions_pkey PRIMARY KEY (version);


--
-- Name: fournisseur fournisseur_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.fournisseur
    ADD CONSTRAINT fournisseur_pkey PRIMARY KEY (id);


--
-- Name: frais_de_port frais_de_port_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.frais_de_port
    ADD CONSTRAINT frais_de_port_pkey PRIMARY KEY (id);


--
-- Name: home_slider home_slider_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.home_slider
    ADD CONSTRAINT home_slider_pkey PRIMARY KEY (id);


--
-- Name: inventory_movements inventory_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_pkey PRIMARY KEY (id);


--
-- Name: messenger_messages messenger_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.messenger_messages
    ADD CONSTRAINT messenger_messages_pkey PRIMARY KEY (id);


--
-- Name: movement_type movement_type_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.movement_type
    ADD CONSTRAINT movement_type_pkey PRIMARY KEY (id);


--
-- Name: note_de_frais note_de_frais_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.note_de_frais
    ADD CONSTRAINT note_de_frais_pkey PRIMARY KEY (id);


--
-- Name: order_items order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_pkey PRIMARY KEY (id);


--
-- Name: order order_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT order_pkey PRIMARY KEY (id);


--
-- Name: order_source order_source_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_source
    ADD CONSTRAINT order_source_pkey PRIMARY KEY (id);


--
-- Name: order_tax order_tax_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_tax
    ADD CONSTRAINT order_tax_pkey PRIMARY KEY (id);


--
-- Name: order_type order_type_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_type
    ADD CONSTRAINT order_type_pkey PRIMARY KEY (id);


--
-- Name: payment_method payment_method_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payment_method
    ADD CONSTRAINT payment_method_pkey PRIMARY KEY (id);


--
-- Name: payment_type payment_type_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payment_type
    ADD CONSTRAINT payment_type_pkey PRIMARY KEY (id);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: product_categories product_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT product_categories_pkey PRIMARY KEY (product_id, categories_id);


--
-- Name: product product_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product
    ADD CONSTRAINT product_pkey PRIMARY KEY (id);


--
-- Name: product_variant product_variant_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_variant
    ADD CONSTRAINT product_variant_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: related_product related_product_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.related_product
    ADD CONSTRAINT related_product_pkey PRIMARY KEY (id);


--
-- Name: reset_password_request reset_password_request_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.reset_password_request
    ADD CONSTRAINT reset_password_request_pkey PRIMARY KEY (id);


--
-- Name: reviews_product reviews_product_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.reviews_product
    ADD CONSTRAINT reviews_product_pkey PRIMARY KEY (id);


--
-- Name: size size_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.size
    ADD CONSTRAINT size_pkey PRIMARY KEY (id);


--
-- Name: square_config square_config_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.square_config
    ADD CONSTRAINT square_config_pkey PRIMARY KEY (id);


--
-- Name: status_commande status_commande_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.status_commande
    ADD CONSTRAINT status_commande_pkey PRIMARY KEY (id);


--
-- Name: status_payment status_payment_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.status_payment
    ADD CONSTRAINT status_payment_pkey PRIMARY KEY (id);


--
-- Name: style style_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.style
    ADD CONSTRAINT style_pkey PRIMARY KEY (id);


--
-- Name: tax tax_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.tax
    ADD CONSTRAINT tax_pkey PRIMARY KEY (id);


--
-- Name: transaction_caisse transaction_caisse_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_caisse
    ADD CONSTRAINT transaction_caisse_pkey PRIMARY KEY (id);


--
-- Name: transaction_type transaction_type_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_type
    ADD CONSTRAINT transaction_type_pkey PRIMARY KEY (id);


--
-- Name: transporteur transporteur_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transporteur
    ADD CONSTRAINT transporteur_pkey PRIMARY KEY (id);


--
-- Name: type_cash type_cash_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.type_cash
    ADD CONSTRAINT type_cash_pkey PRIMARY KEY (id);


--
-- Name: user user_pkey; Type: CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."user"
    ADD CONSTRAINT user_pkey PRIMARY KEY (id);


--
-- Name: idx_209aa41d4584665a; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_209aa41d4584665a ON public.product_variant USING btree (product_id);


--
-- Name: idx_209aa41d498da827; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_209aa41d498da827 ON public.product_variant USING btree (size_id);


--
-- Name: idx_209aa41d7ada1fb5; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_209aa41d7ada1fb5 ON public.product_variant USING btree (color_id);


--
-- Name: idx_2939a94c514956fd; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_2939a94c514956fd ON public.collection_statistiques USING btree (collection_id);


--
-- Name: idx_2cca19d6155b63dd; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_2cca19d6155b63dd ON public.cash_details USING btree (type_cash_id);


--
-- Name: idx_2cca19d6649365fc; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_2cca19d6649365fc ON public.cash_details USING btree (transaction_caisse_id);


--
-- Name: idx_5cecc7be84667448; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_5cecc7be84667448 ON public.adress USING btree (user_adress_id);


--
-- Name: idx_62809db0a80ef684; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_62809db0a80ef684 ON public.order_items USING btree (product_variant_id);


--
-- Name: idx_62809db0e24bfbc; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_62809db0e24bfbc ON public.order_items USING btree (order_associated_id);


--
-- Name: idx_65d29b325aa1164f; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_65d29b325aa1164f ON public.payments USING btree (payment_method_id);


--
-- Name: idx_65d29b327108aeb2; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_65d29b327108aeb2 ON public.payments USING btree (statut_payment_id);


--
-- Name: idx_65d29b32b7195eee; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_65d29b32b7195eee ON public.payments USING btree (order_payment_id);


--
-- Name: idx_65d29b32dc058279; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_65d29b32dc058279 ON public.payments USING btree (payment_type_id);


--
-- Name: idx_6eeaa67d242c7ad2; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_6eeaa67d242c7ad2 ON public.commande USING btree (collections_id);


--
-- Name: idx_6eeaa67d670c757f; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_6eeaa67d670c757f ON public.commande USING btree (fournisseur_id);


--
-- Name: idx_6fc5088797c86fa4; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_6fc5088797c86fa4 ON public.frais_de_port USING btree (transporteur_id);


--
-- Name: idx_75ea56e016ba31db; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_75ea56e016ba31db ON public.messenger_messages USING btree (delivered_at);


--
-- Name: idx_75ea56e0e3bd61ce; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_75ea56e0e3bd61ce ON public.messenger_messages USING btree (available_at);


--
-- Name: idx_75ea56e0fb7336f0; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_75ea56e0fb7336f0 ON public.messenger_messages USING btree (queue_name);


--
-- Name: idx_7ce748aa76ed395; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_7ce748aa76ed395 ON public.reset_password_request USING btree (user_id);


--
-- Name: idx_89e6102d82ea2e54; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_89e6102d82ea2e54 ON public.commande_statistiques USING btree (commande_id);


--
-- Name: idx_89e6102d97c86fa4; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_89e6102d97c86fa4 ON public.commande_statistiques USING btree (transporteur_id);


--
-- Name: idx_89fcc38dbcb5c6f5; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_89fcc38dbcb5c6f5 ON public.cart_details USING btree (carts_id);


--
-- Name: idx_a99419434584665a; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_a99419434584665a ON public.product_categories USING btree (product_id);


--
-- Name: idx_a9941943a21214b7; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_a9941943a21214b7 ON public.product_categories USING btree (categories_id);


--
-- Name: idx_ab91278942d8d3b5; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_ab91278942d8d3b5 ON public."Cart" USING btree (user_cart_id);


--
-- Name: idx_bf9f9c49a80ef684; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_bf9f9c49a80ef684 ON public.inventory_movements USING btree (product_variant_id);


--
-- Name: idx_bf9f9c49ea4ed04a; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_bf9f9c49ea4ed04a ON public.inventory_movements USING btree (movement_type_id);


--
-- Name: idx_c4de21882754735b; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_c4de21882754735b ON public.transaction_caisse USING btree (user_caisse_id);


--
-- Name: idx_c4de218827b4febf; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_c4de218827b4febf ON public.transaction_caisse USING btree (caisse_id);


--
-- Name: idx_c4de21884c3a3bb; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_c4de21884c3a3bb ON public.transaction_caisse USING btree (payment_id);


--
-- Name: idx_c4de21889b338547; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_c4de21889b338547 ON public.transaction_caisse USING btree (order_caisse_id);


--
-- Name: idx_c4de2188b3e6b071; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_c4de2188b3e6b071 ON public.transaction_caisse USING btree (transaction_type_id);


--
-- Name: idx_cddaf5167127340d; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_cddaf5167127340d ON public.order_tax USING btree (order_tax_id);


--
-- Name: idx_cddaf516b2a824d8; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_cddaf516b2a824d8 ON public.order_tax USING btree (tax_id);


--
-- Name: idx_d325d3ee4fa9a58b; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_d325d3ee4fa9a58b ON public.collections USING btree (user_collections_id);


--
-- Name: idx_d34a04ad82ea2e54; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_d34a04ad82ea2e54 ON public.product USING btree (commande_id);


--
-- Name: idx_d34a04adbacd6074; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_d34a04adbacd6074 ON public.product USING btree (style_id);


--
-- Name: idx_e0851d6c13f58654; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_e0851d6c13f58654 ON public.reviews_product USING btree (product_reviews_id);


--
-- Name: idx_e0851d6c3ece1b7f; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_e0851d6c3ece1b7f ON public.reviews_product USING btree (user_review_id);


--
-- Name: idx_e6eccf53514956fd; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_e6eccf53514956fd ON public.note_de_frais USING btree (collection_id);


--
-- Name: idx_ec53ce084584665a; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_ec53ce084584665a ON public.related_product USING btree (product_id);


--
-- Name: idx_f529939821dfc797; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_f529939821dfc797 ON public."order" USING btree (carrier_id);


--
-- Name: idx_f529939829bb6799; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_f529939829bb6799 ON public."order" USING btree (order_source_id);


--
-- Name: idx_f5299398333625d8; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_f5299398333625d8 ON public."order" USING btree (order_type_id);


--
-- Name: idx_f52993986bf700bd; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_f52993986bf700bd ON public."order" USING btree (status_id);


--
-- Name: idx_f52993989d86650f; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_f52993989d86650f ON public."order" USING btree (user_id_id);


--
-- Name: idx_f5299398c273a89b; Type: INDEX; Schema: public; Owner: symfony
--

CREATE INDEX idx_f5299398c273a89b ON public."order" USING btree (shipping_adress_id);


--
-- Name: uniq_6fc5088782ea2e54; Type: INDEX; Schema: public; Owner: symfony
--

CREATE UNIQUE INDEX uniq_6fc5088782ea2e54 ON public.frais_de_port USING btree (commande_id);


--
-- Name: uniq_8d93d649e7927c74; Type: INDEX; Schema: public; Owner: symfony
--

CREATE UNIQUE INDEX uniq_8d93d649e7927c74 ON public."user" USING btree (email);


--
-- Name: uniq_9bace7e1c74f2195; Type: INDEX; Schema: public; Owner: symfony
--

CREATE UNIQUE INDEX uniq_9bace7e1c74f2195 ON public.refresh_tokens USING btree (refresh_token);


--
-- Name: messenger_messages notify_trigger; Type: TRIGGER; Schema: public; Owner: symfony
--

CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON public.messenger_messages FOR EACH ROW EXECUTE FUNCTION public.notify_messenger_messages();


--
-- Name: product_variant fk_209aa41d4584665a; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_variant
    ADD CONSTRAINT fk_209aa41d4584665a FOREIGN KEY (product_id) REFERENCES public.product(id);


--
-- Name: product_variant fk_209aa41d498da827; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_variant
    ADD CONSTRAINT fk_209aa41d498da827 FOREIGN KEY (size_id) REFERENCES public.size(id);


--
-- Name: product_variant fk_209aa41d7ada1fb5; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_variant
    ADD CONSTRAINT fk_209aa41d7ada1fb5 FOREIGN KEY (color_id) REFERENCES public.color(id);


--
-- Name: collection_statistiques fk_2939a94c514956fd; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.collection_statistiques
    ADD CONSTRAINT fk_2939a94c514956fd FOREIGN KEY (collection_id) REFERENCES public.collections(id);


--
-- Name: cash_details fk_2cca19d6155b63dd; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.cash_details
    ADD CONSTRAINT fk_2cca19d6155b63dd FOREIGN KEY (type_cash_id) REFERENCES public.type_cash(id);


--
-- Name: cash_details fk_2cca19d6649365fc; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.cash_details
    ADD CONSTRAINT fk_2cca19d6649365fc FOREIGN KEY (transaction_caisse_id) REFERENCES public.transaction_caisse(id);


--
-- Name: adress fk_5cecc7be84667448; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.adress
    ADD CONSTRAINT fk_5cecc7be84667448 FOREIGN KEY (user_adress_id) REFERENCES public."user"(id);


--
-- Name: order_items fk_62809db0a80ef684; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT fk_62809db0a80ef684 FOREIGN KEY (product_variant_id) REFERENCES public.product_variant(id);


--
-- Name: order_items fk_62809db0e24bfbc; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT fk_62809db0e24bfbc FOREIGN KEY (order_associated_id) REFERENCES public."order"(id);


--
-- Name: payments fk_65d29b325aa1164f; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_65d29b325aa1164f FOREIGN KEY (payment_method_id) REFERENCES public.payment_method(id);


--
-- Name: payments fk_65d29b327108aeb2; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_65d29b327108aeb2 FOREIGN KEY (statut_payment_id) REFERENCES public.status_payment(id);


--
-- Name: payments fk_65d29b32b7195eee; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_65d29b32b7195eee FOREIGN KEY (order_payment_id) REFERENCES public."order"(id);


--
-- Name: payments fk_65d29b32dc058279; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_65d29b32dc058279 FOREIGN KEY (payment_type_id) REFERENCES public.payment_type(id);


--
-- Name: commande fk_6eeaa67d242c7ad2; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.commande
    ADD CONSTRAINT fk_6eeaa67d242c7ad2 FOREIGN KEY (collections_id) REFERENCES public.collections(id);


--
-- Name: commande fk_6eeaa67d670c757f; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.commande
    ADD CONSTRAINT fk_6eeaa67d670c757f FOREIGN KEY (fournisseur_id) REFERENCES public.fournisseur(id);


--
-- Name: frais_de_port fk_6fc5088782ea2e54; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.frais_de_port
    ADD CONSTRAINT fk_6fc5088782ea2e54 FOREIGN KEY (commande_id) REFERENCES public.commande(id);


--
-- Name: frais_de_port fk_6fc5088797c86fa4; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.frais_de_port
    ADD CONSTRAINT fk_6fc5088797c86fa4 FOREIGN KEY (transporteur_id) REFERENCES public.transporteur(id);


--
-- Name: reset_password_request fk_7ce748aa76ed395; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.reset_password_request
    ADD CONSTRAINT fk_7ce748aa76ed395 FOREIGN KEY (user_id) REFERENCES public."user"(id);


--
-- Name: commande_statistiques fk_89e6102d82ea2e54; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.commande_statistiques
    ADD CONSTRAINT fk_89e6102d82ea2e54 FOREIGN KEY (commande_id) REFERENCES public.commande(id);


--
-- Name: commande_statistiques fk_89e6102d97c86fa4; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.commande_statistiques
    ADD CONSTRAINT fk_89e6102d97c86fa4 FOREIGN KEY (transporteur_id) REFERENCES public.transporteur(id);


--
-- Name: cart_details fk_89fcc38dbcb5c6f5; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.cart_details
    ADD CONSTRAINT fk_89fcc38dbcb5c6f5 FOREIGN KEY (carts_id) REFERENCES public."Cart"(id);


--
-- Name: product_categories fk_a99419434584665a; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT fk_a99419434584665a FOREIGN KEY (product_id) REFERENCES public.product(id) ON DELETE CASCADE;


--
-- Name: product_categories fk_a9941943a21214b7; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product_categories
    ADD CONSTRAINT fk_a9941943a21214b7 FOREIGN KEY (categories_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: Cart fk_ab91278942d8d3b5; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."Cart"
    ADD CONSTRAINT fk_ab91278942d8d3b5 FOREIGN KEY (user_cart_id) REFERENCES public."user"(id);


--
-- Name: inventory_movements fk_bf9f9c49a80ef684; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT fk_bf9f9c49a80ef684 FOREIGN KEY (product_variant_id) REFERENCES public.product_variant(id);


--
-- Name: inventory_movements fk_bf9f9c49ea4ed04a; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT fk_bf9f9c49ea4ed04a FOREIGN KEY (movement_type_id) REFERENCES public.movement_type(id);


--
-- Name: transaction_caisse fk_c4de21882754735b; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_caisse
    ADD CONSTRAINT fk_c4de21882754735b FOREIGN KEY (user_caisse_id) REFERENCES public."user"(id);


--
-- Name: transaction_caisse fk_c4de218827b4febf; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_caisse
    ADD CONSTRAINT fk_c4de218827b4febf FOREIGN KEY (caisse_id) REFERENCES public.caisse(id);


--
-- Name: transaction_caisse fk_c4de21884c3a3bb; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_caisse
    ADD CONSTRAINT fk_c4de21884c3a3bb FOREIGN KEY (payment_id) REFERENCES public.payments(id);


--
-- Name: transaction_caisse fk_c4de21889b338547; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_caisse
    ADD CONSTRAINT fk_c4de21889b338547 FOREIGN KEY (order_caisse_id) REFERENCES public."order"(id);


--
-- Name: transaction_caisse fk_c4de2188b3e6b071; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.transaction_caisse
    ADD CONSTRAINT fk_c4de2188b3e6b071 FOREIGN KEY (transaction_type_id) REFERENCES public.transaction_type(id);


--
-- Name: order_tax fk_cddaf5167127340d; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_tax
    ADD CONSTRAINT fk_cddaf5167127340d FOREIGN KEY (order_tax_id) REFERENCES public."order"(id);


--
-- Name: order_tax fk_cddaf516b2a824d8; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.order_tax
    ADD CONSTRAINT fk_cddaf516b2a824d8 FOREIGN KEY (tax_id) REFERENCES public.tax(id);


--
-- Name: collections fk_d325d3ee4fa9a58b; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.collections
    ADD CONSTRAINT fk_d325d3ee4fa9a58b FOREIGN KEY (user_collections_id) REFERENCES public."user"(id);


--
-- Name: product fk_d34a04ad82ea2e54; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product
    ADD CONSTRAINT fk_d34a04ad82ea2e54 FOREIGN KEY (commande_id) REFERENCES public.commande(id);


--
-- Name: product fk_d34a04adbacd6074; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.product
    ADD CONSTRAINT fk_d34a04adbacd6074 FOREIGN KEY (style_id) REFERENCES public.style(id);


--
-- Name: reviews_product fk_e0851d6c13f58654; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.reviews_product
    ADD CONSTRAINT fk_e0851d6c13f58654 FOREIGN KEY (product_reviews_id) REFERENCES public.product(id);


--
-- Name: reviews_product fk_e0851d6c3ece1b7f; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.reviews_product
    ADD CONSTRAINT fk_e0851d6c3ece1b7f FOREIGN KEY (user_review_id) REFERENCES public."user"(id);


--
-- Name: note_de_frais fk_e6eccf53514956fd; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.note_de_frais
    ADD CONSTRAINT fk_e6eccf53514956fd FOREIGN KEY (collection_id) REFERENCES public.collections(id);


--
-- Name: related_product fk_ec53ce084584665a; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public.related_product
    ADD CONSTRAINT fk_ec53ce084584665a FOREIGN KEY (product_id) REFERENCES public.product(id);


--
-- Name: order fk_f529939821dfc797; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT fk_f529939821dfc797 FOREIGN KEY (carrier_id) REFERENCES public.carrier(id);


--
-- Name: order fk_f529939829bb6799; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT fk_f529939829bb6799 FOREIGN KEY (order_source_id) REFERENCES public.order_source(id);


--
-- Name: order fk_f5299398333625d8; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT fk_f5299398333625d8 FOREIGN KEY (order_type_id) REFERENCES public.order_type(id);


--
-- Name: order fk_f52993986bf700bd; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT fk_f52993986bf700bd FOREIGN KEY (status_id) REFERENCES public.status_commande(id);


--
-- Name: order fk_f52993989d86650f; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT fk_f52993989d86650f FOREIGN KEY (user_id_id) REFERENCES public."user"(id);


--
-- Name: order fk_f5299398c273a89b; Type: FK CONSTRAINT; Schema: public; Owner: symfony
--

ALTER TABLE ONLY public."order"
    ADD CONSTRAINT fk_f5299398c273a89b FOREIGN KEY (shipping_adress_id) REFERENCES public.adress(id);


--
-- PostgreSQL database dump complete
--


-- Auto-generated tenant demo
CREATE DATABASE "demo_db" ENCODING='UTF8' TEMPLATE=template0;
INSERT INTO tenants(code,name,dbname) VALUES('demo','Demo Tenant','demo_db');

-- Auto-generated tenant demo
CREATE DATABASE "demo_db" ENCODING='UTF8' TEMPLATE=template0;
INSERT INTO tenants(code,name,dbname) VALUES('demo','Demo Tenant','demo_db');

-- Auto-generated tenant demo
CREATE DATABASE "demo_db" ENCODING='UTF8' TEMPLATE=template0;
INSERT INTO tenants(code,name,dbname) VALUES('demo','Demo Tenant','demo_db');

-- Auto-generated tenant demo
CREATE DATABASE "demo_db" ENCODING='UTF8' TEMPLATE=template0;
INSERT INTO tenants(code,name,dbname) VALUES('demo','Demo Tenant','demo_db');

-- Auto-generated tenant demo10
CREATE DATABASE "demo_db10" ENCODING='UTF8' TEMPLATE=template0;
INSERT INTO tenants(code,name,dbname) VALUES('demo10','Demo Tenant 2','demo_db10');
