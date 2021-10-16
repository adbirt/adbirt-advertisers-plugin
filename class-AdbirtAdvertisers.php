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

        return $this;
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

        $adbirt_js_path = $is_debug_mode ? '' : '';
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
        <a href="<?php echo $attrs['href'] ?>">
            <button class="button btn">
                <?php echo $content; ?>
            </button>
        </a>
<?php
        return ob_get_clean();
    }
}
