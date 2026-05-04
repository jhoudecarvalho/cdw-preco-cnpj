<?php
/**
 * Campos extras no formulário de registro WooCommerce.
 *
 * @package WSRP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WSRP_Registration_Fields.
 */
final class WSRP_Registration_Fields {

	/**
	 * Singleton.
	 *
	 * @var WSRP_Registration_Fields|null
	 */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return WSRP_Registration_Fields
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construtor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'maybe_register' ), 40 );
	}

	/**
	 * Registra hooks se o módulo estiver habilitado.
	 */
	public function maybe_register() {
		if ( ! WSRP_User_Meta::is_registration_module_enabled() ) {
			return;
		}
		add_action( 'woocommerce_register_form_start', array( $this, 'render_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration_fields' ), 10, 4 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_registration_fields' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Scripts e estilos apenas na página Minha conta (cadastro).
	 */
	public function enqueue_assets() {
		if ( ! is_account_page() || is_user_logged_in() ) {
			return;
		}
		if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			return;
		}
		wp_enqueue_style(
			'wsrp-frontend-css',
			WSRP_PLUGIN_URL . 'assets/css/registration.css',
			array(),
			WSRP_VERSION
		);
		wp_enqueue_script(
			'wsrp-registration-mask',
			WSRP_PLUGIN_URL . 'assets/js/registration-mask.js',
			array( 'jquery' ),
			WSRP_VERSION,
			true
		);
		wp_localize_script(
			'wsrp-registration-mask',
			'wsrpRegistration',
			array(
				'i18n' => array(
					'cpf_cnpj'      => __( 'CPF ou CNPJ', 'wc-smart-registration-pricing' ),
					'full_name'     => __( 'Nome completo', 'wc-smart-registration-pricing' ),
					'company'       => __( 'Nome da empresa', 'wc-smart-registration-pricing' ),
					'ie'            => __( 'Inscrição Estadual', 'wc-smart-registration-pricing' ),
					'pf_doc_label'  => __( 'CPF', 'wc-smart-registration-pricing' ),
					'pj_doc_label'  => __( 'CNPJ', 'wc-smart-registration-pricing' ),
				),
			)
		);
	}

	/**
	 * Renderiza o template dos campos.
	 */
	public function render_fields() {
		$settings = WSRP_User_Meta::get_settings();
		$template  = WSRP_PLUGIN_DIR . 'templates/registration-fields.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Valida envio do cadastro.
	 *
	 * @param WP_Error $errors   Erros existentes.
	 * @param string   $username Login (pode ser vazio).
	 * @param string   $password Senha.
	 * @param string   $email    E-mail.
	 * @return WP_Error
	 */
	public function validate_registration_fields( $errors, $username, $password, $email ) {
		if ( ! ( $errors instanceof WP_Error ) ) {
			$errors = new WP_Error();
		}
		if ( ! isset( $_POST['wsrp_registration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wsrp_registration_nonce'] ) ), 'wsrp_registration' ) ) {
			$errors->add( 'wsrp_nonce', __( 'Falha de segurança no cadastro. Atualize a página e tente novamente.', 'wc-smart-registration-pricing' ) );
			return $errors;
		}

		$person = isset( $_POST['wsrp_person_type'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_person_type'] ) ) : '';
		if ( 'pessoa_fisica' !== $person && 'pessoa_juridica' !== $person ) {
			$errors->add( 'wsrp_person', __( 'Selecione CPF ou CNPJ válido.', 'wc-smart-registration-pricing' ) );
			return $errors;
		}

		$doc = isset( $_POST['wsrp_document'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_document'] ) ) : '';
		$d   = WSRP_User_Meta::digits_only( $doc );

		if ( 'pessoa_fisica' === $person ) {
			if ( 11 !== strlen( $d ) ) {
				$errors->add( 'wsrp_cpf_len', __( 'CPF deve conter 11 dígitos.', 'wc-smart-registration-pricing' ) );
			} elseif ( ! WSRP_User_Meta::validate_cpf( $d ) ) {
				$errors->add( 'wsrp_cpf_invalid', __( 'CPF inválido.', 'wc-smart-registration-pricing' ) );
			} elseif ( WSRP_User_Meta::cpf_exists_for_other_user( $d, 0 ) ) {
				$errors->add( 'wsrp_cpf_dup', __( 'Este CPF já está cadastrado.', 'wc-smart-registration-pricing' ) );
			}
			$name = isset( $_POST['wsrp_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_full_name'] ) ) : '';
			if ( '' === trim( $name ) ) {
				$errors->add( 'wsrp_name', __( 'Informe o nome completo.', 'wc-smart-registration-pricing' ) );
			}
		} else {
			if ( 14 !== strlen( $d ) ) {
				$errors->add( 'wsrp_cnpj_len', __( 'CNPJ deve conter 14 dígitos.', 'wc-smart-registration-pricing' ) );
			} elseif ( ! WSRP_User_Meta::validate_cnpj( $d ) ) {
				$errors->add( 'wsrp_cnpj_invalid', __( 'CNPJ inválido.', 'wc-smart-registration-pricing' ) );
			} elseif ( WSRP_User_Meta::cnpj_exists_for_other_user( $d, 0 ) ) {
				$errors->add( 'wsrp_cnpj_dup', __( 'Este CNPJ já está cadastrado.', 'wc-smart-registration-pricing' ) );
			}
			$company = isset( $_POST['wsrp_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_company_name'] ) ) : '';
			if ( '' === trim( $company ) ) {
				$errors->add( 'wsrp_company', __( 'Informe o nome da empresa.', 'wc-smart-registration-pricing' ) );
			}
			$ie = isset( $_POST['wsrp_ie'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_ie'] ) ) : '';
			if ( ! WSRP_User_Meta::validate_ie( $ie ) ) {
				$errors->add( 'wsrp_ie', __( 'Inscrição estadual inválida. Use apenas números ou ISENTO.', 'wc-smart-registration-pricing' ) );
			}
		}

		return $errors;
	}

	/**
	 * Persiste dados após criação do cliente.
	 *
	 * @param int    $customer_id        ID do usuário.
	 * @param array  $new_customer_data  Dados do WC.
	 * @param string $password_generated Se senha foi gerada.
	 */
	public function save_registration_fields( $customer_id, $new_customer_data, $password_generated ) {
		unset( $password_generated );
		if ( ! isset( $_POST['wsrp_registration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wsrp_registration_nonce'] ) ), 'wsrp_registration' ) ) {
			return;
		}

		$person = isset( $_POST['wsrp_person_type'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_person_type'] ) ) : '';
		$doc    = isset( $_POST['wsrp_document'] ) ? WSRP_User_Meta::digits_only( sanitize_text_field( wp_unslash( $_POST['wsrp_document'] ) ) ) : '';

		$data = array(
			'billing_persontype' => 'pessoa_juridica' === $person ? '2' : '1',
		);

		if ( 'pessoa_juridica' === $person ) {
			$data['billing_cnpj']      = $doc;
			$data['billing_cpf']       = '';
			$data['billing_company']   = isset( $_POST['wsrp_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_company_name'] ) ) : '';
			$ie                        = isset( $_POST['wsrp_ie'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_ie'] ) ) : '';
			$data['billing_ie']        = 0 === strcasecmp( $ie, 'ISENTO' ) ? 'ISENTO' : $ie;
			$data['billing_first_name'] = $data['billing_company'];
			$data['billing_last_name']  = '';
		} else {
			$data['billing_cpf']     = $doc;
			$data['billing_cnpj']    = '';
			$data['billing_company'] = '';
			$data['billing_ie']      = '';
			$full                    = isset( $_POST['wsrp_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_full_name'] ) ) : '';
			$parts                   = self::split_full_name( $full );
			$data['billing_first_name'] = $parts['first'];
			$data['billing_last_name']  = $parts['last'];
		}

		WSRP_User_Meta::save_customer_billing_meta( $customer_id, $data );

		$display = 'pessoa_juridica' === $person ? $data['billing_company'] : trim( $data['billing_first_name'] . ' ' . $data['billing_last_name'] );
		wp_update_user(
			array(
				'ID'           => $customer_id,
				'first_name'   => $data['billing_first_name'],
				'last_name'    => $data['billing_last_name'],
				'display_name' => $display ? $display : $new_customer_data['user_login'],
			)
		);
	}

	/**
	 * Divide nome completo em primeiro e último nome.
	 *
	 * Convenção brasileira: o último token é o sobrenome; tudo antes vira
	 * primeiro nome. Quando o usuário informa apenas uma palavra, last_name
	 * fica vazio (o WooCommerce e o Brazilian Market aceitam — gravar "-"
	 * como sobrenome polui display_name).
	 *
	 * @param string $full Nome completo.
	 * @return array{first:string,last:string}
	 */
	private static function split_full_name( $full ) {
		$full = trim( preg_replace( '/\s+/', ' ', (string) $full ) );
		if ( '' === $full ) {
			return array( 'first' => '', 'last' => '' );
		}
		$pos = strrpos( $full, ' ' );
		if ( false === $pos ) {
			return array( 'first' => $full, 'last' => '' );
		}
		return array(
			'first' => trim( substr( $full, 0, $pos ) ),
			'last'  => trim( substr( $full, $pos + 1 ) ),
		);
	}
}
