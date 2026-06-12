<?php
/**
 * Telegram Bot Webhook Universale per Podcast AI
 * Riconosce il bot tramite il token nella query string e carica i metadati dal DB.
 */

set_time_limit(0);        // nessun timeout PHP
ignore_user_abort(true);  // continua anche se Telegram chiude la connessione

require_once 'db.php';
require_once 'PodcastCore.php';
require_once 'config.php';

// 1. Identificazione del bot tramite token nella URL (es: podcastTelegram.php?token=ABC)
$token = $_GET['token'] ?? null;

if (!$token) {
    file_put_contents('telegram_error.log', "[" . date('Y-m-d H:i:s') . "] Token mancante nella URL." . PHP_EOL, FILE_APPEND);
    exit;
}

// 2. Lookup della configurazione nel Database
try {
    $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE token = :token");
    $stmt->execute([':token' => $token]);
    $config = $stmt->fetch();

    if (!$config) {
        throw new Exception("Configurazione non trovata per il token fornito.");
    }
} catch (Exception $e) {
    file_put_contents('telegram_error.log', "[" . date('Y-m-d H:i:s') . "] Errore DB: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit;
}

// 3. Ricezione dell'input da Telegram
$content = file_get_contents("php://input");
// Debug: Logga l'input ricevuto usando il nome del podcast per distinguere i log
$logFile = 'telegram_' . $config['id'] . '_debug.log';
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $content . PHP_EOL, FILE_APPEND);

$update = json_decode($content, true);

if (!$update || !isset($update["update_id"])) {
    exit;
}

$updateId = $update["update_id"];
$cacheFile = 'processed_updates_' . $config['id'] . '.log';

// --- DEDUPLICAZIONE ---
if (file_exists($cacheFile)) {
    $processedUpdates = explode("\n", file_get_contents($cacheFile));
    if (in_array($updateId, $processedUpdates)) {
        exit;
    }
}
$currentUpdates = file_exists($cacheFile) ? explode("\n", file_get_contents($cacheFile)) : [];
$currentUpdates[] = $updateId;
if (count($currentUpdates) > 100)
    array_shift($currentUpdates);
@file_put_contents($cacheFile, implode("\n", $currentUpdates));

if (!isset($update["message"])) {
    exit;
}

$message = $update["message"];
$chatId = $message["chat"]["id"];
$chatType = $message["chat"]["type"] ?? "private";
$text = $message["text"] ?? "";
$firstName = $message["from"]["first_name"] ?? "amico";

// --- FILTRO ASCOLTO (Privacy nei gruppi) ---
if ($chatType === "group" || $chatType === "supergroup") {
    $botUsername = $config['username']; // Recuperato dal DB
    $isMentioned = (strpos($text, $botUsername) !== false);
    // Controllo se è una risposta al bot (usando lo username pulito)
    $cleanUsername = str_replace('@', '', $botUsername);
    $isReplyToBot = (isset($message["reply_to_message"]["from"]["is_bot"]) && $message["reply_to_message"]["from"]["username"] === $cleanUsername);

    if (!$isMentioned && !$isReplyToBot) {
        exit;
    }
    $text = trim(str_replace($botUsername, "", $text));
}

// Ignora i comandi (eccetto /start) o messaggi vuoti
if (empty($text) || (strpos($text, '/') === 0 && $text !== '/start')) {
    exit;
}

// --- LOGICA DI RISPOSTA ---

if ($text === '/start') {
    $welcomeMessage = sprintf($config['start_message'], $firstName);
    sendTelegramRequest($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $welcomeMessage
    ]);
    exit;
}

