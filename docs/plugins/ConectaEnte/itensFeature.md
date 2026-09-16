# Levantamento: funcionalidades do tema Pnab → plugin ConectaEnte

## Contexto

O tema `Pnab` (`src/themes/Pnab`) implementa hoje o **CultEditais**, a integração da Política Nacional Aldir Blanc (PNAB) com a Plataforma CultBR do Ministério da Cultura — mas só funciona na instalação do próprio Ministério, porque metade da lógica de integração (inclusive 12 dos 30 campos obrigatórios) mora no tema visual, não em um plugin isolado.

O plugin `ConectaEnte` (`src/plugins/ConectaEnte`, hoje um stub vazio) nasce para permitir que **qualquer estado ou município** publique seus próprios editais e os envie à Plataforma CultBR — hoje só o Ministério consegue fazer isso. O `docs/relatorio-gestao.pdf` é o relatório de situação (~80% da documentação pronta, desenvolvimento ainda não iniciado) que documenta (a) como o CultEditais/tema Pnab funciona hoje, (b) o contrato da nova API "Conecta Ente" que vai substituir a API de Gestão atual, (c) 24 decisões de desenho já fechadas para o novo plugin, e (d) fragilidades do sistema atual que motivaram essas decisões.

Este documento é a lista comparativa de funcionalidades (o que migra do tema Pnab para o plugin, e o que é genuinamente novo), servindo de base para o desenho do `ConectaEnte` antes de qualquer código ser escrito.

**Fontes cruzadas nesta análise:**
- `src/themes/Pnab/Theme.php` + toda a árvore do tema (componentes, views, config) — lido por completo.
- `docs/relatorio-gestao.pdf` (23 páginas) — lido por completo.
- Convenções de plugin do próprio repo (`src/plugins/AldirBlanc`, `src/core/Plugin.php`, `src/core/Module.php`) — para referência de como o `ConectaEnte` deve ser estruturado quando a implementação começar.
- Os documentos internos citados no relatório (`decisoes.md`, `subagents/*.md`) **não existem neste checkout** — provavelmente vivem em outro repositório/local não versionado aqui. Isso é uma lacuna a considerar: a lista abaixo se apoia no que o relatório resume desses documentos, não nos originais.

---

## A. Funcionalidades do tema Pnab que o plugin vai assumir (mantidas, mas adaptadas ao novo contrato de API)

### 1. Gestão de Ente Federado
- Painel de oportunidades por ente, "Minha Equipe" (vínculos agente↔ente), scoping de API por ente (`federativeEntityId` em metadados).
- **Muda o mecanismo**: hoje é seleção manual numa tela (sessão, banner de troca). No plugin, cada **subsite da instalação é o próprio ente**, identificado automaticamente pela credencial — elimina a tela de seleção, a sessão a administrar e os "portões" de bloqueio associados.
- Origem: `Theme.php` (`panel.federativeEntities`, `panel.federativeEntityAgents`, `panel.federativeEntityOpportunities`, hooks `API.find(opportunity).params`/`API.find(agent).params`).

### 2. PAR (Plano de Ação e Relatório) — cascata de 4 níveis
- Componente de seleção Exercício → Meta → Ação → Atividade, validação de que o edital aponta para um ponto válido da árvore.
- **Muda**: fonte passa a ser a nova API (formato agora documentado/definido, o que hoje não existe); "modelos oficiais" pré-aprovados pelo Ministério somem — vira cascata livre (gestor escolhe manualmente as 4 identificações a cada edital).
- Origem: `components/mc-federative-entity-par/*`, `Theme.php` (validação `entity(Opportunity).validationErrors`, `POST(opportunity.generateopportunity):before`).

### 3. Regras de negócio do Edital (Opportunity) específicas da PNAB
- Segmento/etapa/pauta/território/tipo de edital, cotas (IN MinC 10/2023: 25% negros, 10% indígenas, 5% PCD), formas de inscrição alternativas, ações afirmativas, recursos de outras fontes, reconciliação de faixas/vagas, total de vagas e valor obrigatórios, tipos de proponente + regulamento obrigatórios.
- Explicitamente confirmado no relatório como reaproveitado ("dezenas de telas em uso" — §7 do relatório).
- Origem: `opportunity-types.php`, `components/opportunity-reserva-vagas-cotas/*`, `opportunity-recursos-outras-fontes/*`, `opportunity-formas-inscricao-edital/*`, `opportunity-outras-modalidades-acoes-afirmativas/*`, `opportunity-ranges-config/*`.

### 4. Envio assíncrono do edital + histórico + painel de reenvio
- Fila com retentativa, aba "Logs CultBr" no próprio edital, painel administrativo de sincronização/reenvio em lote.
- Desenho da UX é reaproveitado (§7 do relatório), mas a implementação muda para a nova API (Conecta Ente) e ganha duas capacidades que **hoje não existem**: validação do pacote *antes* de enviar, e leitura correta do erro (`detail`) — ver seção B.
- Origem: `Theme.php` (`entity(Opportunity).update:finish`), `views/panel/opportunities-sync.php`, `components/opportunity-cultbr-logs/*`.

