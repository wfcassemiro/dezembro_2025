# 📄 Formatos de CSV Aceitos

## Formatos Suportados

O sistema agora aceita múltiplos formatos de CSV de CAT Tools:

### Formato 1: CSV com Cabeçalho Padrão (Vírgula)
```csv
Type,Segments,Source words,Source chars,Percent
Repetition,11,17,240,0.61
101%,5,24,168,0.86
100%,37,47,223,1.69
95%-99%,29,30,165,1.08
No match,64,2136,12355,76.78
```

### Formato 2: CSV com Ponto-e-vírgula
```csv
Type;Segments;Words;Characters
Repetition;11;17;240
101%;5;24;168
100%;37;47;223
```

### Formato 3: Formato com Espaços/Tabs (SDL Trados)
```
Type                Segments    Source words    Source chars    Percent
Repetition          11          17              240             0.61
101%                5           24              168             0.86
100%                37          47              223             1.69
95%-99%             29          30              165             1.08
No match            64          2136            12355           76.78
```

### Formato 4: Com Cabeçalhos Extras (Seu Arquivo)
```
Statistics for file(s) [por-BR-eng-US]15_Anexo.docx

Analysis
Scope Selected documents

Type Segments Source words Source chars Source tags Percent
Repetition 11 17 240 11 0.61
101% 5 24 168 0 0.86
100% 37 47 223 0 1.69
```

---

## Detecção Automática

O sistema detecta automaticamente:

1. **Encoding:** UTF-8, ISO-8859-1, Windows-1252
2. **Delimitador:** Vírgula (,) ou Ponto-e-vírgula (;)
3. **Formato:** Espaços múltiplos, Tabs, ou CSV tradicional
4. **Cabeçalho:** Busca por "Type" e "Segments" ou "Source words"
5. **Início de Dados:** Detecta automaticamente por padrões (Repetition, 100%, etc)

---

## Colunas Necessárias

### Mínimo Obrigatório:
1. **Type/Match Type**: Tipo de correspondência (Repetition, 100%, No match, etc)
2. **Segments**: Número de segmentos
3. **Words/Source words**: Número de palavras

### Opcionais (ignoradas):
- Characters/Source chars
- Tags/Source tags
- Percent
- Outras colunas extras

---

## Tipos de Match Reconhecidos

### Variações Aceitas:
```
Repetition, Repetitions, Rep, Reps
101%, Context Match, CM, Context
100%, Perfect Match, Exact Match
95%-99%, 95-99%, 95% - 99%, 95 - 99%
85%-94%, 85-94%, 85% - 94%, 85 - 94%
75%-84%, 75-84%, 75% - 84%, 75 - 84%
50%-74%, 50-74%, 50% - 74%, 50 - 74%
No match, No-match, New, Fragments, Fragment
```

---

## Exemplos de Arquivos Válidos

### SDL Trados Studio
```
Analysis Statistics

Type                Segments    Source words
Repetition          45          168
Context Match       12          34
100%                37          47
95% - 99%           29          30
No Match            64          2136
```

### memoQ
```
Type;Segments;Words;Characters
Repetitions;45;168;756
101%;12;34;153
100%;37;47;211
95-99%;29;30;135
No match;64;2136;9612
```

### Wordfast
```
Match Type,Segment Count,Word Count
Rep,45,168
CM,12,34
TM100,37,47
TM95-99,29,30
NoMatch,64,2136
```

---

## Troubleshooting

### CSV não é reconhecido?

1. **Verificar estrutura básica:**
   ```bash
   head -20 seu_arquivo.csv
   ```

2. **Verificar encoding:**
   ```bash
   file -bi seu_arquivo.csv
   ```

3. **Verificar delimitador:**
   - Contar vírgulas: `grep -o "," arquivo.csv | wc -l`
   - Contar ponto-e-vírgulas: `grep -o ";" arquivo.csv | wc -l`

4. **Ver logs do processamento:**
   ```bash
   tail -f /var/log/supervisor/backend.err.log
   ```

### Mensagem: "Nenhuma palavra encontrada"

**Causas comuns:**
- Coluna de palavras está vazia
- Valores não são numéricos
- Delimitador incorreto

**Solução:**
- Verificar se há valores numéricos na 2ª ou 3ª coluna
- Tentar converter para UTF-8
- Verificar se há linhas de dados válidas

---

## Teste Rápido

Crie este arquivo para testar:

```csv
Type,Segments,Words
Repetition,10,50
100%,15,75
95-99%,20,100
No match,50,750
```

Salve como `teste.csv` e faça upload.

**Resultado esperado:**
- ✅ 4 linhas processadas
- ✅ 975 palavras totais
- ✅ 95 segmentos totais

---

**Data:** 02/12/2025  
**Versão:** 1.2 - Suporte Multi-formato  
**Status:** ✅ Implementado
