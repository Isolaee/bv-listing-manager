<?php
/**
 * Plugin Name: BV Listing Manager
 * Description: ACF front-end listings + WooCommerce. Sets title, assigns category on process-listing, routes to correct product, publishes listing after payment, supports draft save, secure edit, and hide/republish for paid listings.
 * Version: 3.2.0
 */

if (!defined('ABSPATH')) exit;

/* =============================================================================
   0) BASIC HELPERS
============================================================================= */

/**
 * Map listing_type -> WC Product ID
 * Update these IDs to your real products.
 */
function bv_lm_get_product_for_listing_type($type) {
    $map = [
        'osakeanti' => 772,
        'osaketori' => 773,
        'velkakirja' => 1722,
    ];
    return isset($map[$type]) ? (int) $map[$type] : 0;
}

/**
 * Assign correct WP category based on listing_type.
 * osakeanti -> osakeannit
 * osaketori -> osaketori
 * velkakirja -> velkakirjat
 */
function bv_lm_set_category_for_listing($post_id, $listing_type) {

    $slug = '';
    if ($listing_type === 'osakeanti') {
        $slug = 'osakeannit';
    } elseif ($listing_type === 'osaketori') {
        $slug = 'osaketori';
    } elseif ($listing_type === 'velkakirja') {
        $slug = 'velkakirjat';
    }

    if (!$slug) return;

    $term = get_term_by('slug', $slug, 'category');
    if ($term && !is_wp_error($term)) {
        wp_set_post_terms((int)$post_id, [(int)$term->term_id], 'category', false);
    }
}

/**
 * Can current user manage listing?
 * Allowed: Admin (manage_options) OR Post Author
 */
if (!function_exists('bv_lm_user_can_manage_listing')) {
    function bv_lm_user_can_manage_listing($post_id) {

        $post_id = (int) $post_id;
        if (!$post_id) return false;

        if (!is_user_logged_in()) return false;

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') return false;

        if (current_user_can('manage_options')) return true;

        return ((int) $post->post_author === (int) get_current_user_id());
    }
}

/**
 * Secure Hide URL
 */
if (!function_exists('bv_lm_get_hide_url')) {
    function bv_lm_get_hide_url($post_id) {
        $post_id = (int) $post_id;
        return wp_nonce_url(
            admin_url('admin-post.php?action=bv_lm_hide_listing&post_id=' . $post_id),
            'bv_lm_hide_listing_' . $post_id
        );
    }
}

/**
 * Secure Republish URL
 */
if (!function_exists('bv_lm_get_republish_url')) {
    function bv_lm_get_republish_url($post_id) {
        $post_id = (int) $post_id;
        return wp_nonce_url(
            admin_url('admin-post.php?action=bv_lm_republish_listing&post_id=' . $post_id),
            'bv_lm_republish_listing_' . $post_id
        );
    }
}

/**
 * Helper: build the correct "resume editing" URL for a draft
 * Draft create pages accept ?post_id=ID to prefill fields.
 */
if (!function_exists('bv_lm_get_resume_url_for_draft')) {
    function bv_lm_get_resume_url_for_draft($post_id) {

        $post_id = (int) $post_id;

        // Prefer explicit draft meta
        $type = get_post_meta($post_id, '_bv_listing_type', true);

        // Fallback by category
        if (!$type) {
            if (has_term('Osakeannit', 'category', $post_id)) {
                $type = 'osakeanti';
            } elseif (has_term('Osaketori', 'category', $post_id)) {
                $type = 'osaketori';
            } elseif (has_term('Velkakirja', 'category', $post_id)) {
                $type = 'velkakirja';
            }
        }

        if ($type === 'osakeanti') {
            return add_query_arg(['post_id' => $post_id], home_url('/create-osakeanti/'));
        } elseif ($type === 'velkakirja') {
            return add_query_arg(['post_id' => $post_id], home_url('/create-velkakirja/'));
        }

        return add_query_arg(['post_id' => $post_id], home_url('/create-osaketori/'));
    }
}

/* =============================================================================
   1) ACF SAVE: SET POST TITLE
============================================================================= */

add_action('acf/save_post', function ($post_id) {

    if (is_admin()) return;
    if (get_post_type($post_id) !== 'post') return;

    if (!function_exists('get_field')) return;

    $title = (string) get_field('Ilmoituksen_otsikko', $post_id);
    if ($title === '') {
        $title = (string) get_field('Ilmoituksen_otsikko', $post_id);
    }

    if ($title !== '') {
        wp_update_post([
            'ID'         => (int) $post_id,
            'post_title' => wp_strip_all_tags($title),
        ]);
    }

}, 20);

/* =============================================================================
   2) /process-listing HANDLER: CATEGORY + CART + SESSION + CHECKOUT
============================================================================= */

