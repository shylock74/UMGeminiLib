<?php
/**
 * newsCron.php
 * Esecuzione programmata (Cron Job) o manuale per la generazione e invio della rassegna news Telegram.
 * 
 * Utilizzo CLI:
 *   php newsCron.php [podcast_id]
 * 
 * Utilizzo Web / HTTP:
 *   https://tuodominio.com/newsCron.php?secret=YOUR_CRON_SECRET[&podcast_id=1]
 */

set_time_limit(0);
ignore_user_abort(true);

require_once 'db.php';
require_once 'config.php';
require_once 'NewsCore.php';

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
        // Se non specificato, esegui per i podcast con gruppi aventi is_news_active = 1
        $stmt = $pdo->query("SELECT DISTINCT p.* FROM podcasts p INNER JOIN quiz_targets qt ON p.id = qt.podcast_id WHERE qt.is_news_active = 1");
        $podcasts = $stmt->fetchAll();
        
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
        $botToken = $podcast['token'];
        $emoji = $podcast['emoji'] ?? '🍷';

        // Recupera i gruppi Telegram abilitati alle news per questo podcast
        $stmtTargets = $pdo->prepare("SELECT * FROM quiz_targets WHERE podcast_id = :podcast_id AND is_news_active = 1");
        $stmtTargets->execute([':podcast_id' => $pId]);
        $targets = $stmtTargets->fetchAll();

        if (empty($targets)) {
            $results[] = [
                'podcast_id' => $pId,
                'podcast_name' => $pName,
                'status' => 'skipped',
                'message' => 'Nessun gruppo o canale Telegram abilitato alle news per questo podcast.'
            ];
            continue;
        }

        // Generazione del post editoriale con l'IA da Google News
        $newsResult = NewsCore::generaPostNews($pdo, $pId, [
            'podcastName' => $pName,
            'emoji' => $emoji
        ], DEFAULT_MODEL);

        $postText = $newsResult['editorial_post'];
        
        // Conversione Markdown standard per Telegram
        $formattedText = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $postText);
        $formattedText = preg_replace('/__(.+?)__/s', '_$1_', $formattedText);
        $formattedText = preg_replace('/^#{1,6}\s+/m', '', $formattedText);

        $sentCount = 0;
        $failedCount = 0;
        $details = [];

        // Invio a ciascuna destinazione
        foreach ($targets as $target) {
            $chatId = $target['chat_id'];
            $chatTitle = $target['chat_title'] ?? $chatId;

            $sendResp = sendTelegramMessage($botToken, $chatId, $formattedText, 'Markdown');
            $respData = json_decode($sendResp, true);

            if ($respData && !empty($respData['ok'])) {
                $sentCount++;
                $stmtUpdate = $pdo->prepare("UPDATE quiz_targets SET last_news_sent_at = NOW() WHERE id = :id");
                $stmtUpdate->execute([':id' => $target['id']]);

                $details[] = [
                    'chat_id' => $chatId,
                    'chat_title' => $chatTitle,
                    'status' => 'success',
                    'message_id' => $respData['result']['message_id'] ?? null
                ];
            } else {
                $failedCount++;
                $errorDesc = $respData['description'] ?? 'Errore invio messaggio Telegram';
                $details[] = [
                    'chat_id' => $chatId,
                    'chat_title' => $chatTitle,
                    'status' => 'error',
                    'error' => $errorDesc
                ];
            }
        }

        // Se inviato con successo ad almeno una destinazione, archivia l'articolo in sent_news
        if ($sentCount > 0 && !empty($newsResult['source_url'])) {
            try {
                $stmtArchive = $pdo->prepare("INSERT INTO sent_news (podcast_id, article_title, article_url) VALUES (:podcast_id, :title, :url)");
                $stmtArchive->execute([
                    ':podcast_id' => $pId,
                    ':title' => $newsResult['article_title'] ?: 'Notizia del Giorno',
                    ':url' => $newsResult['source_url']
                ]);
            } catch (Exception $eArch) {
                // Ignora errore salvataggio archivio
            }
        }

        $results[] = [
            'podcast_id' => $pId,
            'podcast_name' => $pName,
            'status' => ($sentCount > 0 ? 'success' : ($failedCount > 0 ? 'failed' : 'empty')),
            'article_title' => $newsResult['article_title'],
            'source_name' => $newsResult['source_name'],
            'source_url' => $newsResult['source_url'],
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
@file_put_contents('news_cron.log', "[" . date('Y-m-d H:i:s') . "] " . json_encode($response, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

// Output per Web o CLI
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "=== NEWS CRON EXECUTION ===\n";
    echo "Status: " . ($response['status'] ?? 'unknown') . "\n";
    if (isset($response['results'])) {
        foreach ($response['results'] as $res) {
            echo "- Podcast: " . ($res['podcast_name'] ?? 'N/A') . " -> Inviati: " . ($res['sent_count'] ?? 0) . ", Falliti: " . ($res['failed_count'] ?? 0) . "\n";
            if (isset($res['article_title'])) {
                echo "  Notizia: " . $res['article_title'] . "\n";
            }
        }
    }
    if (isset($response['message'])) {
        echo "Error: " . $response['message'] . "\n";
    }
    echo "===========================\n";
}

/**
 * Helper per inviare messaggi di testo Telegram con fallback Markdown
 */
function sendTelegramMessage($botToken, $chatId, $text, $parseMode = 'Markdown') {
    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => false
    ];

    $result = sendCurlRequest($url, $params);
    $data = json_decode($result, true);

    // Se Telegram rifiuta il Markdown, ritenta senza parse_mode
    if (!$data || empty($data['ok'])) {
        unset($params['parse_mode']);
        $result = sendCurlRequest($url, $params);
    }

    return $result;
}

function sendCurlRequest($url, $params) {
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
