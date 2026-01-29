<?php
/**
 * Plugin Name: DW WooCommerce CPF Validator
 * Plugin URI: https://github.com/agenciadw/wc-cpf-validator
 * Description: Plugin para validação de CPF no checkout do WooCommerce usando a API CPF.CNPJ
 * Version: 0.1.0
 * Author: David William da Costa
 * Author URI: https://github.com/agenciadw/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-cpf-validator
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define( 'WC_CPF_VALIDATOR_VERSION', '0.1.0' );
define( 'WC_CPF_VALIDATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_CPF_VALIDATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_CPF_VALIDATOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active
 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', 'wc_cpf_validator_woocommerce_missing_notice' );
    return;
}

function wc_cpf_validator_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e( 'WooCommerce CPF Validator requer que o WooCommerce esteja instalado e ativo.', 'wc-cpf-validator' ); ?></p>
    </div>
    <?php
}

/**
 * Main plugin class
 */
class WC_CPF_Validator {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes() {
        require_once WC_CPF_VALIDATOR_PLUGIN_DIR . 'includes/class-wc-cpf-validator-settings.php';
        require_once WC_CPF_VALIDATOR_PLUGIN_DIR . 'includes/class-wc-cpf-validator-api.php';
        require_once WC_CPF_VALIDATOR_PLUGIN_DIR . 'includes/class-wc-cpf-validator-checkout.php';
        require_once WC_CPF_VALIDATOR_PLUGIN_DIR . 'includes/class-wc-cpf-validator-admin.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_filter( 'plugin_action_links_' . WC_CPF_VALIDATOR_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
        
        // Initialize components
        WC_CPF_Validator_Settings::get_instance();
        WC_CPF_Validator_Checkout::get_instance();
        WC_CPF_Validator_Admin::get_instance();
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'wc-cpf-validator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
    
    /**
     * Add settings link to plugins page
     */
    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cpf_validator' ) . '">' . __( 'Configurações', 'wc-cpf-validator' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

/**
 * Initialize the plugin
 */
function wc_cpf_validator_init() {
    return WC_CPF_Validator::get_instance();
}

// Start the plugin early enough to work on WC AJAX checkout refresh too.
add_action( 'plugins_loaded', 'wc_cpf_validator_init', 20 );
// Back-compat / safety in case another environment relies on this hook.
add_action( 'woocommerce_loaded', 'wc_cpf_validator_init' );
// Extra safety for environments where WC loads late.
add_action( 'woocommerce_init', 'wc_cpf_validator_init' );
