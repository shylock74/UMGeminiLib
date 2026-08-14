<?php
/**
 * QuizCore.php
 * Generazione automatica di quiz a risposta multipla basati sulla Knowledge Base YAML dei podcast.
 * Utilizza Gemini con fallback trasparente su OpenAI e valida i vincoli nativi di Telegram sendPoll.
 */

require_once 'Gemini.php';
require_once 'OpenAI.php';
require_once 'config.php';

class QuizCore {
    /**
     * Genera un quiz a risposta multipla partendo da un file YAML di un podcast.
     * 
     * @param string $yamlFile Percorso del file YAML (es: 'vinoKB.yaml')
     * @param array $options Opzioni di configurazione (es: 'podcastName', 'emoji')
     * @param string $model Modello da utilizzare (default: DEFAULT_MODEL)
     * @param callable $onWait Callback di notifica attesa
     * @return array Dati strutturati del quiz pronti per Telegram sendPoll
     * @throws Exception In caso di errore di generazione o parsing
     */
    public static function generaQuiz($yamlFile = 'vinoKB.yaml', $options = [], $model = DEFAULT_MODEL, $onWait = null) {
        $yamlPath = file_exists($yamlFile) ? $yamlFile : __DIR__ . '/' . $yamlFile;
        if (!file_exists($yamlPath)) {
            throw new Exception("File YAML non trovato: " . $yamlFile);
        }

        $knowledgeBase = @file_get_contents($yamlPath);
        if (!$knowledgeBase) {
            throw new Exception("Impossibile leggere il file Knowledge Base: " . $yamlFile);
        }

        $podcastName = $options['podcastName'] ?? "Il vino lo porto io";
        $emoji = $options['emoji'] ?? "🍷";

        $prompt = <<<EOD
Sei un autore esperto e sommelier del podcast "$podcastName".
Il tuo compito è creare un quiz a risposta multipla divertente, istruttivo e stimolante basandoti ESCLUSIVAMENTE sui contenuti presenti nel dataset YAML fornito di seguito (abbinamenti vino-cibo, caratteristiche organolettiche dei vini, tecniche di vinificazione, territori e note dei produttori).

## **KNOWLEDGE_BASE_YAML:**
$knowledgeBase

## **REGOLE E VINCOLI RIGIDI PER IL QUIZ:**
1. Scegli a caso un tema o un episodio interessante tra quelli presenti nel dataset.
2. Formula UNA domanda a risposta multipla con esattamente 4 opzioni di risposta.
3. Solo UNA delle 4 opzioni deve essere corretta; le altre 3 devono essere plausibili ma errate (distrattori).
4. Fornisci una breve spiegazione educativa (da mostrare all'utente dopo la risposta).
5. Rispetta tassativamente questi limiti di caratteri di Telegram Poll:
   - "question": massimo 280 caratteri.
   - Ciascuna opzione in "options": massimo 90 caratteri.
   - "explanation": massimo 190 caratteri.
6. L'output DEVE essere ESCLUSIVAMENTE un oggetto JSON valido, senza testo introduttivo o conclusivo.

## **FORMATO JSON RICHIESTO:**
{
  "question": "$emoji Domanda del quiz...",
  "options": [
    "Opzione A",
    "Opzione B",
    "Opzione C",
    "Opzione D"
  ],
  "correct_option_id": 0,
  "explanation": "Spiegazione del perché questa risposta è corretta basata sull'episodio...",
  "episode_title": "Titolo dell'episodio da cui è tratto il quiz"
}
EOD;

        $actualModel = "";
        $usage = null;
        $rawOutput = "";

        try {
            $gemini = new Gemini($model);
            $rawOutput = $gemini->generateText($prompt, $onWait);
            $actualModel = $model;
            $usage = $gemini->getLastUsage();
        } catch (Exception $e) {
            try {
                $openai = new OpenAI(DEFAULT_OPENAI_MODEL);
                $rawOutput = $openai->generateText($prompt, $onWait);
                $actualModel = DEFAULT_OPENAI_MODEL;
                $usage = $openai->getLastUsage();
            } catch (Exception $e2) {
                throw new Exception("Errore generazione quiz AI: " . $e->getMessage() . " (OpenAI fallback: " . $e2->getMessage() . ")");
            }
        }

        // Pulizia e parsing del JSON
        $quizData = self::parseAndValidateJson($rawOutput, $emoji);
        $quizData['model'] = $actualModel;
        $quizData['usage'] = $usage;

        return $quizData;
    }

    /**
     * Estrae, analizza e valida il JSON prodotto dall'IA garantendo i limiti Telegram.
     */
    private static function parseAndValidateJson($rawOutput, $emoji = "🍷") {
        $clean = trim($rawOutput);
        
        // Rimuove blocchi markdown ```json ... ``` se presenti
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        $decoded = json_decode($clean, true);

        if (!$decoded || !is_array($decoded)) {
            // Tentativo di estrazione del primo blocco { ... }
            if (preg_match('/\{[\s\S]*\}/', $clean, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (!$decoded || !isset($decoded['question']) || !isset($decoded['options']) || !isset($decoded['correct_option_id'])) {
            throw new Exception("Formato JSON del quiz non valido o incompleto: " . substr($rawOutput, 0, 300));
        }

        $question = trim((string)$decoded['question']);
        $options = (array)$decoded['options'];
        $correctOptionId = (int)$decoded['correct_option_id'];
        $explanation = isset($decoded['explanation']) ? trim((string)$decoded['explanation']) : "";
        $episodeTitle = isset($decoded['episode_title']) ? trim((string)$decoded['episode_title']) : "";

        // Garanzia: almeno 2 opzioni, max 10
        if (count($options) < 2) {
            throw new Exception("Il quiz deve contenere almeno 2 opzioni di risposta.");
        }
        if (count($options) > 10) {
            $options = array_slice($options, 0, 10);
        }

        // Normalizzazione opzioni (rimuove prefissi tipo "A) ", "1. ")
        $cleanOptions = [];
        foreach ($options as $opt) {
            $cleanOpt = trim(preg_replace('/^[a-d0-9][\)\.\-]\s*/i', '', (string)$opt));
            if (empty($cleanOpt)) {
                $cleanOpt = "Opzione " . (count($cleanOptions) + 1);
            }
            // Limite Telegram opzione: 100 caratteri
            if (mb_strlen($cleanOpt, 'UTF-8') > 98) {
                $cleanOpt = mb_substr($cleanOpt, 0, 95, 'UTF-8') . '...';
            }
            $cleanOptions[] = $cleanOpt;
        }

        // Controllo indice corretto valido
        if ($correctOptionId < 0 || $correctOptionId >= count($cleanOptions)) {
            $correctOptionId = 0;
        }

        // Limite Telegram domanda: 300 caratteri
        if (mb_strlen($question, 'UTF-8') > 298) {
            $question = mb_substr($question, 0, 295, 'UTF-8') . '...';
        }

        // Limite Telegram explanation: 200 caratteri
        if (mb_strlen($explanation, 'UTF-8') > 198) {
            $explanation = mb_substr($explanation, 0, 195, 'UTF-8') . '...';
        }

        return [
            'question' => $question,
            'options' => $cleanOptions,
            'correct_option_id' => $correctOptionId,
            'explanation' => $explanation,
            'episode_title' => $episodeTitle
        ];
    }
}
