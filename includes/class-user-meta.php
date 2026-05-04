<?php
/**
 * Metadados de usuário e validação de documentos.
 *
 * @package WSRP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WSRP_User_Meta — utilitário estático (sem instâncias).
 */
final class WSRP_User_Meta {

	/**
	 * Cache request-scoped do is_current_user_cnpj() por user_id. Evita
	 * chamadas redundantes a is_user_logged_in / get_current_user_id /
	 * get_user_meta nos múltiplos filtros de preço por página.
	 *
	 * @var array<int,bool>
	 */
	private static $is_cnpj_cache = array();

	/**
	 * Configurações mescladas com padrão.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$defaults = function_exists( 'wsrp_default_settings' ) ? wsrp_default_settings() : array();
		$stored   = get_option( 'wsrp_settings', array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/**
	 * Módulo de preços ativo.
	 */
	public static function is_pricing_module_enabled() {
		$s = self::get_settings();
		return ! empty( $s['pricing_enabled'] );
	}

	/**
	 * Módulo de cadastro ativo e Brazilian Market presente.
	 */
	public static function is_registration_module_enabled() {
		$s = self::get_settings();
		if ( empty( $s['registration_enabled'] ) ) {
			return false;
		}
		return function_exists( 'wsrp_is_brazilian_market_active' ) && wsrp_is_brazilian_market_active();
	}

