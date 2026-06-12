<?php
/**
 * Backend per il sito "Il vino lo porto io"
 * Accetta una domanda e costruisce un prompt basato sulla knowledge base del podcast.
 */

header('Content-Type: application/json');
require_once 'SommelierCore.php';

try {
    // Recupero dell'input (JSON, POST o GET)
    $input = json_decode(file_get_contents('php://input'), true);
    $domanda = $input['domanda'] ?? $_POST['domanda'] ?? $_GET['domanda'] ?? null;
    $model = $input['model'] ?? $_POST['model'] ?? $_GET['model'] ?? DEFAULT_MODEL;

    if (!$domanda) {
        throw new Exception("Il parametro 'domanda' è richiesto.");
    }

    // Utilizzo della logica centralizzata
    $result = SommelierCore::elaboraDomanda($domanda, $model);

    // Risposta JSON
    echo json_encode([
        'status' => 'success',
        'domanda' => $domanda,
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