add_action('template_redirect', function () {

    if (!is_page('process-listing')) return;

    if (!function_exists('WC')) wp_die('WooCommerce missing');

    $post_id      = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
    $listing_type = isset($_GET['listing_type']) ? sanitize_text_field((string) $_GET['listing_type']) : '';

    if (!$post_id || !$listing_type) {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    // Only author can pay for their listing
    if (!is_user_logged_in() || get_current_user_id() !== (int) $post->post_author) {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    // Assign correct category
    bv_lm_set_category_for_listing($post_id, $listing_type);

    // Pick product
    $product_id = bv_lm_get_product_for_listing_type($listing_type);
    if (!$product_id) {
        wp_die('BV LM: Invalid listing_type or product not configured.');
    }

    if (!WC()->cart) wc_load_cart();

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($product_id);
    wc_clear_notices();

    WC()->session->set('bv_pending_post_id', $post_id);

    wp_safe_redirect(wc_get_checkout_url());
    exit;
});

/* ===========================
Extra action to clear stale carts and suppress pop-ups
============================== */
add_action('template_redirect', function () {
    // Strip ?removed_item param on listing pages — it triggers
    // WooCommerce Blocks' "item removed / undo?" client-side banner
    if (isset($_GET['removed_item']) && is_page(['jata-ilmoitus', 'create-osaketori', 'create-osakeanti', 'create-velkakirja'])) {
        wp_safe_redirect(remove_query_arg('removed_item'));
        exit;
    }

    if (!function_exists('wc_clear_notices')) return;

    if (is_page(['create-osaketori', 'create-osakeanti', 'create-velkakirja'])) {
        wc_clear_notices();
    }
}, 5);

/* =============================================================================
   3) ATTACH LISTING ID TO ORDER META
============================================================================= */

add_action('woocommerce_checkout_create_order', function ($order, $data) {

    if (!function_exists('WC') || !WC()->session) return;

    $post_id = (int) WC()->session->get('bv_pending_post_id');

    if ($post_id) {
        $order->update_meta_data('_bv_pending_post_id', $post_id);
        $order->add_order_note("BV LM: listing attached on checkout_create_order: $post_id");
    } else {
        $order->add_order_note('BV LM: checkout_create_order – no bv_pending_post_id in session.');
    }

}, 10, 2);

/* =============================================================================
   4) PUBLISH AFTER PAYMENT AND MARK AS PAID
============================================================================= */

function bv_lm_publish_from_order($order_id, $source_hook = '') {

    if (!function_exists('wc_get_order')) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $order->add_order_note("BV LM: publish_from_order triggered via $source_hook.");

    $post_id = (int) $order->get_meta('_bv_pending_post_id');

    // Fallback: session
    if (!$post_id && function_exists('WC') && WC()->session) {
        $session_id = (int) WC()->session->get('bv_pending_post_id');
        if ($session_id) {
            $post_id = $session_id;
            $order->add_order_note("BV LM: fallback listing from session: $post_id");
        }
    }

    if (!$post_id) {
        $order->add_order_note('BV LM: no listing ID found on order.');
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        $order->add_order_note("BV LM: post $post_id not found.");
        return;
    }

    $status = get_post_status($post_id);
    if (!in_array($status, ['draft', 'pending'], true)) {
        $order->add_order_note("BV LM: post $post_id has status '$status', not publishing.");
        return;
    }

    $update = wp_update_post([
        'ID'          => (int) $post_id,
        'post_status' => 'publish',
    ], true);

    if (is_wp_error($update)) {
        $order->add_order_note('BV LM: error publishing post ' . $post_id . ': ' . $update->get_error_message());
    } else {
        $order->add_order_note("BV LM: post $post_id published successfully.");

        // Mark as paid so republish later will not require checkout
        update_post_meta($post_id, '_bv_listing_paid', 1);
        update_post_meta($post_id, '_bv_last_paid_order_id', (int) $order_id);
    }

    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('bv_pending_post_id');
    }
}

add_action('woocommerce_payment_complete', function ($order_id) {
    bv_lm_publish_from_order($order_id, 'payment_complete');
});
add_action('woocommerce_order_status_processing', function ($order_id) {
    bv_lm_publish_from_order($order_id, 'status_processing');
});
add_action('woocommerce_order_status_completed', function ($order_id) {
    bv_lm_publish_from_order($order_id, 'status_completed');
});
add_action('woocommerce_thankyou', function ($order_id) {
    if ($order_id) bv_lm_publish_from_order($order_id, 'thankyou');
});

/* =============================================================================
   5) FRONTEND EDIT PAGE SECURITY + EDIT BUTTON SHORTCODE
============================================================================= */

/**
 * Only author OR admin can edit.
 */
if (!function_exists('bv_user_can_edit_listing')) {
    function bv_user_can_edit_listing($post_id) {
        return bv_lm_user_can_manage_listing($post_id);
    }
}

/**
 * Where to send user when edit denied.
 */
if (!function_exists('bv_get_edit_denied_redirect_url')) {
    function bv_get_edit_denied_redirect_url() {
        if (function_exists('wc_get_account_endpoint_url')) {
            $base = wc_get_account_endpoint_url('my-listings');
        } else {
            $base = home_url('/oma-tili/');
        }
        return add_query_arg('bv_edit_denied', '1', $base);
    }
}

/**
 * Show Finnish message when edit denied.
 */
add_action('woocommerce_account_content', function () {
    if (empty($_GET['bv_edit_denied'])) return;
    echo '
    <div class="woocommerce-error" role="alert" style="margin-bottom:15px;">
        <strong>Et voi muokata tätä ilmoitusta.</strong><br>
        Sinulla ei ole oikeuksia muokata kyseistä ilmoitusta.
    </div>';
});

/**
 * Hard block /edit-listing-main/ if not allowed.
 */
add_action('template_redirect', function () {

    if (is_admin()) return;
    if (!is_page('edit-listing-main')) return;

    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

    if (!$post_id || !bv_user_can_edit_listing($post_id)) {
        wp_safe_redirect(bv_get_edit_denied_redirect_url());
        exit;
    }

}, 0);

/**
 * Block forced ACF save from unauthorized user.
 */
add_action('acf/save_post', function ($post_id) {

    if (is_admin()) return;
    if (get_post_type($post_id) !== 'post') return;

    $ref = wp_get_referer();
    if (!$ref || strpos($ref, 'edit-listing-main') === false) return;

    if (!bv_user_can_edit_listing($post_id)) {
        wp_die(
            'Et voi muokata tätä ilmoitusta. Sinulla ei ole oikeuksia muokata kyseistä ilmoitusta.',
            'Pääsy estetty',
            ['response' => 403]
        );
    }

}, 1);

/**
 * Edit button shortcode for single post templates
 * Usage: [bv_edit_ad_button]
 */
add_shortcode('bv_edit_ad_button', function () {

    if (!is_singular('post')) return '';

    $post_id = (int) get_the_ID();
    if (!$post_id) return '';

    if (!bv_user_can_edit_listing($post_id)) return '';

    $url = home_url('/edit-listing-main/?post_id=' . $post_id);
    return '<a class="oxy-button button" href="' . esc_url($url) . '">Muokkaa ilmoitusta</a>';
});

/* =============================================================================
   5B) EDIT LISTING PAGE: ACF FORM SHORTCODE (RESTORE ORIGINAL BEHAVIOR)
============================================================================= */

/**
 * Ensure ACF form head runs on edit page
 * Must run before any output
 */
add_action('template_redirect', function () {
    if (is_admin()) return;
    if (!is_page('edit-listing-main')) return;

    if (function_exists('acf_form_head')) {
        acf_form_head();
    }
}, 1);

/**
 * Validate that current user can edit this listing
 * Returns WP_Post or WP_Error
 */
if (!function_exists('bv_get_editable_listing')) {
    function bv_get_editable_listing($post_id) {

        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'Sinun pitää kirjautua sisään muokataksesi ilmoituksia.');
        }

        $post_id = (int) $post_id;
        if (!$post_id) {
            return new WP_Error('no_post', 'Virhe: ilmoitusta ei löytynyt.');
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('invalid_post', 'Virhe: virheellinen ilmoitus.');
        }

        if (!bv_lm_user_can_manage_listing($post_id)) {
            return new WP_Error('not_author', 'Et voi muokata tätä ilmoitusta.');
        }

        return $post;
    }
}

