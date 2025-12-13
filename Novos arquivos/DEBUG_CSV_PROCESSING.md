# 🔍 Debug: Processamento de CSV

## Problema Resolvido

Melhorias implementadas para lidar com diferentes formatos de CSV:

### 1. Detecção Automática de Encoding ✅
```php
// Detecta: UTF-8, ISO-8859-1, Windows-1252, ASCII
$encoding = mb_detect_encoding($content, [...]);
if ($encoding && $encoding !== 'UTF-8') {
    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
}
```

### 2. Detecção Automática de Delimitador ✅
```php
// Detecta se usa vírgula (,) ou ponto-e-vírgula (;)
if (substr_count($content, ';') > substr_count($content, ',')) {
    $delimiter = ';';
}
```

### 3. Busca Flexível de Colunas ✅
```php
// Tenta encontrar palavras na posição 2 ou 3
if (isset($data[2]) && is_numeric(...)) {
    $words = intval($data[2]);
} elseif (isset($data[3]) && is_numeric(...)) {
    $words = intval($data[3]);
}
```

### 4. Normalização Melhorada ✅
- Case-insensitive
- Detecta padrões de porcentagem
- Mapeia variações (ex: "95% - 99%" → "95-99%")

### 5. Logging Detalhado ✅
```php
error_log("CSV $fileName: Total processado - X linhas, Y palavras");
```

---

## Como Verificar os Logs

### 1. Logs do PHP
```bash
# Ver logs em tempo real
tail -f /var/log/php_errors.log

# Buscar por "CSV"
grep "CSV" /var/log/php_errors.log | tail -20
```

### 2. Logs do Navegador
```javascript
// Abrir Console do Desenvolvedor (F12)
// Verificar aba "Console" e "Network"
```

---

## Formatos de CSV Suportados

### Exemplo 1: Vírgula como delimitador
```csv
Type,Segments,Words,Characters,Percent
Repetition,45,168,0,0.86
101%,12,34,156,0.21
100%,37,47,223,1.69
```

### Exemplo 2: Ponto-e-vírgula como delimitador
```csv
Type;Segments;Words;Characters;Percent
Repetition;45;168;0;0,86
101%;12;34;156;0,21
100%;37;47;223;1,69
```

### Exemplo 3: Espaços nos nomes
```csv
Type,Segments,Words,Characters
Context Match,12,34,156
95% - 99%,29,30,165
85% - 94%,1,10,48
```

---

## Teste Manual do CSV

### 1. Criar arquivo de teste
```bash
cat > /tmp/test_analysis.csv << 'EOF'
Type,Segments,Words,Characters,Percent
Repetition,10,50,200,5.0
101%,5,20,80,2.0
100%,15,75,300,7.5
95-99%,20,100,400,10.0
No match,50,750,3000,75.0
EOF
```

### 2. Testar encoding
```bash
# Verificar encoding
file -bi /tmp/test_analysis.csv

# Converter se necessário
iconv -f ISO-8859-1 -t UTF-8 arquivo_original.csv > arquivo_utf8.csv
```

### 3. Testar delimitador
```bash
# Contar vírgulas
grep -o "," arquivo.csv | wc -l

# Contar ponto-e-vírgulas
grep -o ";" arquivo.csv | wc -l
```

---

## Checklist de Troubleshooting

### ✅ Estrutura do Arquivo
- [ ] Arquivo tem extensão .csv
- [ ] Contém linha de cabeçalho com "Type" e "Segments"
- [ ] Tem pelo menos 3 colunas
- [ ] Linhas de dados começam após o cabeçalho

### ✅ Encoding
- [ ] UTF-8, ISO-8859-1, ou Windows-1252
- [ ] Sem caracteres especiais não reconhecidos
- [ ] BOM é opcional (será removido automaticamente)

### ✅ Formato
- [ ] Delimitador consistente (vírgula OU ponto-e-vírgula)
- [ ] Números são válidos (sem letras)
- [ ] Tipos de match reconhecíveis

### ✅ Conteúdo
- [ ] Pelo menos 1 linha com palavras > 0
- [ ] Tipos de match mapeáveis (100%, 95-99%, No Match, etc)
- [ ] Valores numéricos válidos

---

## Mensagens de Erro Comuns

### "Nenhuma palavra encontrada no arquivo"
**Causa:** CSV não tem dados válidos na coluna de palavras
**Solução:**
1. Verificar se a coluna "Words" existe
2. Verificar se os valores são numéricos
3. Ver logs para identificar quantas linhas foram processadas

### "Tipo de match não reconhecido"
**Causa:** Nome da faixa fuzzy não está mapeado
**Solução:**
1. Ver logs para identificar o nome exato
2. Adicionar ao mapeamento em `normalizeMatchType()`

### "Não foi possível abrir o arquivo CSV"
**Causa:** Problema de permissões ou caminho
**Solução:**
1. Verificar permissões do diretório /tmp
2. Verificar tamanho do arquivo (não exceder upload_max_filesize)

---

## Exemplo de Log Bem-Sucedido

```
=== Iniciando processamento de 1 arquivo(s) CSV ===
Processando arquivo: Analysis-Project.csv
CSV Analysis-Project.csv: Cabeçalho encontrado na linha 5
CSV Analysis-Project.csv linha 6: 5 colunas
CSV Analysis-Project.csv linha 7: 5 colunas
CSV Analysis-Project.csv linha 8: 5 colunas
CSV Analysis-Project.csv: Fim da seção de dados na linha 15
CSV Analysis-Project.csv: Total processado - 8 linhas, 1234 palavras, 150 segmentos
✓ Arquivo Analysis-Project.csv processado: 1234 palavras
```

---

## Testar com PHP Manual

```php
<?php
// Teste rápido de processamento
$csvContent = "Type,Segments,Words
Repetition,10,50
100%,15,75
No match,50,750";

$lines = explode("\n", $csvContent);
foreach ($lines as $line) {
    $data = str_getcsv($line);
    print_r($data);
}
?>
```

---

## Contato para Suporte

Se o problema persistir após verificar todos os itens acima:

1. **Verificar logs:** Envie os últimos 50 linhas do log
2. **Amostra do CSV:** Envie as primeiras 10 linhas do arquivo
3. **Encoding:** Informe o encoding detectado
4. **Origem:** Informe qual CAT Tool gerou o CSV

---

**Data:** 02/12/2025
**Versão:** 1.1 - Debug Melhorado
**Status:** ✅ Implementado
