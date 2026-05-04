<?php
/**
 * Regras de preço para clientes CNPJ.
 *
 * @package WSRP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WSRP_Pricing_Rules.
 */
final class WSRP_Pricing_Rules {

	/**
	 * Marcador único do bloco "Preço de varejo / Preço CNPJ" no HTML — usado
	 * para detectar se o bloco já foi inserido por filtros anteriores.
	 */
	const PRICE_BLOCK_MARKER = 'wsrp-cnpj-price-block';

	/**
	 * Instância singleton.
	 *
	 * @var WSRP_Pricing_Rules|null
	 */
	private static $instance = null;

	/**
	 * Evita recursão ao ler preço base.
	 *
	 * @var bool
	 */
	private static $applying = false;

	/**
	 * Cache do bloco HTML por produto na request atual (evita reconstruir o
	 * HTML uma vez no filtro inicial e outra na re-inserção pós-simulador).
	 *
	 * @var array<string,string>
	 */
	private $price_block_cache = array();

	/**
	 * Singleton.
	 *
	 * @return WSRP_Pricing_Rules
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construtor: hooks de preço e exibição.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_hooks' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles' ), 20 );
	}

	/**
	 * Estilos do badge de preço CNPJ na vitrine.
	 */
	public function enqueue_public_styles() {
		if ( ! WSRP_User_Meta::is_pricing_module_enabled() || is_admin() ) {
			return;
		}
		wp_enqueue_style(
			'wsrp-frontend-css',
			WSRP_PLUGIN_URL . 'assets/css/registration.css',
			array(),
			WSRP_VERSION
		);
	}

	/**
	 * Registra filtros se o módulo estiver ativo.
	 *
	 * Aplica desconto apenas no "preço efetivo" (get_price / variation_get_price);
	 * regular_price e sale_price ficam intactos para preservar a noção nativa de
	 * promoção do WooCommerce e os badges de "Sale!".
	 */
	public function register_hooks() {
		if ( ! WSRP_User_Meta::is_pricing_module_enabled() ) {
			return;
		}
		add_filter( 'woocommerce_product_get_price', array( $this, 'apply_cnpj_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'apply_cnpj_price' ), 10, 2 );
		// Insere o bloco "Preço de varejo / Preço CNPJ" cedo, antes de outros plugins
		// que alterem o HTML (ex.: temas, badges).
		add_filter( 'woocommerce_get_price_html', array( $this, 'replace_cnpj_price_html' ), 50, 2 );
		// Garante última posição na fila do filtro: se algum plugin substituiu o HTML
		// completamente (ex.: WC Simulador de Parcelas com "mostrar preço WC"
		// desligado), reinjeta o bloco. A duplicação é evitada pelo PRICE_BLOCK_MARKER.
		add_filter( 'woocommerce_get_price_html', array( $this, 'prepend_cnpj_list_price_if_missing' ), PHP_INT_MAX, 2 );
		// Carrinho/checkout clássicos (shortcodes [woocommerce_cart] e [woocommerce_checkout]).
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'cart_savings_row' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'checkout_savings_row' ) );
		// Cart/Checkout Blocks e Mini-cart Block: a economia aparece como meta no item via Store API.
		add_filter( 'woocommerce_store_api_cart_item_data', array( $this, 'cart_item_data_for_blocks' ), 10, 2 );
	}

