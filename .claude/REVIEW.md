# REVIEW.md

Critérios de revisão de código para alterações neste repositório — tanto no core (`./`, `src/core`, `src/modules`) quanto em temas e plugins (`src/themes/*`, `src/plugins/*`).

## Escopo

Aplica-se a qualquer diff revisado neste projeto: PHP (core, módulos, temas, plugins) e frontend Vue (temas/plugins com build via laravel-mix/pnpm). Revisão de PR/branch e revisão de diff local seguem os mesmos critérios abaixo.

## PHP

- Seguir PSR-12 (formatação, namespaces, visibilidade explícita de métodos/propriedades, uma classe por arquivo, etc.).
- Seguir PSR-4 conforme o autoload já configurado no `composer.json` raiz (`MapasCulturais\` → `src/core`, `MapasCulturais\Modules\` → `src/modules`, `MapasCulturais\Themes\` → `src/themes`) — não introduzir classes fora do namespace esperado pelo diretório.
- Tipagem: preferir type hints e tipos de retorno explícitos (PHP 8.3) em vez de docblocks redundantes.
- Compatibilidade com o pinning existente (Doctrine ORM `2.16.*`, PHPUnit `^10.5`) — não sugerir migrações de major version como parte de uma revisão pontual.

## Princípios SOLID

- **S**RP: uma classe/método deve ter um único motivo para mudar. Sinalizar controllers/entities/plugins que acumulam responsabilidades não relacionadas.
- **O**CP: preferir extensão via hooks (`MapasCulturais\Hooks`) e composição a modificar comportamento existente com condicionais acopladas ao caso de uso novo.
- **L**SP: subclasses de `Module`/`Plugin`/`Entity` devem poder substituir a classe-base sem quebrar contratos (assinatura de `_init()`, `register()`, etc.).
- **I**SP: evitar interfaces/classes-base "gordas" que forcem implementações vazias em módulos/plugins que não precisam de todos os métodos.
- **D**IP: depender de abstrações (hooks, interfaces, `App::i()` como ponto único de acesso) em vez de instanciar dependências concretas diretamente onde evitável.

## Clean Code (Robert C. Martin)

- Nomes que revelam intenção; eliminar abreviações obscuras e nomes genéricos (`data`, `tmp`, `handle`).
- Funções pequenas, um nível de abstração por função, poucos parâmetros (evitar flags booleanas como parâmetro de comportamento).
- Comentários só quando o código não pode expressar a intenção sozinho (workaround, aviso de efeito colateral, WHY não-óbvio) — nunca comentário que restate o que o código já diz. Regra vale para o repo inteiro, não só para `ConectaEnte`.
- Sem código morto, sem blocos comentados, sem implementações parcialmente prontas deixadas para "depois".
- Duplicação: preferir três linhas repetidas a uma abstração prematura, mas sinalizar duplicação real de lógica de negócio (regras de permissão, validação) que deveria viver num único lugar.
- Tratamento de erro não deve mascarar exceções nem usar `catch` genérico sem necessidade; falhar de forma explícita perto da causa raiz.

## Vue.js (temas/plugins com frontend)

- Componentes com responsabilidade única; extrair lógica repetida para composables em vez de duplicar entre componentes.
- Props tipadas e validadas; evitar mutação direta de props (usar `emit`/estado local).
- Reatividade: preferir `computed` a lógica derivada recalculada manualmente em métodos/watchers; evitar `watch` quando `computed` resolve.
- Nomenclatura de eventos e props seguindo convenção kebab-case em templates, camelCase em script.
- Não misturar chamadas diretas a `fetch`/API dentro de componentes de apresentação — isolar em serviços/composables.

## Aplicação por área

- **Core (`src/core`, `src/modules`)**: maior rigor — mudanças aqui afetam todos os temas/plugins. Priorizar compatibilidade com o sistema de hooks existente e com o cache de permissões (`AgentPermissionCache` e afins).
- **Temas/Plugins (`src/themes/*`, `src/plugins/*`)**: mesmos critérios, mas avaliar também aderência ao padrão já estabelecido pelo tema/plugin de referência (ex.: `Pnab` como modelo para `ConectaEnte` — ver `documentation`/memória do projeto). Não perpetuar duplicação entre `Pnab` e `ConectaEnte`; ao portar lógica, seguir os princípios acima em vez de copiar literalmente.