### 5. Cadastro de Agente diferenciado (Individual × Coletivo)
- Sub-tipos de coletivo, campos obrigatórios condicionais por tipo, dados sensíveis (raça, gênero, renda, PCD, comunidades tradicionais), fluxo obrigatório de "completar perfil".
- Não é mencionado explicitamente como um dos 41 itens do relatório, mas é regra de negócio PNAB (não de integração) — **provável reaproveitamento**, a confirmar com quem levantou os 41 itens.
- Origem: `agent-types.php`, `Theme.php` (`registerAgentMetadataByType`, `hasRequiredAgentFieldsFilled`).

### Funcionalidades do tema que **não** devem migrar (eliminadas por decisão já registrada no relatório — "O Delta", §8)
- Seletor manual de ente + sessão + banner de troca — substituído por identificação automática via credencial/subsite.
- Papel dedicado `GestorCultBr` — substituído pelo papel "administrador da instalação", que já existe no Mapas Culturais.
- Tela de "Consolidação de dados" pós-login (sync CPF → CultBR) — deixa de fazer sentido sem seleção manual de ente.
- "Modelos oficiais" de edital pré-atrelados a uma ação do PAR pelo Ministério — vira cascata livre.
- Dependência do tema visual para completar a integração — o plugin passa a ser autossuficiente (funciona em qualquer instalação/tema).

*(Autenticação multi-provedor/gov.br/captcha e branding/UI do tema Pnab são customizações de tema, não de integração — ficam fora do escopo do plugin.)*

---

## B. Funcionalidades genuinamente novas (não existem no tema Pnab hoje)

1. **Assistente de adoção do acervo existente** — tela de adoção em lote para associar editais já publicados (sem ente/PAR) ao novo modelo de dados. É o único componente do projeto sem nenhuma especificação ainda (0%) e a motivação original do plugin: sem ele, instalar em município com editais rodando é inviável.
2. **Identificação automática do ente via credencial por subsite** — elimina a necessidade de o usuário escolher; depende de o Ministério emitir a credencial (pendência fora do controle da equipe técnica).
3. **Validação do pacote antes do envio** — hoje o CultEditais envia sem conferir nada; o plugin vai bloquear o envio e nomear o campo faltante para quem está editando (decisão já tomada, reversão deliberada do comportamento atual).
4. **Tratamento robusto de erros da API** — ler o campo `detail` (os dois contratos o usam; o sistema atual nunca lê), checar o código HTTP antes de tentar interpretar o corpo como JSON, distinguir "lista vazia é resposta válida" de "API caiu".
5. **Separação nativa de dados entre entes via subsites** do próprio Mapas Culturais, em vez do mecanismo de sessão/carimbo/portões de bloqueio construído no tema.
6. **Vocabulário SNIIC carregado pelo próprio plugin** (tipos de proponente, formas de inscrição) — a nova API não publica mais essas listas de valores válidos.
7. **Cascata livre do PAR sem modelos oficiais** — já é decisão registrada, mas as regras de validação equivalentes ainda estão pendentes de definição pela Alta Gestão.
8. **Autossuficiência total do plugin** — instala em qualquer tema/instalação existente, sem depender de customização visual para os campos funcionarem.
9. **Rastreabilidade completa de todas as integrações** — hoje só o envio de edital gera histórico; sincronização de ente e consulta de PAR não deixam rastro nenhum.
10. **Verificação de credencial no cadastro**, distinguindo "chave inválida" de "chave do tipo errado" (a API nova permite essa distinção; a atual não).
11. **Guarda do identificador que o CultBR devolve**, permitindo conferência bidirecional depois — o CultEditais nunca armazenou isso.

### Pendências que bloqueiam parte do desenho (fora do alcance da equipe técnica, citadas no relatório)
- Emissão da credencial de acesso por ente (Ministério da Cultura) — sem ela, formato exato dos dados do ente e regras de atualização seguem indefinidas.
- Definição de como o CultBR identifica um edital (só o número, ou o par ente+número) — maior risco do projeto: se for só o número, um município pode sobrescrever o edital de outro.
- Especificação do assistente de adoção do acervo (Alta Gestão).
- Regras de validação do PAR na ausência de modelos oficiais (Alta Gestão).

---

## Como validar esta lista

Isto é um levantamento, não uma implementação — não há testes/execução de código a rodar. Sugestão de verificação:
1. Confrontar com `decisoes.md` e os `subagents/*.md` citados no relatório (não estão neste repositório — provavelmente precisam ser obtidos de quem gerou o relatório) para confirmar que nada das 24 decisões fechadas foi mal interpretado aqui.
2. Revisar com quem levantou os "41 itens" do relatório (§7) se a lista de 5 funcionalidades do tema Pnab acima cobre integralmente esses 41 itens, ou se há granularidade adicional a detalhar antes de abrir issues/tickets de implementação.
3. Só depois de validado, usar esta lista como base para desenhar a estrutura do `Plugin.php`/`Controller.php`/`Entities/`/`db-updates.php` do `ConectaEnte`, seguindo o padrão já usado pelo plugin `AldirBlanc` neste mesmo repositório.