	/**
	 * Contexto onde o desconto CNPJ deve valer (frontend e AJAX público).
	 *
	 * Exclui feeds (públicos, anônimos) e sinaliza para plugins de cache que a
	 * resposta é específica do usuário, evitando que páginas com preço CNPJ
	 * sejam servidas para visitantes não-PJ.
	 */
	private function should_apply_cnpj_pricing() {
		if ( ! WSRP_User_Meta::is_current_user_cnpj() ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		return true;
	}

	/**
	 * Aplica desconto ao preço exibido/calculado para CNPJ.
	 *
	 * @param string     $price   Preço.
	 * @param WC_Product $product Produto.
	 * @return string
	 */
	public function apply_cnpj_price( $price, $product ) {
		if ( self::$applying || ! $this->should_apply_cnpj_pricing() ) {
			return $price;
		}
		if ( '' === (string) $price || null === $price ) {
			return $price;
		}
		$base = (float) $price;
		if ( $base <= 0 ) {
			return $price;
		}
		$new = $this->compute_discounted_price( $base, $product );
		if ( $new < 0 ) {
			$new = 0;
		}
		return wc_format_decimal( $new, wc_get_price_decimals() );
	}

	/**
	 * Calcula preço com desconto.
	 *
	 * @param float      $base    Preço base.
	 * @param WC_Product $product Produto.
	 * @return float
	 */
	public function compute_discounted_price( $base, $product ) {
		$rule = $this->get_effective_rule( $product, (float) $base );
		if ( null === $rule || (float) $rule['value'] <= 0 ) {
			return (float) $base;
		}
		if ( 'fixed' === $rule['type'] ) {
			return max( 0, (float) $base - (float) $rule['value'] );
		}
		$pct = min( 100, (float) $rule['value'] );
		return round( (float) $base * ( 1 - $pct / 100 ), wc_get_price_decimals() );
	}

	/**
	 * Regra efetiva: categoria (melhor para o cliente) ou global.
	 *
	 * Quando o produto está em múltiplas categorias com regra, escolhe a regra
	 * que produz o menor preço final usando o próprio preço do produto como
	 * base — comparar percentuais e valores fixos sobre uma base fixa
	 * (ex.: 100) inverte a escolha em produtos com preço muito acima ou abaixo
	 * dessa base.
	 *
	 * @param WC_Product $product         Produto.
	 * @param float|null $reference_price Preço base para comparação (opcional).
	 * @return array{type:string,value:float}|null
	 */
	public function get_effective_rule( $product, $reference_price = null ) {
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$cat_ids    = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $cat_ids ) ) {
			$cat_ids = array();
		}

		if ( null === $reference_price || (float) $reference_price <= 0 ) {
			$reference_price = $this->get_reference_price( $product );
		}
		if ( (float) $reference_price <= 0 ) {
			$reference_price = 100.0;
		}

		$rules      = get_option( 'wsrp_category_rules', array() );
		$best_price = null;
		$best_rule  = null;

		if ( is_array( $rules ) && ! empty( $cat_ids ) ) {
			foreach ( $cat_ids as $cid ) {
				$cid = (int) $cid;
				if ( empty( $rules[ $cid ] ) || ! is_array( $rules[ $cid ] ) ) {
					continue;
				}
				$r = $rules[ $cid ];
				if ( empty( $r['enabled'] ) || (float) $r['value'] <= 0 ) {
					continue;
				}
				$type  = isset( $r['type'] ) && 'fixed' === $r['type'] ? 'fixed' : 'percent';
				$value = (float) $r['value'];
				$sim   = $this->simulate_price( (float) $reference_price, $type, $value );
				if ( null === $best_price || $sim < $best_price ) {
					$best_price = $sim;
					$best_rule  = array( 'type' => $type, 'value' => $value );
				}
			}
		}

		if ( null !== $best_rule ) {
			return $best_rule;
		}

