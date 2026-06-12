<?php
/**
 * SommelierCore.php
 * Logica centrale per il Sommelier AI "Il vino lo porto io"
 * Centralizza il prompt e l'accesso alla Knowledge Base.
 */

require_once 'Gemini.php';
require_once 'OpenAI.php';
require_once 'config.php';

class SommelierCore {
    /**
     * Elabora una domanda usando il prompt strutturato e la Knowledge Base
     */
    public static function elaboraDomanda($domanda, $model = DEFAULT_MODEL, $onWait = null) {
        if (empty($domanda)) {
            throw new Exception("La domanda è vuota.");
        }

        // Caricamento della Knowledge Base esterna
        $knowledgeBase = @file_get_contents('vinoKB.yaml');
        if (!$knowledgeBase) {
            throw new Exception("Impossibile caricare la Knowledge Base (vinoKB.yaml).");
        }

        // Prompt strutturato per l'esperto del podcast
        $prompt = <<<EOD
**System Prompt / Istruzioni:**

Sei l'esperto del podcast "Il vino lo porto io". La tua conoscenza si basa in via prioritaria sul dataset in formato YAML fornito di seguito, che raccoglie gli abbinamenti del Sommelier Marco Barbetti e dello Chef/sommelier Gabriele Palermo.

## **KNOWLEDGE_BASE_YAML:**

$knowledgeBase

**IL TUO COMPITO E REGOLE DI OUTPUT:**
1. **Priorità KB:** Cerca prima di tutto una risposta o un'analogia tecnica (struttura, profili) nel dataset fornito.
2. **Fallback LLM:** Se nel dataset non trovi assolutamente nulla di pertinente al vino o al piatto richiesto, usa la tua conoscenza generale di sommelier.
3. **Avviso Fallback:** In caso di fallback (se usi la tua conoscenza e non il podcast), DEVI iniziare la risposta ESATTAMENTE con questa frase: "Nel podcast non ci sono informazioni relative a questo vino o a questo piatto, questo è il risultato della mia ricerca: " seguita dal consiglio.
4. **Link Episodi:** Se nel dataset YAML per gli episodi citati sono presenti dei campi 'url_episodio', aggiungi in fondo alla risposta l'elenco dei link agli episodi citati. L'elenco deve essere in **ordine numerico di episodio** e ogni link deve essere separato dal precedente da **due a capo**. Precedi l'elenco dalla frase "Ascolta gli episodi qui: " (o al singolare se è uno solo).


**VINCOLI RIGIDI DI FORMATTAZIONE:**
1. **Formattazione Libera:** Puoi usare il Markdown se utile alla leggibilità, ma mantieni uno stile pulito.

2. **Struttura della risposta:** Se usi il podcast, cita il consiglio degli esperti. Se usi il fallback, spiega brevemente il motivo tecnico dell'abbinamento (concordanza o contrapposizione).
**RICHIESTA UTENTE:**
$domanda
EOD;


        $actualModel = "";
        $usage = null;
        $output = "";

        try {
            // Chiamata alla classe Gemini
            $gemini = new Gemini($model);
            $output = $gemini->generateText($prompt, $onWait);
            $actualModel = $model;
            $usage = $gemini->getLastUsage();
        } catch (Exception $e) {
            // Fallback alla classe OpenAI
            try {
                $openai = new OpenAI(DEFAULT_OPENAI_MODEL);
                $output = $openai->generateText($prompt, $onWait);
                $actualModel = DEFAULT_OPENAI_MODEL;
                $usage = $openai->getLastUsage();
            } catch (Exception $e2) {
                // Se fallisce anche OpenAI, rilancia l'eccezione originale di Gemini per segnalare il problema
                throw $e;
            }
        }
        
        // Formattazione: doppio a capo dopo ogni punto (anche se non seguito da spazio)
        $output = preg_replace('/\.(\s+|$)/', ".\n\n", $output);

        return [
            'output' => $output,
            'model' => $actualModel,
            'usage' => $usage
        ];
    }
}
