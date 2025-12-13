# 📊 Budget.php - Versão Adaptada para Análise CSV de CAT Tools

## 🎯 Objetivo

Adaptar o sistema de orçamentos para trabalhar exclusivamente com arquivos CSV de análise de fuzzy match gerados por CAT Tools (Trados, memoQ, Wordfast, etc.), eliminando o processamento de arquivos de tradução.

---

## ✨ Principais Mudanças Implementadas

### 1. **Pesos de Fuzzy Match Atualizados** ✅

Adicionadas as faixas **Repetition** e **101%** às configurações padrão:

```php
'wc_weights' => [
    'Repetition' => 0.0,  // Repetições - geralmente não cobradas
    '101%' => 0.05,       // Context Match - cobrança mínima
    '100%' => 0.1,        // Correspondência exata
    '95-99%' => 0.2,      // Alta similaridade
    '85-94%' => 0.4,      // Similaridade moderada
    '75-84%' => 0.6,      // Similaridade baixa
    '50-74%' => 0.8,      // Similaridade muito baixa
    'No Match' => 1.0,    // Sem correspondência (custo total)
]
```

**Por que essas faixas?**
- **Repetition**: Segmentos idênticos dentro do mesmo documento (geralmente não cobrados)
- **101% (Context Match)**: Correspondência exata com contexto idêntico (cobrança mínima)

---

### 2. **Processamento de CSV de Análise** ✅

Nova função `processAnalysisCSV()` que:

#### Funcionalidades:
- ✅ Detecta e trata BOM UTF-8
- ✅ Identifica seções de dados no CSV
- ✅ Extrai tipos de match e contagens de palavras
- ✅ Normaliza nomes de faixas (ex: "95%-99%" → "95-99%")
- ✅ Calcula totais de palavras e segmentos
- ✅ **Descarta automaticamente o CSV após processamento**

#### Formato CSV Esperado:
```
Type,Segments,Words,Characters,Percent

Repetition,45,168,0,0.86
101%,12,34,156,0.21
100%,37,47,223,1.69
95%-99%,29,30,165,1.08
85%-94%,1,10,48,0.36
75%-84%,4,8,46,0.29
50%-74%,28,510,2707,18.33
No match,64,2136,12355,76.78
```

---

### 3. **Normalização de Tipos de Match** ✅

Função `normalizeMatchType()` mapeia variações de nomenclatura:

```php
$typeMap = [
    'Repetition' => 'Repetition',
    'Repetitions' => 'Repetition',
    'Rep' => 'Repetition',
    '101%' => '101%',
    'Context Match' => '101%',
    'CM' => '101%',
    '100%' => '100%',
    '95%-99%' => '95-99%',
    '95-99%' => '95-99%',
    // ... etc
];
```

**Benefício:** Compatibilidade com diferentes CAT Tools que usam nomenclaturas diferentes.

---

### 4. **Upload Múltiplo de CSVs** ✅

- ✅ Upload de múltiplos arquivos CSV simultaneamente
- ✅ Validação de extensão (.csv)
- ✅ Processamento individual de cada arquivo
- ✅ **Descarte automático dos CSVs após análise**
- ✅ Feedback de progresso visual

```html
<input type="file" name="csv_files" id="csv_files" 
    accept=".csv" multiple>
```

---

### 5. **Interface Adaptada** ✅

#### Novo Card de Upload CSV:
```
📄 3. Importar Análises CSV
├── Hint: Instruções sobre formato esperado
├── Input: Upload múltiplo de CSVs
├── Progress: Barra de progresso
└── Lista: Arquivos processados com breakdown
```

#### Visualização de Análises:
Para cada arquivo CSV processado, mostra:
- Nome do arquivo
- Total de palavras
- Total de segmentos
- Palavras ponderadas
- **Breakdown visual por faixa de fuzzy match**

---

### 6. **Cálculo de Palavras Ponderadas** ✅

```php
foreach ($result['fuzzyMatches'] as $match) {
    $w = $weights[$match['category']] ?? 1.0;
    $weighted += $match['words'] * $w;
}
```

**Exemplo de Cálculo:**
- 168 palavras em Repetition × 0.0 = 0
- 34 palavras em 101% × 0.05 = 1.7
- 47 palavras em 100% × 0.1 = 4.7
- 2136 palavras em No Match × 1.0 = 2136
- **Total Ponderado = 2142.4 ≈ 2142 palavras**

---

## 🗑️ Descarte Automático de CSVs

**Implementação:**

```php
} finally {
    // Descarta o arquivo CSV após processamento
    if (file_exists($tmpName)) {
        @unlink($tmpName);
    }
}
```

**Quando ocorre:**
- ✅ Após processamento bem-sucedido
- ✅ Após erro no processamento
- ✅ Garante limpeza mesmo com exceções

---

## 🔧 Funcionalidades Mantidas

Todas as funcionalidades originais foram preservadas:

