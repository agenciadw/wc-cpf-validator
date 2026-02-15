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

        add_filter( 'woocommerce_get_sections_advanced', array( $this, 'add_section' ) );
        add_filter( 'woocommerce_get_settings_advanced', array( $this, 'add_settings' ), 10, 2 );
    }
    
    public function add_section( $sections ) {
        $sections['cpf_validator'] = __( 'Validação CPF', 'wc-cpf-validator' );
        return $sections;
    }
    
    public function add_settings( $settings, $current_section ) {
        if ( 'cpf_validator' !== $current_section ) {
            return $settings;
        }
        
        $custom_settings = array(
            array(
                'title' => __( 'Configurações de Validação de CPF', 'wc-cpf-validator' ),
                'type'  => 'title',
                'desc'  => __( 'Configure a integração com uma API de consulta de CPF para validação no checkout.', 'wc-cpf-validator' ),
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
                'title'   => __( 'Provedor de API (CPF)', 'wc-cpf-validator' ),
                'desc'    => __( 'Escolha qual serviço será usado para consultar/validar o CPF.', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_api_provider',
                'type'    => 'select',
                'default' => 'cpfcnpj',
                'options' => array(
                    'cpfcnpj' => __( 'CPF.CNPJ (cpfcnpj.com.br)', 'wc-cpf-validator' ),
                    'cpfhub'  => __( 'CPFHub (cpfhub.io)', 'wc-cpf-validator' ),
                ),
            ),
            array(
                'title'   => __( 'Provedor de API (CNPJ)', 'wc-cpf-validator' ),
                'desc'    => __( 'Escolha qual serviço será usado para consultar/validar o CNPJ. Observação: no momento, apenas CPF.CNPJ suporta CNPJ.', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_cnpj_api_provider',
                'type'    => 'select',
                'default' => 'cpfcnpj',
                'options' => array(
                    'cpfcnpj' => __( 'CPF.CNPJ (cpfcnpj.com.br)', 'wc-cpf-validator' ),
                    'cpfhub'  => __( 'CPFHub (não suporta CNPJ)', 'wc-cpf-validator' ),
                ),
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
                'title'   => __( 'CPFHub API Key', 'wc-cpf-validator' ),
                'desc'    => sprintf(
                    __( 'Insira sua API Key da CPFHub. Documentação em %s', 'wc-cpf-validator' ),
                    '<a href="https://www.cpfhub.io/" target="_blank">https://www.cpfhub.io/</a>'
                ),
                'id'      => 'wc_cpf_validator_cpfhub_api_key',
                'type'    => 'password',
                'default' => '',
                'css'     => 'width: 400px;'
            ),
            array(
                'title'   => __( 'Pacote CPF (CPF.CNPJ)', 'wc-cpf-validator' ),
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
                    '21' => __( 'CPF Lookalike - Nome + E-mails + Telefones + WhatsApp (R$ 0,24)', 'wc-cpf-validator' ),
                    '26' => __( 'CPF D Simplificado - Nome + Data + Situação (R$ 0,33)', 'wc-cpf-validator' ),
                )
            ),
            array(
                'title'   => __( 'Pacote CNPJ (CPF.CNPJ)', 'wc-cpf-validator' ),
                'desc'    => __( 'Selecione o pacote de CNPJ que você contratou (usado quando a validação de CNPJ estiver habilitada).', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_cnpj_package',
                'type'    => 'select',
                'default' => '6',
                'options' => array(
                    '4'  => __( 'CNPJ A - Razão Social (R$ 0,13)', 'wc-cpf-validator' ),
                    '5'  => __( 'CNPJ B - Razão Social + Fantasia + Endereço (R$ 0,24)', 'wc-cpf-validator' ),
                    '10' => __( 'CNPJ C - Dados + Situação cadastral (R$ 0,32)', 'wc-cpf-validator' ),
                    '6'  => __( 'CNPJ D - Dados completos + QSA (R$ 0,45)', 'wc-cpf-validator' ),
                    '11' => __( 'CNPJ F - Simples/SIMEI/Suframa (R$ 0,30)', 'wc-cpf-validator' ),
                    '19' => __( 'CNPJ Lookalike - E-mails/Telefones/WhatsApp dos sócios (R$ 0,26)', 'wc-cpf-validator' ),
                ),
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
                'title'   => __( 'Validar CNPJ (quando existir)', 'wc-cpf-validator' ),
                'desc'    => __( 'Quando o campo billing_cnpj estiver presente no checkout, validar CNPJ via API (compatível com Brazilian Market e FunnelKit).', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_validate_cnpj',
                'default' => 'no',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'Validar e-mail e telefone (CPF Lookalike)', 'wc-cpf-validator' ),
                'desc'    => __( 'Exige que o e-mail e o telefone informados correspondam aos dados do CPF (pacote CPF Lookalike - ID 21). Após 3 tentativas incorretas em cada campo, redireciona para o WhatsApp.', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_lookalike_validate_contact',
                'default' => 'no',
                'type'    => 'checkbox'
            ),
            array(
                'title'   => __( 'URL do WhatsApp (redirecionamento)', 'wc-cpf-validator' ),
                'desc'    => __( 'Link para redirecionar o comprador após 3 tentativas incorretas de e-mail ou telefone (ex: https://wa.me/5511999999999). Obrigatório para ativar o redirecionamento.', 'wc-cpf-validator' ),
                'id'      => 'wc_cpf_validator_lookalike_whatsapp_url',
                'type'    => 'text',
                'default' => '',
                'placeholder' => 'https://wa.me/5511999999999',
                'css'     => 'width: 400px;'
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
    
    public static function get_option( $key, $default = '' ) {
        return get_option( 'wc_cpf_validator_' . $key, $default );
    }
}
