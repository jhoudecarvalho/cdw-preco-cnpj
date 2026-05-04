<?php
/**
 * Painel de configurações no admin.
 *
 * @package WSRP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WSRP_Admin_Settings.
 */
final class WSRP_Admin_Settings {

	/**
	 * Singleton.
	 *
	 * @var WSRP_Admin_Settings|null
	 */
	private static $instance = null;

	/**
	 * Opção de categorias.
	 *
	 * @var string
	 */
	private $cat_option = 'wsrp_category_rules';

	/**
	 * Singleton.
	 *
	 * @return WSRP_Admin_Settings
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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_wsrp_settings_group', array( $this, 'settings_option_page_capability' ) );
		add_action( 'admin_post_wsrp_save_category_rules', array( $this, 'save_category_rules' ) );
		add_action( 'admin_post_wsrp_export_cnpj_users', array( $this, 'export_cnpj_users_csv' ) );
	}

	/**
	 * Permite salvar opções via options.php com a mesma capability do WooCommerce (gerente de loja).
	 *
	 * O core usa manage_options por padrão; sem este filtro apenas administradores conseguem gravar.
	 *
	 * @param string $capability Capability padrão (manage_options).
	 * @return string
	 */
	public function settings_option_page_capability( $unused_default_cap ) {
		return 'manage_woocommerce';
	}

