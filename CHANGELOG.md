# Changelog

Todas as alterações relevantes deste plugin são documentadas neste arquivo. O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e o versionamento segue [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [1.3.1] — 2026-05-04

### Notas

- Release operacional para validar o pipeline end-to-end após o ajuste do secret `DEPLOY_REMOTE_PATH` no GitHub Actions (apontava para `cdw-preco-cnpj/` — nome do repo — em vez de `wc-smart-registration-pricing/` — slug do plugin). Sem mudanças funcionais em relação à 1.3.0.

## [1.3.0] — 2026-05-04

### Adicionado

- **Auto-update** via servidor `plugins.cdwtech.com.br`. Integração com **Plugin Update Checker (YahnisElsts v5)**: o plugin consulta `https://plugins.cdwtech.com.br/wc-smart-registration-pricing/info.json` periodicamente e o WP-admin passa a mostrar **«atualização disponível»** automaticamente quando uma nova release é publicada (sem upload manual de zip).
- **Pipeline de release** (`.github/workflows/release.yml`): toda tag `vX.Y.Z` (ou disparo manual) roda `composer install --no-dev --optimize-autoloader`, empacota o plugin com `vendor/` incluído (excluindo `.git*`, IDE files, `build/`, `*.zip`, `RELEASE_BODY.md`), publica `wc-smart-registration-pricing-X.Y.Z.zip` em **GitHub Releases** e faz `scp` do zip + `info.json` para o update server.
- **Bootstrap defensivo do PUC**: se `vendor/autoload.php` faltar (deploy interrompido, extração parcial do zip), exibe um `admin_notice` explicativo em vez de derrubar o site com fatal.

### Alterado

- **`composer.json` introduzido**: declara `yahnis-elsts/plugin-update-checker:^5.0` como única dependência de runtime.
- **`vendor/` em `.gitignore`**: deixa de ser versionado. CI gera no build e empacota no zip de release; clientes recebem via auto-update sem precisar de Composer no servidor.

### Corrigido

- **Singleton vazio em `WSRP_User_Meta`** removido — todos os métodos eram estáticos; `instance()` nunca era usado de forma significativa.
- **`is_current_user_cnpj()` memoizada por `user_id`** em cache request-scoped (`WSRP_User_Meta::$is_cnpj_cache`). Evita chamadas redundantes a `is_user_logged_in / get_current_user_id / get_user_meta` nos múltiplos filtros de preço por página de catálogo. O cache é invalidado em `save_customer_billing_meta` quando `billing_persontype` é gravado.

## [1.2.0] — 2026-05-04

### Corrigido

- **Duplicidade de CPF/CNPJ sem `LIKE` com wildcard duplo**: `cpf_exists_for_other_user` / `cnpj_exists_for_other_user` agora usam `meta_value IN (digits, masked)` em `wp_usermeta`. O fallback antigo (`LIKE '%digits%'`) era full-scan caro em bases grandes e gerava falsos positivos por substring. Adicionados helpers públicos `format_cpf_mask` / `format_cnpj_mask`.
- **Regra "melhor preço" simulada contra o preço real do produto** em `WSRP_Pricing_Rules::get_effective_rule()`. Antes a comparação era contra base fixa de 100, o que invertia a escolha de regra em produtos com preço muito acima ou abaixo dessa base (ex.: regra fixa R$ 30 vs. percentual 50% num produto de R$ 50). Helper privado `get_reference_price()` com guarda anti-recursão.
- **Filtros de preço restritos a `get_price` e `variation_get_price`**: `regular_price` e `sale_price` (e variações) deixam de ser filtrados, preservando a noção nativa de promoção do WooCommerce e os badges de "Sale!".
- **Export CSV de usuários CNPJ em chunks**: itera em blocos de 500 com `count_total => false` e descarta cache de objetos por iteração. `wc_set_time_limit(0)` quando disponível. Suporta bases grandes sem estourar memória/timeout.
- **Exclusão de feeds + `DONOTCACHEPAGE`** em `should_apply_cnpj_pricing()`: páginas com preço CNPJ não vazam para feeds RSS e plugins de cache recebem o sinal de que a resposta é específica do usuário.
- **`flush_rewrite_rules()` removido** da ativação/desativação — o plugin não registra rewrite/CPT/taxonomia, então o flush era desnecessário e custoso.
- **Re-inserção do bloco "Preço de varejo / Preço CNPJ"** em `PHP_INT_MAX` em vez de `100050` (prioridade mágica que dependia de o WC Simulador de Parcelas continuar em `99999`). Constante `PRICE_BLOCK_MARKER` centraliza o marcador usado na detecção de duplicação. Helper único `get_or_build_price_block()` memoizado por produto na request, evitando reconstruir o HTML duas vezes.
- **Suporte a Cart/Checkout Blocks** via `woocommerce_store_api_cart_item_data`: a economia CNPJ aparece como meta do item ("Benefício preço CNPJ — Economia de R$ X em relação ao preço de varejo") no Cart Block, Mini-cart Block e Checkout Block. A linha global de economia continua sendo exibida no shortcode legacy.
- **`split_full_name`** retorna `last = ''` em vez de `'-'` quando o usuário informa apenas uma palavra. Evita `display_name = "João -"`.
- **Helper text do template** envolvido em `<div>` em vez de `<p>` para evitar HTML inválido com `<p>` aninhados gerados pelo `wpautop`.

## [1.1.0] — 2026-04-19

### Adicionado

- Versão inicial avaliada do plugin **CDW Preço CNPJ** (`wc-smart-registration-pricing`):
    - **Cadastro CPF/CNPJ na Minha Conta** com alternância PF/PJ por máscara dinâmica e validação módulo 11. Depende do plugin *WooCommerce Extra Checkout Fields for Brazil (Brazilian Market)* — grava `billing_persontype`, `billing_cpf`, `billing_cnpj`, `billing_company`, `billing_ie`.
    - **Preço diferenciado para clientes CNPJ** (`billing_persontype === '2'`): regra global e/ou por categoria (`%` ou R$ fixo) com escolha automática da regra que dá o menor preço final ao cliente.
    - **Badge "Preço de varejo / Preço CNPJ"** na vitrine, com compatibilidade explícita com o WC Simulador de Parcelas.
    - **Linha de economia** no carrinho/checkout clássicos.
    - Painel admin em `WooCommerce → CDW Preço CNPJ` com 4 abas (Geral, Desconto Global, Desconto por Categoria, Usuários CNPJ + export CSV).
    - Compatibilidade declarada com **HPOS** (`custom_order_tables`) e **Cart/Checkout Blocks** (`cart_checkout_blocks`).
