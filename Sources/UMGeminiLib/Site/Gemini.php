<?php
/**
 * Gemini API Client
 * Mirrors UMGeminiLite functionality
 */

require_once 'config.php';

class Gemini {
    private $apiKey;
    private $model;

    public function __construct($model = DEFAULT_MODEL, $apiKey = GEMINI_API_KEY) {
        $this->model = $model;
        $this->apiKey = $apiKey;
    }

    private $lastUsage = null;

    public function getLastUsage() {
        return $this->lastUsage;
    }

    /**
     * Generates text based on a prompt
     * 
     * @param string $textPrompt The prompt to send
     * @param callable $onWait Optional callback called every 10 seconds
     * @return string The generated text
     * @throws Exception If the request fails
     */
    public function generateText($textPrompt, $onWait = null) {
        $maxRetries = 5;
        $retryDelay = 1000000; // 1 secondo (in microsecondi)
        
        // Segnala immediatamente l'inizio del tentativo
        if ($onWait) {
            call_user_func($onWait, $this->model, 1);
        }

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
            
            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $textPrompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => 1.0
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "x-goog-api-key: {$this->apiKey}"
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // max 300s per la risposta Gemini

            // Utilizziamo curl_multi per poter gestire il callback durante l'attesa
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
                
                // Se è passato il tempo per il callback (5 secondi)
                if ($onWait && (time() - $lastWaitCall) >= 5) {
                    call_user_func($onWait, $this->model, $attempt + 1);
                    $lastWaitCall = time();
                }

                // Controllo timeout manuale per sicurezza
                if (time() - $startTime > 305) {
                    break;
                }
            } while ($active && $status == CURLM_OK);

            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            curl_close($ch);

            // Se l'errore è 503 (High demand/Service Unavailable), riprova
            if ($httpCode === 503 && $attempt < $maxRetries - 1) {
                usleep($retryDelay);
                $retryDelay *= 2; 
                continue;
            }

            if ($httpCode !== 200) {
                throw new Exception("Gemini API error (HTTP {$httpCode}): " . $response);
            }

            $result = json_decode($response, true);
            
            // Salvataggio dei metadati di utilizzo
            $this->lastUsage = $result['usageMetadata'] ?? null;

            // Controllo se la risposta è stata bloccata per sicurezza o altro motivo
            $finishReason = $result['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            
            if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $errorMsg = "Gemini API non ha restituito testo. Motivo: " . $finishReason . ". Risposta completa: " . $response;
                file_put_contents('gemini_error.log', "[" . date('Y-m-d H:i:s') . "] " . $errorMsg . PHP_EOL, FILE_APPEND);
                throw new Exception($errorMsg);
            }

            $text = $result['candidates'][0]['content']['parts'][0]['text'];
            if (empty(trim($text))) {
                $errorMsg = "Gemini API ha restituito testo vuoto. Motivo: " . $finishReason . ". Risposta completa: " . $response;
                file_put_contents('gemini_error.log', "[" . date('Y-m-d H:i:s') . "] " . $errorMsg . PHP_EOL, FILE_APPEND);
                throw new Exception($errorMsg);
            }

            return $text;
        }
        
        throw new Exception("Errore persistente dopo $maxRetries tentativi.");
    }
}
