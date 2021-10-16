<?php

/**
 * @package adbirt-advertisers
 * @version 1.0.0
 */

$is_debug_mode = true;

/**
 * For Adbirt
 *
 * @class
 */
class AdbirtAdvertisers
{

    public function __construct()
    {
        add_filter('wp_enqueue_scripts', array($this, 'register_css_and_js'));
        add_filter('the_content', array($this, 'add_advertisers_js_filter'), 1);

        add_shortcode('adbirt-landing-page-tracker', array($this, 'adbirt_landing_page_tracker_shortcode'));
        add_shortcode('adbirt-success-page-tracker', array($this, 'adbirt_success_page_tracker_shortcode'));
        add_shortcode('adbirt-download-link', array($this, 'adbirt_download_button_shortcode'));


        return $this;
    }

    public function adbirt_landing_page_tracker_shortcode($attributes = array(), $content = '')
    {
        $attrs = shortcode_atts(
            array(),
            $attributes
        );
    }


    public function generate_random_string($length = 5)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString . '_' . time();
    }


    public function adbirt_success_page_tracker_shortcode($attributes = array(), $content = '')
    {
        $attrs = shortcode_atts(
            array(),
            $attributes
        );
    }


    public function add_advertisers_js_filter($content)
    {
        global $wp;

        $url_query_vars = $wp->query_vars;

        // $req_url = add_query_arg($wp->query_vars, home_url($wp->request));
        $req_url = home_url(add_query_arg($url_query_vars, $wp->request));

        $is_landing_page_url = false;
        $is_success_page_url = false;

        $shortcode = $is_landing_page_url ? '[adbirt-landing-page-tracker]' : ($is_success_page_url ? '[adbirt-success-page-tracker]' : '');

        $shortcode_content = do_shortcode($shortcode);
        $content .= $shortcode_content;

        return $content;
    }

    public function register_css_and_js()
    {
        $this->register_css();
        $this->register_js();
    }

    public function register_css()
    {
        // 
    }

    public function register_js()
    {
        global $is_debug_mode;

        $adbirt_js_path = $is_debug_mode ? trailingslashit(plugin_dir_url(__FILE__)) . 'assets/js/adbirt.js' : 'https://adbirt.com/public/js/adbirt.js';
        wp_enqueue_script('adbirt-advertisers', $adbirt_js_path, false, '1.0.0', false);
    }

    public function adbirt_download_button_shortcode($attributes = array(), $content = '')
    {

        $attrs = shortcode_atts(
            array(
                'href' => '',
            ),
            $attributes
        );

        ob_start();
?>
        <a id="adbirt-<?php echo $this->generate_random_string(); ?>" href="<?php echo $attrs['href'] ?>">
            <button class="button btn">
                <?php echo $content; ?>
            </button>
        </a>
<?php
        return ob_get_clean();
    }
}
