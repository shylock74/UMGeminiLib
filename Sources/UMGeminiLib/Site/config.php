<?php
/**
 * Configuration for Gemini API
 */

define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');

// Models mapping from UMGeminiLib
$GEMINI_MODELS = [
    'gemini-2.5-flash' => 'Gemini 2.5 Flash',
    'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite',
    'gemini-2.5-pro' => 'Gemini 2.5 Pro',
    'gemini-3-pro-preview' => 'Gemini 3 Pro Preview',
    'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview',
    'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash Lite Preview',
];

define('DEFAULT_MODEL', 'gemini-3.1-flash-lite-preview');

// Telegram Bot Configuration
define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');

// OpenAI API Configuration
define('OPENAI_API_KEY', 'YOUR_OPENAI_API_KEY');
$OPENAI_MODELS = [
    'gpt-5.5' => 'GPT 5.5',
    'gpt-5.4' => 'GPT 5.4',
    'gpt-5.4-mini' => 'GPT 5.4 Mini',
];
define('DEFAULT_OPENAI_MODEL', 'gpt-5.4-mini');

/**
 * Returns a friendly name for the model ID
 */
function getFriendlyModelName($modelId) {
    global $GEMINI_MODELS, $OPENAI_MODELS;
    if (isset($GEMINI_MODELS[$modelId])) return $GEMINI_MODELS[$modelId];
    if (isset($OPENAI_MODELS[$modelId])) return $OPENAI_MODELS[$modelId];
    return $modelId;
}