	/**
	 * Cliente logado é CNPJ (PJ).
	 *
	 * Memoizado por user_id durante a request. A entrada é invalidada quando
	 * save_customer_billing_meta grava billing_persontype (ver abaixo).
	 */
	public static function is_current_user_cnpj() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$uid = (int) get_current_user_id();
		if ( array_key_exists( $uid, self::$is_cnpj_cache ) ) {
			return self::$is_cnpj_cache[ $uid ];
		}
		$pt = get_user_meta( $uid, 'billing_persontype', true );
		return self::$is_cnpj_cache[ $uid ] = ( '2' === (string) $pt );
	}

	/**
	 * Apenas dígitos.
	 *
	 * @param string $value Valor bruto.
	 * @return string
	 */
	public static function digits_only( $value ) {
		return preg_replace( '/\D+/', '', (string) $value );
	}

	/**
	 * Valida CPF (módulo 11).
	 *
	 * @param string $cpf CPF com ou sem máscara.
	 * @return bool
	 */
	public static function validate_cpf( $cpf ) {
		$d = self::digits_only( $cpf );
		if ( 11 !== strlen( $d ) ) {
			return false;
		}
		if ( preg_match( '/^(\d)\1{10}$/', $d ) ) {
			return false;
		}
		for ( $t = 9; $t < 11; $t++ ) {
			$d2 = 0;
			for ( $m = 0, $c = $t + 1; $m < $t; $m++ ) {
				$d2 += (int) $d[ $m ] * $c--;
			}
			$d2 = ( ( 10 * $d2 ) % 11 ) % 10;
			if ( (int) $d[ $t ] !== $d2 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Valida CNPJ (módulo 11).
	 *
	 * @param string $cnpj CNPJ com ou sem máscara.
	 * @return bool
	 */
	public static function validate_cnpj( $cnpj ) {
		$d = self::digits_only( $cnpj );
		if ( 14 !== strlen( $d ) ) {
			return false;
		}
		if ( preg_match( '/^(\d)\1{13}$/', $d ) ) {
			return false;
		}
		$calc = function ( $len ) use ( $d ) {
			$pos = $len - 7;
			$sum = 0;
			for ( $i = 0; $i < $len; $i++ ) {
				$sum += (int) $d[ $i ] * $pos--;
				if ( $pos < 2 ) {
					$pos = 9;
				}
			}
			$r = $sum % 11;
			return $r < 2 ? 0 : 11 - $r;
		};
		$j = $calc( 12 );
		$k = $calc( 13 );
		return (int) $d[12] === $j && (int) $d[13] === $k;
	}

	/**
	 * Inscrição estadual: ISENTO ou numérico.
	 *
	 * @param string $ie Valor informado.
	 * @return bool
	 */
	public static function validate_ie( $ie ) {
		$ie = trim( (string) $ie );
		if ( '' === $ie ) {
			return false;
		}
		if ( 0 === strcasecmp( $ie, 'ISENTO' ) ) {
			return true;
		}
		return (bool) preg_match( '/^\d+$/', $ie );
	}

	/**
	 * CPF formatado na máscara padrão (000.000.000-00).
	 *
	 * @param string $digits 11 dígitos.
	 * @return string
	 */
	public static function format_cpf_mask( $digits ) {
		$digits = self::digits_only( $digits );
		if ( 11 !== strlen( $digits ) ) {
			return $digits;
		}
		return sprintf(
			'%s.%s.%s-%s',
			substr( $digits, 0, 3 ),
			substr( $digits, 3, 3 ),
			substr( $digits, 6, 3 ),
			substr( $digits, 9, 2 )
		);
	}

	/**
	 * CNPJ formatado na máscara padrão (00.000.000/0000-00).
	 *
	 * @param string $digits 14 dígitos.
	 * @return string
	 */
	public static function format_cnpj_mask( $digits ) {
		$digits = self::digits_only( $digits );
		if ( 14 !== strlen( $digits ) ) {
			return $digits;
		}
		return sprintf(
			'%s.%s.%s/%s-%s',
			substr( $digits, 0, 2 ),
			substr( $digits, 2, 3 ),
			substr( $digits, 5, 3 ),
			substr( $digits, 8, 4 ),
			substr( $digits, 12, 2 )
		);
	}

	/**
	 * Verifica se CPF já está em uso por outro usuário.
	 *
	 * Busca pelo valor digits-only e pela máscara padrão; evita LIKE com wildcard
	 * em ambos os lados (caro em wp_usermeta sem índice e gera falsos positivos
	 * por substring).
	 *
	 * @param string $cpf    CPF.
	 * @param int    $ignore_user_id ID a ignorar (novo = 0).
	 * @return bool True se já existe.
	 */
	public static function cpf_exists_for_other_user( $cpf, $ignore_user_id = 0 ) {
		global $wpdb;
		$digits = self::digits_only( $cpf );
		if ( 11 !== strlen( $digits ) ) {
			return false;
		}
		$masked = self::format_cpf_mask( $digits );
		$uid    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN (%s, %s) LIMIT 1",
				'billing_cpf',
				$digits,
				$masked
			)
		);
		if ( ! $uid ) {
			return false;
		}
		return (int) $uid !== (int) $ignore_user_id;
	}

	/**
	 * Verifica se CNPJ já está em uso por outro usuário.
	 *
	 * @param string $cnpj CNPJ.
	 * @param int    $ignore_user_id ID a ignorar.
	 * @return bool
	 */
	public static function cnpj_exists_for_other_user( $cnpj, $ignore_user_id = 0 ) {
		global $wpdb;
		$digits = self::digits_only( $cnpj );
		if ( 14 !== strlen( $digits ) ) {
			return false;
		}
		$masked = self::format_cnpj_mask( $digits );
		$uid    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN (%s, %s) LIMIT 1",
				'billing_cnpj',
				$digits,
				$masked
			)
		);
		if ( ! $uid ) {
			return false;
		}
		return (int) $uid !== (int) $ignore_user_id;
	}

	/**
	 * Salva metadados de faturamento após cadastro.
	 *
	 * @param int   $customer_id ID do cliente.
	 * @param array $data        Dados normalizados (billing_*).
	 */
	public static function save_customer_billing_meta( $customer_id, array $data ) {
		$map = array(
			'billing_persontype',
			'billing_cpf',
			'billing_cnpj',
			'billing_company',
			'billing_ie',
			'billing_first_name',
			'billing_last_name',
		);
		foreach ( $map as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = $data[ $key ];
			if ( in_array( $key, array( 'billing_cpf', 'billing_cnpj' ), true ) ) {
				$value = self::digits_only( (string) $value );
			} elseif ( 'billing_ie' === $key ) {
				$value = sanitize_text_field( (string) $value );
				if ( 0 === strcasecmp( $value, 'ISENTO' ) ) {
					$value = 'ISENTO';
				}
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			update_user_meta( $customer_id, $key, $value );
		}

		if ( array_key_exists( 'billing_persontype', $data ) ) {
			unset( self::$is_cnpj_cache[ (int) $customer_id ] );
		}
	}

	/**
	 * Resumo textual do desconto configurado (coluna admin).
	 *
	 * @return string
	 */
	public static function get_discount_config_summary() {
		$s      = self::get_settings();
		$parts  = array();
		$rules  = get_option( 'wsrp_category_rules', array() );
		$has_cat = false;
		if ( is_array( $rules ) ) {
			foreach ( $rules as $r ) {
				if ( is_array( $r ) && ! empty( $r['enabled'] ) ) {
					$has_cat = true;
					break;
				}
			}
		}
		if ( ! empty( $s['global_discount_enabled'] ) && (float) $s['global_discount_value'] > 0 ) {
			$type = isset( $s['global_discount_type'] ) && 'fixed' === $s['global_discount_type'] ? 'fixed' : 'percent';
			if ( 'fixed' === $type ) {
				$parts[] = sprintf(
					/* translators: %s: valor monetário */
					__( 'Global: R$ %s', 'wc-smart-registration-pricing' ),
					wc_format_decimal( (float) $s['global_discount_value'], wc_get_price_decimals() )
				);
			} else {
				$parts[] = sprintf(
					/* translators: %s: percentual */
					__( 'Global: %s%%', 'wc-smart-registration-pricing' ),
					wc_format_decimal( (float) $s['global_discount_value'], 2 )
				);
			}
		}
		if ( $has_cat ) {
			$parts[] = __( 'Regras por categoria', 'wc-smart-registration-pricing' );
		}
		if ( empty( $parts ) ) {
			return __( 'Nenhum desconto ativo', 'wc-smart-registration-pricing' );
		}
		return implode( ' + ', $parts );
	}
}