/**
 * Shortcode: [bv_edit_listing]
 * Renders ACF edit form with limited fields
 */
if (!function_exists('bv_edit_listing_shortcode')) {
    function bv_edit_listing_shortcode() {

        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        $post    = bv_get_editable_listing($post_id);

        if (is_wp_error($post)) {
            return esc_html($post->get_error_message());
        }

        $fields      = [];
        $heading_txt = '';
        $file_field  = '';

        if (has_term('Osakeannit', 'category', $post_id)) {

            $fields = [
                'ilmoituksen_otsikko',
                'yrityksen_nimi',
                'verkkosivu_url',
                'yrityksen_perustamisvuosi',
                'Luokitus',
                'yrityksen_toimiala',
                'sijainti',
                'sijanti_kaupunki',
                'henkilosto',
                'ilmoitusteksti',
                'liikevaihto',
                'tulos_viimeisin',
                'tavoitteet_2026',
                'valuaatio',
                'Osakeannin_koko',
                'minimisijoitus',
                'Maksimisijoitus',
                'osakeannin_kpl_maara',
                'osakkeiden_kpl_maara_ennen_antia',
                'osakeannin_tila',
                'haemme_lisaa_osaamista',
                'kuva',
                'videopitch',
                'markkinointimateriaali_tiedosto',
                'markkinointimateriaalin_nimi',
                'lisatieto',
            ];

            $heading_txt = '';
            $file_field  = 'markkinointimateriaali_tiedosto';

        } elseif (has_term('Osaketori', 'category', $post_id)) {

            $fields = [
                'ilmoituksen_otsikko',
                'yrityksen_nimi',
                'verkkosivu_url',
                'yrityksen_perustamisvuosi',
                'Luokitus',
                'yrityksen_toimiala',
                'sijainti',
                'sijainti_kaupunki',
                'henkilosto',
                'ilmoitusteksti_ot',
                'liikevaihto_viimeisin_ot',
                'tulos_viimeisin_ot',
                'onko_osakkeella_kaupankayntirajoituksia_ot',
                'lisatieto_kaupankayntirajoitukset_ot',
                'myytavien_osakkeiden_maara_ot',
                'osuus_yrityksen_osakkeista_ot',
                'valuaatio_ot',
                'hintapyynto_ot',
                'kuva',
                'markkinointimateriaali',
                'markkinointimateriaalin_nimi',
                'lisatieto_ot',
            ];

            $heading_txt = '';
            $file_field  = 'markkinointimateriaali_ot';
            
        } elseif (has_term('Velkakirjat', 'category', $post_id)) {

            $fields = [
                'ilmoituksen_otsikko',
                'yrityksen_nimi',
                'verkkosivu_url',
                'yrityksen_perustamisvuosi',
                'Luokitus',
                'yrityksen_toimiala',
                'sijainti',
                'sijainti_kaupunki',
                'henkilosto',
                'ilmoitusteksti',
                'liikevaihto',
                'tulos_viimeisin',
                'talouden_tila',
                'lainan_kokonaismaara',
                'velkakirjan_tyyppi',
                'minimimerkinta_velkakirja',
                'velkakirjan_erapaiva',
                'velkakirjan_lainaaika',
                'velkakirjan_korkotyyppi',
                'lainan_nimelliskorko',
                'korkojakso',
                'maksutiheys',
                'takaukset_vakuudet',
                'senioriteetti',
                'velkakirjan_oikeudet_rajoitukset',
                'konversion_aikaikkuna',
                'konversio_tapa',
                'konversiohinnan_maarittely_vk',
                'konversiohinta_fixed_vk',
                'konversiohinta_discount',
                'konversiohinta_valuationcap',
                'konversio_exit_triggerit',
                'konversio_tulos',
                'konversio_osakelaji',
                'osakkeiden_aanioikeus',
                'osakkeiden_osinkooikeus',
                'mahdolliset_rajoitukset',
                'laimennusvaikutus',
                'onko_muita_lainoja',
                'lainaan_liittyvat_riskit',
                'lainan_muut_ehdot',
                'velkakirjan_tila',
                'kuva',
                'videopitch',
                'markkinointimateriaali_tiedosto',
                'markkinointimateriaalin_nimi',
                'haluatko_lisata_lisatiedon',
                'lisatieto',
            ];

            $heading_txt = '';
            $file_field  = 'markkinointimateriaali_tiedosto';

        } else {
            return 'Tällä ilmoituksella ei ole Osakeannit, Osaketori tai velkakirjat kategoriaa.';
        }

        if (empty($fields)) {
            return 'Tälle ilmoitustyypille ei ole määritelty muokattavia kenttiä.';
        }

        if (!function_exists('acf_form')) {
            return 'ACF ei ole käytettävissä.';
        }

        ob_start();

        echo '<h2>' . esc_html($heading_txt) . '</h2>';

        // Show current file if present
        if ($file_field && function_exists('get_field')) {

            $file_value = get_field($file_field, $post_id);

            $file_url  = '';
            $file_name = '';

            if (is_array($file_value) && !empty($file_value['url'])) {
                $file_url  = $file_value['url'];
                $file_name = !empty($file_value['filename']) ? $file_value['filename'] : basename($file_url);
            } elseif (is_numeric($file_value)) {
                $file_url  = wp_get_attachment_url($file_value);
                $file_name = $file_url ? basename($file_url) : '';
            } elseif (is_string($file_value) && filter_var($file_value, FILTER_VALIDATE_URL)) {
                $file_url  = $file_value;
                $file_name = basename($file_url);
            }

            if ($file_url) {
                echo '<p><strong>Nykyinen markkinointimateriaali:</strong> ';
                echo '<a href="' . esc_url($file_url) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html($file_name) .
                '</a></p>';
            }
        }

        acf_form([
            'post_id'      => $post_id,
            'fields'       => $fields,
            'submit_value' => 'Tallenna muutokset',
            'uploader'     => 'basic',
            'return'       => get_permalink($post_id),
        ]);

        return ob_get_clean();
    }
}

