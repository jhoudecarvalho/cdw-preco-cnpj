## CDW Preço CNPJ 1.3.0

Primeira release com **auto-update** via `plugins.cdwtech.com.br`. Consolida o trabalho de auditoria iniciado em 1.1.0 (12 pontos resolvidos em 1.2.0 / 1.3.0) e adiciona o pipeline de release.

### Adicionado

- **Auto-update** via Plugin Update Checker (YahnisElsts v5). O plugin consulta `https://plugins.cdwtech.com.br/wc-smart-registration-pricing/info.json` e o WP-admin passa a oferecer atualização automaticamente quando uma nova release é publicada.
- **Pipeline de release** (`.github/workflows/release.yml`): tag `vX.Y.Z` ⇒ build com `composer install --no-dev` ⇒ zip com `vendor/` incluído ⇒ GitHub Release ⇒ SCP do zip + `info.json` para o update server.
- **Bootstrap defensivo**: aviso no admin se `vendor/autoload.php` faltar, em vez de fatal.

### Corrigido

- **Singleton vazio** em `WSRP_User_Meta` removido (todos os métodos já eram `static`).
- **`is_current_user_cnpj()` memoizada por user_id** com invalidação em `save_customer_billing_meta`. Reduz chamadas redundantes nos filtros de preço por página de catálogo.

### Notas

- Para sites já com 1.2.0 instalado, a atualização para 1.3.0 vem por auto-update assim que o `info.json` ficar disponível em `plugins.cdwtech.com.br`.
- O zip de release contém `vendor/` pré-instalado — não é necessário rodar Composer no servidor.
