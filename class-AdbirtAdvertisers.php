<?php

/**
 * @package adbirt-advertisers
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adbirt conversion tracking for WordPress.
 *
 * Loads the Adbirt web SDK (bundled in assets/js/adbirt.js) on every page so
 * the click id an Adbirt ad adds to the landing URL is kept in a first-party
 * cookie, then reports conversions to the new Adbirt backend
 * (POST {api}/api/v1/events):
 * - in the browser, with the campaign's publishable token (shortcodes and
 *   data-adbirt-event attributes);
 * - from the server, with the secret token, for WordPress sign-ups and
 *   WooCommerce paid orders (the order number is the transaction id, so a
 *   campaign that pays for every order charges each one once).
 *
 * Version 1 called adbirt.com endpoints of the legacy platform, which no
 * longer exist; its shortcodes are kept and mapped to events.
 */
class AdbirtAdvertisers
{
    const OPTION = 'adbirt_advertisers_settings';
    const DEFAULT_API = 'https://api.adbirt.com';
    /** Must match the web SDK's cookie name. */
    const CLICK_ID_COOKIE = '_adb_clickid';
    const ORDER_META = '_adbirt_reported';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_sdk'));
        add_filter('script_loader_tag', array($this, 'add_sdk_attributes'), 10, 2);

        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // Generic event.
        add_shortcode('adbirt-event', array($this, 'event_shortcode'));
        add_shortcode('adbirt-download-link', array($this, 'download_link_shortcode'));

        // Version 1 shortcodes. The SDK captures the click id on every page, so
        // the "init"/"landing" ones have nothing left to do; the "success" ones
        // report an event when the page loads.
        foreach (array('adbirt-landing-page-tracker', 'adbirt-redirect-form-submit-init', 'adbirt-payment-init', 'adbirt-async-form-submit') as $tag) {
            add_shortcode($tag, array($this, 'no_op_shortcode'));
        }
        add_shortcode('adbirt-success-page-tracker', array($this, 'legacy_success_shortcode'));
        add_shortcode('adbirt-redirect-form-submit-success', array($this, 'legacy_success_shortcode'));
        add_shortcode('adbirt-payment-success', array($this, 'legacy_payment_shortcode'));