/**
 * Register shortcode reliably for Gutenberg pages
 */
add_action('init', function () {
    add_shortcode('bv_edit_listing', 'bv_edit_listing_shortcode');
}, 1);

/**
 * Fallback: force shortcode parsing for edit page content
 * Helps if theme or builder bypasses normal shortcode rendering
 */
add_filter('the_content', function ($content) {
    if (is_admin()) return $content;
    if (!is_page('edit-listing-main')) return $content;

    if (strpos($content, '[bv_edit_listing]') !== false) {
        $content = do_shortcode($content);
    }
    return $content;
}, 99);

/* =============================================================================
   6) HIDE / REPUBLISH ACTIONS (INSIDE SAME PLUGIN)
============================================================================= */

/**
 * Hide listing: publish -> draft
 * Redirect to Draft listings
 */
add_action('admin_post_bv_lm_hide_listing', function () {

    if (!is_user_logged_in()) wp_die('Sinun täytyy olla kirjautuneena.');

    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
    if (!$post_id) wp_die('Virheellinen pyyntö.');

    $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'bv_lm_hide_listing_' . $post_id)) {
        wp_die('Tietoturvatarkistus epäonnistui.');
    }

    if (!bv_lm_user_can_manage_listing($post_id)) {
        wp_die('Sinulla ei ole oikeuksia tähän.');
    }

    if (get_post_status($post_id) !== 'publish') {
        wp_die('Vain julkaistun ilmoituksen voi piilottaa.');
    }

    wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);

    $redirect = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('draft-listings')
        : home_url('/oma-tili/draft-listings/');

    $redirect = add_query_arg('bv_notice', 'hidden', $redirect);

    wp_safe_redirect($redirect);
    exit;
});