// 1. Segnala che il bot sta scrivendo
sendTelegramRequest($token, 'sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);

// 2. Invio immagine di attesa
$searchPhoto = file_exists($config['search_photo']) ? new CURLFile($config['search_photo']) : 'https://ulti.media/UMGemini/' . $config['search_photo'];
$waitingCaption = sprintf($config['waiting_caption'], $firstName);

$waitingMessage = sendTelegramRequest($token, 'sendPhoto', [
    'chat_id' => $chatId,
    'photo' => $searchPhoto,
    'caption' => $waitingCaption,
    'parse_mode' => 'Markdown'
]);

$waitingData = json_decode($waitingMessage, true);
$waitingMessageId = $waitingData['result']['message_id'] ?? null;

// 3. Chiamata alla logica centralizzata PodcastCore
try {
    $podcastOptions = [
        'podcastName' => $config['podcast_name'],
        'experts' => $config['experts'],
        'fallbackPrefix' => $config['fallback_prefix']
    ];

    // Messaggi di attesa creativi
    $waitingMessages = [
        "Sto ancora scavando nei miei archivi digitali... 🕵️‍♂️",
        "Un attimo, sto riordinando i pensieri (e i file)... 📂",
        "Ho quasi finito di riascoltare l'episodio giusto... 🎧",
        "Sto consultando il mio database interno... un secondo! 🤖",
        "Il mio processore sta fumando, ma la risposta sta arrivando... 🔥",
        "Sto verificando le note degli esperti... 📝",
        "Ancora un momento, sto mettendo in ordine i fatti... 🧩",
        "La risposta è quasi pronta, sto solo lucidando il testo... ✨",
        "Sto cercando di non perdermi tra le mille informazioni... 🧭",
        "Mmm, questa è una domanda interessante... ci sto lavorando! 🤔",
        "Sto filtrando la conoscenza come un decanter con il vino... 🍷",
        "Un attimo, sto chiedendo conferma ai miei circuiti... 🔌",
        "Sempre qui! Sto solo leggendo le ultime righe... 📖",
        "Sto collegando i puntini... ✍️",
        "Ho quasi versato la risposta nel calice del bot... 🥂",
        "Ancora qualche secondo di pazienza... ⏳",
        "Sto sincronizzando i database... 🔗",
        "Sto cercando l'abbinamento perfetto per le tue parole... 🎨",
        "Sto rileggendo tutto per essere sicuro... ✅",
        "Quasi fatto! Sto arrivando... 🏃‍♂️",
        "Sto lucidando i bit del server... ✨",
        "Sto chiedendo il permesso al mio gatto per procedere... 🐱",
        "Sto cercando la risposta nel fondo di un calice (vuoto)... 🍷",
        "Sto mettendo in ordine alfabetico le nuvole... ☁️",
        "Sto traducendo dal binario al sommelierese... 🤖",
        "Sto aspettando che l'intelligenza artificiale finisca la sua pausa caffè... ☕️",
        "Sto verificando se la risposta è bio e a chilometro zero... 🌱",
        "Sto cercando di non far arrabbiare l'algoritmo... 🌪",
        "Sto decantando i dati per eliminare i sedimenti... 🏺",
        "Sto interrogando gli spiriti del vino... 👻",
        "Sto cercando di capire se un'AI può sentire il retrogusto di mandorla... 🧠",
        "Sto gonfiando i palloncini per la festa dei dati... 🎈",
        "Sto cercando la chiave della cantina digitale... 🔑",
        "Sto convincendo il firewall che non sono un astemio... 🛡",
        "Sto sincronizzando i miei neuroni al silicio... ⚡️",
        "Sto leggendo i tarocchi del podcast... 🃏",
        "Sto cercando di non inciampare nei cavi della rete... 🔌",
        "Sto rincorrendo un'idea che stava scappando... 🏃‍♂️",
        "Sto mettendo le bollicine nei dati frizzanti... 🥂",
        "Sto verificando l'annata dei pacchetti ricevuti... 🗓",
        "Sto chiedendo consiglio a un vecchio floppy disk... 💾",
        "Sto cercando di capire perché il modem sta sussurrando... 🤫",
        "Sto dando da mangiare ai criceti che fanno girare il server... 🐹",
        "Sto dipingendo la risposta con colori pastello... 🎨",
        "Sto accordando le corde del database... 🎸",
        "Sto cercando di non far evaporare le informazioni importanti... 💨",
        "Sto mettendo il cappotto ai server, fa freddo nel cloud... 🧥",
        "Sto cercando di capire se i pixel hanno sete... 🖼",
        "Sto navigando in un mare di zeri e uni... 🌊",
        "Sto aspettando che la risposta finisca di lievitare... 🍞",
        "Sto cercando di non far cadere il segnale nel vuoto... 🕳",
        "Sto facendo lo stretching ai processori... 🧘‍♂️",
        "Sto chiedendo un parere al sommelier di sistema... 🤵",
        "Sto cercando di non far sbiadire le idee... 🖍",
        "Sto mettendo in riga i bit ribelli... 📏",
        "Sto cercando di capire se il Wi-Fi preferisce il bianco o il rosso... 📶",
        "Sto leggendo le istruzioni... scherzo, vado a intuito! 📖",
        "Sto cercando di non far surriscaldare la fantasia... 🌡",
        "Sto mettendo lo zucchero filato nei dati dolci... 🍭",
        "Sto cercando di capire se il cloud ha bisogno di un ombrello... ☂️",
        "Sto facendo il solletico alla CPU per farla ridere... 🤏",
        "Sto cercando di non far scappare le metafore... 🦋",
        "Sto mettendo il sale sulla coda della risposta... 🧂",
        "Sto cercando di capire se l'algoritmo ha dormito bene... 😴",
        "Sto facendo la convergenza alle ruote del bus dati... 🚌",
        "Sto cercando di non far ingarbugliare i pensieri... 🧶",
        "Sto mettendo la crema solare ai pixel... ☀️",
        "Sto cercando di capire se il database ha fame... 🍕",
        "Sto facendo il karaoke con i codici sorgente... 🎤",
        "Sto cercando di non far annoiare i circuiti... 🎮",
        "Sto mettendo i fiori nei cannoni del server... 🌸",
        "Sto cercando di capire se il router è felice... 😊",
        "Sto facendo la barba ai dati troppo lunghi... 🪒",
        "Sto cercando di non far cadere la linea... 🎣",
        "Sto mettendo il profumo ai bit... 👃",
        "Sto cercando di capire se l'IA ha bisogno di occhiali... 👓",
        "Sto facendo il gioco delle tre carte con i server... 🃏",
        "Sto cercando di non far sciogliere la risposta... 🍦",
        "Sto mettendo le scarpe da corsa ai pacchetti... 👟",
        "Sto cercando di capire se il processore è timido... 😳",
        "Sto facendo il solletico alle memorie RAM... 🍭",
        "Sto cercando di non far sbadigliare l'utente... 🥱",
        "Sto mettendo la ciliegina sulla torta dei dati... 🍒",
        "Sto cercando di capire se il kernel ha freddo... 🧥",
        "Sto facendo la danza della pioggia di dati... 💃",
        "Sto cercando di non far perdere la pazienza al bot... 🤖",
        "Sto mettendo il turbo ai calcoli... 🚀",
        "Sto cercando di capire se il bitrate è troppo basso... 🔉",
        "Sto facendo le parole crociate con il codice... 🧩",
        "Sto cercando di non far invecchiare i dati... 🍷",
        "Sto mettendo la cravatta ai bit eleganti... 👔",
        "Sto cercando di capire se la risposta è pronta per il debutto... 🎭",
        "Sto facendo il backup dei sogni dell'IA... 💤",
        "Sto cercando di non far scivolare i pensieri sulla buccia di banana... 🍌",
        "Sto mettendo il peperoncino ai dati piccanti... 🌶",
        "Sto cercando di capire se il server ha bisogno di una vacanza... 🏖",
        "Sto facendo la conta dei bit... uno, due, tre... 🔢",
        "Sto cercando di non far volare via le idee... 🎈",
        "Sto mettendo l'ancora ai dati pesanti... ⚓️",
        "Sto cercando di capire se la risposta è abbastanza frizzante... 🥤"
    ];
    shuffle($waitingMessages);
    $msgIndex = 0;
    $pingMessageId = null;
    $onWait = function ($modelId, $attempt) use ($token, $chatId, &$msgIndex, $waitingMessages, &$pingMessageId) {
        $friendlyModel = getFriendlyModelName($modelId);
        $text = "Sto provando con {$friendlyModel}, tentativo # {$attempt}\n\n";
        $text .= $waitingMessages[$msgIndex % count($waitingMessages)];
        
        if ($pingMessageId === null) {
            $resp = sendTelegramRequest($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
            $data = json_decode($resp, true);
            $pingMessageId = $data['result']['message_id'] ?? null;
        } else {
            sendTelegramRequest($token, 'editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $pingMessageId,
                'text' => $text
            ]);
        }
        $msgIndex++;
    };

    $startTime = microtime(true);
    $result = PodcastCore::elaboraDomanda($text, $config['yaml_file'], DEFAULT_MODEL, $podcastOptions, $onWait);
    $endTime = microtime(true);
    $durationSeconds = round($endTime - $startTime);
    if ($durationSeconds >= 60) {
        $minutes = floor($durationSeconds / 60);
        $seconds = $durationSeconds % 60;
        $durationText = $minutes . " minut" . ($minutes > 1 ? "i" : "o") . " e " . $seconds . " second" . ($seconds != 1 ? "i" : "o");
    } else {
        $durationText = $durationSeconds . " secondi";
    }

    $response = $result['output'];
    $friendlyModel = getFriendlyModelName($result['model']);

    $usage = $result['usage'];
    $inputTokens = $usage['promptTokenCount'] ?? $usage['prompt_tokens'] ?? 0;
    $outputTokens = $usage['candidatesTokenCount'] ?? $usage['completion_tokens'] ?? 0;

    $response .= "\n\n\n_Modello: {$friendlyModel}_";
    $response .= "\n_Tokens: {$inputTokens} in, {$outputTokens} out_";
    $response .= "\n_({$durationText})_";

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] GEMINI OK - risposta (" . strlen($response) . " chars) in {$durationText}: " . substr($response, 0, 200) . PHP_EOL, FILE_APPEND);
} catch (Exception $e) {
    $errorDetail = "⚠️ *Errore tecnico rilevato*\n\n";
    $errorDetail .= "*Messaggio:* " . $e->getMessage() . "\n";
    $errorDetail .= "*File:* " . basename($e->getFile()) . "\n";
    $errorDetail .= "*Linea:* " . $e->getLine() . "\n";
    $errorDetail .= "\n_Riprova tra poco o contatta il supporto se il problema persiste._";
    
    $response = $errorDetail;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] GEMINI EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . PHP_EOL, FILE_APPEND);
}