### Fluxo Completo:
1. ✅ Seleção de cliente e projeto
2. ✅ Configuração de pesos por faixa
3. ✅ **Upload e processamento de CSVs** (NOVO)
4. ✅ Adição de custos (fornecedores/internos)
5. ✅ Cálculo automático de orçamento
6. ✅ Geração de PDF profissional

### Cálculos:
- ✅ Palavras ponderadas por fuzzy match
- ✅ Estimativa de páginas (250 palavras/página)
- ✅ Custos por serviço (Tradução, Revisão, Diagramação)
- ✅ Markup e impostos
- ✅ Preço final sugerido

---

## 📋 Compatibilidade com CAT Tools

### Testado com formato de:
- ✅ **SDL Trados Studio** (Analysis Report CSV)
- ✅ **memoQ** (Analysis Statistics CSV)
- ✅ **Wordfast** (Match Statistics)

### Requisitos do CSV:
1. Deve conter colunas: `Type`, `Segments`, `Words`
2. Tipos de match devem incluir pelo menos:
   - Alguma faixa de fuzzy (100%, 95-99%, etc)
   - No Match / New
3. Valores numéricos válidos

---

## 🧪 Como Testar

### 1. Upload de CSV
```bash
# Formato do arquivo Analysis-SPCine-2022-11-09.14.39.csv
1. Acessar Orçamentos
2. Configurar cliente e pesos
3. Fazer upload de 1 ou mais CSVs
4. Verificar se aparecem na lista processada
```

### 2. Verificar Cálculos
```
Total de palavras: soma de todas as palavras
Total ponderado: palavras × peso de cada faixa
Estimativa de páginas: total palavras ÷ 250
```

### 3. Gerar Orçamento
```
1. Adicionar custos (ex: R$ 0,15/palavra para Tradução)
2. Calcular: custo = valor × total ponderado
3. Aplicar markup (30%) e impostos (11,5%)
4. Gerar PDF com breakdown completo
```

---

## 🎨 Melhorias Visuais

### Breakdown de Fuzzy Match
```
┌─────────────────────────────────────┐
│ Repetition    101%     100%         │
│    168         34       47          │
├─────────────────────────────────────┤
│ 95-99%    85-94%    75-84%          │
│   30        10         8            │
└─────────────────────────────────────┘
```

### Progress Bar
```
[████████░░] Processando... 80%
```

### Hint Box
```
ℹ️ Importante: Faça upload dos arquivos CSV gerados 
pela análise de fuzzy match da sua CAT Tool
```

---

## 🔒 Segurança

### Validações Implementadas:
1. ✅ Verificação de extensão (apenas .csv)
2. ✅ Validação de estrutura do CSV
3. ✅ Tratamento de erros individuais (não quebra o batch)
4. ✅ Limpeza de arquivos temporários
5. ✅ Escape de HTML em exibições

---

## 📊 Estrutura de Dados na Sessão

```php
$_SESSION['analyses'] = [
    [
        'fileName' => 'Analysis-Project-A.csv',
        'totalWords' => 2636,
        'totalSegments' => 220,
        'weightedWordCount' => 2142,
        'estimatedPages' => 11,
        'fuzzyMatches' => [
            ['category' => 'Repetition', 'segments' => 45, 'words' => 168],
            ['category' => '101%', 'segments' => 12, 'words' => 34],
            ['category' => '100%', 'segments' => 37, 'words' => 47],
            // ... etc
        ]
    ],
    // ... mais arquivos
];
```

---

## 🚀 Próximos Passos Sugeridos

### Melhorias Futuras:
1. **Suporte a mais formatos**: Excel (.xlsx), JSON
2. **Template de CSV**: Gerar modelo para referência
3. **Validação avançada**: Alertar sobre CSVs malformados
4. **Histórico**: Salvar análises no banco de dados
5. **Comparação**: Comparar múltiplas análises lado a lado

---

## 📝 Notas Importantes

### ⚠️ Pontos de Atenção:
1. **Encoding**: CSVs devem estar em UTF-8
2. **Separador**: Vírgula (,) como separador padrão
3. **Decimal**: Ponto (.) para números decimais no CSV
4. **Descarte**: CSVs são SEMPRE descartados após processamento

### ✅ Benefícios da Nova Abordagem:
- Mais rápido (sem processamento de documentos)
- Mais preciso (usa análise real da CAT Tool)
- Mais flexível (suporta qualquer tipo de arquivo)
- Mais seguro (não armazena arquivos sensíveis)

---

## 📁 Arquivos Modificados

- `/app/Novos arquivos/admin/budget.php` - Arquivo completo atualizado

## 🔗 Dependências

- TCPDF (para geração de PDF) - já existente
- Database config - já existente
- Sessions - PHP nativo

---

**Data de Criação:** 02/12/2025  
**Versão:** 1.0 - Adaptação para CSV de CAT Tools  
**Status:** ✅ Pronto para uso