        add_action('user_register', array($this, 'report_registration'));
        add_action('woocommerce_payment_complete', array($this, 'report_order'));
        add_action('woocommerce_order_status_completed', array($this, 'report_order'));
    }

    // ---------------------------------------------------------------- settings

    public static function settings()
    {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'publishable_token' => '',
            'secret_token' => '',
            'api_url' => self::DEFAULT_API,
            'registration_event' => 'registration',
            'order_event' => 'purchase',
        ));
    }

    public function add_settings_page()
    {
        add_options_page('Adbirt', 'Adbirt', 'manage_options', 'adbirt-advertisers', array($this, 'render_settings_page'));
    }

    public function register_settings()
    {
        register_setting('adbirt_advertisers', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));
    }

    public function sanitize_settings($input)
    {
        $current = self::settings();
        $input = is_array($input) ? $input : array();
        $clean = array();

        $publishable = trim((string) ($input['publishable_token'] ?? ''));
        $clean['publishable_token'] = preg_match('/^adb_pk_[a-f0-9]{48}$/', $publishable) ? $publishable : '';
        if ($publishable !== '' && $clean['publishable_token'] === '') {
            add_settings_error(self::OPTION, 'publishable', 'The publishable token must look like adb_pk_ followed by 48 characters.');
        }

        // An empty secret field keeps the saved one, so it never has to be shown again.
        $secret = trim((string) ($input['secret_token'] ?? ''));
        if ($secret === '') {
            $clean['secret_token'] = $current['secret_token'];
        } elseif (preg_match('/^adb_sk_[a-f0-9]{48}$/', $secret)) {
            $clean['secret_token'] = $secret;
        } else {
            $clean['secret_token'] = $current['secret_token'];
            add_settings_error(self::OPTION, 'secret', 'The secret token must look like adb_sk_ followed by 48 characters.');
        }
        if (!empty($input['clear_secret'])) {
            $clean['secret_token'] = '';
        }

        $api = esc_url_raw(trim((string) ($input['api_url'] ?? '')), array('https', 'http'));
        $clean['api_url'] = $api ? untrailingslashit($api) : self::DEFAULT_API;

        foreach (array('registration_event' => 'registration', 'order_event' => 'purchase') as $key => $fallback) {
            $name = trim((string) ($input[$key] ?? ''));
            $clean[$key] = self::valid_event_name($name) ? $name : $fallback;
        }
        return $clean;
    }

    public static function valid_event_name($name)
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.:-]{0,99}$/', $name);
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
?>
        <div class="wrap">
            <h1>Adbirt conversion tracking</h1>
            <p>Copy the tokens from your Adbirt dashboard: <strong>SDKs &amp; tracking</strong>, pick the campaign, choose “Adbirt SDK”, then <strong>Generate tokens</strong>. Set the campaign's landing page to a page on this site.</p>
            <form method="post" action="options.php">
                <?php settings_fields('adbirt_advertisers'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="adbirt-publishable">Publishable token</label></th>
                        <td><input id="adbirt-publishable" class="regular-text code" name="<?php echo esc_attr(self::OPTION); ?>[publishable_token]" value="<?php echo esc_attr($settings['publishable_token']); ?>" placeholder="adb_pk_…" autocomplete="off">
                            <p class="description">Used in visitors' browsers. Safe to publish.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adbirt-secret">Secret token</label></th>
                        <td><input id="adbirt-secret" class="regular-text code" type="password" name="<?php echo esc_attr(self::OPTION); ?>[secret_token]" value="" placeholder="<?php echo $settings['secret_token'] ? esc_attr('Saved (leave empty to keep it)') : 'adb_sk_…'; ?>" autocomplete="new-password">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[clear_secret]" value="1"> Remove the saved secret token</label>
                            <p class="description">Optional. Stays on this server; it reports sign-ups and WooCommerce paid orders, and confirms them so campaigns that pay only for confirmed conversions are charged.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adbirt-registration">Sign-up event</label></th>
                        <td><input id="adbirt-registration" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[registration_event]" value="<?php echo esc_attr($settings['registration_event']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adbirt-order">Paid order event</label></th>
                        <td><input id="adbirt-order" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[order_event]" value="<?php echo esc_attr($settings['order_event']); ?>">
                            <p class="description">Use the same names as the payable events on your campaign.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adbirt-api">API address</label></th>
                        <td><input id="adbirt-api" class="regular-text code" name="<?php echo esc_attr(self::OPTION); ?>[api_url]" value="<?php echo esc_attr($settings['api_url']); ?>"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Shortcodes</h2>
            <p><code>[adbirt-event name="registration"]</code> reports an event when the page is viewed (e.g. a thank-you page). Optional <code>value</code>, <code>currency</code> and <code>transaction_id</code>.</p>
            <p><code>[adbirt-download-link href="https://…" event="download"]Download[/adbirt-download-link]</code> reports a click.</p>
            <p>Any element can report itself: <code>&lt;form data-adbirt-event="registration"&gt;</code> on submit, anything else on click.</p>
        </div>
<?php
    }

    // ------------------------------------------------------------------ script

    public function enqueue_sdk()
    {
        $settings = self::settings();
        if ($settings['publishable_token'] === '') {
            return;
        }
        $path = plugin_dir_path(__FILE__) . 'assets/js/adbirt.js';
        // Queue calls made before the script has loaded; the SDK replays them.
        wp_register_script('adbirt-queue', false, array(), '2.0.0', false);
        wp_enqueue_script('adbirt-queue');
        wp_add_inline_script('adbirt-queue', 'window.adbirt = window.adbirt || { q: [] };');
        wp_enqueue_script('adbirt-advertisers', plugin_dir_url(__FILE__) . 'assets/js/adbirt.js', array('adbirt-queue'), (string) filemtime($path), false);
    }

    public function add_sdk_attributes($tag, $handle)
    {
        if ($handle !== 'adbirt-advertisers') {
            return $tag;
        }
        $settings = self::settings();
        $attributes = sprintf(
            ' async data-token="%s" data-base-url="%s"',
            esc_attr($settings['publishable_token']),
            esc_attr($settings['api_url'])
        );
        return preg_replace('/<script\b/', '<script' . $attributes, $tag, 1);
    }

    // -------------------------------------------------------------- shortcodes

    public function no_op_shortcode()
    {
        return '';
    }

    public function legacy_success_shortcode($attributes = array())
    {
        $attrs = shortcode_atts(array('event' => 'conversion'), $attributes);
        return $this->event_shortcode(array('name' => $attrs['event']));
    }

    public function legacy_payment_shortcode($attributes = array())
    {
        $attrs = shortcode_atts(array('event' => 'purchase'), $attributes);
        return $this->event_shortcode(array('name' => $attrs['event']));
    }

    /** Reports one event when the page is viewed. */
    public function event_shortcode($attributes = array())
    {
        $attrs = shortcode_atts(array('name' => '', 'value' => '', 'currency' => '', 'transaction_id' => ''), $attributes);
        $name = trim($attrs['name']);
        if (!self::valid_event_name($name)) {
            return '';
        }
        $options = array();
        if ($attrs['value'] !== '' && is_numeric($attrs['value']) && (float) $attrs['value'] >= 0) {
            $options['value'] = (float) $attrs['value'];
        }
        if (preg_match('/^[A-Za-z]{3}$/', $attrs['currency'])) {
            $options['currency'] = strtoupper($attrs['currency']);
        }
        if (trim($attrs['transaction_id']) !== '') {
            $options['transactionId'] = substr(trim($attrs['transaction_id']), 0, 128);
        }
        // The SDK keeps adbirt.q working after it loads, so one form covers both cases.
        $call = wp_json_encode(array('track', $name, (object) $options));
        return '<script>(window.adbirt = window.adbirt || { q: [] }).q.push(' . $call . ');</script>';
    }

    public function download_link_shortcode($attributes = array(), $content = '')
    {
        $attrs = shortcode_atts(array('href' => '', 'download' => null, 'event' => 'download'), $attributes);
        $event = self::valid_event_name($attrs['event']) ? $attrs['event'] : 'download';
        $download = isset($attrs['download']) ? ' download="' . esc_attr($attrs['download']) . '"' : '';
        return '<a class="button btn adbirt-btn" href="' . esc_url($attrs['href']) . '"' . $download
            . ' data-adbirt-event="' . esc_attr($event) . '">' . wp_kses_post($content) . '</a>';
    }

    // ------------------------------------------------------------ server events

    public static function click_id_from_cookie()
    {
        $raw = isset($_COOKIE[self::CLICK_ID_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::CLICK_ID_COOKIE])) : '';
        return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $raw) ? $raw : '';
    }

    /**
     * POST one event with the secret token. Returns the decoded response body,
     * or null when nothing was sent (no secret token, no click id) or it failed.
     */
    public static function send_server_event($event_name, $click_id, $extra = array())
    {
        $settings = self::settings();
        if ($settings['secret_token'] === '' || $click_id === '' || !self::valid_event_name($event_name)) {
            return null;
        }
        $body = array_merge(array('event_name' => $event_name, 'adb_clickid' => $click_id), $extra);
        $response = wp_remote_post($settings['api_url'] . '/api/v1/events', array(
            'timeout' => 5,
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['secret_token'],
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            return null;
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function report_registration($user_id)
    {
        $click_id = self::click_id_from_cookie();
        if ($click_id === '') {
            return;
        }
        update_user_meta($user_id, '_adbirt_click_id', $click_id);
        $settings = self::settings();
        self::send_server_event($settings['registration_event'], $click_id, array(
            'transaction_id' => 'wp-user-' . $user_id,
            'validation_status' => 'validated',
        ));
    }

    public function report_order($order_id)
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(self::ORDER_META)) {
            return;
        }
        // The click id is captured at checkout (see remember_order_click_id);
        // fall back to the cookie when the payment completes in the same visit.
        $click_id = (string) $order->get_meta('_adbirt_click_id');
        if ($click_id === '') {
            $click_id = self::click_id_from_cookie();
        }
        if ($click_id === '') {
            return;
        }
        $settings = self::settings();
        $result = self::send_server_event($settings['order_event'], $click_id, array(
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'transaction_id' => 'wc-order-' . $order->get_id(),
            'validation_status' => 'validated',
        ));
        if (is_array($result)) {
            $order->update_meta_data(self::ORDER_META, gmdate('c'));
            $order->save();
        }
    }

    /** Keep the click id on the order, so a payment confirmed later (a webhook) still attributes. */
    public static function remember_order_click_id($order)
    {
        $click_id = self::click_id_from_cookie();
        if ($click_id !== '' && is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_adbirt_click_id', $click_id);
        }
    }
}

add_action('woocommerce_checkout_create_order', array('AdbirtAdvertisers', 'remember_order_click_id'));
