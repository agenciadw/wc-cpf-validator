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
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'admin_footer', array( $this, 'add_balance_widget' ) );
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_cpf_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_cpf_column' ), 10, 2 );
        add_action( 'admin_menu', array( $this, 'register_logs_menu' ), 20 );
        add_action( 'admin_init', array( $this, 'maybe_export_logs_csv' ), 5 );
        add_action( 'wp_ajax_wc_cpf_validator_clear_logs', array( $this, 'ajax_clear_logs' ) );
    }

    public function maybe_export_logs_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-cpf-validator-logs' ) {
            return;
        }
        if ( ! isset( $_GET['export'] ) || $_GET['export'] !== 'csv' ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wc_cpf_export_logs' ) ) {
            wp_die( esc_html__( 'Link de exportação inválido ou expirado.', 'wc-cpf-validator' ) );
        }
        $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
        $search       = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $this->export_logs_csv( $level_filter, $search );
        exit;
    }

    public function register_logs_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'CPF Validator - Logs', 'wc-cpf-validator' ),
            __( 'CPF Validator Logs', 'wc-cpf-validator' ),
            'manage_options',
            'wc-cpf-validator-logs',
            array( $this, 'render_logs_page' )
        );
    }

    public function ajax_clear_logs() {
        check_ajax_referer( 'wc_cpf_validator_logs_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'wc-cpf-validator' ) ) );
        }
        WC_CPF_Validator_Logger::clear_all();
        wp_send_json_success( array( 'message' => __( 'Todos os logs foram limpos.', 'wc-cpf-validator' ) ) );
    }

    public function admin_notices() {
        $screen = get_current_screen();
        
        if ( ! $screen || strpos( $screen->id, 'woocommerce' ) === false ) {
            return;
        }
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

    public function display_cpf_column( $column, $post_id ) {
        if ( $column === 'billing_cpf' ) {
            $cpf = get_post_meta( $post_id, '_billing_cpf', true );
            
            if ( $cpf ) {
                echo esc_html( $cpf );
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

    public function render_logs_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
        $search       = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

        $per_page     = 100;
        $paged        = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $offset       = ( $paged - 1 ) * $per_page;

        $total_logs   = WC_CPF_Validator_Logger::get_total( $level_filter, $search );
        $total_pages  = $total_logs > 0 ? (int) ceil( $total_logs / $per_page ) : 1;
        $logs         = WC_CPF_Validator_Logger::get_logs( $per_page, $offset, $level_filter, $search );
        $counts       = WC_CPF_Validator_Logger::get_counts_by_level();
        $count_info   = $counts['info'];
        $count_warning = $counts['warning'];
        $count_error  = $counts['error'];
        $page_slug    = 'wc-cpf-validator-logs';
        ?>
        <div class="wrap wc-cpf-validator-logs-wrap">
            <h1><?php esc_html_e( 'CPF Validator - Logs', 'wc-cpf-validator' ); ?></h1>

            <div class="wc-cpf-validator-stats" style="display:flex;gap:15px;margin:20px 0;flex-wrap:wrap;">
                <div style="flex:1;min-width:120px;padding:15px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
                    <div style="font-size:32px;font-weight:600;color:#2271b1;"><?php echo esc_html( number_format_i18n( $total_logs ) ); ?></div>
                    <div style="color:#646970;font-size:13px;margin-top:5px;"><?php esc_html_e( 'Total de Logs', 'wc-cpf-validator' ); ?></div>
                </div>
                <div style="flex:1;min-width:120px;padding:15px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
                    <div style="font-size:32px;font-weight:600;color:#4ab866;"><?php echo esc_html( number_format_i18n( $count_info ) ); ?></div>
                    <div style="color:#646970;font-size:13px;margin-top:5px;">ℹ️ <?php esc_html_e( 'Info', 'wc-cpf-validator' ); ?></div>
                </div>
                <div style="flex:1;min-width:120px;padding:15px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
                    <div style="font-size:32px;font-weight:600;color:#f0b849;"><?php echo esc_html( number_format_i18n( $count_warning ) ); ?></div>
                    <div style="color:#646970;font-size:13px;margin-top:5px;">⚠️ <?php esc_html_e( 'Warning', 'wc-cpf-validator' ); ?></div>
                </div>
                <div style="flex:1;min-width:120px;padding:15px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
                    <div style="font-size:32px;font-weight:600;color:#d63638;"><?php echo esc_html( number_format_i18n( $count_error ) ); ?></div>
                    <div style="color:#646970;font-size:13px;margin-top:5px;">❌ <?php esc_html_e( 'Error', 'wc-cpf-validator' ); ?></div>
                </div>
            </div>

            <div class="tablenav top" style="background:#fff;padding:15px;border:1px solid #c3c4c7;border-radius:4px;margin-bottom:15px;">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
                    <label for="level-filter" style="font-weight:600;"><?php esc_html_e( 'Filtrar por nível:', 'wc-cpf-validator' ); ?></label>
                    <select name="level" id="level-filter">
                        <option value="" <?php selected( $level_filter, '' ); ?>><?php esc_html_e( 'Todos', 'wc-cpf-validator' ); ?></option>
                        <option value="info" <?php selected( $level_filter, 'info' ); ?>>ℹ️ Info</option>
                        <option value="warning" <?php selected( $level_filter, 'warning' ); ?>>⚠️ Warning</option>
                        <option value="error" <?php selected( $level_filter, 'error' ); ?>>❌ Error</option>
                    </select>
                    <input type="search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar nos logs...', 'wc-cpf-validator' ); ?>" style="min-width:200px;" />
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrar', 'wc-cpf-validator' ); ?></button>
                    <?php
                    $export_url = add_query_arg( array(
                        'page'     => $page_slug,
                        'export'   => 'csv',
                        'level'    => $level_filter,
                        'search'   => $search,
                        '_wpnonce' => wp_create_nonce( 'wc_cpf_export_logs' ),
                    ), admin_url( 'admin.php' ) );
                    ?>
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button">📥 <?php esc_html_e( 'Exportar CSV', 'wc-cpf-validator' ); ?></a>
                    <button type="button" id="wc-cpf-validator-clear-logs" class="button button-link-delete" style="margin-left:auto;">🗑️ <?php esc_html_e( 'Limpar todos os logs', 'wc-cpf-validator' ); ?></button>
                </form>
            </div>
            <div id="wc-cpf-validator-clear-result" style="margin-bottom:15px;"></div>

            <?php if ( $total_logs > 0 ) : ?>
                <p style="color:#646970;font-size:13px;">
                    <?php
                    printf(
                        esc_html__( 'Mostrando %d-%d de %d logs', 'wc-cpf-validator' ),
                        $offset + 1,
                        min( $offset + $per_page, $total_logs ),
                        $total_logs
                    );
                    ?>
                </p>
            <?php endif; ?>

            <table class="widefat striped" style="background:#fff;">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e( 'Data', 'wc-cpf-validator' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( 'Nível', 'wc-cpf-validator' ); ?></th>
                        <th><?php esc_html_e( 'Mensagem', 'wc-cpf-validator' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $logs ) ) : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <?php
                            $level_colors = array(
                                'error'   => '#d63638',
                                'warning' => '#f0b849',
                                'info'    => '#4ab866',
                            );
                            $level_icons = array(
                                'error'   => '❌',
                                'warning' => '⚠️',
                                'info'    => 'ℹ️',
                            );
                            $color = isset( $level_colors[ $log->level ] ) ? $level_colors[ $log->level ] : '#646970';
                            $icon  = isset( $level_icons[ $log->level ] ) ? $level_icons[ $log->level ] : '•';
                            $ctx   = ! empty( $log->context ) ? json_decode( $log->context, true ) : array();
                            $ctx   = is_array( $ctx ) ? $ctx : array();
                            $msg   = esc_html( $log->message );
                            if ( ! empty( $ctx ) ) {
                                $parts = array();
                                if ( ! empty( $ctx['cpf_masked'] ) ) {
                                    $parts[] = 'CPF: ' . esc_html( $ctx['cpf_masked'] );
                                }
                                $nome = trim( ( $ctx['first_name'] ?? '' ) . ' ' . ( $ctx['last_name'] ?? '' ) );
                                if ( $nome !== '' ) {
                                    $parts[] = 'Nome: ' . esc_html( $nome );
                                }
                                if ( isset( $ctx['email'] ) && $ctx['email'] !== '' ) {
                                    $parts[] = 'E-mail: ' . esc_html( $ctx['email'] );
                                }
                                if ( ! empty( $ctx['phone'] ) || ! empty( $ctx['cellphone'] ) ) {
                                    $parts[] = 'Telefone: ' . esc_html( ( $ctx['phone'] ?? '' ) . ( ( $ctx['phone'] ?? '' ) && ( $ctx['cellphone'] ?? '' ) ? ' / ' : '' ) . ( $ctx['cellphone'] ?? '' ) );
                                }
                                if ( array_key_exists( 'email_valid', $ctx ) ) {
                                    $parts[] = 'E-mail válido: ' . ( $ctx['email_valid'] ? 'Sim' : 'Não' );
                                }
                                if ( array_key_exists( 'phone_valid', $ctx ) ) {
                                    $parts[] = 'Telefone válido: ' . ( $ctx['phone_valid'] ? 'Sim' : 'Não' );
                                }
                                if ( ! empty( $parts ) ) {
                                    $msg .= ' | ' . implode( ' | ', $parts );
                                }
                            }
                            if ( $search !== '' ) {
                                $msg = preg_replace( '/(' . preg_quote( $search, '/' ) . ')/iu', '<mark style="background:#fff59d;padding:2px 4px;">$1</mark>', $msg );
                            }
                            ?>
                            <tr>
                                <td><code style="font-size:12px;color:#646970;"><?php echo esc_html( $log->created_at ); ?></code></td>
                                <td>
                                    <span style="display:inline-block;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:600;background:<?php echo esc_attr( $color ); ?>15;color:<?php echo esc_attr( $color ); ?>;border:1px solid <?php echo esc_attr( $color ); ?>;">
                                        <?php echo esc_html( $icon . ' ' . ucfirst( $log->level ) ); ?>
                                    </span>
                                </td>
                                <td style="font-size:13px;line-height:1.5;"><?php echo wp_kses_post( $msg ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3" style="text-align:center;padding:40px;color:#646970;">
                                <?php
                                if ( $search !== '' || $level_filter !== '' ) {
                                    esc_html_e( 'Nenhum log encontrado com estes filtros.', 'wc-cpf-validator' );
                                } else {
                                    esc_html_e( 'Nenhum log encontrado.', 'wc-cpf-validator' );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom" style="padding:15px 0;">
                    <?php
                    $base_url = add_query_arg( array( 'page' => $page_slug, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
                    if ( $level_filter !== '' ) {
                        $base_url = add_query_arg( 'level', $level_filter, $base_url );
                    }
                    if ( $search !== '' ) {
                        $base_url = add_query_arg( 'search', $search, $base_url );
                    }
                    echo wp_kses_post( paginate_links( array(
                        'base'      => $base_url,
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $total_pages,
                        'current'   => $paged,
                    ) ) );
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            $('#wc-cpf-validator-clear-logs').on('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Limpar todos os logs do CPF Validator?', 'wc-cpf-validator' ) ); ?>')) return;
                var $btn = $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'wc_cpf_validator_clear_logs',
                    nonce: '<?php echo esc_js( wp_create_nonce( 'wc_cpf_validator_logs_nonce' ) ); ?>'
                }, function(res) {
                    if (res.success) {
                        $('#wc-cpf-validator-clear-result').html('<div class="notice notice-success"><p>' + (res.data && res.data.message ? res.data.message : '') + '</p></div>');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        $('#wc-cpf-validator-clear-result').html('<div class="notice notice-error"><p>' + (res.data && res.data.message ? res.data.message : 'Erro.') + '</p></div>');
                        $btn.prop('disabled', false);
                    }
                }).fail(function() {
                    $('#wc-cpf-validator-clear-result').html('<div class="notice notice-error"><p><?php echo esc_js( __( 'Erro na requisição.', 'wc-cpf-validator' ) ); ?></p></div>');
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    private function export_logs_csv( $level_filter, $search ) {
        $total = WC_CPF_Validator_Logger::get_total( $level_filter, $search );
        $limit = min( $total, 10000 );
        $logs  = WC_CPF_Validator_Logger::get_logs( $limit, 0, $level_filter, $search );

        $filename = 'wc-cpf-validator-logs-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        if ( $out === false ) {
            return;
        }
        fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        $headers = array(
            __( 'Data', 'wc-cpf-validator' ),
            __( 'Nível', 'wc-cpf-validator' ),
            __( 'Mensagem', 'wc-cpf-validator' ),
            __( 'CPF (mascarado)', 'wc-cpf-validator' ),
            __( 'Nome', 'wc-cpf-validator' ),
            __( 'E-mail', 'wc-cpf-validator' ),
            __( 'Telefone', 'wc-cpf-validator' ),
            __( 'E-mail válido', 'wc-cpf-validator' ),
            __( 'Telefone válido', 'wc-cpf-validator' ),
        );
        fputcsv( $out, $headers, ';' );

        foreach ( $logs as $log ) {
            $ctx = ! empty( $log->context ) ? json_decode( $log->context, true ) : array();
            $ctx = is_array( $ctx ) ? $ctx : array();
            $nome = trim( ( $ctx['first_name'] ?? '' ) . ' ' . ( $ctx['last_name'] ?? '' ) );
            $telefone = trim( ( $ctx['phone'] ?? '' ) . ( ( ! empty( $ctx['phone'] ) && ! empty( $ctx['cellphone'] ) ) ? ' / ' : '' ) . ( $ctx['cellphone'] ?? '' ) );
            $email_ok = array_key_exists( 'email_valid', $ctx ) ? ( $ctx['email_valid'] ? __( 'Sim', 'wc-cpf-validator' ) : __( 'Não', 'wc-cpf-validator' ) ) : '';
            $phone_ok = array_key_exists( 'phone_valid', $ctx ) ? ( $ctx['phone_valid'] ? __( 'Sim', 'wc-cpf-validator' ) : __( 'Não', 'wc-cpf-validator' ) ) : '';

            $row = array(
                $log->created_at,
                $log->level,
                $log->message,
                $ctx['cpf_masked'] ?? '',
                $nome,
                $ctx['email'] ?? '',
                $telefone,
                $email_ok,
                $phone_ok,
            );
            fputcsv( $out, $row, ';' );
        }

        fclose( $out );
        exit;
    }
}
