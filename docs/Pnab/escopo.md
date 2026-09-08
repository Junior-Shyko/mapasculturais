# Pnab — Escopo e notas de investigação

Notas consolidadas de uma sessão de debug sobre autenticação (reCAPTCHA) e o fluxo de
"oportunidades a partir de modelo" no tema Pnab. Objetivo: documentar o que foi
aprendido sobre esses dois fluxos para não precisar re-investigar do zero depois.

## 1. Login com reCAPTCHA falhando ("Captcha incorreto, tente novamente!")

### Sintoma
Cadastro (`autenticacao/register`) funcionava normalmente, mas login
(`autenticacao/login`) sempre retornava `"Captcha incorreto, tente novamente!"`,
mesmo resolvendo o captcha corretamente. No console do navegador aparecia:
```
Uncaught (in promise) Error: Missing required parameters: sitekey
```

### Causa raiz
Login e cadastro usam **dois widgets de captcha diferentes**, cada um lendo o
sitekey de uma fonte de config distinta:

- **Cadastro** — `src/plugins/MultipleLocalAuth/components/create-account/template.php:72,117`
  usa `<VueRecaptcha :sitekey="configs['google-recaptcha-sitekey']">`, valor que vem
  direto da config do `MultipleLocalAuth\Provider` (lê `GOOGLE_RECAPTCHA_SITEKEY`/`GOOGLE_RECAPTCHA_SECRET`
  em `Provider.php:62-63`).
- **Login** — `src/themes/Pnab/components/login/template.php:56,99,132,183` usa o
  componente genérico `<mc-captcha>` do módulo Components
  (`src/modules/Components/components/mc-captcha/script.js:26`), que lê o sitekey de
  `$MAPAS.mcCaptchaConfig.captcha.key`. Esse valor vem de `config/captcha.php`, que
  lê as variáveis `CAPTCHA_SITEKEY`/`CAPTCHA_SECRET` (com fallback pra
  `GOOGLE_RECAPTCHA_SITEKEY`/`GOOGLE_RECAPTCHA_SECRET`).

No ambiente de dev, `dev/docker-compose.yml` só definia `GOOGLE_RECAPTCHA_SITEKEY`/
`GOOGLE_RECAPTCHA_SECRET`. Por algum motivo o fallback interno de `config/captcha.php`
(`env('CAPTCHA_SITEKEY', env('GOOGLE_RECAPTCHA_SITEKEY', null))`) não resolvia,
então `mcCaptchaConfig.captcha.key` chegava `null` no client — o widget de login
nunca renderizava de verdade, `g-recaptcha-response` ficava sempre vazio, e o
backend (`MultipleLocalAuth\Provider::verifyRecaptcha2()`, `Provider.php:668-678`)
recusava o login.

### Correção aplicada
Setar explicitamente `CAPTCHA_SITEKEY`/`CAPTCHA_SECRET` em `dev/docker-compose.yml`
(mesmos valores de `GOOGLE_RECAPTCHA_SITEKEY`/`GOOGLE_RECAPTCHA_SECRET`), e recriar
o container (`docker compose -f dev/docker-compose.yml up -d --force-recreate mapas`).
Confirmado via `curl http://localhost/auth` que `mcCaptchaConfig.captcha.key` passou
a vir populado, e o login voltou a funcionar.

> **Débito técnico**: a causa raiz de fundo (por que o fallback nested em
> `config/captcha.php:15` não resolvia sozinho) não foi 100% confirmada — pode ser
> ordem de carregamento de config, cache atrelado a `version.txt`, ou algo
> específico de subsite. A correção aplicada foi um workaround funcional, não uma
> correção de código. Ideal a médio prazo: unificar o widget de captcha do login
> da Pnab para usar a mesma fonte de config do cadastro (`VueRecaptcha` direto com
> `configs['google-recaptcha-sitekey']`), eliminando a duplicidade de fontes.

### Achado de segurança relacionado (não corrigido, fora de escopo desta sessão)
`views/auth/multiple-local.php:8,25` e `views/auth/register.php` serializam o
array `$config` inteiro (incluindo `google-recaptcha-secret`) via `json_encode` e
injetam no HTML (`<login config='...'>`). Isso expõe a **secret key do reCAPTCHA
no client-side**. A ser tratado em uma sessão futura.

## 2. Fluxo "Minhas oportunidades" / modelos (aba "Meus modelos")

### Endpoint `/api/opportunity/find`
É um endpoint **interno** desta mesma aplicação (não é chamada externa), servido
por `MapasCulturais\Controllers\Opportunity` (`src/core/Controllers/Opportunity.php`)
via a action `find` genérica (`src/core/Traits/ControllerAPI.php:237-240`), que monta
a query com `ApiQuery` (`src/core/ApiQuery.php`).

### `parAction` não é um campo real da entidade
O tema Pnab intercepta a query antes dela chegar no `ApiQuery`, via hook
`API.find(opportunity).params` (`src/themes/Pnab/Theme.php:342`). Quando detecta a
combinação específica da aba "Meus modelos" — `isModel=EQ(1)` + `status=EQ(-1)` e
usuário `gestorCultBr` (`Theme.php:388-392`) — ele:

