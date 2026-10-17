<?php
/**
 * Plugin Name: RAR WP Commerce Studio AI
 * Plugin URI: https://github.com/ruhulaminrevens/RAR-WP-Commerce-Studio-AI
 * Description: Free-first WooCommerce SEO, manual AI workflows, opt-in AI API and reversible product image tools.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: RAR
 * License: GPL-2.0-or-later
 * Text Domain: rar-wp-commerce-studio-ai
 */
defined('ABSPATH') || exit;

final class RAR_WP_Commerce_Studio_AI {
    const VERSION = '0.1.0';
    const SLUG = 'rar-wp-commerce-studio-ai';
    const OPTION = 'rar_studio_settings';
    const SEO_KEYS = array('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw');

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        foreach (array('preview', 'apply_seo', 'restore_seo', 'save_settings', 'generate_api', 'create_image', 'apply_image', 'restore_image') as $action) {
            add_action('admin_post_rar_studio_' . $action, array(__CLASS__, $action));
        }
    }

    private static function defaults() {
        return array(
            'api_enabled' => 0, 'provider' => 'gemini', 'daily_limit' => 5,
            'gemini_enabled' => 0, 'openai_enabled' => 0, 'claude_enabled' => 0,
            'gemini_model' => 'gemini-2.5-flash-lite',
            'openai_model' => 'gpt-4.1-mini',
            'claude_model' => 'claude-sonnet-4-5'
        );
    }

    private static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
    }

    private static function url($id = 0, $notice = '') {
        $args = array('page' => self::SLUG);
        if ($id) { $args['product_id'] = absint($id); }
        if ($notice) { $args['rar_notice'] = $notice; }
        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function require_product() {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Insufficient permissions.', 'rar-wp-commerce-studio-ai'), '', array('response' => 403)); }
        $id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        check_admin_referer('rar_studio_product_' . $id);
        if (!$id || !current_user_can('edit_post', $id) || !function_exists('wc_get_product')) {
            wp_die(esc_html__('Product access denied.', 'rar-wp-commerce-studio-ai'), '', array('response' => 403));
        }
        $product = wc_get_product($id);
        if (!$product) { wp_die(esc_html__('Product not found.', 'rar-wp-commerce-studio-ai'), '', array('response' => 404)); }
        return $product;
    }

    private static function redirect($id, $message) {
        wp_safe_redirect(self::url($id, $message));
        exit;
    }

    public static function menu() {
        add_menu_page('RAR Commerce Studio', 'RAR Commerce Studio', 'manage_woocommerce', self::SLUG, array(__CLASS__, 'page'), 'dashicons-superhero-alt', 56);
    }

    public static function assets($hook) {
        if ('toplevel_page_' . self::SLUG !== $hook) { return; }
        wp_enqueue_style('rar-studio-admin', plugins_url('assets/admin.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('rar-studio-admin', plugins_url('assets/admin.js', __FILE__), array(), self::VERSION, true);
    }

    private static function text_excerpt($value, $length) {
        $value = preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value));
        return wp_html_excerpt(trim($value), $length, '…');
    }

    private static function local_seo($product) {
        $name = self::text_excerpt($product->get_name(), 70);
        $base = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
        $desc = self::text_excerpt($base, 153);
        if (!$desc) { $desc = 'Explore ' . $name . ' and its details on our online shop.'; }
        return array(
            '_yoast_wpseo_title' => self::text_excerpt($name . ' | ' . get_bloginfo('name'), 65),
            '_yoast_wpseo_metadesc' => $desc,
            '_yoast_wpseo_focuskw' => self::text_excerpt($product->get_name(), 70)
        );
    }

    private static function fingerprint($product) {
        $values = array($product->get_id(), $product->get_name(), $product->get_short_description(), $product->get_description());
        foreach (self::SEO_KEYS as $key) { $values[] = get_post_meta($product->get_id(), $key, true); }
        return hash('sha256', wp_json_encode($values));
    }

    private static function store_preview($product, $fields, $source) {
        $values = array();
        foreach (self::SEO_KEYS as $key) { $values[$key] = sanitize_text_field((string) ($fields[$key] ?? '')); }
        set_transient('rar_studio_preview_' . get_current_user_id() . '_' . $product->get_id(), array(
            'fingerprint' => self::fingerprint($product), 'fields' => $values,
            'source' => $source, 'at' => time()
        ), 30 * MINUTE_IN_SECONDS);
    }

    public static function preview() {
        $product = self::require_product();
        self::store_preview($product, self::local_seo($product), 'Free local template (no API)');
        self::redirect($product->get_id(), 'preview');
    }

    public static function apply_seo() {
        $product = self::require_product();
        $id = $product->get_id();
        $preview = get_transient('rar_studio_preview_' . get_current_user_id() . '_' . $id);
        if (!$preview || !hash_equals($preview['fingerprint'], self::fingerprint($product))) {
            self::redirect($id, 'stale');
        }
        $snapshot = array();
        foreach (self::SEO_KEYS as $key) {
            $snapshot[$key] = array('exists' => metadata_exists('post', $id, $key), 'value' => get_post_meta($id, $key, true));
        }
        update_post_meta($id, '_rar_studio_seo_snapshot', $snapshot);
        foreach (self::SEO_KEYS as $key) {
            update_post_meta($id, $key, sanitize_text_field($preview['fields'][$key]));
        }
        delete_transient('rar_studio_preview_' . get_current_user_id() . '_' . $id);
        self::redirect($id, 'seo_applied');
    }

    public static function restore_seo() {
        $product = self::require_product();
        $id = $product->get_id();
        $snapshot = get_post_meta($id, '_rar_studio_seo_snapshot', true);
        if (!is_array($snapshot)) { self::redirect($id, 'no_backup'); }
        foreach (self::SEO_KEYS as $key) {
            if (empty($snapshot[$key]['exists'])) { delete_post_meta($id, $key); }
            else { update_post_meta($id, $key, $snapshot[$key]['value']); }
        }
        delete_post_meta($id, '_rar_studio_seo_snapshot');
        self::redirect($id, 'seo_restored');
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', array('response' => 403)); }
        check_admin_referer('rar_studio_settings');
        $old = self::settings();
        $settings = $old;
        $settings['api_enabled'] = !empty($_POST['api_enabled']) && !empty($_POST['confirm_api_costs']) ? 1 : 0;
        foreach (array('gemini', 'openai', 'claude') as $provider) {
            $settings[$provider . '_enabled'] = !empty($_POST[$provider . '_enabled']) ? 1 : 0;
            $model = sanitize_text_field(wp_unslash($_POST[$provider . '_model'] ?? ''));
            if (preg_match('/^[a-zA-Z0-9._-]{3,80}$/D', $model)) { $settings[$provider . '_model'] = $model; }
        }
        $provider = sanitize_key(wp_unslash($_POST['provider'] ?? 'gemini'));
        if (in_array($provider, array('gemini', 'openai', 'claude'), true)) { $settings['provider'] = $provider; }
        $settings['daily_limit'] = max(1, min(20, absint($_POST['daily_limit'] ?? 5)));
        update_option(self::OPTION, $settings, false);
        self::redirect(0, 'settings_saved');
    }

    private static function api_key($provider) {
        $constants = array('gemini' => 'RAR_STUDIO_GEMINI_API_KEY', 'openai' => 'RAR_STUDIO_OPENAI_API_KEY', 'claude' => 'RAR_STUDIO_CLAUDE_API_KEY');
        $constant = $constants[$provider] ?? '';
        return $constant && defined($constant) ? (string) constant($constant) : '';
    }

    private static function api_suggestion($product, $provider, $config) {
        $key = self::api_key($provider);
        if (!$key) { return new WP_Error('rar_missing_key', 'API key is not configured in wp-config.php.'); }
        $model = $config[$provider . '_model'];
        $prompt = 'Create accurate SEO suggestions for this eCommerce product. Never invent product features, ingredients, prices or claims. Reply ONLY as a JSON object with string keys seo_title (max 65 chars), meta_description (max 155 chars), focus_keyphrase (max 70 chars). Product: ' .
            wp_json_encode(array('name' => $product->get_name(), 'short_description' => self::text_excerpt($product->get_short_description(), 1400), 'description' => self::text_excerpt($product->get_description(), 2400)));
        $headers = array('Content-Type' => 'application/json');
        if ('gemini' === $provider) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
            $headers['x-goog-api-key'] = $key;
            $body = array('contents' => array(array('parts' => array(array('text' => $prompt)))), 'generationConfig' => array('temperature' => 0.2, 'maxOutputTokens' => 380));
        } elseif ('openai' === $provider) {
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers['Authorization'] = 'Bearer ' . $key;
            $body = array('model' => $model, 'messages' => array(array('role' => 'user', 'content' => $prompt)), 'max_completion_tokens' => 480);
        } else {
            $url = 'https://api.anthropic.com/v1/messages';
            $headers['x-api-key'] = $key;
            $headers['anthropic-version'] = '2023-06-01';
            $body = array('model' => $model, 'messages' => array(array('role' => 'user', 'content' => $prompt)), 'max_tokens' => 380);
        }
        $response = wp_remote_post($url, array('headers' => $headers, 'body' => wp_json_encode($body), 'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 60000));
        if (is_wp_error($response)) { return $response; }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) { return new WP_Error('rar_ai_http', 'Provider error HTTP ' . absint($status) . '. Check model, free-tier availability and API billing.'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = '';
        if ('gemini' === $provider) { $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; }
        if ('openai' === $provider) { $text = $data['choices'][0]['message']['content'] ?? ''; }
        if ('claude' === $provider) { $text = $data['content'][0]['text'] ?? ''; }
        $text = trim((string) $text);
        $text = preg_replace('/^' . chr(96) . '{3}(?:json)?\s*|\s*' . chr(96) . '{3}$/i', '', $text);
        $json = json_decode($text, true);
        if (!is_array($json) || !isset($json['seo_title'], $json['meta_description'], $json['focus_keyphrase'])) {
            return new WP_Error('rar_bad_ai_json', 'AI response did not contain the expected JSON SEO fields.');
        }
        return array(
            '_yoast_wpseo_title' => self::text_excerpt(sanitize_text_field($json['seo_title']), 65),
            '_yoast_wpseo_metadesc' => self::text_excerpt(sanitize_text_field($json['meta_description']), 155),
            '_yoast_wpseo_focuskw' => self::text_excerpt(sanitize_text_field($json['focus_keyphrase']), 70)
        );
    }

    public static function generate_api() {
        $product = self::require_product();
        $config = self::settings();
        $provider = $config['provider'];
        if (!$config['api_enabled'] || !$config[$provider . '_enabled']) { self::redirect($product->get_id(), 'api_off'); }
        if (!is_ssl()) { self::redirect($product->get_id(), 'https_required'); }
        $option = 'rar_studio_usage_' . gmdate('Ymd');
        $used = absint(get_option($option, 0));
        if ($used >= $config['daily_limit']) { self::redirect($product->get_id(), 'limit'); }
        update_option($option, $used + 1, false);
        $result = self::api_suggestion($product, $provider, $config);
        if (is_wp_error($result)) {
            set_transient('rar_studio_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS * 5);
            self::redirect($product->get_id(), 'api_error');
        }
        self::store_preview($product, $result, 'API: ' . $provider);
        self::redirect($product->get_id(), 'preview');
    }

    private static function image_preview_id($id) {
        $image_id = absint(get_post_meta($id, '_rar_studio_image_draft_id', true));
        return $image_id && get_post_status($image_id) === 'inherit' ? $image_id : 0;
    }

    public static function create_image() {
        $product = self::require_product();
        $id = $product->get_id();
        if (!extension_loaded('gd')) { self::redirect($id, 'no_gd'); }
        if (self::image_preview_id($id)) { self::redirect($id, 'image_exists'); }
        $src_id = $product->get_image_id();
        $file = $src_id ? get_attached_file($src_id) : '';
        if (!$file || !is_readable($file) || filesize($file) > 15 * MB_IN_BYTES) { self::redirect($id, 'bad_image'); }
        $info = @getimagesize($file);
        if (!$info || $info[0] * $info[1] > 16000000 || $info[0] < 1 || $info[1] < 1) { self::redirect($id, 'bad_image'); }
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($file); break;
            case IMAGETYPE_PNG: $source = @imagecreatefrompng($file); break;
            case IMAGETYPE_WEBP: $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false; break;
            default: $source = false;
        }
        if (!$source) { self::redirect($id, 'bad_image'); }
        $canvas = imagecreatetruecolor(800, 800);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        $scale = min(740 / $info[0], 740 / $info[1], 1);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        imagecopyresampled($canvas, $source, (int) ((800 - $width) / 2), (int) ((800 - $height) / 2), 0, 0, $width, $height, $info[0], $info[1]);
        imagedestroy($source);
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) { imagedestroy($canvas); self::redirect($id, 'image_failed'); }
        $name = wp_unique_filename($upload['path'], 'rar-studio-' . $id . '-white-square.jpg');
        $path = trailingslashit($upload['path']) . $name;
        $saved = imagejpeg($canvas, $path, 88);
        imagedestroy($canvas);
        if (!$saved) { self::redirect($id, 'image_failed'); }
        $attachment = wp_insert_attachment(array('post_mime_type' => 'image/jpeg', 'post_title' => sanitize_text_field($product->get_name() . ' - White Background'), 'post_status' => 'inherit'), $path);
        if (is_wp_error($attachment) || !$attachment) { wp_delete_file($path); self::redirect($id, 'image_failed'); }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $path));
        update_post_meta($attachment, '_wp_attachment_image_alt', sanitize_text_field($product->get_name()));
        update_post_meta($id, '_rar_studio_image_draft_id', $attachment);
        self::redirect($id, 'image_created');
    }

    public static function apply_image() {
        $product = self::require_product();
        $id = $product->get_id();
        $draft = self::image_preview_id($id);
        if (!$draft) { self::redirect($id, 'bad_image'); }
        update_post_meta($id, '_rar_studio_image_previous_id', absint($product->get_image_id()));
        $product->set_image_id($draft);
        $product->save();
        delete_post_meta($id, '_rar_studio_image_draft_id');
        self::redirect($id, 'image_applied');
    }

    public static function restore_image() {
        $product = self::require_product();
        $id = $product->get_id();
        if (!metadata_exists('post', $id, '_rar_studio_image_previous_id')) { self::redirect($id, 'no_backup'); }
        $product->set_image_id(absint(get_post_meta($id, '_rar_studio_image_previous_id', true)));
        $product->save();
        delete_post_meta($id, '_rar_studio_image_previous_id');
        self::redirect($id, 'image_restored');
    }

    private static function form($action, $id, $button, $style = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rar-inline">';
        echo '<input type="hidden" name="action" value="rar_studio_' . esc_attr($action) . '">';
        echo '<input type="hidden" name="product_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('rar_studio_product_' . $id);
        echo '<button type="submit" class="button ' . esc_attr($style) . '">' . esc_html($button) . '</button></form>';
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $config = self::settings();
        $id = absint($_GET['product_id'] ?? 0);
        $product = ($id && function_exists('wc_get_product') && current_user_can('edit_post', $id)) ? wc_get_product($id) : false;
        $preview = $product ? get_transient('rar_studio_preview_' . get_current_user_id() . '_' . $id) : false;
        $notice = sanitize_key(wp_unslash($_GET['rar_notice'] ?? ''));
        $notices = array(
            'preview' => 'Suggestion prepared. No product data was changed.',
            'stale' => 'Product changed since preview. Generate a new suggestion.',
            'seo_applied' => 'SEO metadata saved. Restore is available.',
            'seo_restored' => 'Previous SEO metadata restored.',
            'settings_saved' => 'Settings saved. API is disabled unless explicitly enabled with cost confirmation.',
            'api_off' => 'AI API is OFF or this provider is disabled.',
            'https_required' => 'HTTPS is required for API requests.',
            'limit' => 'Daily API request limit reached.',
            'api_error' => 'API request failed. See error below.',
            'no_backup' => 'No backup exists to restore.',
            'no_gd' => 'GD extension is needed for local image processing.',
            'bad_image' => 'Source/draft image missing, unsupported or too large.',
            'image_exists' => 'Review the existing derivative before creating another.',
            'image_failed' => 'Image creation failed.',
            'image_created' => 'New 800×800 image draft created. Product image was not changed.',
            'image_applied' => 'Derivative set as product image. Previous image can be restored.',
            'image_restored' => 'Original product image restored.'
        );
        echo '<div class="wrap rar-studio" id="rar-studio"><header class="rar-hero"><div><p class="rar-eyebrow">RAR • WORDPRESS TOOLKIT</p><h1>Commerce Studio <span>AI</span></h1><p>Free-first product, SEO and image workflow. Manual approval by design.</p></div><div class="rar-status">API <strong>' . ($config['api_enabled'] ? 'ENABLED' : 'OFF · SAFE') . '</strong></div></header>';
        if ($notice && isset($notices[$notice])) { echo '<div class="notice notice-info"><p>' . esc_html($notices[$notice]) . '</p></div>'; }
        $error = get_transient('rar_studio_error_' . get_current_user_id());
        if ($error) { echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>'; delete_transient('rar_studio_error_' . get_current_user_id()); }
        echo '<div class="rar-layout"><aside class="rar-side"><div class="rar-side-heading">STUDIO NAVIGATION</div><nav aria-label="Studio sections"><a href="#overview">Overview</a><a href="#products">Product & SEO</a><a href="#images">Image Studio</a><a href="#manual-ai">Subscription Workflow</a><a href="#api">Optional AI API</a><a href="#safety">Safety & status</a></nav></aside><main class="rar-main">';
        echo '<details class="rar-mobile-nav"><summary>Jump to section</summary><nav><a href="#overview">Overview</a> · <a href="#products">Product & SEO</a> · <a href="#images">Image Studio</a> · <a href="#manual-ai">Manual AI</a> · <a href="#api">API Settings</a></nav></details>';
        echo '<section id="overview" class="rar-section"><h2>Overview</h2><div class="rar-grid"><div class="rar-tile"><span>01</span><strong>Local SEO</strong><small>No AI API / no charge</small></div><div class="rar-tile"><span>02</span><strong>Image standardizer</strong><small>800×800, white JPG draft</small></div><div class="rar-tile"><span>03</span><strong>Manual AI prompt</strong><small>Use your existing chat subscription</small></div><div class="rar-tile"><span>04</span><strong>Opt-in API</strong><small>Three providers; OFF by default</small></div></div></section>';
        echo '<section id="products" class="rar-section"><h2>Product & SEO Assistant</h2><p>Enter an existing WooCommerce product ID. Local suggestions are templates, not a substitute for editorial review.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="rar-lookup"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><label for="rar-product">Product ID</label><input id="rar-product" type="number" min="1" name="product_id" value="' . esc_attr($id ?: '') . '" required><button class="button button-primary">Load product</button></form>';
        if ($product) {
            echo '<div class="rar-product"><div>' . ($product->get_image_id() ? wp_get_attachment_image($product->get_image_id(), array(96, 96)) : '') . '</div><div><h3>' . esc_html($product->get_name()) . '</h3><p>Product #' . esc_html($id) . ' · ' . esc_html($product->get_status()) . '</p><a href="' . esc_url(get_edit_post_link($id)) . '">Open product editor ↗</a></div></div>';
            self::form('preview', $id, 'Generate FREE local SEO preview', 'button-primary');
            if ($preview) {
                echo '<div class="rar-preview"><h3>Pending review · ' . esc_html($preview['source']) . '</h3>';
                foreach (self::SEO_KEYS as $key) {
                    $labels = array('_yoast_wpseo_title' => 'SEO title', '_yoast_wpseo_metadesc' => 'Meta description', '_yoast_wpseo_focuskw' => 'Focus keyphrase');
                    echo '<label>' . esc_html($labels[$key]) . '</label><div class="rar-value">' . esc_html($preview['fields'][$key]) . '</div>';
                }
                echo '<p class="rar-muted">Applying updates only these three Yoast SEO fields; it does not change title, prices, stock, images or description.</p>';
                self::form('apply_seo', $id, 'Approve & apply SEO fields', 'button-primary');
                echo '</div>';
            }
            if (metadata_exists('post', $id, '_rar_studio_seo_snapshot')) {
                self::form('restore_seo', $id, 'Restore previous SEO fields');
            }
        } else { echo '<div class="rar-hint">Select a valid product to begin. No product is selected automatically.</div>'; }
        echo '</section>';
        echo '<section id="images" class="rar-section"><h2>Free Image Studio</h2><p>Creates a new 800×800 JPEG on a pure white canvas, preserving aspect ratio and keeping the original file untouched. No external service. Transparent parts become white. Product labels are not regenerated.</p>';
        if ($product) {
            $draft = self::image_preview_id($id);
            if ($draft) {
                echo '<div class="rar-product">' . wp_get_attachment_image($draft, array(220, 220)) . '<div><h3>New derivative is ready for review</h3><p>Original product image remains active until you approve.</p>';
                self::form('apply_image', $id, 'Apply draft as product image', 'button-primary');
                echo '</div></div>';
            } else { self::form('create_image', $id, 'Create local white-background draft'); }
            if (metadata_exists('post', $id, '_rar_studio_image_previous_id')) { self::form('restore_image', $id, 'Restore previous featured image'); }
        } else { echo '<p class="rar-muted">Select a product first.</p>'; }
        echo '</section>';
        echo '<section id="manual-ai" class="rar-section"><h2>Use ChatGPT / Gemini / Claude subscriptions manually</h2><p>No extra API billing: copy this product-safe prompt, run it in your subscribed chat app, review and paste results in the WooCommerce editor yourself. Subscriptions cannot be treated as general-purpose API credentials.</p>';
        if ($product) {
            $prompt = 'Improve SEO for the following WooCommerce product. Do not invent specifications, ingredients, price, certifications or claims. Provide SEO title (max 65 chars), meta description (max 155 chars), focus keyphrase, and a concise improved product description. Product name: ' . $product->get_name() . '. Description: ' . self::text_excerpt($product->get_description(), 1800) . '. Short description: ' . self::text_excerpt($product->get_short_description(), 800);
            echo '<textarea id="rar-ai-prompt" rows="6" readonly>' . esc_textarea($prompt) . '</textarea><button type="button" class="button" id="rar-copy-prompt">Copy prompt</button><span id="rar-copy-status" aria-live="polite"></span>';
        } else { echo '<p class="rar-muted">Select a product first to create a prompt.</p>'; }
        echo '</section>';
        echo '<section id="api" class="rar-section"><h2>Optional AI API</h2><p><strong>Default OFF.</strong> Enabling is a separate, conscious opt-in. Free-tier eligibility varies by provider, account and model. API billing is separate from Plus / Pro subscriptions. API keys must be stored in wp-config.php constants (never in this plugin database).</p>';
        if (current_user_can('manage_options')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rar-settings"><input type="hidden" name="action" value="rar_studio_save_settings">';
            wp_nonce_field('rar_studio_settings');
            echo '<label class="rar-check"><input type="checkbox" name="api_enabled" value="1" ' . checked($config['api_enabled'], 1, false) . '> Enable outbound AI API requests</label>';
            echo '<label class="rar-check"><input type="checkbox" name="confirm_api_costs" value="1"> I understand requests may incur separate API charges (required to enable on each save).</label>';
            echo '<div class="rar-field"><label for="rar-provider">Selected provider</label><select name="provider" id="rar-provider">';
            foreach (array('gemini' => 'Google Gemini', 'openai' => 'OpenAI GPT', 'claude' => 'Anthropic Claude') as $key => $title) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($config['provider'], $key, false) . '>' . esc_html($title) . '</option>';
            }
            echo '</select></div><div class="rar-field"><label for="rar-limit">Maximum API attempts / UTC day (1–20)</label><input id="rar-limit" type="number" min="1" max="20" name="daily_limit" value="' . esc_attr($config['daily_limit']) . '"></div>';
            foreach (array('gemini' => 'RAR_STUDIO_GEMINI_API_KEY', 'openai' => 'RAR_STUDIO_OPENAI_API_KEY', 'claude' => 'RAR_STUDIO_CLAUDE_API_KEY') as $key => $constant) {
                echo '<div class="rar-provider"><label class="rar-check"><input type="checkbox" name="' . esc_attr($key) . '_enabled" value="1" ' . checked($config[$key . '_enabled'], 1, false) . '> ' . esc_html(ucfirst($key)) . ' enabled</label>';
                echo '<div class="rar-field"><label>Model ID</label><input type="text" name="' . esc_attr($key) . '_model" value="' . esc_attr($config[$key . '_model']) . '"></div>';
                echo '<small>Key: <code>' . esc_html($constant) . '</code> — ' . (self::api_key($key) ? '<span class="rar-green">Configured</span>' : 'Not configured') . '</small></div>';
            }
            echo '<button class="button button-primary">Save API settings</button></form>';
        }
        if ($product) {
            echo '<p><strong>Generate a paid/free-tier API preview only when ready:</strong></p>';
            self::form('generate_api', $id, 'Generate via selected API (counts as request)');
        }
        echo '</section>';
        echo '<section id="safety" class="rar-section"><h2>Safety & scope</h2><ul><li>No external AI calls on install, activation, page load or free-local actions.</li><li>API disabled by default; manual provider enable and confirmation required.</li><li>Only product name and descriptions are sent when API generation is explicitly requested.</li><li>SEO and featured-image edits have one-step restoration.</li><li>No automatic changes to prices, stock, orders, SKU, shipping, pages or galleries.</li><li>Image drafts occupy hosting disk until you remove unused media files.</li></ul><p class="rar-muted">v0.1.0 foundation: bulk queue, editable AI result fields, gallery automation, richer SEO integrations and cost estimation are planned, not shipped.</p></section>';
        echo '</main></div></div>';
    }
}

add_action('plugins_loaded', array('RAR_WP_Commerce_Studio_AI', 'boot'));
