<?php
/**
 * Checkout integration class
 *
 * @package WC_CPF_Validator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_CPF_Validator_Checkout {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Check if validation is enabled
        if ( WC_CPF_Validator_Settings::get_option( 'enabled' ) !== 'yes' ) {
            return;
        }
        
        // Add/adjust CPF field on checkout (compatible with other plugins that already add billing_cpf).
        // Use a very late priority to re-add the field even if another plugin removes it on refresh.
        add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ), 9999 );
        
        // Validate CPF field
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_cpf_field' ) );
        
        // Save CPF to order
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_cpf_to_order' ) );
        
        // Display CPF in order details
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_cpf_in_admin_order' ) );
        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'display_cpf_in_order_details' ) );
        
        // Add scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // AJAX handler for real-time validation
        add_action( 'wp_ajax_validate_cpf', array( $this, 'ajax_validate_cpf' ) );
        add_action( 'wp_ajax_nopriv_validate_cpf', array( $this, 'ajax_validate_cpf' ) );
    }
    
    /**
     * Add/adjust CPF field in WooCommerce checkout fields.
     *
     * This makes the plugin compatible with plugins like:
     * Brazilian Market on WooCommerce (woocommerce-extra-checkout-fields-for-brazil),
     * which already provides the `billing_cpf` field.
     */
    public function filter_checkout_fields( $fields ) {
        if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            $fields['billing'] = array();
        }

        $required = WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes';
        $label = WC_CPF_Validator_Settings::get_option( 'field_label', 'CPF' );
        $placeholder = WC_CPF_Validator_Settings::get_option( 'field_placeholder', '000.000.000-00' );

        $position = WC_CPF_Validator_Settings::get_option( 'field_position', 'after_billing_email' );

        // If another plugin already added billing_cpf, reuse it and just enhance it.
        if ( isset( $fields['billing']['billing_cpf'] ) && is_array( $fields['billing']['billing_cpf'] ) ) {
            if ( empty( $fields['billing']['billing_cpf']['type'] ) ) {
                $fields['billing']['billing_cpf']['type'] = 'text';
            }

            // Only set label/placeholder if missing, to avoid fighting other plugins.
            if ( empty( $fields['billing']['billing_cpf']['label'] ) ) {
                $fields['billing']['billing_cpf']['label'] = $label;
            }
            if ( empty( $fields['billing']['billing_cpf']['placeholder'] ) ) {
                $fields['billing']['billing_cpf']['placeholder'] = $placeholder;
            }

            // Enforce required if configured here.
            $fields['billing']['billing_cpf']['required'] = $required;

            // Ensure our wrapper class exists so CSS/JS can target the field row.
            if ( empty( $fields['billing']['billing_cpf']['class'] ) || ! is_array( $fields['billing']['billing_cpf']['class'] ) ) {
                $fields['billing']['billing_cpf']['class'] = array();
            }
            foreach ( array( 'form-row-wide', 'wc-cpf-validator-input' ) as $needed_class ) {
                if ( ! in_array( $needed_class, $fields['billing']['billing_cpf']['class'], true ) ) {
                    $fields['billing']['billing_cpf']['class'][] = $needed_class;
                }
            }

            // Ensure maxlength attribute exists.
            if ( empty( $fields['billing']['billing_cpf']['custom_attributes'] ) || ! is_array( $fields['billing']['billing_cpf']['custom_attributes'] ) ) {
                $fields['billing']['billing_cpf']['custom_attributes'] = array();
            }
            if ( empty( $fields['billing']['billing_cpf']['custom_attributes']['maxlength'] ) ) {
                $fields['billing']['billing_cpf']['custom_attributes']['maxlength'] = '14';
            }

            // Adjust priority so it lands near the configured position.
            $fields['billing']['billing_cpf']['priority'] = $this->calculate_cpf_priority( $fields['billing'], $position );

            return $fields;
        }

        // Otherwise, add the field ourselves.
        $fields['billing']['billing_cpf'] = array(
            'type'              => 'text',
            'label'             => $label,
            'placeholder'       => $placeholder,
            'required'          => $required,
            'class'             => array( 'form-row-wide', 'wc-cpf-validator-input' ),
            'custom_attributes' => array(
                'maxlength' => '14',
            ),
            'priority'          => $this->calculate_cpf_priority( $fields['billing'], $position ),
        );

        return $fields;
    }

    /**
     * Calculate a priority value for billing_cpf based on the desired position.
     */
    private function calculate_cpf_priority( $billing_fields, $position ) {
        $defaults = array(
            'billing_first_name' => 10,
            'billing_last_name'  => 20,
            'billing_email'      => 110,
            'billing_phone'      => 100,
        );

        $ref_field = 'billing_email';
        $offset = 1;

        switch ( $position ) {
            case 'before_billing_first_name':
                $ref_field = 'billing_first_name';
                $offset = -1;
                break;
            case 'after_billing_last_name':
                $ref_field = 'billing_last_name';
                $offset = 1;
                break;
            case 'after_billing_phone':
                $ref_field = 'billing_phone';
                $offset = 1;
                break;
            case 'after_billing_email':
            default:
                $ref_field = 'billing_email';
                $offset = 1;
                break;
        }

        $ref_priority = $defaults[ $ref_field ] ?? 100;
        if ( isset( $billing_fields[ $ref_field ] ) && is_array( $billing_fields[ $ref_field ] ) && isset( $billing_fields[ $ref_field ]['priority'] ) ) {
            $ref_priority = (int) $billing_fields[ $ref_field ]['priority'];
        }

        $priority = $ref_priority + $offset;
        if ( $priority < 1 ) {
            $priority = 1;
        }

        return $priority;
    }
    
    /**
     * Validate CPF field
     */
    public function validate_cpf_field() {
        $cpf = isset( $_POST['billing_cpf'] ) ? sanitize_text_field( $_POST['billing_cpf'] ) : '';
        
        // Check if required
        if ( WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes' && empty( $cpf ) ) {
            wc_add_notice( __( 'CPF é um campo obrigatório.', 'wc-cpf-validator' ), 'error' );
            return;
        }
        
        // If not required and empty, skip validation
        if ( empty( $cpf ) ) {
            return;
        }
        
        // Validate CPF with API
        $validation = WC_CPF_Validator_API::validate_cpf_api( $cpf );
        
        if ( ! $validation['valid'] ) {
            wc_add_notice( $validation['message'], 'error' );
        }
    }
    
    /**
     * Save CPF to order meta
     */
    public function save_cpf_to_order( $order_id ) {
        if ( isset( $_POST['billing_cpf'] ) ) {
            $cpf = sanitize_text_field( $_POST['billing_cpf'] );
            
            // Format and save CPF
            $cpf_formatted = WC_CPF_Validator_API::format_cpf( $cpf );
            update_post_meta( $order_id, '_billing_cpf', $cpf_formatted );
            
            // Save additional data if enabled
            if ( WC_CPF_Validator_Settings::get_option( 'save_data' ) === 'yes' ) {
                $validation = WC_CPF_Validator_API::validate_cpf_api( $cpf );
                
                if ( $validation['valid'] && isset( $validation['data'] ) ) {
                    $data = $validation['data'];
                    
                    // Save relevant data
                    if ( isset( $data['nome'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_nome', sanitize_text_field( $data['nome'] ) );
                    }
                    if ( isset( $data['nascimento'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_nascimento', sanitize_text_field( $data['nascimento'] ) );
                    }
                    if ( isset( $data['genero'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_genero', sanitize_text_field( $data['genero'] ) );
                    }
                    if ( isset( $data['situacao'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_situacao', sanitize_text_field( $data['situacao'] ) );
                    }
                    
                    // Save full API response for reference
                    update_post_meta( $order_id, '_billing_cpf_api_data', json_encode( $data ) );
                }
            }
        }
    }
    
    /**
     * Display CPF in admin order page
     */
    public function display_cpf_in_admin_order( $order ) {
        $order_id = $order->get_id();
        $cpf = get_post_meta( $order_id, '_billing_cpf', true );
        
        if ( $cpf ) {
            echo '<p><strong>' . __( 'CPF:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $cpf ) . '</p>';
            
            // Display additional data if available
            $nome = get_post_meta( $order_id, '_billing_cpf_nome', true );
            if ( $nome ) {
                echo '<p><strong>' . __( 'Nome (CPF):', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $nome ) . '</p>';
            }
            
            $nascimento = get_post_meta( $order_id, '_billing_cpf_nascimento', true );
            if ( $nascimento ) {
                echo '<p><strong>' . __( 'Data de Nascimento:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $nascimento ) . '</p>';
            }
            
            $situacao = get_post_meta( $order_id, '_billing_cpf_situacao', true );
            if ( $situacao ) {
                $status_class = ( $situacao === 'Regular' ) ? 'status-completed' : 'status-failed';
                echo '<p><strong>' . __( 'Situação CPF:', 'wc-cpf-validator' ) . '</strong> <span class="' . $status_class . '">' . esc_html( $situacao ) . '</span></p>';
            }
        }
    }
    
    /**
     * Display CPF in customer order details
     */
    public function display_cpf_in_order_details( $order ) {
        $order_id = $order->get_id();
        $cpf = get_post_meta( $order_id, '_billing_cpf', true );
        
        if ( $cpf ) {
            echo '<p><strong>' . __( 'CPF:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $cpf ) . '</p>';
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        if ( ! is_checkout() && ! $this->is_funnelkit_checkout() ) {
            return;
        }

        // Enqueue jQuery Mask Plugin
        wp_enqueue_script(
            'jquery-mask',
            'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js',
            array( 'jquery' ),
            '1.14.16',
            true
        );

        wp_enqueue_script(
            'wc-cpf-validator',
            WC_CPF_VALIDATOR_PLUGIN_URL . 'assets/js/checkout.js',
            array( 'jquery', 'jquery-mask' ),
            WC_CPF_VALIDATOR_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wc-cpf-validator',
            WC_CPF_VALIDATOR_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            WC_CPF_VALIDATOR_VERSION
        );
        
        // Localize script
        wp_localize_script( 'wc-cpf-validator', 'wcCpfValidator', array(
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wc_cpf_validator_nonce' ),
            'realtime'       => WC_CPF_Validator_Settings::get_option( 'realtime' ) === 'yes',
            'validating'     => __( 'Validando CPF...', 'wc-cpf-validator' ),
            'valid'          => __( 'CPF válido', 'wc-cpf-validator' ),
            'invalid'        => __( 'CPF inválido', 'wc-cpf-validator' ),
            'fieldLabel'     => WC_CPF_Validator_Settings::get_option( 'field_label', 'CPF' ),
            'fieldPlaceholder' => WC_CPF_Validator_Settings::get_option( 'field_placeholder', '000.000.000-00' ),
            'required'       => WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes',
        ) );
    }

    /**
     * Detect FunnelKit checkout pages (AeroCheckout / WFACP).
     */
    private function is_funnelkit_checkout() {
        // Plugin presence
        if ( class_exists( 'WFACP_Core' ) || defined( 'WFACP_VERSION' ) ) {
            // If helper functions exist, trust them.
            if ( function_exists( 'wfacp_is_checkout' ) ) {
                try {
                    return (bool) wfacp_is_checkout();
                } catch ( Exception $e ) {
                    // ignore
                }
            }
            if ( function_exists( 'wfacp_is_checkout_page' ) ) {
                try {
                    return (bool) wfacp_is_checkout_page();
                } catch ( Exception $e ) {
                    // ignore
                }
            }
            // Otherwise, fall through to generic detection below.
        }

        // Common helper functions (if available).
        if ( function_exists( 'wfacp_is_checkout' ) ) {
            try {
                return (bool) wfacp_is_checkout();
            } catch ( Exception $e ) {
                // ignore
            }
        }
        if ( function_exists( 'wfacp_is_checkout_page' ) ) {
            try {
                return (bool) wfacp_is_checkout_page();
            } catch ( Exception $e ) {
                // ignore
            }
        }

        // Common CPT used by FunnelKit checkouts.
        if ( function_exists( 'post_type_exists' ) && post_type_exists( 'wfacp_checkout' ) && function_exists( 'is_singular' ) && is_singular( 'wfacp_checkout' ) ) {
            return true;
        }

        // Fallback: query var commonly used by FunnelKit.
        if ( isset( $_GET['wfacp_id'] ) ) {
            return true;
        }

        return false;
    }
    
    /**
     * AJAX handler for CPF validation
     */
    public function ajax_validate_cpf() {
        check_ajax_referer( 'wc_cpf_validator_nonce', 'nonce' );
        
        $cpf = isset( $_POST['cpf'] ) ? sanitize_text_field( $_POST['cpf'] ) : '';
        
        if ( empty( $cpf ) ) {
            wp_send_json_error( array(
                'message' => __( 'CPF não informado.', 'wc-cpf-validator' )
            ) );
        }
        
        // Validate CPF
        $validation = WC_CPF_Validator_API::validate_cpf_api( $cpf );
        
        if ( $validation['valid'] ) {
            wp_send_json_success( array(
                'message' => $validation['message'],
                'data'    => isset( $validation['data'] ) ? $validation['data'] : array()
            ) );
        } else {
            wp_send_json_error( array(
                'message' => $validation['message']
            ) );
        }
    }
}
