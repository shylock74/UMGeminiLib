# Documentazione Tecnica: vinoBackend.php

L'endpoint `vinoBackend.php` è un'API specializzata progettata per fornire consigli su abbinamenti vino e cibo. Risponde alle domande basandosi esclusivamente su una knowledge base predefinita derivata dal podcast **"Il vino lo porto io"**.

## Informazioni Generali

- **URL Access Point**: `https://ulti.media/UMGemini/vinoBackend.php`
- **Metodi supportati**: `POST`, `GET`
- **Formato Input**: JSON, parametri POST o parametri URL (GET).
- **Formato Output**: JSON

---

## Parametri di Ingresso

| Parametro | Tipo | Descrizione |
| :--- | :--- | :--- |
| `domanda` | String | La domanda o il dubbio relativo all'abbinamento vino/cibo. |
| `model` | String | (Opzionale) Il modello Gemini da utilizzare. Default: quello configurato nel sistema. |

---

## Comportamento dell'AI

L'API è configurata con istruzioni di sistema rigorose:
1. **Knowledge Base Esclusiva**: L'AI risponde basandosi *soltanto* sulle informazioni fornite nel preambolo (podcast "Il vino lo porto io").
2. **Brevità e Sintesi**: Le risposte sono ottimizzate per essere concise.
3. **Solo Testo**: L'output non contiene formattazione Markdown, ma solo testo semplice (plain text).

---

## Esempi di Utilizzo

### Richiesta JSON (POST)

**Endpoint**: `https://ulti.media/UMGemini/vinoBackend.php`

**Headers**:
- `Content-Type: application/json`

**Body**:
```json
{
  "domanda": "Cosa posso abbinare a un brasato di manzo con castagne?"
}
```

### Richiesta cURL
```bash
curl -X POST https://ulti.media/UMGemini/vinoBackend.php \
     -H "Content-Type: application/json" \
     -d '{"domanda": "Quale vino per il filetto di maiale?"}'
```

---

## Risposta (JSON)

In caso di successo, l'API restituisce un oggetto con la seguente struttura:

```json
{
  "status": "success",
  "domanda": "Quale vino per il filetto di maiale?",
  "model": "gemini-1.5-flash",
  "output": "Il sommelier consiglia un vino rosso di pregio che bilanci la succulenza del maiale, preferibilmente con note evolute."
}
```

### Gestione Errori

In caso di errore (es: parametro `domanda` mancante), l'API restituisce:

```json
{
  "status": "error",
  "message": "Parametro 'domanda' mancante."
}
```

---

## Note Implementative

- L'endpoint utilizza la classe `Gemini.php` per interfacciarsi con le API di Google.
- La knowledge base è cablata internamente sotto forma di preambolo al prompt.
