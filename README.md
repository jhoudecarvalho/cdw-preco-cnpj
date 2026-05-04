# CDW Preço CNPJ

Cadastro CPF/CNPJ na "Minha Conta" e preços diferenciados para clientes CNPJ no WooCommerce. Complementar ao **WooCommerce Extra Checkout Fields for Brazil (Brazilian Market)**.

- **Slug interno**: `wc-smart-registration-pricing`
- **Text Domain**: `wc-smart-registration-pricing`
- **Versão atual**: ver `CHANGELOG.md` ou o header do arquivo principal.

## Funcionalidades

### Cadastro inteligente
- Campo único **CPF / CNPJ** no formulário de registro com máscara dinâmica e alternância automática entre PF e PJ.
- Validação módulo 11 (CPF e CNPJ) e checagem de unicidade contra `wp_usermeta` (sem `LIKE` com wildcard duplo — usa exatamente os formatos digits-only e máscara padrão).
- Persiste `billing_persontype`, `billing_cpf`, `billing_cnpj`, `billing_company`, `billing_ie`, `billing_first_name`, `billing_last_name`.
- Requer **Brazilian Market** ativo. Sem ele, o módulo de cadastro é desativado silenciosamente; o módulo de preços continua funcional.

### Preços CNPJ
- Aplicado a clientes com `billing_persontype === '2'`.
- **Desconto global** (% ou R$ fixo) e/ou **regras por categoria** (% ou R$ fixo). Quando o produto está em várias categorias com regra, prevalece o **maior benefício** (menor preço final) — comparado contra o preço real do produto, não contra base fixa.
- Filtros aplicados em `woocommerce_product_get_price` e `woocommerce_product_variation_get_price` (não em `regular_price` / `sale_price`, para preservar a noção nativa de promoção do WC).
- Bloco **"Preço de varejo / Preço CNPJ"** na vitrine, com compat para o **WC Simulador de Parcelas** (re-injeção em `PHP_INT_MAX`).
- **Linha de economia** no carrinho/checkout clássicos e meta por item no Cart/Checkout/Mini-cart Block via Store API (`woocommerce_store_api_cart_item_data`).
- Excluído em feeds; sinaliza `DONOTCACHEPAGE` para que plugins de cache não sirvam preço CNPJ a visitantes não-PJ.

### Painel admin
`WooCommerce → CDW Preço CNPJ`. Quatro abas:

1. **Configurações gerais** — liga/desliga cada módulo + texto auxiliar abaixo do CPF/CNPJ.
2. **Desconto global CNPJ** — % ou R$ fixo.
3. **Desconto por categoria** — uma regra por `product_cat`.
4. **Usuários CNPJ** — listagem paginada + export CSV em chunks (suporta bases grandes).

## Auto-update

Distribuído via `plugins.cdwtech.com.br`:

- O plugin embute **Plugin Update Checker (YahnisElsts v5)** via Composer.
- O bootstrap (IIFE no topo de `wc-smart-registration-pricing.php`) consulta `https://plugins.cdwtech.com.br/wc-smart-registration-pricing/info.json`.
- WP-admin passa a oferecer "atualização disponível" automaticamente quando uma release é publicada.

## Release

Toda tag `vX.Y.Z` empurrada para o repositório dispara `.github/workflows/release.yml`:

1. `composer install --no-dev --optimize-autoloader`.
2. Empacota `wc-smart-registration-pricing-X.Y.Z.zip` (com `vendor/`, sem `.git*`/`build/`/IDE files/`RELEASE_BODY.md`).
3. Publica como **GitHub Release** anexando o zip e usando `RELEASE_BODY.md` como descrição.
4. Gera `info.json` (nome, slug, versão, download_url, changelog) e faz `scp` do zip + `info.json` para o update server.
5. Valida que o manifest está acessível em `https://plugins.cdwtech.com.br/wc-smart-registration-pricing/info.json`.

### Disparo manual

```text
GitHub → Actions → Release → Run workflow → version = 1.3.1
```

### Secrets necessários no repo

- `DEPLOY_SSH_KEY` — chave privada (PEM) com acesso ao update server.
- `DEPLOY_SSH_HOST` — hostname do update server.
- `DEPLOY_SSH_USER` — usuário SSH.
- `DEPLOY_REMOTE_PATH` — diretório remoto onde os artefatos são gravados (ex.: `/srv/plugins.cdwtech.com.br/wc-smart-registration-pricing`).

## Desenvolvimento local

```bash
composer install
```

`vendor/autoload.php` é necessário para o PUC carregar — sem ele, o plugin emite um aviso no admin e não fatalmente.

## Licença

GPL-2.0-or-later.
