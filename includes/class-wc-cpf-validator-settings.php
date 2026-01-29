<?php
/**
 * Settings class
 *
 * @package WC_CPF_Validator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_CPF_Validator_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter( 'woocommerce_get_sections_checkout', array( $this, 'add_section' ) );
        add_filter( 'woocommerce_get_settings_checkout', array( $this, 'add_settings' ), 10, 2 );
    }
    
    /**
     * Add CPF Validator section to WooCommerce Checkout settings
     */
    public function add_section( $sections ) {
        $sections['cpf_validator'] = __( 'Validação CPF', 'wc-cpf-validator' );
        return $sections;
    }
    
    /**
     * Add settings fields
     */
    public function add_settings( $settings, $current_section ) {
        if ( 'cpf_validator' !== $current_section ) {
            return $settings;
        }
        
        $custom_settings = array(
            array(
                'title' => __( 'Configurações de Validação de CPF', 'wc-cpf-validator' ),
                'type'  => 'title',
                'desc'  => __( 'Configure a integração com a API CPF.CNPJ para validação de CPF no checkout.', 'wc-cpf-validator' ),
                'id'    => 'wc_cpf_validator_settings'
            ),
            array(
                'title'   => __( 'Habilitar Validação', 'wc-cpf-validator' ),
                'desc'    => __( 'Habilitar validação de CPF no checkout', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_enabled',
                'default' => 'yes',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'Token da API', 'wc-cpf-validator' ),
                'desc'    => sprintf( 
                    __( 'Insira seu token da API CPF.CNPJ. Obtenha em %s', 'wc-cpf-validator' ),
                    '<a href="https://www.cpfcnpj.com.br/admin/tokens.html" target="_blank">https://www.cpfcnpj.com.br/admin/tokens.html</a>'
                ),
                'id'      => 'wc_cpf_validator_api_token',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width: 400px;'
            ),
            array(
                'title'   => __( 'Pacote da API', 'wc-cpf-validator' ),
                'desc'    => __( 'Selecione o pacote de CPF que você contratou', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_api_package',
                'type'    => 'select',
                'default' => '1',
                'options' => array(
                    '1'  => __( 'CPF A - Nome Completo (R$ 0,15)', 'wc-cpf-validator' ),
                    '7'  => __( 'CPF B - Nome + Data Nascimento (R$ 0,22)', 'wc-cpf-validator' ),
                    '2'  => __( 'CPF C - Nome + Data Nascimento + Mãe + Gênero (R$ 0,25)', 'wc-cpf-validator' ),
                    '8'  => __( 'CPF D - Nome + Nome Social + Data + Situação + Óbito + Comprovante (R$ 0,36)', 'wc-cpf-validator' ),
                    '9'  => __( 'CPF E - CPF D + Nome da Mãe + Gênero (R$ 0,47)', 'wc-cpf-validator' ),
                    '26' => __( 'CPF D Simplificado - Nome + Data + Situação (R$ 0,33)', 'wc-cpf-validator' ),
                )
            ),
            array(
                'title'   => __( 'Campo Obrigatório', 'wc-cpf-validator' ),
                'desc'    => __( 'Tornar o campo CPF obrigatório no checkout', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_required',
                'default' => 'yes',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'Validação em Tempo Real', 'wc-cpf-validator' ),
                'desc'    => __( 'Validar CPF com a API em tempo real (ao digitar). Se desabilitado, valida apenas ao enviar o pedido.', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_realtime',
                'default' => 'yes',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'Salvar Dados da API', 'wc-cpf-validator' ),
                'desc'    => __( 'Salvar dados retornados pela API nos metadados do pedido (nome, data nascimento, etc)', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_save_data',
                'default' => 'yes',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'Posição do Campo', 'wc-cpf-validator' ),
                'desc'    => __( 'Escolha onde o campo CPF deve aparecer no formulário de checkout', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_field_position',
                'type'    => 'select',
                'default' => 'after_billing_email',
                'options' => array(
                    'before_billing_first_name' => __( 'Antes do Primeiro Nome', 'wc-cpf-validator' ),
                    'after_billing_last_name'   => __( 'Depois do Sobrenome', 'wc-cpf-validator' ),
                    'after_billing_email'       => __( 'Depois do Email', 'wc-cpf-validator' ),
                    'after_billing_phone'       => __( 'Depois do Telefone', 'wc-cpf-validator' ),
                )
            ),
            array(
                'title'   => __( 'Rótulo do Campo', 'wc-cpf-validator' ),
                'desc'    => __( 'Texto que aparece acima do campo CPF', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_field_label',
                'type'    => 'text',
                'default' => 'CPF',
                'css'     => 'width: 300px;'
            ),
            array(
                'title'   => __( 'Placeholder do Campo', 'wc-cpf-validator' ),
                'desc'    => __( 'Texto de exemplo dentro do campo CPF', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_field_placeholder',
                'type'    => 'text',
                'default' => '000.000.000-00',
                'css'     => 'width: 300px;'
            ),
            array(
                'title'   => __( 'Modo de Teste', 'wc-cpf-validator' ),
                'desc'    => __( 'Usar token de teste da API (retorna dados fictícios)', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_test_mode',
                'default' => 'no',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'Log de Erros', 'wc-cpf-validator' ),
                'desc'    => __( 'Registrar erros de API no log do WooCommerce', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_logging',
                'default' => 'yes',
                'type'    => 'checkbox'
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'wc_cpf_validator_settings'
            ),
        );
        
        return $custom_settings;
    }
    
    /**
     * Get setting value
     */
    public static function get_option( $key, $default = '' ) {
        return get_option( 'wc_cpf_validator_' . $key, $default );
    }
}
