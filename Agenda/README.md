# Agenda de Palestras - Translators101

## Descrição
Página pública de topo de funil para exibir todas as palestras agendadas da Translators101, com sistema de captura de leads e sorteio mensal.

## Arquivos

### `agenda.php`
Página principal que:
- Exibe todas as palestras futuras cadastradas na tabela `upcoming_announcements`
- Permite adicionar eventos ao Google Calendar e Apple Calendar
- Contém formulário de captura de leads
- Exibe mensagem de sucesso verde que desaparece em 10 segundos

### `leads_table.sql`
Script SQL para criar a tabela `leads` no banco de dados com os campos:
- `id` - Identificador único
- `nome` - Nome completo do lead
- `email` - Email (único)
- `whatsapp` - Número do WhatsApp
- `fonte` - Origem do cadastro (padrão: 'agenda_palestras')
- `sorteado` - Indicador se já foi sorteado
- `data_sorteio` - Data do sorteio
- `acesso_concedido` - Se o acesso foi concedido
- `acesso_inicio` / `acesso_fim` - Período de acesso

## Instalação

1. **Fazer upload dos arquivos para o servidor**
   - Copie a pasta `Agenda` para `public_html/v/`

2. **Executar o script SQL**
   - Acesse seu phpMyAdmin ou terminal MySQL
   - Execute o conteúdo de `leads_table.sql`

3. **Configurar caminhos**
   - Verifique se os includes apontam corretamente para:
     - `../config/database.php`
     - `../vision/includes/head.php`
     - `../vision/includes/footer.php`

## Acesso
URL: `https://translators101.com/v/Agenda/agenda.php`

## Funcionalidades

### Para Visitantes
- Visualizar todas as palestras agendadas
- Adicionar eventos ao calendário (Google/Apple)
- Cadastrar-se para receber notificações
- Participar do sorteio mensal

### Para Administradores
- Gerenciar leads através do banco de dados
- Realizar sorteio mensal usando as queries SQL fornecidas

## Integração
A página utiliza:
- Tabela existente: `upcoming_announcements`
- Nova tabela: `leads`
- Includes do sistema Vision (head.php, footer.php)
- Variáveis CSS do tema existente

## Mensagens
- **Sucesso**: Verde, desaparece em 10 segundos
- **Erro**: Vermelho, desaparece em 10 segundos

## Sorteio Mensal
Para realizar o sorteio:
```sql
-- Selecionar lead aleatório
SELECT * FROM leads WHERE sorteado = 0 ORDER BY RAND() LIMIT 1;

-- Marcar como sorteado e conceder 15 dias
UPDATE leads 
SET sorteado = 1, 
    data_sorteio = NOW(), 
    acesso_concedido = 1, 
    acesso_inicio = CURDATE(), 
    acesso_fim = DATE_ADD(CURDATE(), INTERVAL 15 DAY) 
WHERE id = 'ID_DO_LEAD_SORTEADO';
```

---
Desenvolvido para Translators101 🎙️