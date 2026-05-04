## CDW Preço CNPJ 1.3.1

Release operacional para validar o pipeline end-to-end após o ajuste do secret `DEPLOY_REMOTE_PATH`. **Sem mudanças funcionais** em relação à 1.3.0.

### Notas

- O secret `DEPLOY_REMOTE_PATH` no GitHub Actions estava apontando para `…/cdw-preco-cnpj/` (nome do repositório) em vez de `…/wc-smart-registration-pricing/` (slug do plugin). Com o caminho corrigido, o `scp` do workflow agora grava no diretório correto que o nginx do `plugins.cdwtech.com.br` serve.
- Esta release confirma que tag `vX.Y.Z` ⇒ build ⇒ GitHub Release ⇒ SCP ⇒ manifest público está funcional ponta-a-ponta sem intervenção manual.

### Como atualizar

Os sites com o plugin instalado vão ver "atualização disponível" automaticamente em até alguns minutos (intervalo padrão do PUC). Para forçar agora: **WP-Admin → Plugins → "Verificar atualizações"**.
