<?php
/**
 * Plugin Name:       CDW Preço CNPJ
 * Plugin URI:        https://www.cdw.com.br
 * Description:       Cadastro CPF/CNPJ na Minha conta e preços diferenciados para clientes CNPJ. Complementar ao Brazilian Market (Extra Checkout Fields for Brazil).
 * Version:           1.3.1
 * Author:             CDW
 * Author URI:         https://www.cdw.com.br
 * Text Domain:       wc-smart-registration-pricing
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC tested up to:   10.7
 *
 * @package WSRP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-update via plugins.cdwtech.com.br (Plugin Update Checker / YahnisElsts).
 *
 * Carrega o autoload do Composer cedo (independente do bootstrap principal em
 * `plugins_loaded`) e registra o PUC apontando para o info.json no update
 * server. Em distribuições oficiais o vendor/ vem incluído no zip de release;
 * se faltar, exibe um aviso no admin em vez de fatal.
 */
( function () {
	$autoload = __DIR__ . '/vendor/autoload.php';
	if ( ! is_readable( $autoload ) ) {
		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>CDW Preço CNPJ:</strong> ' .
					esc_html__( 'dependências do Composer ausentes (vendor/autoload.php). Reinstale o plugin a partir do zip de release.', 'wc-smart-registration-pricing' ) .
					'</p></div>';
			}
		);
		return;
	}

	require_once $autoload;

	if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://plugins.cdwtech.com.br/wc-smart-registration-pricing/info.json',
			__FILE__,
			'wc-smart-registration-pricing'
		);
	}
} )();

define( 'WSRP_VERSION', '1.3.1' );
define( 'WSRP_PLUGIN_FILE', __FILE__ );
define( 'WSRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSRP_BMW_PLUGIN_SLUG', 'woocommerce-extra-checkout-fields-for-brazil/woocommerce-extra-checkout-fields-for-brazil.php' );

/**
 * Retorna se o plugin Brazilian Market está ativo.
 *
 * @return bool
 */
function wsrp_is_brazilian_market_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( WSRP_BMW_PLUGIN_SLUG );
}

/**
 * Opções padrão do plugin.
 *
 * @return array<string, mixed>
 */
function wsrp_default_settings() {
	return array(
		'registration_enabled'     => true,
		'pricing_enabled'          => true,
		'registration_helper_text' => '',
		'global_discount_enabled'  => false,
		'global_discount_type'     => 'percent',
		'global_discount_value'    => 0,
	);
}

/**
 * Ativação: garante opções padrão.
 */
function wsrp_activate() {
	if ( ! get_option( 'wsrp_settings' ) ) {
		add_option( 'wsrp_settings', wsrp_default_settings() );
	}
	if ( false === get_option( 'wsrp_category_rules', false ) ) {
		add_option( 'wsrp_category_rules', array() );
	}
}
register_activation_hook( __FILE__, 'wsrp_activate' );

/**
 * Link de configurações na listagem de plugins.
 *
 * @param array<string, string> $links Links existentes.
 * @return array<string, string>
 */
function wsrp_plugin_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wsrp-settings' );
	$links = (array) $links;
	array_unshift(
		$links,
		sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Configurações', 'wc-smart-registration-pricing' )
		)
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WSRP_PLUGIN_FILE ), 'wsrp_plugin_action_links' );

/**
 * Carrega dependências e inicializa o plugin.
 */
function wsrp_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'CDW Preço CNPJ requer o WooCommerce ativo.', 'wc-smart-registration-pricing' );
				echo '</p></div>';
			}
		);
		return;
	}

	require_once WSRP_PLUGIN_DIR . 'includes/class-user-meta.php';
	require_once WSRP_PLUGIN_DIR . 'includes/class-pricing-rules.php';
	require_once WSRP_PLUGIN_DIR . 'includes/class-registration-fields.php';
	require_once WSRP_PLUGIN_DIR . 'includes/class-admin-settings.php';

	add_action(
		'before_woocommerce_init',
		static function () {
			if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				return;
			}
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WSRP_PLUGIN_FILE, true );
			// Cart/Checkout Blocks: o desconto CNPJ é aplicado via filtros de preço do
			// produto e a economia por item é exposta via Store API (woocommerce_store_api_cart_item_data).
			// A "linha global de economia" só aparece no checkout/cart shortcode legacy.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WSRP_PLUGIN_FILE, true );
		}
	);

	load_plugin_textdomain( 'wc-smart-registration-pricing', false, dirname( plugin_basename( WSRP_PLUGIN_FILE ) ) . '/languages' );

	// WSRP_User_Meta é utilitário estático — não há instância para inicializar.
	WSRP_Pricing_Rules::instance();
	WSRP_Registration_Fields::instance();

	if ( is_admin() ) {
		WSRP_Admin_Settings::instance();
	}

	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( wsrp_is_brazilian_market_active() ) {
				return;
			}
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'CDW Preço CNPJ: o plugin WooCommerce Extra Checkout Fields for Brazil (Brazilian Market) não está ativo. O módulo de cadastro com CPF/CNPJ foi desativado; o módulo de preços CNPJ permanece disponível.', 'wc-smart-registration-pricing' );
			echo '</p></div>';
		}
	);
}
add_action( 'plugins_loaded', 'wsrp_bootstrap', 20 );
