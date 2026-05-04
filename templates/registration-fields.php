<?php
/**
 * Campos de CPF/CNPJ no formulário de registro (antes do e-mail).
 *
 * @package WSRP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = WSRP_User_Meta::get_settings();
$helper   = isset( $settings['registration_helper_text'] ) ? (string) $settings['registration_helper_text'] : '';

$post_person = isset( $_POST['wsrp_person_type'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_person_type'] ) ) : 'pessoa_fisica';
$post_doc    = isset( $_POST['wsrp_document'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_document'] ) ) : '';
$post_name   = isset( $_POST['wsrp_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_full_name'] ) ) : '';
$post_co     = isset( $_POST['wsrp_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_company_name'] ) ) : '';
$post_ie     = isset( $_POST['wsrp_ie'] ) ? sanitize_text_field( wp_unslash( $_POST['wsrp_ie'] ) ) : '';
?>
<div class="wsrp-registration-fields">
	<?php wp_nonce_field( 'wsrp_registration', 'wsrp_registration_nonce' ); ?>
	<input type="hidden" name="wsrp_person_type" id="wsrp_person_type" value="<?php echo esc_attr( $post_person ); ?>" />

	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide wsrp-field wsrp-field--document">
		<label for="wsrp_document"><?php esc_html_e( 'CPF ou CNPJ', 'wc-smart-registration-pricing' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="wsrp_document" id="wsrp_document" autocomplete="off" value="<?php echo esc_attr( $post_doc ); ?>" required aria-required="true" maxlength="18" />
	</p>

	<?php if ( '' !== trim( $helper ) ) : ?>
		<div class="form-row wsrp-helper-text"><?php echo wp_kses_post( wpautop( $helper ) ); ?></div>
	<?php endif; ?>

	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide wsrp-field wsrp-field--pf" id="wsrp_row_full_name">
		<label for="wsrp_full_name"><?php esc_html_e( 'Nome completo', 'wc-smart-registration-pricing' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="wsrp_full_name" id="wsrp_full_name" value="<?php echo esc_attr( $post_name ); ?>" autocomplete="name" <?php echo 'pessoa_juridica' === $post_person ? '' : 'required aria-required="true"'; ?> />
	</p>

	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide wsrp-field wsrp-field--pj" id="wsrp_row_company" style="display:none;">
		<label for="wsrp_company_name"><?php esc_html_e( 'Nome da empresa', 'wc-smart-registration-pricing' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="wsrp_company_name" id="wsrp_company_name" value="<?php echo esc_attr( $post_co ); ?>" autocomplete="organization" />
	</p>

	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide wsrp-field wsrp-field--pj" id="wsrp_row_ie" style="display:none;">
		<label for="wsrp_ie"><?php esc_html_e( 'Inscrição Estadual', 'wc-smart-registration-pricing' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="wsrp_ie" id="wsrp_ie" value="<?php echo esc_attr( $post_ie ); ?>" autocomplete="off" />
	</p>
</div>
