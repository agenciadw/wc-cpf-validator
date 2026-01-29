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
    
    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://api.cpfcnpj.com.br/';

    /**
     * CPFHub Base URL
     */
    const CPFHUB_BASE_URL = 'https://api.cpfhub.io/cpf/';
    
    /**
     * Test API Token
     */
    const TEST_TOKEN = '5ae973d7a997af13f0aaf2bf60e65803';

    /**
     * Runtime cache (per request) to avoid duplicate validations.
     *
     * @var array<string, array>
     */
    private static $runtime_cache = array();

    /**
     * API error codes that should NEVER be cached because they are not CPF-specific
     * and may change quickly (credits, token/account, rate limit, etc).
     *
     * @return string[]
     */
    private static function get_non_cacheable_error_codes() {
        return array( '1000', '1001', '1002', '1003', '1004', '1007' );
    }
    
    /**
     * Validate CPF format
     */
    public static function validate_cpf_format( $cpf ) {
        // Remove non-numeric characters
        $cpf = preg_replace( '/[^0-9]/', '', $cpf );
        
        // Check if has 11 digits
        if ( strlen( $cpf ) != 11 ) {
            return false;
        }
        
        // Check if all digits are the same
        if ( preg_match( '/^(\d)\1+$/', $cpf ) ) {
            return false;
        }
        
        // Validate CPF algorithm
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
    
    /**
     * Format CPF
     */
    public static function format_cpf( $cpf ) {
        $cpf = preg_replace( '/[^0-9]/', '', $cpf );
        return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf );
    }
    
    /**
     * Clean CPF (remove formatting)
     */
    public static function clean_cpf( $cpf ) {
        return preg_replace( '/[^0-9]/', '', $cpf );
    }
    
    /**
     * Get API token
     */
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

    /**
     * Get API provider
     *
     * @return string cpfcnpj|cpfhub
     */
    private static function get_provider() {
        $provider = WC_CPF_Validator_Settings::get_option( 'api_provider', 'cpfcnpj' );
        $provider = is_string( $provider ) ? $provider : 'cpfcnpj';
        $provider = strtolower( trim( $provider ) );
        if ( ! in_array( $provider, array( 'cpfcnpj', 'cpfhub' ), true ) ) {
            $provider = 'cpfcnpj';
        }
        return $provider;
    }

    /**
     * Get CPFHub API Key
     */
    private static function get_cpfhub_api_key() {
        $key = WC_CPF_Validator_Settings::get_option( 'cpfhub_api_key', '' );
        $key = is_string( $key ) ? trim( $key ) : '';
        if ( $key === '' ) {
            self::log( 'CPFHub API Key não configurada' );
            return false;
        }
        return $key;
    }
    
    /**
     * Get API package ID
     */
    private static function get_package_id() {
        return WC_CPF_Validator_Settings::get_option( 'api_package', '1' );
    }
    
    /**
     * Validate CPF with API
     */
    public static function validate_cpf_api( $cpf ) {
        // Clean CPF
        $cpf_clean = self::clean_cpf( $cpf );
        
        // Validate format first
        if ( ! self::validate_cpf_format( $cpf_clean ) ) {
            return array(
                'valid'   => false,
                'message' => __( 'CPF inválido. Por favor, verifique o número digitado.', 'wc-cpf-validator' ),
                'code'    => 'invalid_format'
            );
        }

        $provider = self::get_provider();

        if ( $provider === 'cpfhub' ) {
            return self::validate_cpf_cpfhub_api( $cpf_clean );
        }
        
        // Get token
        $token = self::get_token();
        if ( ! $token ) {
            return array(
                'valid'   => false,
                'message' => __( 'Erro de configuração. Entre em contato com o administrador.', 'wc-cpf-validator' ),
                'code'    => 'no_token'
            );
        }
        
        // Get package ID
        $package_id = self::get_package_id();

        // Cache key (package + cpf). Token is intentionally excluded to maximize reuse.
        $cache_key = 'wc_cpf_validator_' . md5( $package_id . '|' . $cpf_clean );

        // Runtime cache (same request)
        if ( isset( self::$runtime_cache[ $cache_key ] ) && is_array( self::$runtime_cache[ $cache_key ] ) ) {
            return self::$runtime_cache[ $cache_key ];
        }

        // Persistent cache (transient) to avoid consuming credits repeatedly.
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
                    'message' => __( 'CPF validado com sucesso.', 'wc-cpf-validator' ),
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
        
        // Build API URL
        $url = self::API_BASE_URL . $token . '/' . $package_id . '/' . $cpf_clean;
        
        // Make API request
        $start = microtime( true );
        $timeout = (int) apply_filters( 'wc_cpf_validator_api_timeout', 20, $cpf_clean, $package_id, $url );
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
        ) );
        $elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
        
        // Check for errors
        if ( is_wp_error( $response ) ) {
            self::log( sprintf( 'Erro na requisição (%dms): %s', $elapsed_ms, $response->get_error_message() ) );
            return array(
                'valid'   => false,
                'message' => __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' ),
                'code'    => 'request_error'
            );
        }
        
        // Get response body
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        // Lightweight log (avoid logging full payload which can be large/slow)
        $status = isset( $data['status'] ) ? (string) $data['status'] : 'n/a';
        $erro_codigo = isset( $data['erroCodigo'] ) ? (string) $data['erroCodigo'] : '';
        self::log(
            sprintf(
                'API CPF (%dms) status=%s%s',
                $elapsed_ms,
                $status,
                $erro_codigo ? ' erroCodigo=' . $erro_codigo : ''
            )
        );

        // Persist cache (defaults to 30 days, filterable).
        if ( is_array( $data ) ) {
            $erro_codigo = isset( $data['erroCodigo'] ) ? (string) $data['erroCodigo'] : '';
            $should_cache = true;

            // Never cache account/credits/rate limit errors.
            if ( $erro_codigo && in_array( $erro_codigo, self::get_non_cacheable_error_codes(), true ) ) {
                $should_cache = false;
            }

            // Cache TTL: long for success, short for CPF-specific errors.
            if ( $should_cache ) {
                $default_ttl = ( isset( $data['status'] ) && (int) $data['status'] === 1 )
                    ? 30 * DAY_IN_SECONDS
                    : DAY_IN_SECONDS;

                $ttl = (int) apply_filters( 'wc_cpf_validator_cache_ttl', $default_ttl, $cpf_clean, $package_id, $data );
                if ( $ttl > 0 ) {
                    set_transient( $cache_key, $data, $ttl );
                }
            }
        }
        
        // Check API response status
        if ( isset( $data['status'] ) && $data['status'] == 1 ) {
            // CPF is valid
            $result = array(
                'valid'   => true,
                'message' => __( 'CPF validado com sucesso.', 'wc-cpf-validator' ),
                'data'    => $data
            );
            self::$runtime_cache[ $cache_key ] = $result;
            return $result;
        } else {
            // CPF is invalid or API error
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
    }

    /**
     * Validate CPF with CPFHub API
     *
     * @param string $cpf_clean Only digits.
     */
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

        // Cache: long for success, short for errors.
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
    
    /**
     * Get user-friendly error message
     */
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
            '1007' => __( 'Limite de requisições excedido. Aguarde alguns segundos e tente novamente.', 'wc-cpf-validator' ),
        );
        
        if ( isset( $error_messages[ $error_code ] ) ) {
            return $error_messages[ $error_code ];
        }
        
        // Return API error message if available
        if ( isset( $data['erro'] ) ) {
            return $data['erro'];
        }
        
        return __( 'Erro ao validar CPF. Tente novamente.', 'wc-cpf-validator' );
    }
    
    /**
     * Check API balance
     */
    public static function check_balance() {
        if ( self::get_provider() === 'cpfhub' ) {
            return false;
        }
        $token = self::get_token();
        if ( ! $token ) {
            return false;
        }
        
        $package_id = self::get_package_id();
        $url = self::API_BASE_URL . $token . '/saldo/' . $package_id;
        
        $response = wp_remote_get( $url, array(
            'timeout' => 30,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( isset( $data['pacote']['saldo'] ) ) {
            return $data['pacote']['saldo'];
        }
        
        return false;
    }
    
    /**
     * Log messages
     */
    private static function log( $message ) {
        if ( WC_CPF_Validator_Settings::get_option( 'logging' ) !== 'yes' ) {
            return;
        }
        
        $logger = wc_get_logger();
        $logger->info( $message, array( 'source' => 'wc-cpf-validator' ) );
    }
}
