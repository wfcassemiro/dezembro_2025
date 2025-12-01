# 🧪 Guia Completo de Testes

## 📋 Checklist de Validação

Use este guia para testar todas as funcionalidades após a instalação.

---

## ✅ TESTE 1: Verificar Instalação

### Passo 1.1: Arquivos no Lugar Correto
```bash
□ Verificar: public_html/v/admin/certificados_b.php (atualizado)
□ Verificar: public_html/v/admin/get_upcoming_lectures.php (novo arquivo)
```

### Passo 1.2: Acessar Página
```
□ Abrir: seu-dominio.com/v/admin/certificados_b.php
□ Verificar: Página carrega sem erros
□ Verificar: Console JavaScript limpo (F12)
```

**✅ Resultado Esperado:** Página carrega normalmente sem erros

---

## ✅ TESTE 2: Dropdown de Palestras Agendadas

### Passo 2.1: Abrir Modal
```
□ Clicar no botão "Importar CSV"
□ Modal abre
□ Aguardar 1-2 segundos (carregamento AJAX)
```

### Passo 2.2: Verificar Dropdown
```
□ Ver dropdown "Selecionar Palestra Agendada"
□ Dropdown contém opção padrão: "-- Carregar dados..."
□ Dropdown contém palestras (se houver no BD)
```

### Passo 2.3: Formato das Palestras
```
Formato esperado no dropdown:
"01/12/2025 - Título da Palestra (Nome do Palestrante)"

Exemplo:
"01/12/2025 - Palestra ao Vivo (William Cassemiro)"
```

**✅ Resultado Esperado:** Dropdown carrega e exibe palestras formatadas

**❌ Se Falhar:**
- Abrir Console (F12) e verificar erros
- Testar endpoint diretamente: `admin/get_upcoming_lectures.php`
- Verificar se há palestras na tabela `upcoming_announcements`

---

## ✅ TESTE 3: Auto-Preenchimento por Dropdown

### Passo 3.1: Selecionar Palestra
```
□ Selecionar uma palestra do dropdown
□ Aguardar processamento (instantâneo)
```

### Passo 3.2: Verificar Campos Preenchidos
```
□ Campo "Data da Palestra" preenchido (formato: AAAA-MM-DD)
□ Campo "Título da Palestra" preenchido
□ Campo "Nome do Palestrante" preenchido
□ Campo "Duração (minutos)" preenchido
```

### Passo 3.3: Verificar Prévia
```
□ Seção "Informações extraídas" aparece
□ Mostra dados da palestra selecionada
□ Mensagem verde: "Dados carregados! Agora faça upload do CSV..."
```

**✅ Resultado Esperado:** Todos os campos preenchidos automaticamente

---

## ✅ TESTE 4: Upload de CSV

### Passo 4.1: Preparar CSV de Teste
```csv
# Palestra: Teste de Certificados
# Palestrante: João Silva
# Data: 01/12/2025
# Duração: 90 minutos
Nome;Email;Tempo Online (minutos)
Maria Santos;maria@example.com;85
José Oliveira;jose@example.com;90
Ana Costa;ana@example.com;80
```

### Passo 4.2: Fazer Upload
```
□ Clicar em "Selecionar arquivo CSV"
□ Escolher arquivo CSV
□ Arquivo é processado automaticamente
```

### Passo 4.3: Verificar Extração de Dados
```
□ Seção "Informações extraídas do CSV" aparece
□ Mostra:
  - Palestra: Teste de Certificados
  - Palestrante: João Silva
  - Data: 01/12/2025
  - Duração: 90 minutos
  - Participantes: 3
```

### Passo 4.4: Verificar Campos Preenchidos
```
□ Campo "Data da Palestra" preenchido: 2025-12-01
□ Campo "Título da Palestra": Teste de Certificados
□ Campo "Nome do Palestrante": João Silva
□ Campo "Duração (minutos)": 90
```

