# Estratégia: Agente vinculado a múltiplos Subsites (Ente Federado)

## Contexto

No `itensFeature.md` (item A.1) está registrado que, no `ConectaEnte`, cada **subsite da instalação passa a ser o próprio ente federado**, identificado pela credencial — em vez da seleção manual de ente que o tema Pnab faz hoje. Surgiu a dúvida se isso exige que o campo `subsite_id` das entidades, hoje um valor **escalar** (um FK inteiro, não uma lista), precise virar array para permitir que um mesmo agente esteja vinculado a vários subsites/entes.

Este documento verifica o schema atual (core `Entities`, trait `EntityOriginSubsite`, resolução de subsite em `App.php`) e os padrões de relação N:N já usados no repositório (`AgentRelation` do core, `FederativeEntityAgentRelation` do plugin `AldirBlanc`, e o sistema de `Role` para admins de subsite). Conclusão prática: **não se toca em `subsite_id`** — o problema é outra dimensão do modelo, que o core já resolve de duas formas distintas, dependendo do que "vínculo com o ente" precisa significar.

## O que foi verificado

- **`subsite_id` é escalar em toda parte, e está correto assim.** `Agent`, `Space`, `Event`, `Project`, `Opportunity`, `Registration`, `Seal`, `Job`, `Role` — todos têm `@ORM\Column(type="integer", nullable=true)` + `@ORM\ManyToOne` para um único `Subsite` (via o trait `src/core/Traits/EntityOriginSubsite.php`). Esse campo responde "a qual subsite este registro pertence" (tenancy do dado) — uma pergunta diferente de "em quais subsites esta pessoa pode atuar". `App::$subsite` (`src/core/App.php:162`) também resolve **um** subsite por request (por domínio/host). Transformar isso em array quebraria `EntityOriginSubsite::authorizedInThisSite()`, `Subsite::_setNullSubsiteId()`, os filtros de API (`Subsite::applyApiFilters()`) e todo o resto do core que assume 1 registro = 1 subsite.

- **"Uma pessoa administra vários subsites" já existe nativamente, via `Role`, não via `Agent`.** `src/core/Entities/Role.php`: cada linha tem `userId` + `subsiteId` escalares — mas nada impede um mesmo `User` ter várias linhas de `Role`, uma por subsite. `User::addRole()/removeRole()/is()` (`src/core/Entities/User.php:241-334`) já gerenciam isso, e `Traits/ControllerSubSiteAdmin::POST_createAdminRole` (`src/core/Traits/ControllerSubSiteAdmin.php:29-48`) é o endpoint que já faz exatamente "tornar este agente admin deste subsite": `$agent->user->addRole($role, $subsite->id)`. Isso bate com a decisão já registrada no relatório (`itensFeature.md`, "O Delta"): **quem opera o ente passa a ser o administrador da instalação, papel que já existe** — ou seja, a multiplicidade que motivou a pergunta já está resolvida, sem schema novo, só concedendo o papel `admin` em cada subsite/ente que a pessoa deve gerenciar.

- **Não existe hoje um Agent↔Subsite muitos-para-muitos "de domínio" (para além de permissão).** O core tem uma entidade genérica polimórfica pronta para isso — `AgentRelation` (`src/core/Entities/AgentRelation.php`): tabela única `agent_relation`, discriminador `object_type` (enum Postgres), sem constraint de unicidade, então um agente pode ter N linhas apontando para N "owners" diferentes. O plugin `AldirBlanc` já usa exatamente esse mecanismo para o vínculo agente↔ente de hoje: `FederativeEntityAgentRelation extends AgentRelation`, só redeclarando `owner` como `ManyToOne` para `FederativeEntity`, registrado em tempo de execução via listener Doctrine (`AldirBlanc/Traits/DoctrineEventListenerTrait.php`) + hook `doctrine.emum(object_type).values` + uma migração que só adiciona um novo valor ao enum `object_type` (nenhuma tabela nova). É exatamente esse relacionamento que hoje alimenta a tela "Minha Equipe" (agentes vinculados ao ente, com `status`, grupo/papel, `has_control`).

