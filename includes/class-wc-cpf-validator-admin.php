<?php
/**
 * Admin functionality class
 *
 * @package WC_CPF_Validator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_CPF_Validator_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Add admin notices
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        
        // Add balance check to admin
        add_action( 'admin_footer', array( $this, 'add_balance_widget' ) );
        
        // Add custom column to orders list
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_cpf_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_cpf_column' ), 10, 2 );
    }
    
    /**
     * Display admin notices
     */
    public function admin_notices() {
        $screen = get_current_screen();
        
        if ( ! $screen || strpos( $screen->id, 'woocommerce' ) === false ) {
            return;
        }
        
        // Check if plugin is enabled but token is not set
        if ( WC_CPF_Validator_Settings::get_option( 'enabled' ) === 'yes' ) {
            $cpf_provider = WC_CPF_Validator_Settings::get_option( 'api_provider', 'cpfcnpj' );
            $cnpj_provider = WC_CPF_Validator_Settings::get_option( 'cnpj_api_provider', 'cpfcnpj' );
            $validate_cnpj = WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes';
            $token = WC_CPF_Validator_Settings::get_option( 'api_token' );
            $cpfhub_key = WC_CPF_Validator_Settings::get_option( 'cpfhub_api_key' );
            $test_mode = WC_CPF_Validator_Settings::get_option( 'test_mode' ) === 'yes';
            
            $missing_credentials = false;
            $missing_messages = array();
            if ( $test_mode ) {
                $missing_credentials = false;
            } else {
                // CPF credentials
                if ( $cpf_provider === 'cpfhub' ) {
                    if ( empty( $cpfhub_key ) ) {
                        $missing_credentials = true;
                        $missing_messages[] = esc_html__( 'CPFHub API Key (CPF)', 'wc-cpf-validator' );
                    }
                } else {
                    if ( empty( $token ) ) {
                        $missing_credentials = true;
                        $missing_messages[] = esc_html__( 'Token CPF.CNPJ (CPF)', 'wc-cpf-validator' );
                    }
                }

                // CNPJ credentials (only if enabled)
                if ( $validate_cnpj ) {
                    if ( $cnpj_provider !== 'cpfcnpj' ) {
                        $missing_credentials = true;
                        $missing_messages[] = esc_html__( 'Provedor de CNPJ inválido (use CPF.CNPJ)', 'wc-cpf-validator' );
                    } elseif ( empty( $token ) ) {
                        $missing_credentials = true;
                        $missing_messages[] = esc_html__( 'Token CPF.CNPJ (CNPJ)', 'wc-cpf-validator' );
                    }
                }
            }

            if ( $missing_credentials ) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong><?php esc_html_e( 'WooCommerce CPF Validator:', 'wc-cpf-validator' ); ?></strong>
                        <?php
                        $missing_text = '';
                        if ( ! empty( $missing_messages ) ) {
                            $missing_text = ' (' . implode( ', ', array_map( 'esc_html', $missing_messages ) ) . ')';
                        }
                        printf(
                            /* translators: 1: missing settings, 2: settings page URL */
                            esc_html__( 'O plugin está ativo mas existem credenciais/configurações pendentes%s. %s', 'wc-cpf-validator' ),
                            $missing_text,
                            '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=cpf_validator' ) ) . '">' . esc_html__( 'Configure agora', 'wc-cpf-validator' ) . '</a>'
                        );
                        ?>
                    </p>
                </div>
                <?php
            }
            
            // Check API balance (only on settings page)
            if ( isset( $_GET['section'] ) && $_GET['section'] === 'cpf_validator' ) {
                if ( $cpf_provider === 'cpfcnpj' && ! $test_mode && ! empty( $token ) ) {
                    $balance = WC_CPF_Validator_API::check_balance();
                    
                    if ( $balance !== false ) {
                        if ( $balance < 100 ) {
                            ?>
                            <div class="notice notice-warning">
                                <p>
                                    <strong><?php esc_html_e( 'WooCommerce CPF Validator:', 'wc-cpf-validator' ); ?></strong>
                                    <?php
                                    printf(
                                        /* translators: %d: number of credits */
                                        esc_html__( 'Seu saldo de créditos está baixo: %d consultas restantes.', 'wc-cpf-validator' ),
                                        (int) $balance
                                    );
                                    ?>
                                </p>
                            </div>
                            <?php
                        } else {
                            ?>
                            <div class="notice notice-success">
                                <p>
                                    <strong><?php esc_html_e( 'WooCommerce CPF Validator:', 'wc-cpf-validator' ); ?></strong>
                                    <?php
                                    printf(
                                        /* translators: %d: number of credits */
                                        esc_html__( 'Saldo disponível: %d consultas.', 'wc-cpf-validator' ),
                                        (int) $balance
                                    );
                                    ?>
                                </p>
                            </div>
                            <?php
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Add balance widget to admin footer
     */
    public function add_balance_widget() {
        $screen = get_current_screen();
        
        if ( ! $screen || $screen->id !== 'shop_order' ) {
            return;
        }
        
        if ( WC_CPF_Validator_Settings::get_option( 'enabled' ) !== 'yes' ) {
            return;
        }
        
        $test_mode = WC_CPF_Validator_Settings::get_option( 'test_mode' ) === 'yes';
        
        if ( $test_mode ) {
            return;
        }
        
        $token = WC_CPF_Validator_Settings::get_option( 'api_token' );
        if ( empty( $token ) ) {
            return;
        }
        
        $balance = WC_CPF_Validator_API::check_balance();
        
        if ( $balance === false ) {
            return;
        }
        
        ?>
        <div id="wc-cpf-validator-balance" style="position: fixed; bottom: 20px; right: 20px; background: #fff; border: 1px solid #ccc; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); z-index: 9999;">
            <strong><?php esc_html_e( 'API CPF.CNPJ', 'wc-cpf-validator' ); ?></strong><br>
            <span style="font-size: 24px; color: <?php echo $balance < 100 ? '#e74c3c' : '#27ae60'; ?>;">
                <?php echo number_format( $balance, 0, ',', '.' ); ?>
            </span><br>
            <small><?php esc_html_e( 'consultas restantes', 'wc-cpf-validator' ); ?></small>
        </div>
        <?php
    }
    
    /**
     * Add CPF column to orders list
     */
    public function add_cpf_column( $columns ) {
        $new_columns = array();
        
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            
            // Add CPF column after customer name
            if ( $key === 'billing_address' ) {
                $new_columns['billing_cpf'] = __( 'CPF', 'wc-cpf-validator' );
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Display CPF in orders list column
     */
    public function display_cpf_column( $column, $post_id ) {
        if ( $column === 'billing_cpf' ) {
            $cpf = get_post_meta( $post_id, '_billing_cpf', true );
            
            if ( $cpf ) {
                echo esc_html( $cpf );
                
                // Show validation status if available
                $situacao = get_post_meta( $post_id, '_billing_cpf_situacao', true );
                if ( $situacao ) {
                    $color = ( $situacao === 'Regular' ) ? 'green' : 'red';
                    echo '<br><small style="color: ' . $color . ';">(' . esc_html( $situacao ) . ')</small>';
                }
            } else {
                echo '<span style="color: #999;">—</span>';
            }
        }
    }
}