// 4. Invio della FOTO finale
$finalPhoto = file_exists($config['final_photo']) ? new CURLFile($config['final_photo']) : 'https://ulti.media/UMGemini/' . $config['final_photo'];
$finalCaption = sprintf($config['final_caption_prefix'], $firstName);

sendTelegramRequest($token, 'sendPhoto', [
    'chat_id' => $chatId,
    'photo' => $finalPhoto,
    'caption' => $finalCaption
]);

usleep(500000);

// 5. Invio della RISPOSTA completa
// Converti Markdown di Gemini in Markdown v1 compatibile con Telegram
$response = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $response);        // **bold** → *bold*
$response = preg_replace('/__(.+?)__/s', '_$1_', $response);              // __italic__ → _italic_
$response = preg_replace('/^#{1,6}\s+/m', '', $response);                 // ## titoli → rimossi
$response = preg_replace('/```[a-z]*\n?(.+?)```/s', '`$1`', $response);  // ```code``` → `code`

// Usa la domanda senza Markdown per evitare rotture del parser
$finalText = "Hai chiesto: " . $text . "\n\n";
$finalText .= $response;

    $sendResult = sendTelegramRequest($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $finalText,
        'parse_mode' => 'Markdown'
    ]);
    
    // Se l'invio con Markdown fallisce (es. per caratteri speciali non chiusi), riprova in formato piano
    $sendData = json_decode($sendResult, true);
    if (!$sendData || !$sendData['ok']) {
        $sendResult = sendTelegramRequest($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $finalText
        ]);
    }
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] SEND_MSG result: " . $sendResult . PHP_EOL, FILE_APPEND);

// 6. Rimuovi i messaggi di attesa
if ($pingMessageId) {
    sendTelegramRequest($token, 'deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $pingMessageId
    ]);
}
if ($waitingMessageId) {
    sendTelegramRequest($token, 'deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $waitingMessageId
    ]);
}

/**
 * Helper per le chiamate API di Telegram (Accetta il token come parametro)
 */
function sendTelegramRequest($botToken, $method, $params)
{
    $url = "https://api.telegram.org/bot" . $botToken . "/" . $method;

    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);

        $hasFile = false;
        foreach ($params as $val) {
            if ($val instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }

        if ($hasFile) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    } else {
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($params),
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        return @file_get_contents($url, false, $context);
    }
}