## Recomendação

1. **Não alterar `subsite_id` em nenhuma entidade.** Ele continua escalar — é a resposta certa para "a quem este registro pertence".
2. **Para "quem pode operar/administrar o ente" (substitui o papel `GestorCultBr`):** usar o `Role` que já existe — conceder `admin` (ou papel equivalente) ao `User` do agente, uma vez por subsite/ente: `$agent->user->addRole('admin', $subsite->id)`. Zero entidade nova, zero migração. Isso é literalmente a decisão já fechada no relatório.
3. **Para "Minha Equipe" (vínculo agente↔ente com significado próprio — status pendente/habilitado, papel dentro da equipe, metadata PNAB, não apenas permissão de admin):** criar `ConectaEnte\Entities\SubsiteAgentRelation extends \MapasCulturais\Entities\AgentRelation`, espelhando exatamente `FederativeEntityAgentRelation`, mas com `owner` apontando para o `Subsite` do core em vez de `FederativeEntity`. Passos:
   - Nova classe de relação (sem tabela própria — reaproveita `agent_relation`).
   - Listener Doctrine para registrar `MapasCulturais\Entities\Subsite => ConectaEnte\Entities\SubsiteAgentRelation` no `DiscriminatorMap` em runtime (copiar o padrão de `AldirBlanc/Traits/DoctrineEventListenerTrait.php`).
   - Hook `doctrine.emum(object_type).values` adicionando a entrada correspondente.
   - Migração em `db-updates.php` do `ConectaEnte` adicionando `'MapasCulturais\Entities\Subsite'` como novo valor do enum Postgres `object_type` (bloco `DO $$ ... ALTER TYPE object_type ADD VALUE ... END $$`, guardado por checagem de existência, igual ao que `AldirBlanc/db-updates.php:20-30` faz para `FederativeEntity`).
   - **Não precisa alterar `src/core/Entities/Subsite.php`** — a consulta pode ser feita direto via `App::i()->repo(SubsiteAgentRelation::class)->findBy(['agent' => $agent, 'status' => Entity::STATUS_ENABLED])`, sem precisar da coleção de conveniência `__agentRelations` que o core não declara.

Ou seja: a resposta para "o agente precisa estar em vários subsites" tem duas frentes, ambas já com mecanismo pronto no core — uma usando `Role` (sem código novo), outra usando o padrão `AgentRelation`/STI (mesmo padrão que o `AldirBlanc` já usa, só trocando o "owner" de `FederativeEntity` para `Subsite`).

## Arquivos de referência

- `src/core/Entities/Subsite.php`, `Agent.php`, `Role.php`, `User.php` (`addRole`/`removeRole`/`is`)
- `src/core/Traits/EntityOriginSubsite.php`, `EntityAgentRelation.php`, `ControllerSubSiteAdmin.php`
- `src/core/Entities/AgentRelation.php`
- `src/plugins/AldirBlanc/Entities/FederativeEntity.php`, `FederativeEntityAgentRelation.php`
- `src/plugins/AldirBlanc/Traits/DoctrineEventListenerTrait.php`, `Plugin.php` (`initDoctrineMappings`, `getAgentRelationMappings`), `db-updates.php` (migração do enum `object_type`)

## Como validar

Ainda não há código para rodar — isto é uma decisão de arquitetura. Quando for implementada:
1. Testar a concessão de `Role` multi-subsite criando um `User` de teste com `addRole('admin', $subsiteA->id)` e `addRole('admin', $subsiteB->id)`, confirmando `is('admin', $subsiteA->id)` e `is('admin', $subsiteB->id)` ambos `true` (via `tests/run.sh`, seguindo os builders em `tests/src/Builders`).
2. Se `SubsiteAgentRelation` for implementada, testar que um mesmo `Agent` pode ter relações habilitadas com 2+ subsites simultaneamente, e que o enum `object_type` foi migrado corretamente (`tests/run.sh` contra a stack de teste, que roda as migrações do zero).
