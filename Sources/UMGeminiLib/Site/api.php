<?php
/**
 * API Endpoint for Gemini Text Generation
 */

header('Content-Type: application/json');
require_once 'Gemini.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $prompt = $input['prompt'] ?? $_POST['prompt'] ?? $_GET['prompt'] ?? null;
    $model = $input['model'] ?? $_POST['model'] ?? $_GET['model'] ?? DEFAULT_MODEL;

    if (!$prompt) {
        throw new Exception("Prompt is required");
    }

    $gemini = new Gemini($model);
    $output = $gemini->generateText($prompt);

    echo json_encode([
        'status' => 'success',
        'model' => $model,
        'prompt' => $prompt,
        'output' => $output
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
