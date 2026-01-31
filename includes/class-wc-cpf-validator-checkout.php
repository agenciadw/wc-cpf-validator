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
        $validate_cnpj = WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes';

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
        }

        if ( ! isset( $fields['billing']['billing_cpf'] ) ) {
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
        }

        // CNPJ support (compatibility with Brazilian Market / FunnelKit when billing_cnpj exists)
        if ( $validate_cnpj ) {
            $cnpj_label = __( 'CNPJ', 'wc-cpf-validator' );
            $cnpj_placeholder = '00.000.000/0000-00';

            if ( isset( $fields['billing']['billing_cnpj'] ) && is_array( $fields['billing']['billing_cnpj'] ) ) {
                if ( empty( $fields['billing']['billing_cnpj']['type'] ) ) {
                    $fields['billing']['billing_cnpj']['type'] = 'text';
                }
                if ( empty( $fields['billing']['billing_cnpj']['label'] ) ) {
                    $fields['billing']['billing_cnpj']['label'] = $cnpj_label;
                }
                if ( empty( $fields['billing']['billing_cnpj']['placeholder'] ) ) {
                    $fields['billing']['billing_cnpj']['placeholder'] = $cnpj_placeholder;
                }
                if ( empty( $fields['billing']['billing_cnpj']['class'] ) || ! is_array( $fields['billing']['billing_cnpj']['class'] ) ) {
                    $fields['billing']['billing_cnpj']['class'] = array();
                }
                foreach ( array( 'form-row-wide', 'wc-cpf-validator-input', 'wc-cpf-validator-cnpj' ) as $needed_class ) {
                    if ( ! in_array( $needed_class, $fields['billing']['billing_cnpj']['class'], true ) ) {
                        $fields['billing']['billing_cnpj']['class'][] = $needed_class;
                    }
                }
                if ( empty( $fields['billing']['billing_cnpj']['custom_attributes'] ) || ! is_array( $fields['billing']['billing_cnpj']['custom_attributes'] ) ) {
                    $fields['billing']['billing_cnpj']['custom_attributes'] = array();
                }
                if ( empty( $fields['billing']['billing_cnpj']['custom_attributes']['maxlength'] ) ) {
                    $fields['billing']['billing_cnpj']['custom_attributes']['maxlength'] = '18';
                }
                if ( isset( $fields['billing']['billing_cpf']['priority'] ) ) {
                    $fields['billing']['billing_cnpj']['priority'] = (int) $fields['billing']['billing_cpf']['priority'] + 1;
                } elseif ( empty( $fields['billing']['billing_cnpj']['priority'] ) ) {
                    $fields['billing']['billing_cnpj']['priority'] = $this->calculate_cpf_priority( $fields['billing'], $position ) + 1;
                }
            } else {
                // If the store doesn't provide a CNPJ field, we can add one (only when enabled).
                $fields['billing']['billing_cnpj'] = array(
                    'type'              => 'text',
                    'label'             => $cnpj_label,
                    'placeholder'       => $cnpj_placeholder,
                    'required'          => false,
                    'class'             => array( 'form-row-wide', 'wc-cpf-validator-input', 'wc-cpf-validator-cnpj' ),
                    'custom_attributes' => array(
                        'maxlength' => '18',
                    ),
                    'priority'          => isset( $fields['billing']['billing_cpf']['priority'] )
                        ? (int) $fields['billing']['billing_cpf']['priority'] + 1
                        : $this->calculate_cpf_priority( $fields['billing'], $position ) + 1,
                );
            }
        }

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
     * Validate CPF/CNPJ field: only allow checkout with a validated CPF or CNPJ.
     * Blocks incomplete numbers and any number not validated by the API.
     */
    public function validate_cpf_field() {
        $cpf_raw = isset( $_POST['billing_cpf'] ) ? sanitize_text_field( $_POST['billing_cpf'] ) : '';
        $cnpj_enabled = WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes';
        $cnpj_raw = $cnpj_enabled && isset( $_POST['billing_cnpj'] ) ? sanitize_text_field( $_POST['billing_cnpj'] ) : '';

        $cpf_digits = preg_replace( '/[^0-9]/', '', $cpf_raw );
        $cnpj_digits = preg_replace( '/[^0-9]/', '', $cnpj_raw );

        $required = WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes';

        // Require at least one document when field is required.
        if ( $required && strlen( $cpf_digits ) === 0 && strlen( $cnpj_digits ) === 0 ) {
            wc_add_notice( __( 'Informe e valide o CPF ou o CNPJ para finalizar a compra.', 'wc-cpf-validator' ), 'error' );
            return;
        }

        // Block incomplete CPF (any digits but not 11).
        if ( strlen( $cpf_digits ) > 0 && strlen( $cpf_digits ) !== 11 ) {
            wc_add_notice( __( 'Informe um CPF com 11 dígitos e valide antes de finalizar a compra.', 'wc-cpf-validator' ), 'error' );
        }

        // Block incomplete CNPJ (any digits but not 14).
        if ( $cnpj_enabled && strlen( $cnpj_digits ) > 0 && strlen( $cnpj_digits ) !== 14 ) {
            wc_add_notice( __( 'Informe um CNPJ com 14 dígitos e valide antes de finalizar a compra.', 'wc-cpf-validator' ), 'error' );
        }

        // When CPF has 11 digits, it must be validated by API.
        if ( strlen( $cpf_digits ) === 11 ) {
            $validation = WC_CPF_Validator_API::validate_cpf_api( $cpf_raw );
            if ( ! $validation['valid'] ) {
                wc_add_notice( $validation['message'], 'error' );
            }
        }

        // When CNPJ has 14 digits (and validation enabled), it must be validated by API.
        if ( $cnpj_enabled && strlen( $cnpj_digits ) === 14 ) {
            $cnpj_validation = WC_CPF_Validator_API::validate_cpf_api( $cnpj_raw );
            if ( ! $cnpj_validation['valid'] ) {
                wc_add_notice( $cnpj_validation['message'], 'error' );
            }
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
                    // CPFHub uses "data.name"
                    if ( ! isset( $data['nome'] ) && isset( $data['data']['name'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_nome', sanitize_text_field( $data['data']['name'] ) );
                    }
                    if ( isset( $data['nascimento'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_nascimento', sanitize_text_field( $data['nascimento'] ) );
                    }
                    if ( ! isset( $data['nascimento'] ) && isset( $data['data']['birthDate'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_nascimento', sanitize_text_field( $data['data']['birthDate'] ) );
                    }
                    if ( isset( $data['genero'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_genero', sanitize_text_field( $data['genero'] ) );
                    }
                    if ( ! isset( $data['genero'] ) && isset( $data['data']['gender'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_genero', sanitize_text_field( $data['data']['gender'] ) );
                    }
                    if ( isset( $data['situacao'] ) ) {
                        update_post_meta( $order_id, '_billing_cpf_situacao', sanitize_text_field( $data['situacao'] ) );
                    }
                    
                    // Save full API response for reference
                    update_post_meta( $order_id, '_billing_cpf_api_data', json_encode( $data ) );
                }
            }
        }

        // Save CNPJ to order meta (when present)
        if ( isset( $_POST['billing_cnpj'] ) ) {
            $cnpj = sanitize_text_field( $_POST['billing_cnpj'] );
            if ( $cnpj !== '' ) {
                $cnpj_formatted = WC_CPF_Validator_API::format_cnpj( $cnpj );
                update_post_meta( $order_id, '_billing_cnpj', $cnpj_formatted );

                if ( WC_CPF_Validator_Settings::get_option( 'save_data' ) === 'yes' ) {
                    $validation = WC_CPF_Validator_API::validate_cpf_api( $cnpj );
                    if ( $validation['valid'] && isset( $validation['data'] ) ) {
                        $data = $validation['data'];

                        if ( isset( $data['razao'] ) ) {
                            update_post_meta( $order_id, '_billing_cnpj_razao', sanitize_text_field( $data['razao'] ) );
                        }
                        if ( isset( $data['fantasia'] ) ) {
                            update_post_meta( $order_id, '_billing_cnpj_fantasia', sanitize_text_field( $data['fantasia'] ) );
                        }
                        if ( isset( $data['situacao'][0]['nome'] ) ) {
                            update_post_meta( $order_id, '_billing_cnpj_situacao', sanitize_text_field( $data['situacao'][0]['nome'] ) );
                        }

                        update_post_meta( $order_id, '_billing_cnpj_api_data', wp_json_encode( $data ) );
                    }
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
        $cnpj = get_post_meta( $order_id, '_billing_cnpj', true );
        
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

        if ( $cnpj ) {
            echo '<p><strong>' . __( 'CNPJ:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $cnpj ) . '</p>';
            $razao = get_post_meta( $order_id, '_billing_cnpj_razao', true );
            if ( $razao ) {
                echo '<p><strong>' . __( 'Razão Social (CNPJ):', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $razao ) . '</p>';
            }
            $fantasia = get_post_meta( $order_id, '_billing_cnpj_fantasia', true );
            if ( $fantasia ) {
                echo '<p><strong>' . __( 'Nome Fantasia (CNPJ):', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $fantasia ) . '</p>';
            }
        }
    }
    
    /**
     * Display CPF in customer order details
     */
    public function display_cpf_in_order_details( $order ) {
        $order_id = $order->get_id();
        $cpf = get_post_meta( $order_id, '_billing_cpf', true );
        $cnpj = get_post_meta( $order_id, '_billing_cnpj', true );
        
        if ( $cpf ) {
            echo '<p><strong>' . __( 'CPF:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $cpf ) . '</p>';
        }
        if ( $cnpj ) {
            echo '<p><strong>' . __( 'CNPJ:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $cnpj ) . '</p>';
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        if ( ! is_checkout() && ! $this->is_funnelkit_checkout() ) {
            return;
        }

        // Cache-bust assets automatically (helps when JS is updated).
        $js_path  = WC_CPF_VALIDATOR_PLUGIN_DIR . 'assets/js/checkout.js';
        $css_path = WC_CPF_VALIDATOR_PLUGIN_DIR . 'assets/css/checkout.css';
        $js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WC_CPF_VALIDATOR_VERSION;
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WC_CPF_VALIDATOR_VERSION;

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
            $js_ver,
            true
        );
        
        wp_enqueue_style(
            'wc-cpf-validator',
            WC_CPF_VALIDATOR_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            $css_ver
        );
        
        // Localize script
        wp_localize_script( 'wc-cpf-validator', 'wcCpfValidator', array(
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wc_cpf_validator_nonce' ),
            'realtime'       => WC_CPF_Validator_Settings::get_option( 'realtime' ) === 'yes',
            'validateCnpj'   => WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes',
            'validating'     => __( 'Validando CPF...', 'wc-cpf-validator' ),
            'valid'          => __( 'CPF válido', 'wc-cpf-validator' ),
            'invalid'        => __( 'CPF inválido', 'wc-cpf-validator' ),
            'cnpjValidating' => __( 'Validando CNPJ...', 'wc-cpf-validator' ),
            'cnpjValid'      => __( 'CNPJ válido', 'wc-cpf-validator' ),
            'cnpjInvalid'    => __( 'CNPJ inválido', 'wc-cpf-validator' ),
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
                'message' => __( 'Documento não informado.', 'wc-cpf-validator' )
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