		$s = WSRP_User_Meta::get_settings();
		if ( empty( $s['global_discount_enabled'] ) || (float) $s['global_discount_value'] <= 0 ) {
			return null;
		}
		$type = isset( $s['global_discount_type'] ) && 'fixed' === $s['global_discount_type'] ? 'fixed' : 'percent';
		return array(
			'type'  => $type,
			'value' => (float) $s['global_discount_value'],
		);
	}

	/**
	 * Preço base do produto sem o filtro de desconto CNPJ aplicado.
	 *
	 * @param WC_Product $product Produto.
	 * @return float
	 */
	private function get_reference_price( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0.0;
		}
		$prev          = self::$applying;
		self::$applying = true;
		try {
			if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
				$price = (float) $product->get_variation_price( 'min', false );
			} else {
				$price = (float) $product->get_price( 'edit' );
			}
		} finally {
			self::$applying = $prev;
		}
		return $price;
	}

	/**
	 * Simula preço para 100 unidades (comparar regras).
	 *
	 * @param float  $base  Base.
	 * @param string $type  percent|fixed.
	 * @param float  $value Valor da regra.
	 * @return float
	 */
	private function simulate_price( $base, $type, $value ) {
		if ( 'fixed' === $type ) {
			return max( 0, $base - $value );
		}
		$pct = min( 100, $value );
		return round( $base * ( 1 - $pct / 100 ), wc_get_price_decimals() );
	}

	/**
	 * Economia (preço de varejo − preço CNPJ) de um item de carrinho × qty.
	 *
	 * @param array $cart_item Item do carrinho do WooCommerce.
	 * @return float
	 */
	private function compute_cart_item_cnpj_savings( $cart_item ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item['data'] ) ) {
			return 0.0;
		}
		$product = $cart_item['data'];
		if ( ! $product instanceof WC_Product ) {
			return 0.0;
		}

		$prev          = self::$applying;
		self::$applying = true;
		$raw_price      = (float) $product->get_price( 'edit' );
		self::$applying = $prev;

		if ( $raw_price <= 0 ) {
			return 0.0;
		}

		$qty        = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		$discounted = $this->compute_discounted_price( $raw_price, $product );
		$diff       = $raw_price - $discounted;

		return $diff > 0 ? (float) $diff * $qty : 0.0;
	}

	/**
	 * Economia no carrinho (diferença entre preço de catálogo e preço CNPJ).
	 *
	 * @return float
	 */
	public function get_cart_cnpj_savings_total() {
		if ( ! WC()->cart || WC()->cart->is_empty() || ! WSRP_User_Meta::is_current_user_cnpj() ) {
			return 0;
		}
		$total = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$total += $this->compute_cart_item_cnpj_savings( $item );
		}
		return max( 0, (float) $total );
	}

	/**
	 * Adiciona a economia CNPJ como meta do item no Cart/Checkout/Mini-cart Block.
	 *
	 * O Cart Block exibe o array `item_data` (key/value) abaixo do nome do
	 * produto. É a forma suportada de mostrar informações por item nos blocos
	 * sem registrar um bloco React próprio. A linha global de economia
	 * (cart_savings_row / checkout_savings_row) só aparece no shortcode
	 * legacy.
	 *
	 * @param array $item_data Item data atual do Store API.
	 * @param array $cart_item Item do carrinho.
	 * @return array
	 */
	public function cart_item_data_for_blocks( $item_data, $cart_item ) {
		if ( ! is_array( $item_data ) ) {
			$item_data = array();
		}
		if ( ! WSRP_User_Meta::is_current_user_cnpj() ) {
			return $item_data;
		}
		$savings = $this->compute_cart_item_cnpj_savings( $cart_item );
		if ( $savings <= 0 ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'    => __( 'Benefício preço CNPJ', 'wc-smart-registration-pricing' ),
			'value'  => sprintf(
				/* translators: %s: valor formatado de economia */
				__( 'Economia de %s em relação ao preço de varejo', 'wc-smart-registration-pricing' ),
				wp_strip_all_tags( wc_price( $savings ) )
			),
			'hidden' => false,
		);
		return $item_data;
	}

	/**
	 * Exibe preço de varejo (PF) riscado e preço CNPJ em destaque.
	 *
	 * @param string     $html    HTML original do WooCommerce.
	 * @param WC_Product $product Produto.
	 * @return string
	 */
	public function replace_cnpj_price_html( $html, $product ) {
		if ( ! $this->should_apply_cnpj_pricing() || ! $product instanceof WC_Product ) {
			return $html;
		}
		if ( null === $this->get_effective_rule( $product ) ) {
			return $html;
		}
		$built = $this->get_or_build_price_block( $product );
		return '' !== $built ? $built : $html;
	}

	/**
	 * Reinjeta o bloco "Preço de varejo / Preço CNPJ" se o HTML final não
	 * contiver mais o marcador (ex.: outro plugin substituiu todo o HTML do
	 * preço). Registrado em PHP_INT_MAX para garantir última posição.
	 *
	 * @param string     $html    HTML já filtrado.
	 * @param WC_Product $product Produto.
	 * @return string
	 */
	public function prepend_cnpj_list_price_if_missing( $html, $product ) {
		if ( ! $this->should_apply_cnpj_pricing() || ! $product instanceof WC_Product ) {
			return $html;
		}
		if ( null === $this->get_effective_rule( $product ) ) {
			return $html;
		}
		if ( false !== strpos( (string) $html, self::PRICE_BLOCK_MARKER ) ) {
			return $html;
		}
		$built = $this->get_or_build_price_block( $product );
		if ( '' === $built ) {
			return $html;
		}
		return $built . $html;
	}

	/**
	 * Constrói (ou recupera do cache de request) o bloco HTML para o produto.
	 *
	 * @param WC_Product $product Produto.
	 * @return string HTML do bloco; vazio se não aplicável.
	 */
	private function get_or_build_price_block( $product ) {
		$cache_key = $product->get_id() . ':' . $product->get_type();
		if ( isset( $this->price_block_cache[ $cache_key ] ) ) {
			return $this->price_block_cache[ $cache_key ];
		}
		if ( $product->is_type( 'variable' ) ) {
			$built = $this->build_variable_cnpj_price_html( $product );
		} elseif ( $product->is_type( array( 'simple', 'variation', 'subscription', 'variable-subscription' ) ) ) {
			$built = $this->build_simple_cnpj_price_html( $product );
		} else {
			$built = '';
		}
		$this->price_block_cache[ $cache_key ] = $built;
		return $built;
	}

	/**
	 * HTML de preço para produto simples (ou variação): varejo × CNPJ.
	 *
	 * @param WC_Product $product Produto.
	 * @return string HTML vazio se inválido.
	 */
	private function build_simple_cnpj_price_html( $product ) {
		$price_edit = $product->get_price( 'edit' );
		if ( '' === (string) $price_edit || null === $price_edit ) {
			return '';
		}
		$pf_raw = (float) $price_edit;
		if ( $pf_raw <= 0 ) {
			return '';
		}
		$cnpj_raw = $this->compute_discounted_price( $pf_raw, $product );

		$pf_display    = wc_get_price_to_display( $product, array( 'price' => $pf_raw ) );
		$cnpj_display  = wc_get_price_to_display( $product, array( 'price' => $cnpj_raw ) );
		$suffix        = $product->get_price_suffix();

		$pf_html    = wc_price( $pf_display );
		$cnpj_html  = wc_price( $cnpj_display );

		$marker = self::PRICE_BLOCK_MARKER;
		$block  = '<span class="' . esc_attr( $marker . ' ' . $marker . '--simple' ) . '">';
		$block .= '<span class="wsrp-price-line wsrp-price-line--list">';
		$block .= '<span class="wsrp-price-label">' . esc_html__( 'Preço de varejo', 'wc-smart-registration-pricing' ) . '</span> ';
		$block .= '<del class="wsrp-price-original">' . $pf_html . '</del>';
		$block .= '</span> ';
		$block .= '<span class="wsrp-price-line wsrp-price-line--cnpj">';
		$block .= '<span class="wsrp-price-label">' . esc_html__( 'Preço CNPJ', 'wc-smart-registration-pricing' ) . '</span> ';
		$block .= '<ins class="wsrp-price-cnpj-value">' . $cnpj_html . '</ins>';
		$block .= '</span>';
		$block .= $suffix;
		$block .= '</span>';

		return $block;
	}

	/**
	 * HTML de preço para produto variável: faixas de varejo × CNPJ.
	 *
	 * @param WC_Product_Variable $product Produto variável.
	 * @return string
	 */
	private function build_variable_cnpj_price_html( $product ) {
		if ( ! $product instanceof WC_Product_Variable ) {
			return '';
		}
		$children = $product->get_visible_children();
		if ( empty( $children ) ) {
			return '';
		}

		$pf_displays   = array();
		$cnpj_displays = array();

		foreach ( $children as $vid ) {
			$var = wc_get_product( $vid );
			if ( ! $var instanceof WC_Product ) {
				continue;
			}
			$raw = (float) $var->get_price( 'edit' );
			if ( $raw <= 0 ) {
				continue;
			}
			$c_raw = $this->compute_discounted_price( $raw, $var );
			$pf_displays[]   = wc_get_price_to_display( $var, array( 'price' => $raw ) );
			$cnpj_displays[] = wc_get_price_to_display( $var, array( 'price' => $c_raw ) );
		}

		if ( empty( $pf_displays ) ) {
			return '';
		}

		$min_pf    = min( $pf_displays );
		$max_pf    = max( $pf_displays );
		$min_cnpj  = min( $cnpj_displays );
		$max_cnpj  = max( $cnpj_displays );

		$pf_html   = ( abs( $min_pf - $max_pf ) < 0.00001 ) ? wc_price( $min_pf ) : wc_format_price_range( $min_pf, $max_pf );
		$cnpj_html = ( abs( $min_cnpj - $max_cnpj ) < 0.00001 ) ? wc_price( $min_cnpj ) : wc_format_price_range( $min_cnpj, $max_cnpj );

		$marker = self::PRICE_BLOCK_MARKER;
		$block  = '<span class="' . esc_attr( $marker . ' ' . $marker . '--variable' ) . '">';
		$block .= '<span class="wsrp-price-line wsrp-price-line--list">';
		$block .= '<span class="wsrp-price-label">' . esc_html__( 'Preço de varejo', 'wc-smart-registration-pricing' ) . '</span> ';
		$block .= '<del class="wsrp-price-original">' . $pf_html . '</del>';
		$block .= '</span> ';
		$block .= '<span class="wsrp-price-line wsrp-price-line--cnpj">';
		$block .= '<span class="wsrp-price-label">' . esc_html__( 'Preço CNPJ', 'wc-smart-registration-pricing' ) . '</span> ';
		$block .= '<ins class="wsrp-price-cnpj-value">' . $cnpj_html . '</ins>';
		$block .= '</span>';
		$block .= '</span>';

		return $block;
	}

	/**
	 * Linha informativa no carrinho (economia CNPJ).
	 */
	public function cart_savings_row() {
		if ( ! WSRP_User_Meta::is_current_user_cnpj() ) {
			return;
		}
		$savings = $this->get_cart_cnpj_savings_total();
		if ( $savings <= 0 ) {
			return;
		}
		?>
		<tr class="fee wsrp-cnpj-savings-row">
			<th><?php esc_html_e( 'Benefício preço CNPJ (referência)', 'wc-smart-registration-pricing' ); ?></th>
			<td data-title="<?php echo esc_attr__( 'Benefício preço CNPJ', 'wc-smart-registration-pricing' ); ?>">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: valor economizado formatado */
						__( 'Economia em relação ao preço de varejo: %s', 'wc-smart-registration-pricing' ),
						wc_price( $savings )
					)
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Linha equivalente no checkout (tabela de revisão).
	 */
	public function checkout_savings_row() {
		if ( ! is_checkout() || ! WSRP_User_Meta::is_current_user_cnpj() ) {
			return;
		}
		$savings = $this->get_cart_cnpj_savings_total();
		if ( $savings <= 0 ) {
			return;
		}
		?>
		<tr class="fee wsrp-cnpj-savings-row">
			<th><?php esc_html_e( 'Benefício preço CNPJ (referência)', 'wc-smart-registration-pricing' ); ?></th>
			<td>
				<?php
				echo wp_kses_post(
					sprintf(
						__( 'Economia em relação ao preço de varejo: %s', 'wc-smart-registration-pricing' ),
						wc_price( $savings )
					)
				);
				?>
			</td>
		</tr>
		<?php
	}
}
