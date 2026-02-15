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
        if ( WC_CPF_Validator_Settings::get_option( 'enabled' ) !== 'yes' ) {
            return;
        }
        add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ), 9999 );
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_cpf_field' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_cpf_to_order' ) );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_cpf_in_admin_order' ) );
        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'display_cpf_in_order_details' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_validate_cpf', array( $this, 'ajax_validate_cpf' ) );
        add_action( 'wp_ajax_nopriv_validate_cpf', array( $this, 'ajax_validate_cpf' ) );
        add_action( 'wp_ajax_validate_lookalike_contact', array( $this, 'ajax_validate_lookalike_contact' ) );
        add_action( 'wp_ajax_nopriv_validate_lookalike_contact', array( $this, 'ajax_validate_lookalike_contact' ) );
        add_action( 'template_redirect', array( $this, 'maybe_redirect_to_whatsapp' ), 5 );
    }

    /** Add/adjust CPF field (compatible with Brazilian Market, FunnelKit, etc.). */
    public function filter_checkout_fields( $fields ) {
        if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            $fields['billing'] = array();
        }

        $required = WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes';
        $label = WC_CPF_Validator_Settings::get_option( 'field_label', 'CPF' );
        $placeholder = WC_CPF_Validator_Settings::get_option( 'field_placeholder', '000.000.000-00' );
        $validate_cnpj = WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes';

        $position = WC_CPF_Validator_Settings::get_option( 'field_position', 'after_billing_email' );
        if ( isset( $fields['billing']['billing_cpf'] ) && is_array( $fields['billing']['billing_cpf'] ) ) {
            if ( empty( $fields['billing']['billing_cpf']['type'] ) ) {
                $fields['billing']['billing_cpf']['type'] = 'text';
            }
            if ( empty( $fields['billing']['billing_cpf']['label'] ) ) {
                $fields['billing']['billing_cpf']['label'] = $label;
            }
            if ( empty( $fields['billing']['billing_cpf']['placeholder'] ) ) {
                $fields['billing']['billing_cpf']['placeholder'] = $placeholder;
            }
            $fields['billing']['billing_cpf']['required'] = $required;
            if ( empty( $fields['billing']['billing_cpf']['class'] ) || ! is_array( $fields['billing']['billing_cpf']['class'] ) ) {
                $fields['billing']['billing_cpf']['class'] = array();
            }
            foreach ( array( 'form-row-wide', 'wc-cpf-validator-input' ) as $needed_class ) {
                if ( ! in_array( $needed_class, $fields['billing']['billing_cpf']['class'], true ) ) {
                    $fields['billing']['billing_cpf']['class'][] = $needed_class;
                }
            }
            if ( empty( $fields['billing']['billing_cpf']['custom_attributes'] ) || ! is_array( $fields['billing']['billing_cpf']['custom_attributes'] ) ) {
                $fields['billing']['billing_cpf']['custom_attributes'] = array();
            }
            if ( empty( $fields['billing']['billing_cpf']['custom_attributes']['maxlength'] ) ) {
                $fields['billing']['billing_cpf']['custom_attributes']['maxlength'] = '14';
            }
            $fields['billing']['billing_cpf']['priority'] = $this->calculate_cpf_priority( $fields['billing'], $position );
        }
        if ( ! isset( $fields['billing']['billing_cpf'] ) ) {
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

    public function validate_cpf_field() {
        $cpf_raw = isset( $_POST['billing_cpf'] ) ? sanitize_text_field( $_POST['billing_cpf'] ) : '';
        $cnpj_enabled = WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes';
        $cnpj_raw = $cnpj_enabled && isset( $_POST['billing_cnpj'] ) ? sanitize_text_field( $_POST['billing_cnpj'] ) : '';

        $cpf_digits = preg_replace( '/[^0-9]/', '', $cpf_raw );
        $cnpj_digits = preg_replace( '/[^0-9]/', '', $cnpj_raw );

        $required = WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes';
        if ( $required && strlen( $cpf_digits ) === 0 && strlen( $cnpj_digits ) === 0 ) {
            wc_add_notice( __( 'Informe e valide o CPF ou o CNPJ para finalizar a compra.', 'wc-cpf-validator' ), 'error' );
            return;
        }
        if ( strlen( $cpf_digits ) > 0 && strlen( $cpf_digits ) !== 11 ) {
            wc_add_notice( __( 'Informe um CPF com 11 dígitos e valide antes de finalizar a compra.', 'wc-cpf-validator' ), 'error' );
        }
        if ( $cnpj_enabled && strlen( $cnpj_digits ) > 0 && strlen( $cnpj_digits ) !== 14 ) {
            wc_add_notice( __( 'Informe um CNPJ com 14 dígitos e valide antes de finalizar a compra.', 'wc-cpf-validator' ), 'error' );
        }
        if ( strlen( $cpf_digits ) === 11 ) {
            $validation = WC_CPF_Validator_API::validate_cpf_api( $cpf_raw );
            if ( ! $validation['valid'] ) {
                wc_add_notice( $validation['message'], 'error' );
            } else {
                $nome_api = '';
                if ( isset( $validation['data'] ) && is_array( $validation['data'] ) ) {
                    if ( ! empty( $validation['data']['nome'] ) ) {
                        $nome_api = sanitize_text_field( $validation['data']['nome'] );
                    } elseif ( ! empty( $validation['data']['data']['name'] ) ) {
                        $nome_api = sanitize_text_field( $validation['data']['data']['name'] );
                    }
                }
                WC_CPF_Validator_Logger::log( 'info', __( 'CPF validado', 'wc-cpf-validator' ), array(
                    'cpf_masked' => $this->mask_cpf_for_log( $cpf_digits ),
                    'first_name' => $nome_api,
                    'last_name'  => '',
                ) );
                $package_id = WC_CPF_Validator_Settings::get_option( 'api_package', '1' );
                $lookalike_contact = WC_CPF_Validator_Settings::get_option( 'lookalike_validate_contact' ) === 'yes';
                if ( (string) $package_id === '21' && $lookalike_contact && isset( $validation['data'] ) && is_array( $validation['data'] ) ) {
                    $api_data = $validation['data'];
                    WC_CPF_Validator_API::store_lookalike_data_for_cpf( $cpf_digits, $api_data );
                    if ( WC_CPF_Validator_API::get_lookalike_data_for_cpf( $cpf_digits ) === null && isset( $api_data['data'] ) && is_array( $api_data['data'] ) ) {
                        WC_CPF_Validator_API::store_lookalike_data_for_cpf( $cpf_digits, $api_data['data'] );
                    }
                }
            }
        }
        if ( $cnpj_enabled && strlen( $cnpj_digits ) === 14 ) {
            $cnpj_validation = WC_CPF_Validator_API::validate_cpf_api( $cnpj_raw );
            if ( ! $cnpj_validation['valid'] ) {
                wc_add_notice( $cnpj_validation['message'], 'error' );
            }
        }
        if ( strlen( $cpf_digits ) === 11 ) {
            $this->validate_lookalike_contact( $cpf_digits );
        }
    }

    private function validate_lookalike_contact( $cpf_digits ) {
        if ( WC_CPF_Validator_Settings::get_option( 'lookalike_validate_contact' ) !== 'yes' ) {
            return;
        }
        if ( (string) WC_CPF_Validator_Settings::get_option( 'api_package', '1' ) !== '21' ) {
            return;
        }

        $lookalike = WC_CPF_Validator_API::get_lookalike_data_for_cpf( $cpf_digits );
        if ( $lookalike === null ) {
            $first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
            $last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
            $email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
            $phone  = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
            $cellphone = isset( $_POST['billing_cellphone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cellphone'] ) ) : '';
            WC_CPF_Validator_Logger::log( 'warning', __( 'Lookalike: sem dados de e-mail/telefone para o CPF', 'wc-cpf-validator' ), array(
                'cpf_masked' => $this->mask_cpf_for_log( $cpf_digits ),
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
                'cellphone'  => $cellphone,
            ) );
            wc_add_notice( __( 'Não foi possível validar e-mail e telefone para este CPF. Valide o CPF novamente antes de finalizar.', 'wc-cpf-validator' ), 'error' );
            return;
        }

        $first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
        $last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
        $email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
        $phone  = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
        $cellphone = isset( $_POST['billing_cellphone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cellphone'] ) ) : '';
        $has_phone = ( $phone !== '' || $cellphone !== '' );

        $log_ctx_base = array(
            'cpf_masked'  => $this->mask_cpf_for_log( $cpf_digits ),
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'email'       => $email,
            'phone'       => $phone,
            'cellphone'   => $cellphone,
        );

        $session = WC()->session;
        $email_attempts = 0;
        $phone_attempts = 0;
        $whatsapp_url = '';
        if ( $session ) {
            $email_attempts = (int) $session->get( 'wc_cpf_validator_lookalike_email_attempts', 0 );
            $phone_attempts = (int) $session->get( 'wc_cpf_validator_lookalike_phone_attempts', 0 );
            $whatsapp_url = WC_CPF_Validator_Settings::get_option( 'lookalike_whatsapp_url', '' );
            $whatsapp_url = is_string( $whatsapp_url ) ? trim( $whatsapp_url ) : '';
        }

        $max_attempts = 3;

        if ( ! empty( $lookalike['emails'] ) ) {
            $email_ok = WC_CPF_Validator_API::lookalike_email_matches( $cpf_digits, $email );
            if ( ! $email_ok ) {
                if ( $session ) {
                    $email_attempts++;
                    $session->set( 'wc_cpf_validator_lookalike_email_attempts', $email_attempts );
                }
                WC_CPF_Validator_Logger::log( 'warning', __( 'E-mail não vinculado ao CPF', 'wc-cpf-validator' ), array_merge( $log_ctx_base, array( 'email_valid' => false ) ) );
                wc_add_notice( __( 'Erro ao preencher o e-mail: este e-mail não está vinculado com seu CPF, tente novamente ou fale com nossa equipe.', 'wc-cpf-validator' ), 'error' );
                if ( $session && $email_attempts >= $max_attempts && $whatsapp_url !== '' ) {
                    $session->set( 'wc_cpf_validator_redirect_whatsapp', $whatsapp_url );
                    wc_add_notice( __( 'Após 3 tentativas incorretas, você será redirecionado para o atendimento.', 'wc-cpf-validator' ), 'error' );
                }
                return;
            }
            if ( $session ) {
                $session->set( 'wc_cpf_validator_lookalike_email_attempts', 0 );
            }
        }

        if ( ! empty( $lookalike['phones'] ) ) {
            $phone_ok = false;
            if ( $has_phone ) {
                $phone_ok_phone  = ( $phone !== '' ) ? WC_CPF_Validator_API::lookalike_phone_matches( $cpf_digits, $phone ) : false;
                $phone_ok_cell   = ( $cellphone !== '' ) ? WC_CPF_Validator_API::lookalike_phone_matches( $cpf_digits, $cellphone ) : false;
                $phone_ok = $phone_ok_phone || $phone_ok_cell;
            }
            if ( ! $has_phone ) {
                WC_CPF_Validator_Logger::log( 'warning', __( 'Telefone não informado (obrigatório para o CPF)', 'wc-cpf-validator' ), array_merge( $log_ctx_base, array( 'phone_valid' => false ) ) );
                wc_add_notice( __( 'Informe um telefone vinculado ao CPF.', 'wc-cpf-validator' ), 'error' );
                return;
            }
            if ( $has_phone && ! $phone_ok ) {
                if ( $session ) {
                    $phone_attempts++;
                    $session->set( 'wc_cpf_validator_lookalike_phone_attempts', $phone_attempts );
                }
                WC_CPF_Validator_Logger::log( 'warning', __( 'Telefone não vinculado ao CPF', 'wc-cpf-validator' ), array_merge( $log_ctx_base, array( 'phone_valid' => false ) ) );
                wc_add_notice( __( 'Erro ao preencher o telefone: este número de telefone não está vinculado com seu CPF, tente novamente ou fale com nossa equipe.', 'wc-cpf-validator' ), 'error' );
                if ( $session && $phone_attempts >= $max_attempts && $whatsapp_url !== '' ) {
                    $session->set( 'wc_cpf_validator_redirect_whatsapp', $whatsapp_url );
                    wc_add_notice( __( 'Após 3 tentativas incorretas, você será redirecionado para o atendimento.', 'wc-cpf-validator' ), 'error' );
                }
                return;
            }
            if ( $session && $has_phone ) {
                $session->set( 'wc_cpf_validator_lookalike_phone_attempts', 0 );
            }
        }

        $success_fp = 'ajax_' . md5( $cpf_digits . '|' . $email . '|' . $phone . '|' . $cellphone . '|ok' );
        if ( ! $this->should_skip_ajax_log_dedup( $success_fp ) ) {
            WC_CPF_Validator_Logger::log( 'info', __( 'E-mail e telefone válidos (Lookalike)', 'wc-cpf-validator' ), array_merge( $log_ctx_base, array( 'email_valid' => true, 'phone_valid' => true ) ) );
        }
    }

    private function mask_cpf_for_log( $cpf_digits ) {
        $cpf_digits = preg_replace( '/[^0-9]/', '', $cpf_digits );
        if ( strlen( $cpf_digits ) !== 11 ) {
            return '***';
        }
        return substr( $cpf_digits, 0, 3 ) . '.***.***-' . substr( $cpf_digits, -2 );
    }

    /** Dedup: only first request in 2-min window logs (add_option is atomic). */
    private function should_skip_ajax_log_dedup( $log_fingerprint ) {
        $bucket = (int) floor( time() / 120 );
        $option_key = 'wc_cpf_val_dedup_' . $log_fingerprint . '_' . $bucket;
        $added = add_option( $option_key, '1', '', 'no' );
        return ! $added;
    }

    public function maybe_redirect_to_whatsapp() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        $url = WC()->session->get( 'wc_cpf_validator_redirect_whatsapp' );
        if ( empty( $url ) || ! is_string( $url ) ) {
            return;
        }
        $url = esc_url_raw( $url );
        if ( $url === '' ) {
            WC()->session->set( 'wc_cpf_validator_redirect_whatsapp', null );
            return;
        }
        WC()->session->set( 'wc_cpf_validator_redirect_whatsapp', null );
        WC()->session->set( 'wc_cpf_validator_lookalike_email_attempts', 0 );
        WC()->session->set( 'wc_cpf_validator_lookalike_phone_attempts', 0 );
        wp_safe_redirect( $url, 302 );
        exit;
    }

    public function save_cpf_to_order( $order_id ) {
        if ( isset( $_POST['billing_cpf'] ) ) {
            $cpf = sanitize_text_field( $_POST['billing_cpf'] );
            $cpf_formatted = WC_CPF_Validator_API::format_cpf( $cpf );
            update_post_meta( $order_id, '_billing_cpf', $cpf_formatted );
            if ( WC_CPF_Validator_Settings::get_option( 'save_data' ) === 'yes' ) {
                $validation = WC_CPF_Validator_API::validate_cpf_api( $cpf );
                
                if ( $validation['valid'] && isset( $validation['data'] ) ) {
                    $data = $validation['data'];
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
                    update_post_meta( $order_id, '_billing_cpf_api_data', json_encode( $data ) );
                }
            }
        }
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

    public function display_cpf_in_admin_order( $order ) {
        $order_id = $order->get_id();
        $cpf = get_post_meta( $order_id, '_billing_cpf', true );
        $cnpj = get_post_meta( $order_id, '_billing_cnpj', true );
        
        if ( $cpf ) {
            echo '<p><strong>' . __( 'CPF:', 'wc-cpf-validator' ) . '</strong> ' . esc_html( $cpf ) . '</p>';
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

    public function enqueue_scripts() {
        if ( ! is_checkout() && ! $this->is_funnelkit_checkout() ) {
            return;
        }
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
        $lookalike_url = '';
        if ( WC_CPF_Validator_Settings::get_option( 'lookalike_validate_contact' ) === 'yes' && (string) WC_CPF_Validator_Settings::get_option( 'api_package', '1' ) === '21' ) {
            $lookalike_url = WC_CPF_Validator_Settings::get_option( 'lookalike_whatsapp_url', '' );
            $lookalike_url = is_string( $lookalike_url ) ? trim( $lookalike_url ) : '';
            $lookalike_url = $lookalike_url !== '' ? esc_url_raw( $lookalike_url ) : '';
        }

        wp_localize_script( 'wc-cpf-validator', 'wcCpfValidator', array(
            'ajax_url'               => admin_url( 'admin-ajax.php' ),
            'nonce'                  => wp_create_nonce( 'wc_cpf_validator_nonce' ),
            'realtime'               => WC_CPF_Validator_Settings::get_option( 'realtime' ) === 'yes',
            'validateCnpj'           => WC_CPF_Validator_Settings::get_option( 'validate_cnpj' ) === 'yes',
            'validating'             => __( 'Validando CPF...', 'wc-cpf-validator' ),
            'valid'                  => __( 'CPF válido', 'wc-cpf-validator' ),
            'invalid'                => __( 'CPF inválido', 'wc-cpf-validator' ),
            'cnpjValidating'         => __( 'Validando CNPJ...', 'wc-cpf-validator' ),
            'cnpjValid'              => __( 'CNPJ válido', 'wc-cpf-validator' ),
            'cnpjInvalid'            => __( 'CNPJ inválido', 'wc-cpf-validator' ),
            'fieldLabel'             => WC_CPF_Validator_Settings::get_option( 'field_label', 'CPF' ),
            'fieldPlaceholder'       => WC_CPF_Validator_Settings::get_option( 'field_placeholder', '000.000.000-00' ),
            'required'               => WC_CPF_Validator_Settings::get_option( 'required' ) === 'yes',
            'lookalike_whatsapp_url' => $lookalike_url,
        ) );
    }

    private function is_funnelkit_checkout() {
        if ( class_exists( 'WFACP_Core' ) || defined( 'WFACP_VERSION' ) ) {
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
        }
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
        if ( function_exists( 'post_type_exists' ) && post_type_exists( 'wfacp_checkout' ) && function_exists( 'is_singular' ) && is_singular( 'wfacp_checkout' ) ) {
            return true;
        }
        if ( isset( $_GET['wfacp_id'] ) ) {
            return true;
        }

        return false;
    }

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
            $cpf_clean = preg_replace( '/[^0-9]/', '', $cpf );
            $package_id = WC_CPF_Validator_Settings::get_option( 'api_package', '1' );
            $lookalike_contact = WC_CPF_Validator_Settings::get_option( 'lookalike_validate_contact' ) === 'yes';
            $payload = array(
                'message' => $validation['message'],
                'data'    => isset( $validation['data'] ) ? $validation['data'] : array()
            );
            if ( (string) $package_id === '21' && strlen( $cpf_clean ) === 11 && isset( $validation['data'] ) && is_array( $validation['data'] ) ) {
                if ( $lookalike_contact ) {
                    WC_CPF_Validator_API::store_lookalike_data_for_cpf( $cpf_clean, $validation['data'] );
                }
                $lists = WC_CPF_Validator_API::extract_lookalike_contact_lists( $validation['data'] );
                $payload['lookalike_emails']  = isset( $lists['emails'] ) ? $lists['emails'] : array();
                $payload['lookalike_phones'] = isset( $lists['phones'] ) ? $lists['phones'] : array();
            }
            wp_send_json_success( $payload );
        } else {
            wp_send_json_error( array(
                'message' => $validation['message']
            ) );
        }
    }

    public function ajax_validate_lookalike_contact() {
        check_ajax_referer( 'wc_cpf_validator_nonce', 'nonce' );

        if ( WC_CPF_Validator_Settings::get_option( 'lookalike_validate_contact' ) !== 'yes' ) {
            wp_send_json_success( array( 'email_valid' => true, 'phone_valid' => true ) );
        }
        if ( (string) WC_CPF_Validator_Settings::get_option( 'api_package', '1' ) !== '21' ) {
            wp_send_json_success( array( 'email_valid' => true, 'phone_valid' => true ) );
        }

        $cpf = isset( $_POST['cpf'] ) ? sanitize_text_field( wp_unslash( $_POST['cpf'] ) ) : '';
        $cpf_digits = preg_replace( '/[^0-9]/', '', $cpf );
        if ( strlen( $cpf_digits ) !== 11 ) {
            wp_send_json_success( array( 'email_valid' => true, 'phone_valid' => true ) );
        }

        $email     = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
        $phone     = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
        $cellphone = isset( $_POST['billing_cellphone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cellphone'] ) ) : '';

        $lookalike = WC_CPF_Validator_API::get_lookalike_data_for_cpf( $cpf_digits );
        if ( $lookalike === null ) {
            wp_send_json_success( array( 'email_valid' => true, 'phone_valid' => true ) );
        }

        $email_valid = true;
        $phone_valid = true;
        $message_email = '';
        $message_phone  = '';

        if ( ! empty( $lookalike['emails'] ) ) {
            $email_valid = WC_CPF_Validator_API::lookalike_email_matches( $cpf_digits, $email );
            if ( ! $email_valid ) {
                $message_email = __( 'Erro ao preencher o e-mail: este e-mail não está vinculado com seu CPF, tente novamente ou fale com nossa equipe.', 'wc-cpf-validator' );
            }
        }

        if ( ! empty( $lookalike['phones'] ) ) {
            $has_phone = ( $phone !== '' || $cellphone !== '' );
            if ( $has_phone ) {
                $phone_ok_phone  = ( $phone !== '' ) ? WC_CPF_Validator_API::lookalike_phone_matches( $cpf_digits, $phone ) : false;
                $phone_ok_cell   = ( $cellphone !== '' ) ? WC_CPF_Validator_API::lookalike_phone_matches( $cpf_digits, $cellphone ) : false;
                $phone_valid = $phone_ok_phone || $phone_ok_cell;
            } else {
                $phone_valid = false;
            }
            if ( ! $phone_valid ) {
                $message_phone = __( 'Erro ao preencher o telefone: este número de telefone não está vinculado com seu CPF, tente novamente ou fale com nossa equipe.', 'wc-cpf-validator' );
            }
        }
        $has_phone_filled = ( $phone !== '' || $cellphone !== '' );
        $log_email_fail = ( ! $email_valid && $email !== '' && ! empty( $lookalike['emails'] ) );
        $log_phone_fail = ( ! $phone_valid && $has_phone_filled && ! empty( $lookalike['phones'] ) );
        $log_success = ( $email_valid && $phone_valid && ( $email !== '' || $has_phone_filled ) && ( ! empty( $lookalike['emails'] ) || ! empty( $lookalike['phones'] ) ) );

        if ( $log_email_fail || $log_phone_fail || $log_success ) {
            $first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
            $last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
            $log_ctx = array(
                'cpf_masked'  => $this->mask_cpf_for_log( $cpf_digits ),
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'email'       => $email,
                'phone'       => $phone,
                'cellphone'   => $cellphone,
                'email_valid' => $email_valid,
                'phone_valid' => $phone_valid,
            );
            $suffix = $log_success ? 'ok' : ( ( $log_email_fail ? 'e' : '' ) . ( $log_phone_fail ? 'p' : '' ) );
            $log_fingerprint = 'ajax_' . md5( $cpf_digits . '|' . $email . '|' . $phone . '|' . $cellphone . '|' . $suffix );
            if ( $this->should_skip_ajax_log_dedup( $log_fingerprint ) ) {
                // Evita duplicar: mesmo evento já registrado nos últimos 2 minutos
            } else {
                if ( $log_success ) {
                    WC_CPF_Validator_Logger::log( 'info', __( 'E-mail e telefone válidos (validação em tempo real)', 'wc-cpf-validator' ), $log_ctx );
                } elseif ( $log_email_fail && $log_phone_fail ) {
                    WC_CPF_Validator_Logger::log( 'warning', __( 'E-mail e telefone não vinculados ao CPF (validação em tempo real)', 'wc-cpf-validator' ), $log_ctx );
                } elseif ( $log_email_fail ) {
                    WC_CPF_Validator_Logger::log( 'warning', __( 'E-mail não vinculado ao CPF (validação em tempo real)', 'wc-cpf-validator' ), $log_ctx );
                } else {
                    WC_CPF_Validator_Logger::log( 'warning', __( 'Telefone não vinculado ao CPF (validação em tempo real)', 'wc-cpf-validator' ), $log_ctx );
                }
            }
        }

        wp_send_json_success( array(
            'email_valid'      => $email_valid,
            'phone_valid'      => $phone_valid,
            'message_email'    => $message_email,
            'message_phone'    => $message_phone,
            'validates_email'  => ! empty( $lookalike['emails'] ),
            'validates_phone'  => ! empty( $lookalike['phones'] ),
        ) );
    }
}