	/**
	 * Menu em WooCommerce.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'CDW Preço CNPJ', 'wc-smart-registration-pricing' ),
			__( 'CDW Preço CNPJ', 'wc-smart-registration-pricing' ),
			'manage_woocommerce',
			'wsrp-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registro de configurações.
	 */
	public function register_settings() {
		register_setting(
			'wsrp_settings_group',
			'wsrp_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				// Padrão do WordPress é manage_options (só administrador); lojistas usam manage_woocommerce.
				'capability'        => 'manage_woocommerce',
			)
		);
	}

	/**
	 * Sanitiza opções gerais e globais.
	 *
	 * @param array<string, mixed> $input Entrada.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return WSRP_User_Meta::get_settings();
		}
		$old  = WSRP_User_Meta::get_settings();
		$clean = $old;

		$tab = isset( $input['wsrp_settings_tab'] ) ? sanitize_key( $input['wsrp_settings_tab'] ) : 'all';

		if ( 'general' === $tab || 'all' === $tab ) {
			$clean['registration_enabled']     = ! empty( $input['registration_enabled'] );
			$clean['pricing_enabled']          = ! empty( $input['pricing_enabled'] );
			$clean['registration_helper_text'] = isset( $input['registration_helper_text'] ) ? sanitize_textarea_field( wp_unslash( $input['registration_helper_text'] ) ) : '';
		}

		if ( 'global' === $tab || 'all' === $tab ) {
			$clean['global_discount_enabled'] = ! empty( $input['global_discount_enabled'] );
			$clean['global_discount_type']   = isset( $input['global_discount_type'] ) && 'fixed' === $input['global_discount_type'] ? 'fixed' : 'percent';
			$clean['global_discount_value']  = isset( $input['global_discount_value'] ) ? abs( floatval( $input['global_discount_value'] ) ) : 0;
		}

		unset( $clean['wsrp_settings_tab'] );

		return $clean;
	}

	/**
	 * Salva regras por categoria (POST dedicado).
	 */
	public function save_category_rules() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'wc-smart-registration-pricing' ) );
		}
		check_admin_referer( 'wsrp_save_category_rules', 'wsrp_category_nonce' );

		$raw   = isset( $_POST['wsrp_cat'] ) && is_array( $_POST['wsrp_cat'] ) ? wp_unslash( $_POST['wsrp_cat'] ) : array();
		$rules = array();

		foreach ( $raw as $term_id => $row ) {
			$tid = absint( $term_id );
			if ( $tid <= 0 || ! is_array( $row ) ) {
				continue;
			}
			$rules[ $tid ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'type'    => isset( $row['type'] ) && 'fixed' === $row['type'] ? 'fixed' : 'percent',
				'value'   => isset( $row['value'] ) ? abs( floatval( $row['value'] ) ) : 0,
			);
		}

		update_option( $this->cat_option, $rules );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wsrp-settings',
					'tab'     => 'categories',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Exporta CSV de usuários CNPJ em chunks.
	 *
	 * Itera em blocos e descarta o cache de objetos a cada iteração para
	 * suportar bases grandes sem estourar memória.
	 */
	public function export_cnpj_users_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'wc-smart-registration-pricing' ) );
		}
		check_admin_referer( 'wsrp_export_cnpj_users', 'wsrp_export_nonce' );

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		nocache_headers();
		$filename = 'wsrp-usuarios-cnpj-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Não foi possível gerar o arquivo.', 'wc-smart-registration-pricing' ) );
		}
		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$out,
			array(
				__( 'Nome', 'wc-smart-registration-pricing' ),
				__( 'CNPJ', 'wc-smart-registration-pricing' ),
				__( 'E-mail', 'wc-smart-registration-pricing' ),
				__( 'Data de cadastro', 'wc-smart-registration-pricing' ),
				__( 'Desconto aplicado', 'wc-smart-registration-pricing' ),
			)
		);

		$summary  = WSRP_User_Meta::get_discount_config_summary();
		$paged    = 1;
		$per_page = 500;

		do {
			$query = new WP_User_Query(
				array(
					'number'      => $per_page,
					'paged'       => $paged,
					'meta_key'    => 'billing_persontype',
					'meta_value'  => '2',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'fields'      => array( 'ID', 'display_name', 'user_email', 'user_registered' ),
					'count_total' => false,
				)
			);
			$results = $query->get_results();

			foreach ( $results as $user ) {
				$cnpj = get_user_meta( (int) $user->ID, 'billing_cnpj', true );
				fputcsv(
					$out,
					array(
						$user->display_name,
						(string) $cnpj,
						$user->user_email,
						$user->user_registered,
						$summary,
					)
				);
			}

			if ( function_exists( 'flush' ) ) {
				flush();
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			$paged++;
		} while ( count( $results ) === $per_page );

		fclose( $out );
		exit;
	}

	/**
	 * Aba ativa.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$allowed = array( 'general', 'global', 'categories', 'users' );
		return in_array( $tab, $allowed, true ) ? $tab : 'general';
	}

	/**
	 * Renderiza a página de configurações.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab      = $this->current_tab();
		$settings = WSRP_User_Meta::get_settings();
		?>
		<div class="wrap wsrp-admin-wrap">
			<h1><?php esc_html_e( 'CDW Preço CNPJ', 'wc-smart-registration-pricing' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wsrp-settings&tab=general' ) ); ?>" class="nav-tab <?php echo 'general' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Configurações gerais', 'wc-smart-registration-pricing' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wsrp-settings&tab=global' ) ); ?>" class="nav-tab <?php echo 'global' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Desconto global CNPJ', 'wc-smart-registration-pricing' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wsrp-settings&tab=categories' ) ); ?>" class="nav-tab <?php echo 'categories' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Desconto por categoria', 'wc-smart-registration-pricing' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wsrp-settings&tab=users' ) ); ?>" class="nav-tab <?php echo 'users' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Usuários CNPJ', 'wc-smart-registration-pricing' ); ?></a>
			</h2>
			<?php
			if ( ! empty( $_GET['updated'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configurações salvas.', 'wc-smart-registration-pricing' ) . '</p></div>';
			}
			switch ( $tab ) {
				case 'global':
					$this->render_tab_global( $settings );
					break;
				case 'categories':
					$this->render_tab_categories();
					break;
				case 'users':
					$this->render_tab_users();
					break;
				default:
					$this->render_tab_general( $settings );
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Aba: configurações gerais.
	 *
	 * @param array<string, mixed> $settings Configurações atuais.
	 */
	private function render_tab_general( array $settings ) {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wsrp_settings_group' ); ?>
			<input type="hidden" name="wsrp_settings[wsrp_settings_tab]" value="general" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Módulo de cadastro', 'wc-smart-registration-pricing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wsrp_settings[registration_enabled]" value="1" <?php checked( ! empty( $settings['registration_enabled'] ) ); ?> />
							<?php esc_html_e( 'Ativar campos de CPF/CNPJ no cadastro da conta', 'wc-smart-registration-pricing' ); ?>
						</label>
						<?php if ( function_exists( 'wsrp_is_brazilian_market_active' ) && ! wsrp_is_brazilian_market_active() ) : ?>
							<p class="description"><?php esc_html_e( 'Requer o plugin Brazilian Market ativo; caso contrário o cadastro inteligente não será exibido.', 'wc-smart-registration-pricing' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Módulo de preços CNPJ', 'wc-smart-registration-pricing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wsrp_settings[pricing_enabled]" value="1" <?php checked( ! empty( $settings['pricing_enabled'] ) ); ?> />
							<?php esc_html_e( 'Ativar preços diferenciados para clientes CNPJ', 'wc-smart-registration-pricing' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wsrp_registration_helper_text"><?php esc_html_e( 'Texto abaixo do CPF/CNPJ', 'wc-smart-registration-pricing' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="3" id="wsrp_registration_helper_text" name="wsrp_settings[registration_helper_text]"><?php echo esc_textarea( isset( $settings['registration_helper_text'] ) ? (string) $settings['registration_helper_text'] : '' ); ?></textarea>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Salvar alterações', 'wc-smart-registration-pricing' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Aba: desconto global.
	 *
	 * @param array<string, mixed> $settings Configurações atuais.
	 */
	private function render_tab_global( array $settings ) {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wsrp_settings_group' ); ?>
			<input type="hidden" name="wsrp_settings[wsrp_settings_tab]" value="global" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Ativar desconto global', 'wc-smart-registration-pricing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wsrp_settings[global_discount_enabled]" value="1" <?php checked( ! empty( $settings['global_discount_enabled'] ) ); ?> />
							<?php esc_html_e( 'Aplicar desconto global quando não houver regra de categoria', 'wc-smart-registration-pricing' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tipo de desconto', 'wc-smart-registration-pricing' ); ?></th>
					<td>
						<select name="wsrp_settings[global_discount_type]">
							<option value="percent" <?php selected( isset( $settings['global_discount_type'] ) ? $settings['global_discount_type'] : 'percent', 'percent' ); ?>><?php esc_html_e( 'Percentual (%)', 'wc-smart-registration-pricing' ); ?></option>
							<option value="fixed" <?php selected( isset( $settings['global_discount_type'] ) ? $settings['global_discount_type'] : 'percent', 'fixed' ); ?>><?php esc_html_e( 'Valor fixo (R$)', 'wc-smart-registration-pricing' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wsrp_global_discount_value"><?php esc_html_e( 'Percentual ou valor fixo', 'wc-smart-registration-pricing' ); ?></label></th>
					<td>
						<input type="number" step="0.01" min="0" class="small-text" id="wsrp_global_discount_value" name="wsrp_settings[global_discount_value]" value="<?php echo esc_attr( isset( $settings['global_discount_value'] ) ? (string) $settings['global_discount_value'] : '0' ); ?>" />
						<p class="description"><?php esc_html_e( 'Percentual sobre o preço de varejo ou valor fixo em moeda da loja, conforme o tipo selecionado.', 'wc-smart-registration-pricing' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Salvar alterações', 'wc-smart-registration-pricing' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Aba categorias.
	 */
	private function render_tab_categories() {
		$rules = get_option( $this->cat_option, array() );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wsrp_save_category_rules', 'wsrp_category_nonce' ); ?>
			<input type="hidden" name="action" value="wsrp_save_category_rules" />
			<p class="description"><?php esc_html_e( 'Regras por categoria substituem o desconto global para produtos daquela categoria. Se o produto estiver em várias categorias com regra, prevalece o maior benefício (menor preço final).', 'wc-smart-registration-pricing' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Categoria', 'wc-smart-registration-pricing' ); ?></th>
						<th><?php esc_html_e( 'Ativo', 'wc-smart-registration-pricing' ); ?></th>
						<th><?php esc_html_e( 'Tipo', 'wc-smart-registration-pricing' ); ?></th>
						<th><?php esc_html_e( 'Valor', 'wc-smart-registration-pricing' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( is_wp_error( $cats ) || empty( $cats ) ) {
						echo '<tr><td colspan="4">' . esc_html__( 'Nenhuma categoria encontrada.', 'wc-smart-registration-pricing' ) . '</td></tr>';
					} else {
						foreach ( $cats as $term ) {
							$tid = (int) $term->term_id;
							$r   = isset( $rules[ $tid ] ) && is_array( $rules[ $tid ] ) ? $rules[ $tid ] : array();
							$en  = ! empty( $r['enabled'] );
							$typ = isset( $r['type'] ) && 'fixed' === $r['type'] ? 'fixed' : 'percent';
							$val = isset( $r['value'] ) ? (float) $r['value'] : 0;
							?>
							<tr>
								<td><?php echo esc_html( $term->name ); ?></td>
								<td>
									<input type="checkbox" name="wsrp_cat[<?php echo esc_attr( (string) $tid ); ?>][enabled]" value="1" <?php checked( $en ); ?> />
								</td>
								<td>
									<select name="wsrp_cat[<?php echo esc_attr( (string) $tid ); ?>][type]">
										<option value="percent" <?php selected( $typ, 'percent' ); ?>><?php esc_html_e( '%', 'wc-smart-registration-pricing' ); ?></option>
										<option value="fixed" <?php selected( $typ, 'fixed' ); ?>><?php esc_html_e( 'R$', 'wc-smart-registration-pricing' ); ?></option>
									</select>
								</td>
								<td>
									<input type="number" step="0.01" min="0" class="small-text" name="wsrp_cat[<?php echo esc_attr( (string) $tid ); ?>][value]" value="<?php echo esc_attr( (string) $val ); ?>" />
								</td>
							</tr>
							<?php
						}
					}
					?>
				</tbody>
			</table>
			<p>
				<?php submit_button( __( 'Salvar regras por categoria', 'wc-smart-registration-pricing' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Aba usuários CNPJ.
	 */
	private function render_tab_users() {
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;

		$query = new WP_User_Query(
			array(
				'number'     => $per_page,
				'offset'     => ( $paged - 1 ) * $per_page,
				'meta_key'   => 'billing_persontype',
				'meta_value' => '2',
				'orderby'    => 'registered',
				'order'      => 'DESC',
			)
		);

		$users = $query->get_results();
		$total = (int) $query->get_total();
		$pages = (int) ceil( $total / $per_page );

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=wsrp_export_cnpj_users' ), 'wsrp_export_cnpj_users', 'wsrp_export_nonce' );
		?>
		<p>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Exportar CSV', 'wc-smart-registration-pricing' ); ?></a>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nome', 'wc-smart-registration-pricing' ); ?></th>
					<th><?php esc_html_e( 'CNPJ', 'wc-smart-registration-pricing' ); ?></th>
					<th><?php esc_html_e( 'E-mail', 'wc-smart-registration-pricing' ); ?></th>
					<th><?php esc_html_e( 'Data de cadastro', 'wc-smart-registration-pricing' ); ?></th>
					<th><?php esc_html_e( 'Desconto aplicado', 'wc-smart-registration-pricing' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( empty( $users ) ) {
					echo '<tr><td colspan="5">' . esc_html__( 'Nenhum usuário CNPJ encontrado.', 'wc-smart-registration-pricing' ) . '</td></tr>';
				} else {
					$summary = WSRP_User_Meta::get_discount_config_summary();
					foreach ( $users as $user ) {
						if ( ! $user instanceof WP_User ) {
							continue;
						}
						$cnpj = get_user_meta( $user->ID, 'billing_cnpj', true );
						echo '<tr>';
						echo '<td>' . esc_html( $user->display_name ) . '</td>';
						echo '<td>' . esc_html( (string) $cnpj ) . '</td>';
						echo '<td>' . esc_html( $user->user_email ) . '</td>';
						echo '<td>' . esc_html( $user->user_registered ) . '</td>';
						echo '<td>' . esc_html( $summary ) . '</td>';
						echo '</tr>';
					}
				}
				?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=wsrp-settings&tab=users' ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => max( 1, $pages ),
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}
}
