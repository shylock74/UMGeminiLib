<?php
/**
 * Configuration for Gemini API
 */

// Caricamento configurazione locale / segreti se presenti
if (file_exists(__DIR__ . '/config.local.php')) {

    @require_once __DIR__ . '/config.local.php';
}

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY');
}


// Models mapping from UMGeminiLib
$GEMINI_MODELS = [
    'gemini-2.5-flash' => 'Gemini 2.5 Flash',
    'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite',
    'gemini-2.5-pro' => 'Gemini 2.5 Pro',
    'gemini-3-pro-preview' => 'Gemini 3 Pro Preview',
    'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview',
    'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash Lite Preview',
    'gemini-3.5-flash' => 'Gemini 3.5 Flash',
    'gemini-3.6-flash' => 'Gemini 3.6 Flash',
    'gemini-3.7-flash' => 'Gemini 3.7 Flash',
];

define('DEFAULT_MODEL', 'gemini-3.7-flash');

// Telegram Bot Configuration
define('TELEGRAM_BOT_TOKEN', '8466115311:AAEjB-dRka3zEqybZfZFPdjjXQFAVSIEj_c');

// OpenAI API Configuration
define('OPENAI_API_KEY', 'YOUR_OPENAI_API_KEY');
$OPENAI_MODELS = [
    'gpt-5.5' => 'GPT 5.5',
    'gpt-5.4' => 'GPT 5.4',
    'gpt-5.4-mini' => 'GPT 5.4 Mini',
];
define('DEFAULT_OPENAI_MODEL', 'gpt-5.4-mini');

// Cron Job Security Configuration
define('CRON_SECRET', 'vino_quiz_cron_secure_key_2026');

/**
 * Returns a friendly name for the model ID
 */
function getFriendlyModelName($modelId) {
    global $GEMINI_MODELS, $OPENAI_MODELS;
    if (isset($GEMINI_MODELS[$modelId])) return $GEMINI_MODELS[$modelId];
    if (isset($OPENAI_MODELS[$modelId])) return $OPENAI_MODELS[$modelId];
    return $modelId;
}

