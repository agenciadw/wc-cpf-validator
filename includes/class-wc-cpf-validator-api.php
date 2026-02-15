<?php
/**
 * API Integration class
 *
 * @package WC_CPF_Validator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_CPF_Validator_API {

    const API_BASE_URL    = 'https://api.cpfcnpj.com.br/';
    const CPFHUB_BASE_URL = 'https://api.cpfhub.io/cpf/';
    const TEST_TOKEN      = '5ae973d7a997af13f0aaf2bf60e65803';

    /** @var array<string, array> Per-request cache. */
    private static $runtime_cache = array();

    /** Error codes that must not be cached (credits, token, rate limit). */
    private static function get_non_cacheable_error_codes() {
        return array( '1000', '1001', '1002', '1003', '1004', '1007' );
    }

    public static function validate_cpf_format( $cpf ) {
        $cpf = preg_replace( '/[^0-9]/', '', $cpf );
        if ( strlen( $cpf ) != 11 ) {
            return false;
        }
        if ( preg_match( '/^(\d)\1+$/', $cpf ) ) {
            return false;
        }
        for ( $t = 9; $t < 11; $t++ ) {
            $d = 0;
            for ( $c = 0; $c < $t; $c++ ) {
                $d += $cpf[$c] * ( ( $t + 1 ) - $c );
            }
            $d = ( ( 10 * $d ) % 11 ) % 10;
            if ( $cpf[$c] != $d ) {
                return false;
            }
        }
        
        return true;
    }

    public static function validate_cnpj_format( $cnpj ) {
        $cnpj = preg_replace( '/[^0-9]/', '', $cnpj );

        if ( strlen( $cnpj ) !== 14 ) {
            return false;
        }
        if ( preg_match( '/^(\d)\1+$/', $cnpj ) ) {
            return false;
        }

        $weights1 = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
        $weights2 = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

        $sum = 0;
        for ( $i = 0; $i < 12; $i++ ) {
            $sum += (int) $cnpj[ $i ] * $weights1[ $i ];
        }
        $mod = $sum % 11;
        $d1 = ( $mod < 2 ) ? 0 : 11 - $mod;
        if ( (int) $cnpj[12] !== $d1 ) {
            return false;
        }

        $sum = 0;
        for ( $i = 0; $i < 13; $i++ ) {
            $sum += (int) $cnpj[ $i ] * $weights2[ $i ];
        }
        $mod = $sum % 11;
        $d2 = ( $mod < 2 ) ? 0 : 11 - $mod;
        if ( (int) $cnpj[13] !== $d2 ) {
            return false;
        }

        return true;
    }

    public static function format_cpf( $cpf ) {
        $cpf = preg_replace( '/[^0-9]/', '', $cpf );
        return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf );
    }

    public static function format_cnpj( $cnpj ) {
        $cnpj = preg_replace( '/[^0-9]/', '', $cnpj );
        return preg_replace( '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj );
    }

    public static function clean_cpf( $cpf ) {
        return preg_replace( '/[^0-9]/', '', $cpf );
    }

    public static function clean_document( $doc ) {
        return preg_replace( '/[^0-9]/', '', (string) $doc );
    }

    private static function get_token() {
        $test_mode = WC_CPF_Validator_Settings::get_option( 'test_mode' ) === 'yes';
        
        if ( $test_mode ) {
            return self::TEST_TOKEN;
        }
        
        $token = WC_CPF_Validator_Settings::get_option( 'api_token' );
        
        if ( empty( $token ) ) {
            self::log( 'Token da API não configurado' );
            return false;
        }
        
        return $token;
    }

    private static function get_cpf_provider() {
        $provider = WC_CPF_Validator_Settings::get_option( 'api_provider', 'cpfcnpj' );
        $provider = is_string( $provider ) ? $provider : 'cpfcnpj';
        $provider = strtolower( trim( $provider ) );
        if ( ! in_array( $provider, array( 'cpfcnpj', 'cpfhub' ), true ) ) {
            $provider = 'cpfcnpj';
        }
        return $provider;
    }

    private static function get_cnpj_provider() {
        $provider = WC_CPF_Validator_Settings::get_option( 'cnpj_api_provider', 'cpfcnpj' );
        $provider = is_string( $provider ) ? $provider : 'cpfcnpj';
        $provider = strtolower( trim( $provider ) );
        if ( ! in_array( $provider, array( 'cpfcnpj', 'cpfhub' ), true ) ) {
            $provider = 'cpfcnpj';
        }
        return $provider;
    }

    private static function get_cpfhub_api_key() {
        $key = WC_CPF_Validator_Settings::get_option( 'cpfhub_api_key', '' );
        $key = is_string( $key ) ? trim( $key ) : '';
        if ( $key === '' ) {
            self::log( 'CPFHub API Key não configurada' );
            return false;
        }
        return $key;
    }

    private static function get_package_id() {
        return WC_CPF_Validator_Settings::get_option( 'api_package', '1' );
    }

    private static function get_cnpj_package_id() {
        return WC_CPF_Validator_Settings::get_option( 'cnpj_package', '6' );
    }

    private static function check_balance_for_package( $token, $package_id ) {
        $package_id = (string) $package_id;
        if ( $package_id === '' ) {
            return false;
        }

        $url = self::API_BASE_URL . $token . '/saldo/' . $package_id;
        $response = wp_remote_get( $url, array(
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( is_array( $data ) && isset( $data['pacote']['saldo'] ) ) {
            return (int) $data['pacote']['saldo'];
        }

        return false;
    }

    public static function validate_cpf_api( $cpf ) {
        $doc_clean = self::clean_document( $cpf );
        $doc_type  = ( strlen( $doc_clean ) === 14 ) ? 'cnpj' : 'cpf';
        $doc_label = ( $doc_type === 'cnpj' ) ? 'CNPJ' : 'CPF';
        if ( $doc_type === 'cnpj' ) {
            if ( ! self::validate_cnpj_format( $doc_clean ) ) {
                return array(
                    'valid'   => false,
                    'message' => __( 'CNPJ inválido. Por favor, verifique o número digitado.', 'wc-cpf-validator' ),
                    'code'    => 'invalid_format'
                );
            }
        } else {
            if ( ! self::validate_cpf_format( $doc_clean ) ) {
                return array(
                    'valid'   => false,
                    'message' => __( 'CPF inválido. Por favor, verifique o número digitado.', 'wc-cpf-validator' ),
                    'code'    => 'invalid_format'
                );
            }
        }

        $provider = ( $doc_type === 'cnpj' ) ? self::get_cnpj_provider() : self::get_cpf_provider();

        if ( $provider === 'cpfhub' ) {
            if ( $doc_type === 'cnpj' ) {
                return array(
                    'valid'   => false,
                    'message' => __( 'O provedor selecionado para CNPJ não suporta consultas de CNPJ. Use CPF.CNPJ para CNPJ.', 'wc-cpf-validator' ),
                    'code'    => 'provider_unsupported'
                );
            }
            return self::validate_cpf_cpfhub_api( $doc_clean );
        }
        $token = self::get_token();
        if ( ! $token ) {
            return array(
                'valid'   => false,
                'message' => __( 'Erro de configuração. Entre em contato com o administrador.', 'wc-cpf-validator' ),
                'code'    => 'no_token'
            );
        }
        $package_id = ( $doc_type === 'cnpj' ) ? self::get_cnpj_package_id() : self::get_package_id();
        $package_id = is_string( $package_id ) ? trim( $package_id ) : (string) $package_id;
        if ( $package_id === '' ) {
            return array(
                'valid'   => false,
                'message' => __( 'Erro de configuração. Pacote da API não configurado.', 'wc-cpf-validator' ),
                'code'    => 'no_package'
            );
        }
        $cache_key = 'wc_cpf_validator_' . md5( $provider . '|' . $doc_type . '|' . $package_id . '|' . $doc_clean );
        if ( isset( self::$runtime_cache[ $cache_key ] ) && is_array( self::$runtime_cache[ $cache_key ] ) ) {
            return self::$runtime_cache[ $cache_key ];
        }
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['status'] ) ) {
            // Do not trust cached "account/credits" errors. They can be temporary and will block validations.
            if ( isset( $cached['erroCodigo'] ) && in_array( (string) $cached['erroCodigo'], self::get_non_cacheable_error_codes(), true ) ) {
                delete_transient( $cache_key );
            } else {
            $result = array();
            if ( (int) $cached['status'] === 1 ) {
                $result = array(
                    'valid'   => true,
                    'message' => sprintf( __( '%s validado com sucesso.', 'wc-cpf-validator' ), $doc_label ),
                    'data'    => $cached,
                    'cached'  => true,
                );
            } else {
                $result = array(
                    'valid'   => false,
                    'message' => self::get_error_message( $cached ),
                    'code'    => isset( $cached['erroCodigo'] ) ? $cached['erroCodigo'] : 'unknown',
                    'data'    => $cached,
                    'cached'  => true,
                );
            }

            self::$runtime_cache[ $cache_key ] = $result;
            return $result;
            }
        }
        $url = self::API_BASE_URL . $token . '/' . $package_id . '/' . $doc_clean;
        $start = microtime( true );
        $default_timeout = ( (string) $package_id === '21' ) ? 60 : 20;
        $timeout = (int) apply_filters( 'wc_cpf_validator_api_timeout', $default_timeout, $doc_clean, $package_id, $url );
        if ( $timeout < 5 ) {
            $timeout = 5;
        } elseif ( $timeout > 60 ) {
            $timeout = 60;
        }

        $response = wp_remote_get( $url, array(
            'timeout' => $timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        )         );
        $elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
        if ( is_wp_error( $response ) ) {
            self::log( sprintf( 'Erro na requisição (%dms): %s', $elapsed_ms, $response->get_error_message() ) );
            return array(
                'valid'   => false,
                'message' => sprintf( __( 'Erro ao validar %s. Tente novamente.', 'wc-cpf-validator' ), $doc_label ),
                'code'    => 'request_error'
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            if ( $http_code >= 500 && ( ! is_string( $body ) || strlen( $body ) === 0 ) ) {
                $balance = self::check_balance_for_package( $token, $package_id );
                if ( $balance !== false && $balance <= 0 ) {
                    self::log( sprintf( 'Saldo do pacote %s é %d (detected after http=%d)', (string) $package_id, (int) $balance, $http_code ) );
                    return array(
                        'valid'   => false,
                        'message' => __( 'Créditos insuficientes para o pacote selecionado. Verifique o saldo deste pacote na CPF.CNPJ.', 'wc-cpf-validator' ),
                        'code'    => 'no_credits'
                    );
                }
            }
            self::log(
                sprintf(
                    'Resposta inválida da API (%dms) http=%d body_len=%d',
                    $elapsed_ms,
                    $http_code,
                    is_string( $body ) ? strlen( $body ) : 0
                )
            );
            return array(
                'valid'   => false,
                'message' => sprintf( __( 'Instabilidade ao validar %s. Tente novamente em alguns instantes.', 'wc-cpf-validator' ), $doc_label ),
                'code'    => 'invalid_response'
            );
        }
        $status = isset( $data['status'] ) ? (string) $data['status'] : 'n/a';
        $erro_codigo = isset( $data['erroCodigo'] ) ? (string) $data['erroCodigo'] : '';
        self::log(
            sprintf(
                'API CPF.CNPJ (%dms) status=%s%s',
                $elapsed_ms,
                $status,
                $erro_codigo ? ' erroCodigo=' . $erro_codigo : ''
            )
        );
        if ( is_array( $data ) ) {
            $erro_codigo = isset( $data['erroCodigo'] ) ? (string) $data['erroCodigo'] : '';
            $should_cache = true;
            if ( $erro_codigo && in_array( $erro_codigo, self::get_non_cacheable_error_codes(), true ) ) {
                $should_cache = false;
            }
            if ( $should_cache ) {
                $default_ttl = ( isset( $data['status'] ) && (int) $data['status'] === 1 )
                    ? 30 * DAY_IN_SECONDS
                    : DAY_IN_SECONDS;

                $ttl = (int) apply_filters( 'wc_cpf_validator_cache_ttl', $default_ttl, $doc_clean, $package_id, $data );
                if ( $ttl > 0 ) {
                    set_transient( $cache_key, $data, $ttl );
                }
            }
        }
        if ( isset( $data['status'] ) && $data['status'] == 1 ) {
            $result = array(
                'valid'   => true,
                'message' => sprintf( __( '%s validado com sucesso.', 'wc-cpf-validator' ), $doc_label ),
                'data'    => $data
            );
            self::$runtime_cache[ $cache_key ] = $result;
            return $result;
        }
        $error_message = self::get_error_message( $data );
        $result = array(
            'valid'   => false,
            'message' => $error_message,
            'code'    => isset( $data['erroCodigo'] ) ? $data['erroCodigo'] : 'unknown',
            'data'    => $data
        );
        self::$runtime_cache[ $cache_key ] = $result;
        return $result;
    }

    private static function validate_cpf_cpfhub_api( $cpf_clean ) {
        $api_key = self::get_cpfhub_api_key();
        if ( ! $api_key ) {
            return array(
                'valid'   => false,
                'message' => __( 'Erro de configuração. Entre em contato com o administrador.', 'wc-cpf-validator' ),
                'code'    => 'no_api_key'
            );
        }

        $cache_key = 'wc_cpf_validator_cpfhub_' . md5( $cpf_clean );
        if ( isset( self::$runtime_cache[ $cache_key ] ) && is_array( self::$runtime_cache[ $cache_key ] ) ) {
            return self::$runtime_cache[ $cache_key ];
        }

        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['success'] ) ) {
            $result = array();
            if ( ! empty( $cached['success'] ) ) {
                $result = array(
                    'valid'   => true,
                    'message' => __( 'CPF validado com sucesso.', 'wc-cpf-validator' ),
                    'data'    => $cached,
                    'cached'  => true,
                );
            } else {
                $result = array(
                    'valid'   => false,
                    'message' => isset( $cached['message'] ) ? (string) $cached['message'] : __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' ),
                    'code'    => isset( $cached['code'] ) ? (string) $cached['code'] : 'cpfhub_error',
                    'data'    => $cached,
                    'cached'  => true,
                );
            }
            self::$runtime_cache[ $cache_key ] = $result;
            return $result;
        }

        $url = self::CPFHUB_BASE_URL . $cpf_clean;

        $start = microtime( true );
        $timeout = (int) apply_filters( 'wc_cpf_validator_api_timeout', 20, $cpf_clean, 'cpfhub', $url );
        if ( $timeout < 5 ) {
            $timeout = 5;
        } elseif ( $timeout > 60 ) {
            $timeout = 60;
        }

        $response = wp_remote_get( $url, array(
            'timeout' => $timeout,
            'headers' => array(
                'x-api-key' => $api_key,
                'Accept'    => 'application/json',
            ),
        ) );

        $elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            self::log( sprintf( 'CPFHub erro (%dms): %s', $elapsed_ms, $response->get_error_message() ) );
            return array(
                'valid'   => false,
                'message' => __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' ),
                'code'    => 'request_error'
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        $success = is_array( $data ) && ! empty( $data['success'] );
        self::log( sprintf( 'CPFHub (%dms) success=%s', $elapsed_ms, $success ? 'true' : 'false' ) );
        if ( is_array( $data ) ) {
            $default_ttl = $success ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS;
            $ttl = (int) apply_filters( 'wc_cpf_validator_cache_ttl', $default_ttl, $cpf_clean, 'cpfhub', $data );
            if ( $ttl > 0 ) {
                set_transient( $cache_key, $data, $ttl );
            }
        }

        if ( $success ) {
            $result = array(
                'valid'   => true,
                'message' => __( 'CPF validado com sucesso.', 'wc-cpf-validator' ),
                'data'    => $data
            );
            self::$runtime_cache[ $cache_key ] = $result;
            return $result;
        }

        $message = __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' );
        if ( is_array( $data ) ) {
            if ( isset( $data['message'] ) ) {
                $message = (string) $data['message'];
            }
        }

        $result = array(
            'valid'   => false,
            'message' => $message,
            'code'    => 'cpfhub_error',
            'data'    => is_array( $data ) ? $data : array(),
        );
        self::$runtime_cache[ $cache_key ] = $result;
        return $result;
    }
    
    private static function get_error_message( $data ) {
        if ( ! isset( $data['erroCodigo'] ) ) {
            return __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' );
        }
        
        $error_code = $data['erroCodigo'];
        
        $error_messages = array(
            '100'  => __( 'CPF inválido! Por favor, verifique o número digitado.', 'wc-cpf-validator' ),
            '101'  => __( 'Informe um CPF com 11 dígitos.', 'wc-cpf-validator' ),
            '102'  => __( 'O CPF informado não existe na base da Receita Federal.', 'wc-cpf-validator' ),
            '1000' => __( 'Erro de configuração da API. Entre em contato com o administrador.', 'wc-cpf-validator' ),
            '1001' => __( 'Créditos da API esgotados. Entre em contato com o administrador.', 'wc-cpf-validator' ),
            '1002' => __( 'Conta da API suspensa. Entre em contato com o administrador.', 'wc-cpf-validator' ),
            '1003' => __( 'API temporariamente bloqueada. Tente novamente mais tarde.', 'wc-cpf-validator' ),
            '1004' => __( 'Pacote da API indisponível. Entre em contato com o administrador.', 'wc-cpf-validator' ),
            '1005' => __( 'Não é possível consultar este CPF neste pacote (falha no fornecedor ou erro interno). Tente novamente.', 'wc-cpf-validator' ),
            '1006' => __( 'Fornecedor de dados indisponível no momento. Tente novamente mais tarde.', 'wc-cpf-validator' ),
            '1007' => __( 'Limite de requisições excedido. Aguarde alguns segundos e tente novamente.', 'wc-cpf-validator' ),
        );
        
        if ( isset( $error_messages[ $error_code ] ) ) {
            return $error_messages[ $error_code ];
        }
        if ( isset( $data['erro'] ) ) {
            return $data['erro'];
        }
        
        return __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' );
    }

    public static function check_balance() {
        if ( self::get_cpf_provider() === 'cpfhub' ) {
            return false;
        }
        $token = self::get_token();
        if ( ! $token ) {
            return false;
        }
        
        $package_id = self::get_package_id();
        return self::check_balance_for_package( $token, $package_id );
    }

    private static function log( $message ) {
        if ( WC_CPF_Validator_Settings::get_option( 'logging' ) !== 'yes' ) {
            return;
        }
        
        $logger = wc_get_logger();
        $logger->info( $message, array( 'source' => 'wc-cpf-validator' ) );
    }

    /** Extract email and phone lists from CPF Lookalike API response (package 21). */
    public static function extract_lookalike_contact_lists( $data ) {
        $emails = array();
        $phones = array();

        if ( ! is_array( $data ) ) {
            return array( 'emails' => $emails, 'phones' => $phones );
        }

        if ( ! empty( $data['emails'] ) && is_array( $data['emails'] ) ) {
            foreach ( $data['emails'] as $item ) {
                $email = null;
                if ( is_string( $item ) ) {
                    $email = $item;
                } elseif ( is_array( $item ) ) {
                    if ( ! empty( $item['email'] ) ) {
                        $email = $item['email'];
                    } elseif ( ! empty( $item['endereco'] ) ) {
                        $email = $item['endereco'];
                    }
                }
                if ( $email !== null ) {
                    $email = strtolower( trim( $email ) );
                    if ( $email !== '' && ! in_array( $email, $emails, true ) ) {
                        $emails[] = $email;
                    }
                }
            }
        }
        $sources = array();
        if ( ! empty( $data['telefones'] ) && is_array( $data['telefones'] ) ) {
            $sources = array_merge( $sources, $data['telefones'] );
        }
        if ( ! empty( $data['whatsapp'] ) && is_array( $data['whatsapp'] ) ) {
            $sources = array_merge( $sources, $data['whatsapp'] );
        }
        foreach ( $sources as $item ) {
            $digits = null;
            if ( is_string( $item ) ) {
                $digits = preg_replace( '/[^0-9]/', '', $item );
            } elseif ( is_array( $item ) ) {
                $ddd = isset( $item['ddd'] ) ? preg_replace( '/[^0-9]/', '', (string) $item['ddd'] ) : '';
                $num = isset( $item['numero'] ) ? preg_replace( '/[^0-9]/', '', (string) $item['numero'] ) : '';
                $digits = $ddd . $num;
            }
            if ( $digits !== null && strlen( $digits ) >= 10 && ! in_array( $digits, $phones, true ) ) {
                $phones[] = $digits;
            }
        }

        return array( 'emails' => $emails, 'phones' => $phones );
    }

    public static function store_lookalike_data_for_cpf( $cpf_digits, $api_data ) {
        $cpf_digits = preg_replace( '/[^0-9]/', '', $cpf_digits );
        if ( strlen( $cpf_digits ) !== 11 ) {
            return;
        }
        $lists = self::extract_lookalike_contact_lists( $api_data );
        if ( empty( $lists['emails'] ) && empty( $lists['phones'] ) ) {
            self::log( 'Lookalike: store sem emails/phones para CPF ' . substr( $cpf_digits, 0, 3 ) . '.***.***-**' );
            return;
        }
        set_transient( 'wc_cpf_validator_lookalike_' . $cpf_digits, $lists, HOUR_IN_SECONDS );
        self::log( 'Lookalike: dados armazenados para CPF ' . substr( $cpf_digits, 0, 3 ) . '.***.***-** (emails=' . count( $lists['emails'] ) . ', phones=' . count( $lists['phones'] ) . ')' );
    }

    public static function get_lookalike_data_for_cpf( $cpf_digits ) {
        $cpf_digits = preg_replace( '/[^0-9]/', '', $cpf_digits );
        if ( strlen( $cpf_digits ) !== 11 ) {
            return null;
        }
        $cached = get_transient( 'wc_cpf_validator_lookalike_' . $cpf_digits );
        return is_array( $cached ) ? $cached : null;
    }

    public static function lookalike_email_matches( $cpf_digits, $email ) {
        $data = self::get_lookalike_data_for_cpf( $cpf_digits );
        if ( $data === null || empty( $data['emails'] ) ) {
            return true;
        }
        $email = strtolower( trim( $email ) );
        if ( $email === '' ) {
            return false;
        }
        return in_array( $email, $data['emails'], true );
    }

    public static function lookalike_phone_matches( $cpf_digits, $phone ) {
        $data = self::get_lookalike_data_for_cpf( $cpf_digits );
        if ( $data === null || empty( $data['phones'] ) ) {
            return true;
        }
        $phone_digits = preg_replace( '/[^0-9]/', '', $phone );
        if ( strlen( $phone_digits ) < 10 ) {
            return false;
        }
        foreach ( $data['phones'] as $stored ) {
            $stored = (string) $stored;
            if ( strlen( $stored ) < 10 ) {
                continue;
            }
            if ( $phone_digits === $stored ) {
                return true;
            }
            if ( substr( $phone_digits, -10 ) === substr( $stored, -10 ) ) {
                return true;
            }
            if ( substr( $stored, -10 ) === substr( $phone_digits, -10 ) ) {
                return true;
            }
        }
        return false;
    }
}