### Passo 4.5: Verificar Lista de Participantes
```
□ Seção "Participantes" aparece
□ Mostra contador: "(3/3)" ou similar
□ Lista com 3 checkboxes marcados:
  ☑ Maria Santos (maria@example.com)
  ☑ José Oliveira (jose@example.com)
  ☑ Ana Costa (ana@example.com)
```

**✅ Resultado Esperado:** CSV processado e dados exibidos corretamente

**❌ Se Falhar:**
- Verificar formato do CSV (separador ";")
- Abrir Console (F12) e verificar erros
- Verificar se arquivo tem encoding UTF-8

---

## ✅ TESTE 5: Console JavaScript (Crítico!)

### Passo 5.1: Abrir Console
```
□ Pressionar F12
□ Ir para aba "Console"
□ Limpar console
```

### Passo 5.2: Realizar Ações
```
□ Abrir modal
□ Selecionar palestra
□ Fazer upload de CSV
□ Selecionar/desselecionar participantes
```

### Passo 5.3: Verificar Mensagens
```
✅ DEVE APARECER:
  - Logs de validação (se houver)
  - "Data atualizada: ..."
  
❌ NÃO DEVE APARECER:
  - "Uncaught TypeError"
  - "Cannot set properties of null"
  - "Cannot read properties of null"
  - Qualquer erro em vermelho
```

**✅ Resultado Esperado:** Console limpo, sem erros

---

## ✅ TESTE 6: Validação de Formulário

### Passo 6.1: Testar Sem Dados
```
□ Abrir modal
□ Clicar em "Gerar Certificados" (sem preencher nada)
□ Verificar: Botão está desabilitado (disabled)
```

### Passo 6.2: Testar Sem CSV
```
□ Selecionar palestra do dropdown
□ NÃO fazer upload de CSV
□ Tentar clicar em "Gerar Certificados"
□ Verificar: Botão continua desabilitado
```

### Passo 6.3: Testar Sem Participantes Selecionados
```
□ Fazer upload de CSV
□ Desmarcar todos os participantes
□ Verificar: Botão fica desabilitado
□ Verificar: Contador mostra "(0/3)"
```

### Passo 6.4: Testar Completo
```
□ Selecionar palestra OU fazer upload de CSV
□ Verificar que há participantes marcados
□ Verificar: Botão "Gerar Certificados" está habilitado (azul)
```

**✅ Resultado Esperado:** Validações funcionam corretamente

---

## ✅ TESTE 7: Sincronização de Data

### Passo 7.1: Via Dropdown
```
□ Selecionar palestra com data "01/12/2025"
□ Verificar campo "Data da Palestra": 2025-12-01
□ Abrir Console e verificar: csv_date_hidden também está preenchido
```

### Passo 7.2: Via CSV
```
□ Upload CSV com data "# Data: 15/12/2025"
□ Verificar campo "Data da Palestra": 2025-12-15
□ Mudar data manualmente para 2025-12-20
□ Abrir Console e verificar log: "Data atualizada: 2025-12-20"
```

**✅ Resultado Esperado:** Campos visible e hidden sempre sincronizados

---

## ✅ TESTE 8: Seleção de Participantes

### Passo 8.1: Selecionar Todos
```
□ Upload CSV com 3 participantes
□ Clicar "Selecionar Todos"
□ Verificar: Todos os 3 checkboxes marcados
□ Verificar contador: "(3/3)"
□ Verificar mensagem: "3 certificados serão gerados"
```

### Passo 8.2: Desmarcar Todos
```
□ Clicar "Desmarcar Todos"
□ Verificar: Todos os checkboxes desmarcados
□ Verificar contador: "(0/3)"
□ Verificar: Botão "Gerar" desabilitado
```

### Passo 8.3: Seleção Manual
```
□ Marcar apenas 1 participante
□ Verificar contador: "(1/3)"
□ Verificar mensagem: "1 certificados serão gerados"
```

**✅ Resultado Esperado:** Seleção funciona e contadores atualizam