1. Remove o parâmetro `parAction` (singular) da query normal (`Theme.php:396`).
2. Roda uma SQL manual (`Theme.php:433-439`) exigindo, ao mesmo tempo:
   - `opportunity_meta.isModel = '1'`
   - `opportunity_meta.isModelPublic = '1'`
   - `opportunity_meta.parActions` (metadado JSON, plural) contendo a string exata
     recebida em `parAction` (via `jsonb_exists`)
   - um `seal_relation` associando a oportunidade a um dos selos configurados em
     `app.verifiedSealsIds` (`Theme.php:403-417`; em dev = `[1]`, ver
     `dev/config.d/0.main.php:12`)
3. Se qualquer condição falhar, força `$api_params['id'] = 'EQ(-1)'`
   (`Theme.php:399,420,448`) — zero resultados garantidos, mesmo que existam
   modelos no banco que não atendam a todos os requisitos.

### Por que a aba "Meus modelos" estava vazia no ambiente de dev
Confirmado diretamente no banco: `select count(*) from opportunity_meta where
key='isModel' and value='1'` → **0**. Não existe nenhuma oportunidade marcada
como modelo no banco de dev — o problema não é de config nem de query, é
ausência total de dado seed.

### Modal "Usar modelo" (`opportunity-create-based-model`) — onde é acionado
Só existe **um** ponto de entrada no código para esse modal:
`panel--entity-models-card/template.php:112-114`, dentro do rodapé de cada card
de modelo:
```php
<div v-if="showModel && entity.status != -2 && entity.__objectType == 'opportunity' && entity.isModel == 1">
    <opportunity-create-based-model :entitydefault="entity" classes="col-12"></opportunity-create-based-model>
</div>
```
A prop `entitydefault` (obrigatória, sem default —
`opportunity-create-based-model/script.js`) é a oportunidade-modelo específica
daquele card. Esse card, por sua vez, só é renderizado por entidade dentro da aba
"Meus modelos" (`panel--entity-tabs/template.php:165`,
`v-if="entity.__objectType == 'opportunity' && entity.isModel == 1"`).

**Conclusão**: o modal "Usar modelo" não tem nenhum ponto de entrada independente
de um card de modelo já existente. Sem nenhuma oportunidade `isModel=1` no banco,
ele é genuinamente inacessível pela UI.

### ⚠️ Regra de negócio de permissão (destaque)
Em `src/themes/Pnab/views/panel/opportunities.php:13-18,34-44`, o botão genérico
"Criar Oportunidade" (que abre o componente `create-opportunity`, um fluxo
diferente/próprio de seleção de PAR) só é importado e renderizado quando:
```php
$isGestorCultBr = UserAccessService::isGestorCultBr();
if (!$isGestorCultBr) { /* importa e renderiza create-opportunity + opportunity-importer */ }
```

Ou seja:

- **Usuário `gestorCultBr`**: o botão de criação livre de oportunidade é
  **propositalmente escondido**. A única forma prevista de criar uma oportunidade
  é a partir de um modelo (`opportunity-create-based-model`), reforçada pelo aviso
  em `panel--entity-tabs/template.php:57-61` ("Para criar novas oportunidades,
  utilize os modelos disponíveis na aba Meus modelos."). Se não houver nenhum
  modelo (`isModel=1`) publicado/vinculado a um selo verificado, esse usuário
  **fica sem nenhum caminho de UI** para criar oportunidades.
- **Usuário `admin`** (e não `gestorCultBr`): a condição só verifica
  `isGestorCultBr`, não `isAdmin` — então o botão genérico "Criar Oportunidade"
  continua visível e funcional normalmente, independente de existirem modelos ou
  não.

Isso explica por completo o comportamento relatado ("no meu painel não mostra a
opção de criar uma oportunidade"): é regra de negócio para o perfil
`gestorCultBr`, condicionada à existência de modelos oficiais/públicos vinculados
a selos verificados — não é um bug de UI.

### Para destravar em ambiente de dev
Popular no banco uma oportunidade de teste com:
- metadado `isModel = 1`
- metadado `isModelPublic = 1`
- metadado `parActions` (JSON array) contendo a string exata usada pelo filtro
  (ex.: `"1.1 Fomento Cultural"`)
- um `seal_relation` associando essa oportunidade ao selo configurado em
  `app.verifiedSealsIds` (id `1` em dev)

## Referências rápidas de arquivos

| Assunto | Arquivo |
|---|---|
| Widget de captcha do login (Pnab) | `src/themes/Pnab/components/login/template.php` |
| Widget de captcha do cadastro | `src/plugins/MultipleLocalAuth/components/create-account/template.php` |
| Config genérica de captcha | `config/captcha.php` |
| Config específica do MultipleLocalAuth | `src/plugins/MultipleLocalAuth/Provider.php` |
| Env vars de captcha (dev) | `dev/docker-compose.yml` |
| Hook de query da API para oportunidades (Pnab) | `src/themes/Pnab/Theme.php:342` |
| Config de selos verificados (dev) | `dev/config.d/0.main.php:12` |
| Aba "Meus modelos" / listagem | `src/themes/Pnab/components/panel--entity-tabs/template.php` |
| Card de modelo + botão "Usar modelo" | `src/themes/Pnab/components/panel--entity-models-card/template.php` |
| Modal "Usar modelo" | `src/themes/Pnab/components/opportunity-create-based-model/template.php` |
| Botão "Criar Oportunidade" (condicionado a `!isGestorCultBr`) | `src/themes/Pnab/views/panel/opportunities.php` |
| Regra de perfil `gestorCultBr` | `src/plugins/AldirBlanc/Services/UserAccessService.php` |
