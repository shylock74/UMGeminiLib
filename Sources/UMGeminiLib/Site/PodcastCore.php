<?php
/**
 * PodcastCore.php
 * Logica generalizzata per assistenti AI basati su Knowledge Base YAML.
 * Permette di specificare il file YAML e personalizzare i parametri del podcast.
 */

require_once 'Gemini.php';
require_once 'OpenAI.php';
require_once 'config.php';

class PodcastCore {
    /**
     * Elabora una domanda usando un file YAML come Knowledge Base e un prompt strutturato.
     * 
     * @param string $domanda La domanda dell'utente.
     * @param string $yamlFile Percorso del file YAML (relativo o assoluto).
     * @param string $model Il modello Gemini da utilizzare.
     * @param array $options Opzioni per personalizzare il prompt:
     *                      - 'podcastName': Nome del podcast (default: "Il vino lo porto io").
     *                      - 'experts': Descrizione degli esperti (default: "del Sommelier Marco Barbetti e dello Chef/sommelier Gabriele Palermo").
     *                      - 'fallbackPrefix': Frase da usare se la risposta non è in KB (default: "...").
     * @param callable $onWait Optional callback called every 10 seconds.
     */
    public static function elaboraDomanda($domanda, $yamlFile, $model = DEFAULT_MODEL, $options = [], $onWait = null) {
        if (empty($domanda)) {
            throw new Exception("La domanda è vuota.");
        }

        // Caricamento della Knowledge Base esterna
        $knowledgeBase = @file_get_contents($yamlFile);
        if (!$knowledgeBase) {
            throw new Exception("Impossibile caricare la Knowledge Base ($yamlFile).");
        }

        // Parametri di personalizzazione con default basati su SommelierCore
        $podcastName = $options['podcastName'] ?? "Il vino lo porto io";
        $experts = $options['experts'] ?? "del Sommelier Marco Barbetti e dello Chef/sommelier Gabriele Palermo";
        $fallbackPrefix = $options['fallbackPrefix'] ?? "Nel podcast non ci sono informazioni relative a questo vino o a questo piatto, questo è il risultato della mia ricerca: ";

        // Prompt strutturato generalizzato
        $prompt = <<<EOD
**System Prompt / Istruzioni:**

Sei l'esperto del podcast "$podcastName". La tua conoscenza si basa in via prioritaria sul dataset in formato YAML fornito di seguito, che raccoglie le informazioni $experts.

## **KNOWLEDGE_BASE_YAML:**

$knowledgeBase

**IL TUO COMPITO E REGOLE DI OUTPUT:**
1. **Priorità KB:** Cerca prima di tutto una risposta o un'analogia tecnica nel dataset fornito.
2. **Fallback LLM:** Se nel dataset non trovi assolutamente nulla di pertinente alla richiesta, usa la tua conoscenza generale.
3. **Avviso Fallback:** In caso di fallback (se usi la tua conoscenza e non il podcast), DEVI iniziare la risposta ESATTAMENTE con questa frase: "$fallbackPrefix" seguita dal consiglio.
4. **Link Episodi:** Se nel dataset YAML per gli episodi citati sono presenti dei campi 'url_episodio', aggiungi in fondo alla risposta l'elenco dei link agli episodi citati. L'elenco deve essere in **ordine numerico di episodio** e ogni link deve essere separato dal precedente da **due a capo**. Precedi l'elenco dalla frase "Ascolta gli episodi qui: " (o al singolare se è uno solo).


**VINCOLI RIGIDI DI FORMATTAZIONE:**
1. **Formattazione Libera:** Puoi usare il Markdown se utile alla leggibilità, ma mantieni uno stile pulito.

2. **Struttura della risposta:** Se usi il podcast, cita le informazioni degli esperti. Se usi il fallback, spiega brevemente la logica tecnica della risposta.
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