/**
 * Republish listing: draft -> publish (only if paid)
 * Redirect to the post permalink
 */
add_action('admin_post_bv_lm_republish_listing', function () {

    if (!is_user_logged_in()) wp_die('Sinun täytyy olla kirjautuneena.');

    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
    if (!$post_id) wp_die('Virheellinen pyyntö.');

    $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'bv_lm_republish_listing_' . $post_id)) {
        wp_die('Tietoturvatarkistus epäonnistui.');
    }

    if (!bv_lm_user_can_manage_listing($post_id)) {
        wp_die('Sinulla ei ole oikeuksia tähän.');
    }

    if (get_post_status($post_id) !== 'draft') {
        wp_die('Vain luonnoksen voi julkaista uudelleen.');
    }

    $is_paid = (int) get_post_meta($post_id, '_bv_listing_paid', true);
    if (!$is_paid) {
        wp_die('Tätä ilmoitusta ei voi julkaista ilman maksua.');
    }

    // Ensure category remains correct
    $type = (string) get_post_meta($post_id, '_bv_listing_type', true);
    if ($type) {
        bv_lm_set_category_for_listing($post_id, $type);
    }

    wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);

    $redirect = add_query_arg('bv_notice', 'republished', get_permalink($post_id));
    wp_safe_redirect($redirect);
    exit;
});

/**
 * Optional unified button shortcode (hide OR republish depending on status)
 * Usage: [bv_hide_republish_button]
 */
add_shortcode('bv_hide_republish_button', function () {

    if (!is_singular('post')) return '';

    $post_id = (int) get_the_ID();
    if (!$post_id) return '';

    if (!bv_lm_user_can_manage_listing($post_id)) return '';

    $status  = get_post_status($post_id);
    $is_paid = (int) get_post_meta($post_id, '_bv_listing_paid', true);

    if ($status === 'publish') {
        $url = bv_lm_get_hide_url($post_id);
        return '<a class="oxy-button button" href="' . esc_url($url) . '" onclick="return confirm(\'Haluatko varmasti piilottaa tämän ilmoituksen?\');">Piilota ilmoitus</a>';
    }

    if ($status === 'draft' && $is_paid) {
        $url = bv_lm_get_republish_url($post_id);
        return '<a class="oxy-button button" href="' . esc_url($url) . '" onclick="return confirm(\'Haluatko julkaista ilmoituksen uudelleen?\');">Julkaise uudelleen</a>';
    }

    return '';
});

/**
 * Notices: shown when redirected with ?bv_notice=hidden or republished
 */
add_action('wp_footer', function () {

    if (empty($_GET['bv_notice'])) return;

    $notice = sanitize_key((string) $_GET['bv_notice']);

    $msg = '';
    if ($notice === 'hidden') {
        $msg = 'Ilmoitus piilotettiin. Voit julkaista sen uudelleen Luonnokset-välilehdeltä.';
    } elseif ($notice === 'republished') {
        $msg = 'Ilmoitus julkaistiin uudelleen onnistuneesti.';
    } else {
        return;
    }

    echo '<div class="woocommerce-message" role="alert" style="margin:15px 0;">' . esc_html($msg) . '</div>';
});

/* =============================================================================
   7) MY ACCOUNT ENDPOINTS + LISTING PAGES
==============================================================================*/

