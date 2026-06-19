<?php
/**
 * OpenAI API Client
 * Using the Responses API as requested.
 * Mirrors Gemini.php functionality.
 */

require_once 'config.php';

class OpenAI {
    private $apiKey;
    private $model;
    private $lastUsage = null;

    /**
     * @param string $model The model to use (default: DEFAULT_OPENAI_MODEL)
     * @param string $apiKey The API key to use (default: OPENAI_API_KEY)
     */
    public function __construct($model = DEFAULT_OPENAI_MODEL, $apiKey = OPENAI_API_KEY) {
        $this->model = $model;
        $this->apiKey = $apiKey;
    }

    /**
     * Returns usage metadata from the last request
     * @return array|null
     */
    public function getLastUsage() {
        return $this->lastUsage;
    }

    /**
     * Generates text based on a prompt using the Responses API
     * 
     * @param string|array $input The prompt to send (string or array of messages)
     * @param callable $onWait Optional callback called every 10 seconds
     * @param string|null $instructions Optional high-level instructions (priority over input)
     * @return string The generated text
     * @throws Exception If the request fails
     */
    public function generateText($input, $onWait = null, $instructions = null) {
        $maxRetries = 5;
        $retryDelay = 1000000; // 1 secondo (in microsecondi)
        
        // Segnala immediatamente l'inizio del tentativo
        if ($onWait) {
            call_user_func($onWait, $this->model, 1);
        }

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $url = "https://api.openai.com/v1/responses";
            
            $payload = [
                "model" => $this->model,
                "input" => $input
            ];

            if ($instructions) {
                $payload["instructions"] = $instructions;
            }

            // Reasoning effort logic for gpt-5 models as seen in documentation
            if (strpos($this->model, 'gpt-5') !== false) {
                $payload["reasoning"] = ["effort" => "low"];
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer {$this->apiKey}"
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            // Use curl_multi to handle callbacks during wait (matching Gemini.php)
            $mh = curl_multi_init();
            curl_multi_add_handle($mh, $ch);

            $startTime = time();
            $lastWaitCall = $startTime;
            $active = null;

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 0.1);
                }
                
                // Call onWait every 5 seconds
                if ($onWait && (time() - $lastWaitCall) >= 5) {
                    call_user_func($onWait, $this->model, $attempt + 1);
                    $lastWaitCall = time();
                }

                // Manual safety timeout
                if (time() - $startTime > 305) {
                    break;
                }
            } while ($active && $status == CURLM_OK);

            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            curl_close($ch);

            // Retry on 503 (High demand) or 429 (Rate limit)
            if (($httpCode === 503 || $httpCode === 429) && $attempt < $maxRetries - 1) {
                usleep($retryDelay);
                $retryDelay *= 2; 
                continue;
            }

            if ($httpCode !== 200) {
                throw new Exception("OpenAI API error (HTTP {$httpCode}): " . $response);
            }

            $result = json_decode($response, true);
            
            // Salva usage metadata se disponibili
            $this->lastUsage = $result['usage'] ?? null;

            // 1. Controlla se esiste una proprietà 'output_text' aggregata (comune in alcuni SDK/versioni)
            if (isset($result['output_text']) && !empty(trim($result['output_text']))) {
                return $result['output_text'];
            }

            // 2. Determina l'array degli output (può essere in $result['output'] o $result stesso se è un array)
            $outputArray = [];
            if (isset($result['output']) && is_array($result['output'])) {
                $outputArray = $result['output'];
            } elseif (is_array($result)) {
                $outputArray = $result;
            }

            // 3. Aggrega il testo da tutti i componenti 'output_text' nell'array
            $fullText = "";
            foreach ($outputArray as $outputItem) {
                if (isset($outputItem['content']) && is_array($outputItem['content'])) {
                    foreach ($outputItem['content'] as $contentPart) {
                        if (isset($contentPart['type']) && $contentPart['type'] === 'output_text') {
                            $fullText .= $contentPart['text'];
                        }
                    }
                }
            }

            if (empty(trim($fullText))) {
                $errorMsg = "OpenAI API ha restituito testo vuoto o formato non riconosciuto. Risposta completa: " . $response;
                file_put_contents('openai_error.log', "[" . date('Y-m-d H:i:s') . "] " . $errorMsg . PHP_EOL, FILE_APPEND);
                throw new Exception($errorMsg);
            }

            return $fullText;
        }
        
        throw new Exception("Errore persistente dopo $maxRetries tentativi.");
    }
}