---

## ✅ TESTE 9: Geração de Certificados (Fluxo Completo)

### Passo 9.1: Preparar
```
□ Selecionar palestra do dropdown
□ Upload CSV com participantes reais (emails válidos)
□ Verificar que tudo está preenchido
□ Marcar 1-2 participantes
```

### Passo 9.2: Gerar
```
□ Clicar "Gerar Certificados"
□ Aguardar processamento
□ Verificar mensagem de sucesso
```

### Passo 9.3: Verificar Resultado
```
□ Certificados aparecem na lista principal
□ Arquivos PNG foram gerados
□ Emails foram enviados (verificar inbox)
```

**✅ Resultado Esperado:** Certificados gerados com sucesso

---

## ✅ TESTE 10: Casos Extremos

### Teste 10.1: Sem Palestras no BD
```
□ Limpar tabela upcoming_announcements
□ Abrir modal
□ Verificar: Dropdown só mostra opção padrão
□ Verificar: Funcionalidade CSV continua funcionando
```

### Teste 10.2: CSV Vazio
```
□ Upload CSV sem participantes (só cabeçalhos)
□ Verificar: Alert "Nenhum participante válido encontrado"
□ Verificar: Seção de dados não aparece
```

### Teste 10.3: CSV com Caracteres Especiais
```
□ Upload CSV com nomes: "José", "María", "François"
□ Verificar: Caracteres aparecem corretamente
□ Verificar: Certificados gerados com acentos corretos
```

### Teste 10.4: Data Futura
```
□ Selecionar palestra agendada para próxima semana
□ Verificar: Dados preenchidos normalmente
□ Verificar: Geração funciona mesmo com data futura
```

**✅ Resultado Esperado:** Sistema lida bem com casos extremos

---

## 📊 Resumo de Resultados

Após completar todos os testes, preencha:

```
□ TESTE 1: Instalação                    [ ]
□ TESTE 2: Dropdown                      [ ]
□ TESTE 3: Auto-preenchimento            [ ]
□ TESTE 4: Upload CSV                    [ ]
□ TESTE 5: Console JavaScript            [ ]
□ TESTE 6: Validações                    [ ]
□ TESTE 7: Sincronização Data            [ ]
□ TESTE 8: Seleção Participantes         [ ]
□ TESTE 9: Geração Certificados          [ ]
□ TESTE 10: Casos Extremos               [ ]

Total de Testes Passados: ___/10
```

**Status:**
- ✅ 10/10 = Sistema funcionando perfeitamente!
- ⚠️  8-9/10 = Pequenos ajustes necessários
- ❌ <8/10 = Revisar instalação e arquivos

---

## 🐛 Troubleshooting

### Problema: Dropdown Vazio
**Solução:**
1. Verificar se há palestras em `upcoming_announcements`
2. Verificar data das palestras (últimos 30 dias)
3. Testar endpoint: `admin/get_upcoming_lectures.php`

### Problema: Campos Não Preenchem
**Solução:**
1. Abrir Console (F12)
2. Ver erros JavaScript
3. Verificar se função `loadLectureData()` existe
4. Verificar se IDs dos campos estão corretos

### Problema: CSV Não Processa
**Solução:**
1. Verificar encoding do arquivo (UTF-8)
2. Verificar separador (;)
3. Verificar formato do cabeçalho
4. Ver erros no Console

### Problema: Erro ao Gerar Certificados
**Solução:**
1. Verificar permissões da pasta `certificates/`
2. Verificar conexão com banco de dados
3. Verificar se tabelas existem
4. Ver logs do servidor

---

## ✅ Aprovação Final

```
Sistema testado por: ____________________
Data: ___/___/______
Assinatura: ____________________

Status: [ ] APROVADO [ ] REJEITAR [ ] AJUSTES NECESSÁRIOS

Observações:
_________________________________________________
_________________________________________________
_________________________________________________
```

---

**Boa sorte nos testes!** 🚀
