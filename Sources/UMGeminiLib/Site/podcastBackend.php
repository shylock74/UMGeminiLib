<?php
/**
 * Backend generalizzato per diversi podcast basati su Knowledge Base YAML.
 * Accetta domanda, file YAML e opzioni di personalizzazione del prompt.
 */

header('Content-Type: application/json');
require_once 'PodcastCore.php';

try {
    // Recupero dell'input (JSON, POST o GET)
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $domanda = $input['domanda'] ?? $_POST['domanda'] ?? $_GET['domanda'] ?? null;
    $yamlFile = $input['yamlFile'] ?? $_POST['yamlFile'] ?? $_GET['yamlFile'] ?? null;
    $model = $input['model'] ?? $_POST['model'] ?? $_GET['model'] ?? DEFAULT_MODEL;
    
    // Opzioni opzionali per PodcastCore
    $options = array_filter([
        'podcastName' => $input['podcastName'] ?? $_POST['podcastName'] ?? $_GET['podcastName'] ?? null,
        'experts' => $input['experts'] ?? $_POST['experts'] ?? $_GET['experts'] ?? null,
        'fallbackPrefix' => $input['fallbackPrefix'] ?? $_POST['fallbackPrefix'] ?? $_GET['fallbackPrefix'] ?? null,
    ]);

    if (!$domanda) {
        throw new Exception("Il parametro 'domanda' è richiesto.");
    }
    
    if (!$yamlFile) {
        throw new Exception("Il parametro 'yamlFile' è richiesto.");
    }

    // Utilizzo della logica generalizzata
    $result = PodcastCore::elaboraDomanda($domanda, $yamlFile, $model, $options);

    // Risposta JSON
    echo json_encode([
        'status' => 'success',
        'domanda' => $domanda,
        'yamlFile' => $yamlFile,
        'model' => $result['model'],
        'output' => $result['output'],
        'usage' => $result['usage']
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