add_action('init', function () {
    add_rewrite_endpoint('my-listings', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('draft-listings', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('account-info', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('change-password', EP_ROOT | EP_PAGES);
});

add_filter('woocommerce_account_menu_items', function ($items) {

    $new = [];

    if (isset($items['orders'])) {
        $new['orders'] = 'Tehdyt ilmoitustilaukset';
    }

    $new['my-listings']     = 'Omat ilmoitukset';
    $new['draft-listings']  = 'Omat luonnokset';
    $new['hakuvahdit']      = 'Hakuvahdit';
    $new['yhteydenotot']      = 'Yhteydenotot';
    $new['edit-address']    = 'Omat tiedot';
    $new['change-password'] = 'Vaihda salasana';

    if (isset($items['customer-logout'])) {
        $new['customer-logout'] = $items['customer-logout'];
    }

    return $new;
});

/**
 * Force "Omat tiedot" and "Vaihda salasana" menu links to the default Woo edit account page
 * Target: /oma-tili/edit-account/
 */
add_filter('woocommerce_get_endpoint_url', function ($url, $endpoint, $value, $permalink) {

    if ($endpoint === 'account-info' || $endpoint === 'change-password') {
        return home_url('/oma-tili/edit-account/');
    }

    return $url;

}, 20, 4);

/**
 * My listings: published listings only
 */
add_action('woocommerce_account_my-listings_endpoint', function () {

    if (!is_user_logged_in()) {
        echo 'Kirjaudu sisään nähdäksesi ilmoituksesi.';
        return;
    }

    $user_id = get_current_user_id();
    $paged   = get_query_var('paged') ? (int) get_query_var('paged') : 1;

    $query = new WP_Query([
        'post_type'      => 'post',
        'author'         => current_user_can('manage_options')?'':$user_id,
        'post_status'    => ['publish'],
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 8,
        'paged'          => $paged,
        'tax_query'      => [[
            'taxonomy' => 'category',
            'field'    => 'name',
            'terms'    => ['Osakeannit', 'Osaketori', 'Velkakirjat'],
        ]],
    ]);

    echo '<h2>Omat ilmoitukset</h2>';
    echo '<div class="my-listing-item" style="margin-top:20px; border-top:1px solid #ddd; padding-top:10px; padding-bottom:10px;">';
    
    if (!$query->have_posts()) {
        echo 'Sinulla ei ole vielä ilmoituksia.';
        return;
    }

    echo '<div class="my-listings">';

    while ($query->have_posts()) {
        $query->the_post();

        $post_id = (int) get_the_ID();

        $type_label = '';
        if (has_term('Osakeannit', 'category', $post_id)) {
            $type_label = 'Osakeanti-ilmoitus';
        } elseif (has_term('Osaketori', 'category', $post_id)) {
            $type_label = 'Osaketori-ilmoitus';
        } elseif (has_term('Velkakirja', 'category', $post_id)) {
            $type_label = 'Velkakirja-ilmoitus';
        }

        $created_ts = get_post_time('U', true, $post_id);
        $expiry_ts  = $created_ts + 90 * DAY_IN_SECONDS;
        $expiry_str = date_i18n('d.m.Y', $expiry_ts);

        echo '<div class="my-listing-item" style="margin-bottom:20px; border-bottom:1px solid #ddd; padding-bottom:10px;">';
        echo '<h3><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html(get_the_title()) . '</a></h3>';

        if ($type_label) echo '<p>' . esc_html($type_label) . '</p>';
        
         // New feature, post views
        if(function_exists('the_views')) {
          // $display=false param is mandatory is to return only view number as text.
              echo '<p>Kävijöitä: ' . esc_html( the_views( $display = false ) ) . '</p>';
        }

        echo '<p>Voimassa: ' . esc_html($expiry_str) . ' asti</p>';

        echo '<p style="display:flex; gap:12px; flex-wrap:wrap;">';
        echo '<a href="' . esc_url(home_url('/edit-listing-main/?post_id=' . $post_id)) . '">Muokkaa ilmoitusta</a>';

        $hide_url = bv_lm_get_hide_url($post_id);
        echo '<a href="' . esc_url($hide_url) . '" onclick="return confirm(\'Haluatko varmasti piilottaa tämän ilmoituksen?\');">Piilota ilmoitus</a>';
        echo '</p>';

        echo '</div>';
    }

    echo '</div>';

    $pagination = paginate_links([
        'base'    => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
        'format'  => 'paged=%#%',
        'current' => max(1, $paged),
        'total'   => $query->max_num_pages,
        'type'    => 'plain',
        'prev_text'    => '← Edellinen', // Custom "Previous" text
        'next_text'    => 'Seuraava →',     // Custom "Next" text
        'mid_size'     => 2,            // Show 2 numbers on each side of current
        'end_size'     => 1,            // Show 1 number at start and end
    ]);

    if ($pagination) {
        echo '<div class="my-listings-pagination">' . $pagination . '</div>';
    }

    wp_reset_postdata();
});

/* =============================================================================
   8) DRAFT LISTINGS: DELETE + REPUBLISH OR RESUME
============================================================================= */

/**
 * Delete draft handler
 */
add_action('template_redirect', function () {

    if (!is_user_logged_in()) return;
    if (!function_exists('is_account_page') || !is_account_page()) return;

    $post_id = isset($_GET['bv_delete_draft']) ? (int) $_GET['bv_delete_draft'] : 0;
    if (!$post_id) return;

    $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'bv_delete_draft_' . $post_id)) {
        wp_die('Virheellinen pyyntö.');
    }

    $p = get_post($post_id);
    if (!$p || $p->post_type !== 'post') wp_die('Ilmoitusta ei löytynyt.');

    if (get_post_status($post_id) !== 'draft') wp_die('Vain luonnoksen voi poistaa.');

    if ((int) $p->post_author !== get_current_user_id()) wp_die('Et voi poistaa tätä luonnosta.');

    wp_trash_post($post_id);

    $redirect = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('draft-listings')
        : home_url('/oma-tili/draft-listings/');

    $redirect = add_query_arg('deleted', '1', $redirect);

    wp_safe_redirect($redirect);
    exit;
});

/**
 * Draft listings endpoint output
 */
add_action('woocommerce_account_draft-listings_endpoint', function () {

    if (!is_user_logged_in()) {
        echo 'Kirjaudu sisään nähdäksesi luonnoksesi.';
        return;
    }

    if (!empty($_GET['deleted']) && $_GET['deleted'] === '1') {
        echo '<div class="woocommerce-message" role="alert" style="margin-bottom:15px;">Luonnos poistettu.</div>';
    }

    $user_id = get_current_user_id();
    $paged   = get_query_var('paged') ? (int) get_query_var('paged') : 1;

    $query = new WP_Query([
        'post_type'      => 'post',
        'author'         => $user_id,
        'post_status'    => ['draft'],
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 8,
        'paged'          => $paged,
        'tax_query'      => [[
            'taxonomy' => 'category',
            'field'    => 'name',
            'terms'    => ['Osakeannit', 'Osaketori', 'Velkakirjat'],
        ]],
    ]);

    echo '<h2>Omat luonnokset</h2>';
    echo '<div class="my-listing-item" style="margin-top:20px; border-top:1px solid #ddd; padding-top:10px; padding-bottom:10px;">';

    if (!$query->have_posts()) {
        echo 'Sinulla ei ole vielä luonnoksia.';
        return;
    }

    echo '<div class="my-listings">';

    while ($query->have_posts()) {
        $query->the_post();

        $post_id = (int) get_the_ID();

        $category_name = '';
        if (has_term('Osakeannit', 'category', $post_id)) {
            $category_name = 'Osakeannit';
        } elseif (has_term('Osaketori', 'category', $post_id)) {
            $category_name = 'Osaketori';
        } elseif (has_term('Velkakirjat', 'category', $post_id)) {
            $category_name = 'Velkakirjat';
        }

        $type_label = $category_name ? ($category_name . '-ilmoitus') : 'Luonnos';

        // Expiry
        $dt = get_post_datetime($post_id);
        if (!$dt) $dt = get_post_datetime($post_id, 'modified');
        $created_ts = $dt ? $dt->getTimestamp() : time();
        $expiry_ts  = $created_ts + (90 * DAY_IN_SECONDS);
        $expiry_str = date_i18n('d.m.Y', $expiry_ts);

        $title = get_the_title();
        $title = $title ? $title : 'Luonnos';

        $is_paid = (int) get_post_meta($post_id, '_bv_listing_paid', true);

        // Action URLs
        $resume_url = bv_lm_get_resume_url_for_draft($post_id);

        $delete_url = add_query_arg(
            [
                'bv_delete_draft' => $post_id,
                '_wpnonce'        => wp_create_nonce('bv_delete_draft_' . $post_id),
            ],
            function_exists('wc_get_account_endpoint_url')
                ? wc_get_account_endpoint_url('draft-listings')
                : home_url('/oma-tili/draft-listings/')
        );

        echo '<div class="my-listing-item" style="margin-bottom:20px; border-bottom:1px solid #ddd; padding-bottom:10px;">';
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<p>' . esc_html($type_label) . '</p>';

        if ($category_name) {
            echo '<p>Kategoria: ' . esc_html($category_name) . '</p>';
        }

        echo '<p>Voimassa: ' . esc_html($expiry_str) . ' asti</p>';

        echo '<p style="display:flex; gap:12px; flex-wrap:wrap;">';

        // Paid hidden listing -> republish; otherwise normal resume flow
        if ($is_paid) {
            $republish_url = bv_lm_get_republish_url($post_id);
            echo '<a href="' . esc_url($republish_url) . '" onclick="return confirm(\'Haluatko julkaista ilmoituksen uudelleen?\');">Julkaise uudelleen</a>';
        } else {
            echo '<a href="' . esc_url($resume_url) . '">Jatka muokkausta</a>';
        }

        // Delete appears only once
        echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Haluatko varmasti poistaa tämän luonnoksen?\');">Poista</a>';

        echo '</p>';
        echo '</div>';
    }

    echo '</div>';

    $pagination = paginate_links([
        'base'    => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
        'format'  => '?paged=%#%',
        'current' => max(1, $paged),
        'total'   => $query->max_num_pages,
        'type'    => 'plain',
    ]);

    if ($pagination) {
        echo '<div class="my-listings-pagination">' . $pagination . '</div>';
    }

    wp_reset_postdata();
});

/* =============================================================================
   9) DRAFT SAVE AJAX + SUPPRESS BEFOREUNLOAD POPUP
============================================================================= */

add_action('wp_ajax_bv_save_listing_draft', function () {

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in'], 401);
    }

    $nonce = isset($_POST['security']) ? (string) $_POST['security'] : '';
    if (!wp_verify_nonce($nonce, 'bv_save_draft')) {
        wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $user_id = get_current_user_id();

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    // Create draft if no post_id yet
    if (!$post_id) {

        $post_id = wp_insert_post([
            'post_type'   => 'post',
            'post_status' => 'draft',
            'post_author' => $user_id,
            'post_title'  => '',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error(['message' => 'Post creation failed'], 500);
        }

    } else {

        $p = get_post($post_id);
        if (!$p || $p->post_type !== 'post') {
            wp_send_json_error(['message' => 'Invalid post'], 404);
        }
        if ((int) $p->post_author !== $user_id) {
            wp_send_json_error(['message' => 'Not allowed'], 403);
        }

        // Keep as draft
        if (get_post_status($post_id) !== 'draft') {
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        }
    }

    // Listing type + category
    $type = isset($_POST['bv_listing_type']) ? sanitize_text_field((string) $_POST['bv_listing_type']) : '';
    if ($type && in_array($type, ['osakeanti', 'osaketori', 'velkakirja'], true)) {
        update_post_meta($post_id, '_bv_listing_type', $type);
        bv_lm_set_category_for_listing($post_id, $type);
    }

    // Save ACF fields
    if (!empty($_POST['acf']) && is_array($_POST['acf']) && function_exists('update_field')) {
        foreach ($_POST['acf'] as $field_key => $value) {
            update_field($field_key, $value, $post_id);
        }
        
            // Save ACF FILE uploads (image/file fields) coming via FormData
    if (!empty($_FILES['acf']) && !empty($_FILES['acf']['name']) && is_array($_FILES['acf']['name'])) {

        // Media handling helpers
        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        foreach ($_FILES['acf']['name'] as $field_key => $name) {

            // Skip if no file selected
            if (empty($name)) {
                continue;
            }

            // Skip if upload errored
            $error = isset($_FILES['acf']['error'][$field_key]) ? (int) $_FILES['acf']['error'][$field_key] : UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            // Rebuild a normal $_FILES entry for media_handle_upload
            $tmp = [
                'name'     => $_FILES['acf']['name'][$field_key],
                'type'     => $_FILES['acf']['type'][$field_key],
                'tmp_name' => $_FILES['acf']['tmp_name'][$field_key],
                'error'    => $_FILES['acf']['error'][$field_key],
                'size'     => $_FILES['acf']['size'][$field_key],
            ];

            // Put it into a temporary key so WP can process it
            $_FILES['bv_acf_upload'] = $tmp;

            // Upload into Media Library and attach to the listing post
            $attachment_id = media_handle_upload('bv_acf_upload', $post_id);

            // Clean temp key
            unset($_FILES['bv_acf_upload']);

            if (is_wp_error($attachment_id) || !$attachment_id) {
                continue;
            }

            // Store attachment ID into the ACF field
            if (function_exists('update_field')) {
                update_field($field_key, (int) $attachment_id, $post_id);
            }
        }
    }
    }

    // Update WP title after saving fields
    $title = '';
    if (function_exists('get_field')) {
        $title = (string) get_field('Ilmoituksen_otsikko', $post_id);
        if ($title === '') $title = (string) get_field('Ilmoituksen_otsikko', $post_id);
    }
    if ($title !== '') {
        wp_update_post(['ID' => $post_id, 'post_title' => wp_strip_all_tags($title)]);
    }

    wp_send_json_success(['post_id' => (int) $post_id]);
});

add_action('wp_footer', function () {

    if (!is_page(['create-osaketori', 'create-osakeanti', 'create-velkakirja'])) return;

    $nonce = wp_create_nonce('bv_save_draft');
    $ajax  = admin_url('admin-ajax.php');

    echo "<script>
    (function () {

        function currentPostId() {
            try {
                var u = new URL(window.location.href);
                return u.searchParams.get('post_id') || '';
            } catch (e) { return ''; }
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.bv-save-draft');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            // suppress beforeunload dialogs during save
            window.__bvSavingDraft = true;
            window.onbeforeunload = null;

            var form = btn.closest('form');
            if (!form) return;

            var fd = new FormData(form);

            var pid = currentPostId();
            if (pid) fd.append('post_id', pid);

            fd.append('action', 'bv_save_listing_draft');
            fd.append('security', " . json_encode($nonce) . ");

            btn.disabled = true;

            fetch(" . json_encode($ajax) . ", {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {

                btn.disabled = false;

                if (!json || !json.success) {
                    window.__bvSavingDraft = false;
                    alert((json && json.data && json.data.message) ? json.data.message : 'Draft save failed');
                    return;
                }

                var url = new URL(window.location.href);
                url.searchParams.set('post_id', json.data.post_id);
                url.searchParams.set('saved', '1');
                window.location.href = url.toString();
            })
            .catch(function () {
                btn.disabled = false;
                window.__bvSavingDraft = false;
                alert('Draft save failed');
            });

        }, true);

    })();
    </script>";
}, 9999);

/**
 * Suppress browser Leave site confirmation ONLY during draft save redirect.
 */
add_action('wp_head', function () {

    if (!is_page(['create-osaketori', 'create-osakeanti', 'create-velkakirja'])) return;
    ?>
    <script>
    (function () {

        window.__bvSavingDraft = false;

        window.addEventListener('beforeunload', function (e) {

            if (!window.__bvSavingDraft) return;

            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }

            try { delete e.returnValue; } catch (err) {}
            e.returnValue = undefined;

        }, true);

    })();
    </script>
    <?php
}, 0);


/**
 * Make WooCommerce account email field read-only on Edit Account page
 */
add_action('wp_footer', function () {

    if (!function_exists('is_account_page') || !is_account_page()) return;
    if (!is_wc_endpoint_url('edit-account')) return;
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var emailField = document.querySelector('input#account_email');
            if (emailField) {
                emailField.setAttribute('readonly', 'readonly');
                emailField.style.backgroundColor = '#f5f5f5';
                emailField.style.cursor = 'not-allowed';
            }
        });
    </script>
    <?php
});

/**
 * Prevent account email from being changed
 */
add_action('woocommerce_save_account_details', function ($user_id) {

    if (!isset($_POST['account_email'])) return;

    $user = get_user_by('id', $user_id);
    if (!$user) return;

    // Force original email back
    $_POST['account_email'] = $user->user_email;

}, 1);