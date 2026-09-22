<?php
/**
 * Plugin Name: ARPEGE Global 0,25° — Tableaux et cartes
 * Plugin URI: https://github.com/alertesmeteo-hub/ARPEGE-0.25
 * Description: Module unique de cartes interactives et de prévisions ARPEGE de Météo-France pour la France métropolitaine et la Corse.
 * Version: 1.0.0
 * Author: Alertes Météo Hub
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ARP025_VERSION', '1.0.0');
define('ARP025_RELEASE_DATE', '22/09/2026');
define('ARP025_OPTION_BASE_URL', 'arp025_national_data_base_url');
define(
    'ARP025_DEFAULT_BASE_URL',
    'https://raw.githubusercontent.com/alertesmeteo-hub/ARPEGE-0.25/data'
);

// Auto-guérison du pipeline ARPEGE : si index.json est resté bloqué trop
// longtemps (cron GitHub Actions peu fiable), chaque chargement de la page
// relance côté serveur un nouveau run via workflow_dispatch. Le jeton
// GitHub reste EXCLUSIVEMENT côté serveur — à définir dans wp-config.php :
//   define('ARP025_GITHUB_TOKEN', 'github_pat_xxx...');
// Jeton « fine-grained », limité au dépôt alertesmeteo-hub/ARPEGE-0.25,
// permission « Actions » en Read and write.
define('ARP025_GITHUB_REPO', 'alertesmeteo-hub/ARPEGE-0.25');
define('ARP025_GITHUB_DATA_BRANCH', 'data');
define('ARP025_GITHUB_WORKFLOW_BRANCH', 'main');
define('ARP025_GITHUB_WORKFLOW_FILE', 'update-arpege.yml');
// ARPEGE n'est republié que 4x/jour (00/06/12/18 UTC, cron toutes les 3h,
// minute 17) : seuil plus large que pour AROME, cohérent avec ce rythme.
define('ARP025_STALE_THRESHOLD_MIN', 8 * 60);

add_action('wp_enqueue_scripts', 'arp025_register_assets');
add_action('admin_init', 'arp025_register_settings');
add_action('admin_menu', 'arp025_add_settings_page');
add_shortcode('arpege_025_meteo', 'arp025_render_shortcode');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'arp025_plugin_action_links');
add_action('wp_ajax_arp025_autoheal', 'arp025_handle_autoheal');
add_action('wp_ajax_nopriv_arp025_autoheal', 'arp025_handle_autoheal');

function arp025_handle_autoheal() {
    if (!defined('ARP025_GITHUB_TOKEN') || !ARP025_GITHUB_TOKEN) {
        wp_send_json_success(array('configured' => false));
    }

    if (get_transient('arp025_autoheal_lock')) {
        wp_send_json_success(array('skipped' => true));
    }
    set_transient('arp025_autoheal_lock', 1, 5 * MINUTE_IN_SECONDS);

    $generated_at = arp025_fetch_generated_at();
    if (null === $generated_at) {
        wp_send_json_success(array('configured' => true, 'checked' => false));
    }

    $age_minutes = (time() - $generated_at) / 60;
    if ($age_minutes <= ARP025_STALE_THRESHOLD_MIN) {
        wp_send_json_success(array('configured' => true, 'stale' => false, 'age_minutes' => round($age_minutes)));
    }

    if (get_transient('arp025_autoheal_cooldown')) {
        wp_send_json_success(array('configured' => true, 'stale' => true, 'triggered' => false, 'cooldown' => true));
    }
    set_transient('arp025_autoheal_cooldown', 1, 30 * MINUTE_IN_SECONDS);

    $triggered = arp025_trigger_workflow();
    wp_send_json_success(array('configured' => true, 'stale' => true, 'triggered' => $triggered));
}

function arp025_fetch_generated_at() {
    $url = 'https://api.github.com/repos/' . ARP025_GITHUB_REPO . '/contents/index.json'
        . '?ref=' . rawurlencode(ARP025_GITHUB_DATA_BRANCH);
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Accept'     => 'application/vnd.github.raw',
            'User-Agent' => 'arpege-025-meteofrance-autoheal',
        ),
        'timeout' => 8,
    ));
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        return null;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['generated_at'])) {
        return null;
    }
    $timestamp = strtotime($data['generated_at']);
    return $timestamp ? $timestamp : null;
}

function arp025_trigger_workflow() {
    $url = 'https://api.github.com/repos/' . ARP025_GITHUB_REPO . '/actions/workflows/'
        . rawurlencode(ARP025_GITHUB_WORKFLOW_FILE) . '/dispatches';
    $response = wp_remote_post($url, array(
        'headers' => array(
            'Accept'        => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . ARP025_GITHUB_TOKEN,
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'arpege-025-meteofrance-autoheal',
        ),
        'body'    => wp_json_encode(array('ref' => ARP025_GITHUB_WORKFLOW_BRANCH)),
        'timeout' => 8,
    ));
    if (is_wp_error($response)) {
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    return $code >= 200 && $code < 300;
}

function arp025_plugin_action_links($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('options-general.php?page=arpege-025-meteofrance')),
        esc_html__('Réglages', 'arpege-025-meteofrance')
    );
    array_unshift($links, $settings_link);

    $help_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('options-general.php?page=arpege-025-meteofrance')),
        esc_html__('Shortcodes / Aide', 'arpege-025-meteofrance')
    );
    array_unshift($links, $help_link);

    return $links;
}

function arp025_register_assets() {
    wp_register_style(
        'arp025-table',
        plugin_dir_url(__FILE__) . 'assets/arpege-meteo.css',
        array(),
        ARP025_VERSION
    );
    wp_register_script(
        'arp025-table',
        plugin_dir_url(__FILE__) . 'assets/arpege-meteo.js',
        array(),
        ARP025_VERSION,
        true
    );
    wp_register_style(
        'arp025-map',
        plugin_dir_url(__FILE__) . 'assets/arpege-map.css',
        array('arp025-table'),
        ARP025_VERSION
    );
    wp_register_script(
        'arp025-map',
        plugin_dir_url(__FILE__) . 'assets/arpege-map.js',
        array(),
        ARP025_VERSION,
        true
    );
    wp_localize_script('arp025-table', 'ARP025_AUTOHEAL', array(
        'url' => admin_url('admin-ajax.php?action=arp025_autoheal'),
    ));
}

function arp025_register_settings() {
    register_setting(
        'arp025_settings',
        ARP025_OPTION_BASE_URL,
        array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ARP025_DEFAULT_BASE_URL,
        )
    );

    add_settings_section(
        'arp025_main_section',
        'Source des données nationales',
        '__return_false',
        'arpege-025-meteofrance'
    );

    add_settings_field(
        'arp025_data_base_url_field',
        'Adresse du dossier de données',
        'arp025_render_url_field',
        'arpege-025-meteofrance',
        'arp025_main_section'
    );
}

function arp025_render_url_field() {
    $value = get_option(ARP025_OPTION_BASE_URL, ARP025_DEFAULT_BASE_URL);
    printf(
        '<input type="url" class="regular-text code" name="%1$s" value="%2$s" autocomplete="off">',
        esc_attr(ARP025_OPTION_BASE_URL),
        esc_attr($value)
    );
    echo '<p class="description">Conservez l’adresse proposée : elle pointe vers la branche nationale « data » du dépôt.</p>';
}

function arp025_add_settings_page() {
    add_options_page(
        'Tableau ARPEGE Global 0,25°',
        'ARPEGE 0,25°',
        'manage_options',
        'arpege-025-meteofrance',
        'arp025_render_settings_page'
    );
}

function arp025_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>ARPEGE Global 0,25°</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('arp025_settings');
            do_settings_sections('arpege-025-meteofrance');
            submit_button();
            ?>
        </form>
        <p><strong>Version du module : <?php echo esc_html(ARP025_VERSION); ?> (<?php echo esc_html(ARP025_RELEASE_DATE); ?>)</strong></p>
        <h2>Shortcode unique</h2>
        <p><code>[arpege_025_meteo]</code> : cartes interactives, prévisions générales, orages, neige et graphiques.</p>
        <p><code>[arpege_025_meteo code="75056" departement="75" ville="Paris" heures="48"]</code></p>
        <p><code>[arpege_025_meteo code="66136" departement="66" ville="Perpignan" selecteur="non"]</code> : une seule ville, sans recherche.</p>
        <p>Le visiteur peut ensuite rechercher n’importe quelle commune ou saisir un code postal.</p>
        <h2>Auto-guérison du pipeline</h2>
        <p>
            Statut : <strong><?php echo (defined('ARP025_GITHUB_TOKEN') && ARP025_GITHUB_TOKEN) ? '✅ Configurée' : '⚠️ Non configurée'; ?></strong>
        </p>
        <p>
            Si <code>index.json</code> reste bloqué plus de <?php echo esc_html((int) round(ARP025_STALE_THRESHOLD_MIN / 60)); ?> heures,
            chaque chargement de cette page relance automatiquement le pipeline sur GitHub. Pour l'activer, ajouter dans
            <code>wp-config.php</code> :
        </p>
        <p><code>define('ARP025_GITHUB_TOKEN', 'github_pat_xxx...');</code></p>
        <p>
            Jeton « fine-grained » GitHub, limité au dépôt <code>alertesmeteo-hub/ARPEGE-0.25</code>, permission
            « Actions : Read and write » uniquement. Il n'est jamais transmis au navigateur.
        </p>
    </div>
    <?php
}

function arp025_base_url() {
    $url = get_option(ARP025_OPTION_BASE_URL, ARP025_DEFAULT_BASE_URL);
    return untrailingslashit(apply_filters('arp025_national_data_base_url', $url));
}

function arp025_department_code($value) {
    $code = strtoupper(trim((string) $value));
    return preg_match('/^(?:\d{2}|2A|2B)$/', $code) ? $code : '66';
}

function arp025_commune_code($value) {
    $code = strtoupper(trim((string) $value));
    return preg_match('/^[0-9A-Z]{5}$/', $code) ? $code : '66136';
}

function arp025_unique_identifier() {
    if (function_exists('wp_unique_id')) {
        return wp_unique_id('arp025-city-');
    }
    return 'arp025-city-' . wp_rand(1000, 999999);
}

function arp025_map_variable($value) {
    $variable = strtolower(trim(sanitize_key((string) $value)));
    $allowed = array(
        'temperature',
        'temperature_ressentie',
        'thermometre_mouille',
        'point_rosee',
        'humidex',
        'pluie_1h',
        'pluie_cumul',
        'neige',
        'neige_au_sol',
        'equivalent_eau_neige',
        'eau_precipitable',
        'grele',
        'type_precipitation_severe',
        'vent',
        'rafales',
        'pression',
        'pression_surface',
        'nebulosite',
        'nuages_bas',
        'nuages_moyens',
        'nuages_eleves',
        'humidite',
        'mucape',
        'altitude',
        'temperature_min_2m',
        'temperature_max_2m',
        'temperature_surface',
        'couche_limite',
        'flux_sensible',
        'flux_latent',
        'rayonnement_solaire_descendant',
        'rayonnement_thermique_descendant',
    );
    return in_array($variable, $allowed, true) ? $variable : 'temperature';
}

function arp025_render_map_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'variable' => 'temperature',
            'hauteur' => '700',
            'titre' => 'Cartes ARPEGE France',
            'animation' => 'oui',
        ),
        $atts,
        'arpege_025_meteo'
    );

    $variable = arp025_map_variable($atts['variable']);
    $height = max(440, min(900, absint($atts['hauteur'])));
    $title = trim(sanitize_text_field($atts['titre']));
    if ($title === '') {
        $title = 'Cartes ARPEGE France';
    }
    $animation_value = strtolower(trim(sanitize_text_field($atts['animation'])));
    $animation = !in_array($animation_value, array('non', '0', 'false', 'off'), true);
    $map_id = function_exists('wp_unique_id')
        ? wp_unique_id('arp025-map-')
        : 'arp025-map-' . wp_rand(1000, 999999);

    wp_enqueue_style('arp025-map');
    wp_enqueue_script('arp025-map');

    ob_start();
    ?>
    <section
        id="<?php echo esc_attr($map_id); ?>"
        class="arp025-card arp025m-card"
        data-arp025m-app
        data-base-url="<?php echo esc_url(arp025_base_url()); ?>"
        data-variable="<?php echo esc_attr($variable); ?>"
        data-timezone="<?php echo esc_attr(wp_timezone_string()); ?>"
        data-animation="<?php echo $animation ? '1' : '0'; ?>"
        data-module-version="<?php echo esc_attr(ARP025_VERSION); ?>"
        style="--arp025m-height: <?php echo esc_attr($height); ?>px"
    >
        <header class="arp025-header arp025m-header">
            <div>
                <p class="arp025-kicker">MODÈLE HAUTE RÉSOLUTION • ÉCHÉANCES HORAIRES</p>
                <h2><?php echo esc_html($title); ?></h2>
                <p class="arp025-meta" data-arp025m-run>Chargement du dernier run ARPEGE…</p>
            </div>
            <div class="arp025-badge">ARPEGE<br><strong>0,25°</strong></div>
        </header>

        <div class="arp025m-toolbar">
            <div class="arp025m-field arp025m-layer-picker">
                <span>Paramètre</span>
                <button
                    type="button"
                    class="arp025m-layer-trigger"
                    data-arp025m-menu-toggle
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($map_id . '-layers'); ?>"
                >
                    <span data-arp025m-current-layer>Température à 2 m</span>
                    <span class="arp025m-layer-chevron" aria-hidden="true">⌄</span>
                </button>
            </div>
            <div class="arp025m-tools" aria-label="Outils de la carte">
                <button
                    type="button"
                    class="arp025m-tool-toggle"
                    data-arp025m-tool="zoom"
                    aria-pressed="false"
                    title="Afficher l’outil de capture d’image et l’épinglage de la valeur"
                >📷 Outil capture</button>
                <button
                    type="button"
                    class="arp025m-tool-toggle"
                    data-arp025m-tool="diagram"
                    aria-pressed="false"
                    title="Cliquer sur la carte pour afficher le diagramme d’un point"
                >📈 Diagramme</button>
            </div>
            <div class="arp025m-time-controls" aria-label="Navigation dans les échéances">
                <button type="button" data-arp025m-previous title="Échéance précédente" aria-label="Échéance précédente">◀</button>
                <button type="button" data-arp025m-play title="Lancer l’animation" aria-label="Lancer l’animation">▶</button>
                <button type="button" data-arp025m-next title="Échéance suivante" aria-label="Échéance suivante">▶</button>
            </div>
            <div class="arp025m-validity">
                <span>Prévision valable</span>
                <strong data-arp025m-validity>—</strong>
                <small data-arp025m-lead>—</small>
            </div>
        </div>

        <p class="arp025m-tool-hint" data-arp025m-tool-hint hidden></p>

        <div
            id="<?php echo esc_attr($map_id . '-layers'); ?>"
            class="arp025m-layer-menu"
            data-arp025m-layer-menu
            hidden
        >
            <div class="arp025m-layer-menu-head">
                <div>
                    <strong>Choisir une carte ARPEGE</strong>
                    <small>Uniquement les paramètres disponibles dans la production Météo-France</small>
                </div>
                <button type="button" data-arp025m-menu-close aria-label="Réduire le menu">×</button>
            </div>
            <div class="arp025m-layer-grid" data-arp025m-layer-grid></div>
        </div>

        <div class="arp025m-period-selector" data-arp025m-period hidden>
            <div class="arp025m-period-head">
                <div>
                    <strong data-arp025m-period-title>Période personnalisée</strong>
                    <small>Déplacez les deux curseurs pour choisir précisément le début et la fin.</small>
                </div>
                <span data-arp025m-period-summary>—</span>
            </div>
            <div class="arp025m-dual-range" data-arp025m-dual-range>
                <div class="arp025m-dual-range-track" aria-hidden="true"></div>
                <input data-arp025m-period-start type="range" min="0" max="1" value="0" step="1" aria-label="Début de la période">
                <input data-arp025m-period-end type="range" min="0" max="1" value="1" step="1" aria-label="Fin de la période">
            </div>
            <div class="arp025m-period-values">
                <span><small>Du</small><strong data-arp025m-period-start-label>—</strong></span>
                <span><small>Au</small><strong data-arp025m-period-end-label>—</strong></span>
            </div>
        </div>

        <p class="arp025-stale" data-arp025m-stale role="status" hidden>
            Attention : la dernière production disponible a plus de 8 heures.
        </p>

        <div class="arp025m-viewport" data-arp025m-viewport role="img" aria-label="Carte météo ARPEGE interactive">
            <div class="arp025m-scene" data-arp025m-scene>
                <canvas class="arp025m-weather-canvas" data-arp025m-weather aria-hidden="true"></canvas>
                <canvas class="arp025m-vector-canvas" data-arp025m-vectors aria-hidden="true"></canvas>
            </div>
            <canvas class="arp025m-label-canvas" data-arp025m-labels aria-hidden="true"></canvas>
            <div class="arp025m-probe" data-arp025m-probe hidden>
                <strong data-arp025m-probe-value>—</strong>
                <span data-arp025m-probe-label>Valeur ARPEGE</span>
            </div>
            <div class="arp025m-map-titlebar">
                <strong data-arp025m-map-title>Carte ARPEGE</strong>
                <span data-arp025m-map-run>Run ARPEGE —</span>
            </div>
            <div class="arp025m-map-date" data-arp025m-map-date>Échéance —</div>
            <div class="arp025m-map-buttons" aria-label="Commandes de zoom">
                <span class="arp025m-zoom-level" data-arp025m-zoom-level>100 %</span>
                <button type="button" data-arp025m-zoom-in title="Agrandir" aria-label="Agrandir">+</button>
                <button type="button" data-arp025m-zoom-out title="Réduire" aria-label="Réduire">−</button>
                <button type="button" data-arp025m-reset title="Recentrer" aria-label="Recentrer">⌂</button>
                <button type="button" data-arp025m-fullscreen title="Plein écran" aria-label="Plein écran">⛶</button>
            </div>
            <div class="arp025m-advanced-tools" data-arp025m-advanced-tools hidden aria-label="Outils avancés">
                <button type="button" data-arp025m-capture title="Capturer l’image affichée" aria-label="Capturer l’image affichée">📷 Capture PNG</button>
                <button type="button" data-arp025m-pin title="Épingler la valeur au clic" aria-label="Épingler la valeur au clic" aria-pressed="false">📌 Figer la valeur</button>
            </div>
            <div class="arp025m-diagram-popup" data-arp025m-diagram-popup hidden>
                <header>
                    <strong data-arp025m-diagram-title>—</strong>
                    <button type="button" data-arp025m-diagram-close aria-label="Fermer le diagramme">×</button>
                </header>
                <div class="arp025m-diagram-body" data-arp025m-diagram-body>
                    <p class="arp025m-diagram-status" data-arp025m-diagram-status>Chargement…</p>
                </div>
            </div>
            <div class="arp025m-legend" data-arp025m-legend aria-label="Légende de la carte"></div>
            <a class="arp025m-map-brand" href="https://www.alertes-meteo.com/" target="_blank" rel="noopener noreferrer">
                www.alertes-meteo.com • Module v<?php echo esc_html(ARP025_VERSION); ?> (<?php echo esc_html(ARP025_RELEASE_DATE); ?>)
            </a>
            <div class="arp025m-loading" data-arp025m-loading role="status">Chargement de la carte…</div>
            <div class="arp025m-error" data-arp025m-error role="alert" hidden></div>
        </div>

        <div class="arp025m-timeline" data-arp025m-timeline>
            <input data-arp025m-slider type="range" min="0" max="0" value="0" step="1" aria-label="Échéance de prévision">
            <div class="arp025m-timeline-labels"><span>Run</span><span>Échéance maximale</span></div>
        </div>

        <footer class="arp025-footer">
            <span data-arp025m-generated>Mise à jour en cours de lecture…</span>
            <span>
                Données météo directes :
                <a href="https://www.data.gouv.fr/datasets/paquets-arpege-resolution-0-25deg" target="_blank" rel="noopener noreferrer">ARPEGE 0,25° — Météo-France</a>
                • <a href="https://www.alertes-meteo.com/" target="_blank" rel="noopener noreferrer">www.alertes-meteo.com</a>
                • Module cartes v<?php echo esc_html(ARP025_VERSION); ?> (<?php echo esc_html(ARP025_RELEASE_DATE); ?>)
            </span>
        </footer>

        <noscript>
            <p class="arp025-message arp025-error">JavaScript doit être activé pour afficher les cartes.</p>
        </noscript>
    </section>
    <?php
    return ob_get_clean();
}

function arp025_render_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'ville' => 'Perpignan',
            'code' => '66136',
            'departement' => '66',
            'heures' => '102',
            'titre' => '',
            'selecteur' => 'oui',
        ),
        $atts,
        'arpege_025_meteo'
    );

    $hours = max(1, min(102, absint($atts['heures'])));
    $city_name = sanitize_text_field($atts['ville']);
    if ($city_name === '') {
        $city_name = 'Perpignan';
    }
    $city_code = arp025_commune_code($atts['code']);
    $department = arp025_department_code($atts['departement']);
    $title_prefix = trim(sanitize_text_field($atts['titre']));
    if ($title_prefix === '') {
        $title_prefix = 'Prévisions ARPEGE';
    }
    $selector_value = strtolower(trim(sanitize_text_field($atts['selecteur'])));
    $show_selector = !in_array($selector_value, array('non', '0', 'false', 'off'), true);

    $input_id = arp025_unique_identifier();
    $results_id = $input_id . '-results';
    $status_id = $input_id . '-status';

    wp_enqueue_style('arp025-table');
    wp_enqueue_script('arp025-table');
    wp_enqueue_style('arp025-map');
    wp_enqueue_script('arp025-map');

    ob_start();
    ?>
    <section
        class="arp025-card arp025-national"
        data-arp025-app
        data-base-url="<?php echo esc_url(arp025_base_url()); ?>"
        data-default-code="<?php echo esc_attr($city_code); ?>"
        data-default-department="<?php echo esc_attr($department); ?>"
        data-default-name="<?php echo esc_attr($city_name); ?>"
        data-hours="<?php echo esc_attr($hours); ?>"
        data-timezone="<?php echo esc_attr(wp_timezone_string()); ?>"
        data-title-prefix="<?php echo esc_attr($title_prefix); ?>"
        data-selector="<?php echo $show_selector ? '1' : '0'; ?>"
    >
        <header class="arp025-header">
            <div>
                <p class="arp025-kicker">MODÈLE HAUTE RÉSOLUTION • FRANCE MÉTROPOLITAINE</p>
                <h2 data-arp025-title><?php echo esc_html($title_prefix . ' — ' . $city_name); ?></h2>
                <p class="arp025-city-altitude" data-arp025-altitude>Altitude de <?php echo esc_html($city_name); ?> : chargement…</p>
                <p class="arp025-meta" data-arp025-meta>Chargement du dernier run ARPEGE…</p>
            </div>
            <div class="arp025-badge">ARPEGE<br><strong>0,25°</strong></div>
        </header>

        <div class="arp025-toolbar" <?php if (!$show_selector) : ?>hidden<?php endif; ?>>
            <div class="arp025-search">
                <label for="<?php echo esc_attr($input_id); ?>">Choisissez votre commune</label>
                <div class="arp025-search-control">
                    <span class="arp025-search-icon" aria-hidden="true">⌕</span>
                    <input
                        id="<?php echo esc_attr($input_id); ?>"
                        class="arp025-city-input"
                        type="search"
                        value="<?php echo esc_attr($city_name); ?>"
                        placeholder="Nom de commune ou code postal"
                        autocomplete="off"
                        spellcheck="false"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr($results_id); ?>"
                        aria-describedby="<?php echo esc_attr($status_id); ?>"
                    >
                </div>
                <button type="button" class="arp025-locate-button" data-arp025-locate>📍 Détecter ma ville</button>
                <div
                    id="<?php echo esc_attr($results_id); ?>"
                    class="arp025-search-results"
                    role="listbox"
                    hidden
                ></div>
                <p
                    id="<?php echo esc_attr($status_id); ?>"
                    class="arp025-search-status"
                    role="status"
                    aria-live="polite"
                >Saisissez au moins deux lettres ou un code postal.</p>
            </div>
            <div class="arp025-coverage">
                <strong>34 746 communes</strong>
                <span>Métropole et Corse</span>
            </div>
        </div>

        <p class="arp025-stale" data-arp025-stale role="status" hidden>
            Attention : la dernière mise à jour disponible a plus de 8 heures.
        </p>

        <div class="arp025-tabs" role="tablist" aria-label="Type de prévision ARPEGE">
            <button
                type="button"
                class="arp025-tab arp025-tab-map is-active"
                role="tab"
                aria-selected="true"
                data-arp025-tab="map"
            >🗺️ Cartes météo</button>
            <button
                type="button"
                class="arp025-tab"
                role="tab"
                aria-selected="false"
                data-arp025-tab="general"
            >🌤️ Prévisions générales</button>
            <button
                type="button"
                class="arp025-tab arp025-tab-storm"
                role="tab"
                aria-selected="false"
                data-arp025-tab="storms"
            >⛈️ Prévisions orages</button>
            <button
                type="button"
                class="arp025-tab arp025-tab-snow"
                role="tab"
                aria-selected="false"
                data-arp025-tab="snow"
            >❄️ Risque de neige</button>
        </div>

        <div class="arp025-panel arp025-map-panel" data-arp025-panel="map">
            <?php
            echo arp025_render_map_shortcode(
                array(
                    'variable' => 'temperature',
                    'hauteur' => '760',
                    'titre' => 'Cartes ARPEGE Global — résolution 0,25°',
                    'animation' => 'oui',
                )
            );
            ?>
        </div>

        <div class="arp025-panel" data-arp025-panel="general" hidden>
            <div class="arp025-table-wrap arp025-general-wrap" role="region" aria-label="Prévisions horaires générales" tabindex="0">
                <table class="arp025-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Heure</th>
                            <th scope="col">Temps</th>
                            <th scope="col">T°</th>
                            <th scope="col">Hum.</th>
                            <th scope="col">Pluie</th>
                            <th scope="col">Nuages</th>
                            <th scope="col">Vent</th>
                            <th scope="col">Rafales</th>
                            <th scope="col">Pression</th>
                        </tr>
                    </thead>
                    <tbody data-arp025-body-general>
                        <tr>
                            <td colspan="10" class="arp025-loading">Chargement des prévisions…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <section class="arp025-charts" data-arp025-charts aria-label="Diagrammes ARPEGE">
                <article class="arp025-chart-card">
                    <h3 data-arp025-chart-title-temperature>Diagramme températures (°C)</h3>
                    <div class="arp025-chart" data-arp025-chart-temperature></div>
                </article>
                <article class="arp025-chart-card">
                    <h3 data-arp025-chart-title-pressure>Diagramme pression ramenée au niveau de la mer (hPa)</h3>
                    <div class="arp025-chart" data-arp025-chart-pressure></div>
                </article>
                <article class="arp025-chart-card">
                    <h3 data-arp025-chart-title-rain>Diagramme précipitations (mm)</h3>
                    <p class="arp025-chart-total" data-arp025-rain-total>Précipitations cumulées : —</p>
                    <div class="arp025-chart" data-arp025-chart-rain></div>
                </article>
                <article class="arp025-chart-card">
                    <h3 data-arp025-chart-title-wind>Diagramme rafales et vent moyen</h3>
                    <div class="arp025-chart" data-arp025-chart-wind></div>
                </article>
            </section>
        </div>

        <div class="arp025-panel" data-arp025-panel="storms" hidden>
            <p class="arp025-storm-summary" data-arp025-storm-summary>
                Diagnostic convectif ARPEGE 0,25° : chargement…
            </p>
            <div class="arp025-top-scroll" data-arp025-top-scroll="storms" aria-label="Navigation horizontale du tableau orages" hidden><div></div></div>
            <div class="arp025-table-wrap arp025-storm-wrap" data-arp025-scroll-wrap="storms" role="region" aria-label="Prévisions horaires d'orages" tabindex="0">
                <table class="arp025-table arp025-storm-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Heure</th>
                            <th scope="col">Risque orage</th>
                            <th scope="col">MUCAPE</th>
                            <th scope="col">LCL estimé</th>
                            <th scope="col">Foudre</th>
                            <th scope="col">Grêle</th>
                            <th scope="col">Pluie conv.</th>
                            <th scope="col">Pluie 1 h</th>
                            <th scope="col">Rafales</th>
                            <th scope="col">Type</th>
                            <th scope="col">Détails</th>
                        </tr>
                    </thead>
                    <tbody data-arp025-body-storms>
                        <tr>
                            <td colspan="12" class="arp025-loading">Chargement du diagnostic orageux…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="arp025-storm-note">
                <strong>Lecture expert :</strong> la MUCAPE est une sortie directe ARPEGE (CAPE_INS). Le risque, la foudre, la grêle et le type d’orage sont des diagnostics dérivés du CAPE, clairement signalés ; aucune valeur indisponible n’est inventée (la réflectivité radar et le graupel ne sont pas publiés dans les paquets ARPEGE et ont été retirés).
            </p>
        </div>

        <div class="arp025-panel" data-arp025-panel="snow" hidden>
            <p class="arp025-snow-summary" data-arp025-snow-summary>
                Diagnostic neige ARPEGE 0,25° : chargement…
            </p>
            <div class="arp025-top-scroll" data-arp025-top-scroll="snow" aria-label="Navigation horizontale du tableau neige" hidden><div></div></div>
            <div class="arp025-table-wrap arp025-snow-wrap" data-arp025-scroll-wrap="snow" role="region" aria-label="Risque horaire de neige" tabindex="0">
                <table class="arp025-table arp025-snow-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Heure</th>
                            <th scope="col">Risque neige</th>
                            <th scope="col">Phase</th>
                            <th scope="col">Neige 1 h</th>
                            <th scope="col">Neige 3 h</th>
                            <th scope="col">Neige 6 h</th>
                            <th scope="col">Tenue</th>
                            <th scope="col">Pres. hPa</th>
                            <th scope="col">Hum.</th>
                            <th scope="col">Vent moy. / raf.</th>
                            <th scope="col">Cumul neige fraîche</th>
                            <th scope="col">Détails</th>
                        </tr>
                    </thead>
                    <tbody data-arp025-body-snow>
                        <tr>
                            <td colspan="13" class="arp025-loading">Chargement du risque de neige…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="arp025-snow-note">
                <strong>Lecture neige :</strong> les cumuls de neige sont des sorties directes ARPEGE. La neige fraîche et la tenue sont estimées à partir du cumul en eau, de la température à 2 m et de l’altitude du point de grille.
            </p>
        </div>

        <footer class="arp025-footer">
            <span data-arp025-generated>Mise à jour en cours de lecture…</span>
            <span>
                Données météo directes :
                <a href="https://www.data.gouv.fr/datasets/paquets-arpege-resolution-0-25deg" target="_blank" rel="noopener noreferrer">ARPEGE 0,25° — Météo-France</a>
                • Recherche des communes :
                <a href="https://geo.api.gouv.fr/decoupage-administratif/communes" target="_blank" rel="noopener noreferrer">API officielle française</a>
                • <a href="https://www.alertes-meteo.com/" target="_blank" rel="noopener noreferrer">www.alertes-meteo.com</a>
            </span>
            <span class="arp025-plugin-version">Module ARPEGE 0,25° v<?php echo esc_html(ARP025_VERSION); ?> (<?php echo esc_html(ARP025_RELEASE_DATE); ?>)</span>
        </footer>

        <noscript>
            <p class="arp025-message arp025-error">JavaScript doit être activé pour rechercher une commune.</p>
        </noscript>
    </section>
    <?php
    return ob_get_clean();
}
