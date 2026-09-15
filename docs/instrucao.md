# Instruções — Setup do tema Pnab + plugin AldirBlanc no MapasCulturais

Este documento descreve, arquivo por arquivo, todas as alterações feitas neste
repositório (branch `develop`) para adicionar o tema `Pnab` e o plugin
`AldirBlanc` como submódulos git e ativá-los no ambiente de desenvolvimento
Docker. Aplique exatamente estas mudanças no outro repositório para reproduzir
o mesmo setup.

## Contexto

- Tema: `src/themes/Pnab` (submódulo git, repo `theme-Pnab`, versão `v5.12.0`,
  commit `93d2710bac9272a91b3e5e47f8f42c41ef6dfd3f`)
- Plugin: `src/plugins/AldirBlanc` (submódulo git, repo `plugin-AldirBlanc`,
  versão `v5.3.0`, commit `9ef1d1dc6c8841c77bdfd090d518215853679baa`)

## 1. Adicionar os submódulos

Se ainda não existirem no repositório de destino, adicione com `git submodule
add` (isso já cria as entradas certas em `.gitmodules` e o gitlink no índice):

```bash
git submodule add https://github.com/culturagovbr/theme-Pnab.git src/themes/Pnab
git submodule add https://github.com/culturagovbr/plugin-AldirBlanc.git src/plugins/AldirBlanc
```

> **Atenção:** o `.gitignore` do repositório tem a regra `src/plugins/*`, que
> ignora por padrão qualquer pasta nova dentro de `src/plugins/` (isso é
> intencional, pois cada plugin é versionado individualmente como submódulo).
> Por isso, ao adicionar um plugin (não o tema), o `git submodule add` normal
> pode falhar com "Os caminhos a seguir são ignorados por um dos seus arquivos
> .gitignore". Nesse caso use `-f`:
>
> ```bash
> git submodule add -f https://github.com/<org>/<repo>.git src/plugins/<NomeDoPlugin>
> ```
>
> Exemplo real: adicionar o plugin `MultipleLocalAuth`:
>
> ```bash
> cd src/plugins
> git submodule add -f https://github.com/mapasculturais/plugin-MultipleLocalAuth.git MultipleLocalAuth
> ```

Se preferir fixar exatamente nos mesmos commits usados aqui:

```bash
cd src/themes/Pnab && git checkout 93d2710bac9272a91b3e5e47f8f42c41ef6dfd3f && cd -
cd src/plugins/AldirBlanc && git checkout 9ef1d1dc6c8841c77bdfd090d518215853679baa && cd -
```

Resultado esperado em `.gitmodules` (arquivo estava vazio antes):

```ini
[submodule "src/themes/Pnab"]
	path = src/themes/Pnab
	url = https://github.com/culturagovbr/theme-Pnab.git
[submodule "src/plugins/AldirBlanc"]
	path = src/plugins/AldirBlanc
	url = https://github.com/culturagovbr/plugin-AldirBlanc.git
```

## 2. `dev/config.d/0.main.php`

Trocar o tema ativo de `BaseV2` para `Pnab`:

```diff
     /* MAIN */
-    'themes.active' => 'MapasCulturais\Themes\BaseV2',
+    'themes.active' => 'Pnab',
```

## 3. `dev/config.d/plugins.php`

Adicionar `"AldirBlanc"` à lista de plugins (mantendo `MultipleLocalAuth`):

```diff
 return [
     'plugins' => [
-        "MultipleLocalAuth"
+        "MultipleLocalAuth",
+        "AldirBlanc"
     ]
 ];
```

## 4. `dev/docker-compose.yml`

No serviço principal, montar os dois submódulos como volumes dentro do
container (necessário pois submódulos git não entram automaticamente no mount
de `../src`) e habilitar o build de assets:

```diff
       - ../docker/development/router.php:/var/www/dev/router.php
-
-
+      ## Theme (submódulo em src/themes/Pnab, já incluído no mount de ../src)
+      - ../src/themes/Pnab:/var/www/src/themes/Pnab
+      ## Plugin AldirBlanc (submódulo em src/plugins/AldirBlanc)
+      - ../src/plugins/AldirBlanc:/var/www/src/plugins/AldirBlanc
     links:
       - db
       - redis
@@
     environment:
       - REDIRECT_404_ASSETS_TO=
-
-      - BUILD_ASSETS=0
+      - BUILD_ASSETS=1
+      - CI=true
```

