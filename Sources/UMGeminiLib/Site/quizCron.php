<?php
/**
 * quizCron.php
 * Esecuzione programmata (Cron Job) o manuale per la generazione e invio dei quiz Telegram.
 * 
 * Utilizzo CLI:
 *   php quizCron.php [podcast_id]
 * 
 * Utilizzo Web / HTTP:
 *   https://tuodominio.com/quizCron.php?secret=YOUR_CRON_SECRET[&podcast_id=1]
 */

set_time_limit(0);
ignore_user_abort(true);

require_once 'db.php';
require_once 'config.php';
require_once 'QuizCore.php';

$isCli = (php_sapi_name() === 'cli' || defined('STDIN'));

// 1. Verifica Sicurezza / Parametri
$secret = $_GET['secret'] ?? $_POST['secret'] ?? null;
$podcastId = null;

if ($isCli) {
    global $argv;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $podcastId = (int)$argv[1];
    }
} else {
    // Se chiamato via Web, verifica il secret
    if (defined('CRON_SECRET') && !empty(CRON_SECRET) && CRON_SECRET !== 'YOUR_CRON_SECRET') {
        if ($secret !== CRON_SECRET) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(["status" => "error", "message" => "Accesso negato: Secret non valido o mancante."]);
            exit;
        }
    }
    if (isset($_GET['podcast_id'])) {
        $podcastId = (int)$_GET['podcast_id'];
    } elseif (isset($_POST['podcast_id'])) {
        $podcastId = (int)$_POST['podcast_id'];
    }
}

ensureQuizTables($pdo);

// 2. Lookup del podcast (o di tutti i podcast)
try {
    if ($podcastId) {
        $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
        $stmt->execute([':id' => $podcastId]);
        $podcasts = $stmt->fetchAll();
    } else {
        // Se non specificato, esegui per il podcast del vino (o tutti i podcast con gruppi attivi)
        $stmt = $pdo->query("SELECT DISTINCT p.* FROM podcasts p INNER JOIN quiz_targets qt ON p.id = qt.podcast_id WHERE qt.is_active = 1");
        $podcasts = $stmt->fetchAll();
        
        // Se non ci sono gruppi attivi per nessun podcast, prendi almeno il primo podcast
        if (empty($podcasts)) {
            $podcasts = $pdo->query("SELECT * FROM podcasts ORDER BY id ASC LIMIT 1")->fetchAll();
        }
    }

    if (empty($podcasts)) {
        throw new Exception("Nessun podcast configurato trovato nel database.");
    }

    $results = [];

    foreach ($podcasts as $podcast) {
        $pId = $podcast['id'];
        $pName = $podcast['podcast_name'];
        $yamlFile = $podcast['yaml_file'];
        $botToken = $podcast['token'];
        $emoji = $podcast['emoji'] ?? '🍷';

        // Recupera i gruppi Telegram attivi per questo podcast
        $stmtTargets = $pdo->prepare("SELECT * FROM quiz_targets WHERE podcast_id = :podcast_id AND is_active = 1");
        $stmtTargets->execute([':podcast_id' => $pId]);
        $targets = $stmtTargets->fetchAll();

        if (empty($targets)) {
            $results[] = [
                'podcast_id' => $pId,
                'podcast_name' => $pName,
                'status' => 'skipped',
                'message' => 'Nessun gruppo o canale Telegram attivo configurato per questo podcast.'
            ];
            continue;
        }

        // Generazione del Quiz con l'IA
        $quiz = QuizCore::generaQuiz($yamlFile, [
            'podcastName' => $pName,
            'emoji' => $emoji
        ], DEFAULT_MODEL);

        $sentCount = 0;
        $failedCount = 0;
        $details = [];

        // Invio del Quiz a ciascun gruppo
        foreach ($targets as $target) {
            $chatId = $target['chat_id'];
            $chatTitle = $target['chat_title'] ?? $chatId;
            $isAnonymous = !empty($target['is_anonymous']);

            $pollParams = [
                'chat_id' => $chatId,
                'question' => $quiz['question'],
                'options' => json_encode($quiz['options'], JSON_UNESCAPED_UNICODE),
                'type' => 'quiz',
                'correct_option_id' => $quiz['correct_option_id'],
                'is_anonymous' => $isAnonymous ? 'true' : 'false'
            ];

            if (!empty($quiz['explanation'])) {
                $pollParams['explanation'] = $quiz['explanation'];
            }

            $sendResp = sendTelegramPollRequest($botToken, $pollParams);
            $respData = json_decode($sendResp, true);

            if ($respData && !empty($respData['ok'])) {
                $sentCount++;
                // Aggiorna data ultimo quiz inviato
                $stmtUpdate = $pdo->prepare("UPDATE quiz_targets SET last_quiz_sent_at = NOW() WHERE id = :id");
                $stmtUpdate->execute([':id' => $target['id']]);

                $details[] = [
                    'chat_id' => $chatId,
                    'chat_title' => $chatTitle,
                    'status' => 'success',
                    'poll_id' => $respData['result']['poll']['id'] ?? null
                ];
            } else {
                $failedCount++;
                $errorDesc = $respData['description'] ?? 'Errore sconosciuto di invio poll Telegram';
                $details[] = [
                    'chat_id' => $chatId,
                    'chat_title' => $chatTitle,
                    'status' => 'error',
                    'error' => $errorDesc
                ];
            }
        }

        $results[] = [
            'podcast_id' => $pId,
            'podcast_name' => $pName,
            'status' => ($sentCount > 0 ? 'success' : ($failedCount > 0 ? 'failed' : 'empty')),
            'quiz' => [
                'question' => $quiz['question'],
                'options' => $quiz['options'],
                'correct_option_id' => $quiz['correct_option_id'],
                'explanation' => $quiz['explanation'],
                'episode_title' => $quiz['episode_title'],
                'model' => $quiz['model']
            ],
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'details' => $details
        ];
    }

    $response = [
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'results' => $results
    ];

} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $e->getMessage()
    ];
}

// Log dell'esecuzione del cron
@file_put_contents('quiz_cron.log', "[" . date('Y-m-d H:i:s') . "] " . json_encode($response, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

// Output per Web o CLI
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "=== QUIZ CRON EXECUTION ===\n";
    echo "Status: " . ($response['status'] ?? 'unknown') . "\n";
    if (isset($response['results'])) {
        foreach ($response['results'] as $res) {
            echo "- Podcast: " . ($res['podcast_name'] ?? 'N/A') . " -> Inviati: " . ($res['sent_count'] ?? 0) . ", Falliti: " . ($res['failed_count'] ?? 0) . "\n";
            if (isset($res['quiz']['question'])) {
                echo "  Domanda: " . $res['quiz']['question'] . "\n";
            }
        }
    }
    if (isset($response['message'])) {
        echo "Error: " . $response['message'] . "\n";
    }
    echo "===========================\n";
}

/**
 * Helper per inviare la richiesta sendPoll a Telegram
 */
function sendTelegramPollRequest($botToken, $params) {
    $url = "https://api.telegram.org/bot" . $botToken . "/sendPoll";

    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            @file_put_contents('telegram_error.log', "[" . date('Y-m-d H:i:s') . "] Poll cURL Error: " . $error_msg . PHP_EOL, FILE_APPEND);
        }
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