> **Por que `CI=true`:** o `entrypoint.sh` do container roda `pnpm install
> --recursive` quando `BUILD_ASSETS=1`. O serviço `mapas` tem `tty: true` e
> `stdin_open: true`, então o pnpm 10 detecta um TTY interativo e, se o
> lockfile precisar recriar o `node_modules` (ex.: após adicionar um novo
> workspace de tema/plugin), ele pede confirmação por teclado — mas como
> ninguém está de fato digitando nesse pseudo-terminal, o processo **trava
> para sempre** (sem erro, sem timeout). Definir `CI=true` faz o pnpm assumir
> modo não interativo (equivalente a `--frozen-lockfile` por padrão) e evita
> essa trava. Sem essa variável, `dev/start.sh` ou `docker compose up` podem
> ficar "pendurados" indefinidamente na fase de instalação de assets.

## 5. `docker/Dockerfile`

Fixar a versão major do pnpm instalada globalmente (evita quebra por update
automático de major version):

```diff
-RUN npm install -g pnpm
+RUN npm install -g pnpm@10
```

## 6. `src/pnpm-lock.yaml`

Adicionar a entrada do workspace do novo tema (gerado automaticamente ao
rodar `pnpm install` depois de adicionar o submódulo do tema, que tem seu
próprio `package.json` de devDependency em `@mapas/scripts`):

```diff
   themes/Pnab:
     devDependencies:
       '@mapas/scripts':
         specifier: workspace:*
         version: link:../../node_scripts
```

Não edite esse arquivo manualmente — depois de adicionar o submódulo do tema
ou de qualquer plugin com `package.json` próprio (ex.: `MultipleLocalAuth`),
rode dentro do diretório `src`, **no host, fora do Docker**:

```bash
pnpm install --no-frozen-lockfile
```

> **Por que `--no-frozen-lockfile`:** como agora `dev/docker-compose.yml`
> define `CI=true` (ver item 4), qualquer `pnpm install` sem esse flag roda
> em modo `--frozen-lockfile` por padrão e falha com
> `ERR_PNPM_OUTDATED_LOCKFILE` sempre que o `pnpm-lock.yaml` ainda não
> conhece o `package.json` do novo workspace (tema/plugin). O flag
> `--no-frozen-lockfile` força a atualização do lockfile mesmo com
> `CI=true` setado no ambiente.

e isso deve gerar essa mesma entrada (e possivelmente outras, dependendo da
versão do lockfile/pnpm).

> **Erro de permissão (`EACCES`) ao rodar `pnpm install` no host:** se o
> container já rodou antes (com `pnpm install --recursive` executando como
> `root` dentro do container sobre a pasta `src/` montada do host), alguns
> arquivos/pastas dentro de `src/**/node_modules` ficam com dono `root` no
> host. Isso faz o `pnpm install` local falhar com algo como:
>
> ```
> EACCES: permission denied, rmdir '.../node_modules/@escopo/pacote'
> ```
>
> Corrija a posse antes de rodar o `pnpm install` no host:
>
> ```bash
> sudo chown -R $USER:$USER src/
> ```

## Ordem recomendada de aplicação

1. `git submodule add` dos dois repositórios (item 1). Se algum caminho for
   ignorado pelo `.gitignore` (comum em `src/plugins/*`), use `-f`.
2. Editar `dev/config.d/0.main.php` (item 2).
3. Editar `dev/config.d/plugins.php` (item 3).
4. Editar `dev/docker-compose.yml`, incluindo os volumes dos submódulos e as
   variáveis `BUILD_ASSETS=1` e `CI=true` (item 4).
5. Editar `docker/Dockerfile` (item 5).
6. (Se necessário) corrigir posse de arquivos root-owned em `src/` com
   `sudo chown -R $USER:$USER src/`, e rodar
   `pnpm install --no-frozen-lockfile` em `src/` para atualizar o
   `pnpm-lock.yaml` (item 6).
7. Subir o stack: `docker compose -f dev/docker-compose.yml up -d --build`
   (ou o comando equivalente usado no projeto, ex.: `dev/start.sh`).
8. Verificar que o container sobe, que o tema `Pnab` é reconhecido e que o
   plugin `AldirBlanc` (e demais plugins adicionados) é carregado sem erros
   nos logs.

## Adicionando outros plugins/temas depois

O mesmo procedimento vale para qualquer novo submódulo adicionado
posteriormente (ex.: `MultipleLocalAuth`, ou qualquer outro plugin/tema):

1. `git submodule add -f <url> src/plugins/<Nome>` (ou `src/themes/<Nome>`).
2. Adicionar o volume correspondente em `dev/docker-compose.yml` (mesmo
   padrão do item 4).
3. Se o plugin/tema tiver `package.json` próprio, corrigir permissões se
   necessário e rodar `pnpm install --no-frozen-lockfile` em `src/` no host
   para atualizar o `pnpm-lock.yaml`.
4. Adicionar o nome do plugin em `dev/config.d/plugins.php` (se for plugin).
5. Subir/recriar o stack e conferir os logs.
