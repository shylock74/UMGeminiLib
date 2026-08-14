<?php
/**
 * manage_podcasts.php
 * Console di gestione CRUD per i podcast, Quiz Automatici, Rassegna News Telegram e Statistiche Click
 */

require_once 'db.php';
require_once 'config.php';
require_once 'QuizCore.php';
require_once 'NewsCore.php';

ensureQuizTables($pdo);

$message = '';
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// --- GESTIONE AZIONI AJAX / POST PER IL MODULO QUIZ & NEWS ---

// 1. Toggle Quiz Attivo / Disattivo
if ($action === 'toggle_quiz_active' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    $stmt = $pdo->prepare("UPDATE quiz_targets SET is_active = NOT is_active WHERE id = :id");
    $stmt->execute([':id' => $targetId]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: manage_podcasts.php?msg=" . urlencode("Stato Quiz aggiornato!"));
    exit;
}

// 2. Toggle Quiz Anonimo / Pubblico
if ($action === 'toggle_quiz_anonymous' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    $stmt = $pdo->prepare("UPDATE quiz_targets SET is_anonymous = NOT is_anonymous WHERE id = :id");
    $stmt->execute([':id' => $targetId]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: manage_podcasts.php?msg=" . urlencode("Modalità anonimato Quiz aggiornata!"));
    exit;
}

// 3. Toggle News Attivo / Disattivo
if ($action === 'toggle_news_active' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    $stmt = $pdo->prepare("UPDATE quiz_targets SET is_news_active = NOT is_news_active WHERE id = :id");
    $stmt->execute([':id' => $targetId]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: manage_podcasts.php?msg=" . urlencode("Stato News aggiornato!"));
    exit;
}

// 4. Aggiunta Manuale Gruppo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_quiz_target'])) {
    $pId = (int)($_POST['podcast_id'] ?? 1);
    $cId = trim($_POST['chat_id'] ?? '');
    $cTitle = trim($_POST['chat_title'] ?? '') ?: ('Chat ' . $cId);
    $cType = $_POST['chat_type'] ?? 'group';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;
    $isNewsActive = isset($_POST['is_news_active']) ? 1 : 0;

    if (!empty($cId)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO quiz_targets (podcast_id, chat_id, chat_title, chat_type, is_active, is_anonymous, is_news_active) 
                VALUES (:podcast_id, :chat_id, :chat_title, :chat_type, :is_active, :is_anonymous, :is_news_active)
                ON DUPLICATE KEY UPDATE 
                chat_title = VALUES(chat_title), 
                chat_type = VALUES(chat_type), 
                is_active = VALUES(is_active), 
                is_anonymous = VALUES(is_anonymous),
                is_news_active = VALUES(is_news_active)");
            $stmt->execute([
                ':podcast_id' => $pId,
                ':chat_id' => $cId,
                ':chat_title' => $cTitle,
                ':chat_type' => $cType,
                ':is_active' => $isActive,
                ':is_anonymous' => $isAnonymous,
                ':is_news_active' => $isNewsActive
            ]);
            header("Location: manage_podcasts.php?msg=" . urlencode("Gruppo aggiunto con successo alla lista!"));
            exit;
        } catch (Exception $e) {
            $message = "Errore inserimento gruppo: " . $e->getMessage();
        }
    }
}

// 5. Eliminazione Gruppo
if ($action === 'delete_quiz_target' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM quiz_targets WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
        header("Location: manage_podcasts.php?msg=" . urlencode("Gruppo rimosso dalla lista."));
        exit;
    } catch (Exception $e) {
        $message = "Errore eliminazione gruppo: " . $e->getMessage();
    }
}

// 6. Test Invio Immediato Quiz
if ($action === 'send_quiz_now') {
    header('Content-Type: application/json');
    $podcastId = (int)($_GET['podcast_id'] ?? 1);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
        $stmt->execute([':id' => $podcastId]);
        $podcast = $stmt->fetch();
        if (!$podcast) {
            throw new Exception("Podcast non trovato.");
        }

        $stmtTargets = $pdo->prepare("SELECT * FROM quiz_targets WHERE podcast_id = :podcast_id AND is_active = 1");
        $stmtTargets->execute([':podcast_id' => $podcastId]);
        $targets = $stmtTargets->fetchAll();

        if (empty($targets)) {
            throw new Exception("Nessun gruppo attivo selezionato per i Quiz di questo podcast.");
        }

        // Generazione del Quiz con l'IA
        $quiz = QuizCore::generaQuiz($podcast['yaml_file'], [
            'podcastName' => $podcast['podcast_name'],
            'emoji' => $podcast['emoji'] ?? '🍷'
        ], DEFAULT_MODEL);

        $sentCount = 0;
        $failedCount = 0;
        $details = [];

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

            $url = "https://api.telegram.org/bot" . $podcast['token'] . "/sendPoll";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($pollParams));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            $sendResp = curl_exec($ch);
            curl_close($ch);

            $respData = json_decode($sendResp, true);

            if ($respData && !empty($respData['ok'])) {
                $sentCount++;
                $stmtUpdate = $pdo->prepare("UPDATE quiz_targets SET last_quiz_sent_at = NOW() WHERE id = :id");
                $stmtUpdate->execute([':id' => $target['id']]);
                $details[] = ['chat_title' => $chatTitle, 'status' => 'ok'];
            } else {
                $failedCount++;
                $errorDesc = $respData['description'] ?? 'Errore Telegram';
                $details[] = ['chat_title' => $chatTitle, 'status' => 'error', 'error' => $errorDesc];
            }
        }

        echo json_encode([
            'status' => 'success',
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'quiz' => $quiz,
            'details' => $details
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 7. Test Invio Immediato News (con Immagine e Link Corto)
if ($action === 'send_news_now') {
    header('Content-Type: application/json');
    $podcastId = (int)($_GET['podcast_id'] ?? 1);

    try {
        $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
        $stmt->execute([':id' => $podcastId]);
        $podcast = $stmt->fetch();
        if (!$podcast) {
            throw new Exception("Podcast non trovato.");
        }

        $stmtTargets = $pdo->prepare("SELECT * FROM quiz_targets WHERE podcast_id = :podcast_id AND is_news_active = 1");
        $stmtTargets->execute([':podcast_id' => $podcastId]);
        $targets = $stmtTargets->fetchAll();

        if (empty($targets)) {
            throw new Exception("Nessun gruppo abilitato alle News per questo podcast. Attiva lo switch 'News' per almeno un gruppo.");
        }

        // Generazione del Post News con l'IA
        $newsResult = NewsCore::generaPostNews($pdo, $podcastId, [
            'podcastName' => $podcast['podcast_name'],
            'emoji' => $podcast['emoji'] ?? '🍷'
        ], DEFAULT_MODEL);

        $postText = $newsResult['editorial_post'];
        $imageUrl = $newsResult['image_url'] ?? null;

        $formattedText = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $postText);
        $formattedText = preg_replace('/__(.+?)__/s', '_$1_', $formattedText);
        $formattedText = preg_replace('/^#{1,6}\s+/m', '', $formattedText);

        $sentCount = 0;
        $failedCount = 0;
        $details = [];

        foreach ($targets as $target) {
            $chatId = $target['chat_id'];
            $chatTitle = $target['chat_title'] ?? $chatId;

            $sendResp = null;
            $usedPhoto = false;

            if (!empty($imageUrl)) {
                $photoParam = null;
                if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $photoParam = $imageUrl;
                } elseif (file_exists($imageUrl)) {
                    $photoParam = new CURLFile(realpath($imageUrl));
                } elseif (file_exists(__DIR__ . '/' . $imageUrl)) {
                    $photoParam = new CURLFile(__DIR__ . '/' . $imageUrl);
                }

                if ($photoParam) {
                    if (mb_strlen($formattedText, 'UTF-8') <= 1024) {
                        $sendResp = sendTelegramDirectRequest($podcast['token'], 'sendPhoto', [
                            'chat_id' => $chatId,
                            'photo' => $photoParam,
                            'caption' => $formattedText,
                            'parse_mode' => 'Markdown'
                        ]);
                        $usedPhoto = true;
                    } else {
                        $photoCaption = ($podcast['emoji'] ?? '🍷') . " *Rassegna Notizie:* " . ($newsResult['article_title'] ?? 'Novità enologiche');
                        sendTelegramDirectRequest($podcast['token'], 'sendPhoto', [
                            'chat_id' => $chatId,
                            'photo' => $photoParam,
                            'caption' => $photoCaption,
                            'parse_mode' => 'Markdown'
                        ]);
                        usleep(300000);
                        $sendResp = sendTelegramDirectRequest($podcast['token'], 'sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $formattedText,
                            'parse_mode' => 'Markdown',
                            'disable_web_page_preview' => false
                        ]);
                        $usedPhoto = true;
                    }
                }
            }

            if (!$usedPhoto || empty($sendResp)) {
                $sendResp = sendTelegramDirectRequest($podcast['token'], 'sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $formattedText,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => false
                ]);
            }

            $respData = json_decode($sendResp, true);

            if ($respData && !empty($respData['ok'])) {
                $sentCount++;
                $stmtUpdate = $pdo->prepare("UPDATE quiz_targets SET last_news_sent_at = NOW() WHERE id = :id");
                $stmtUpdate->execute([':id' => $target['id']]);
                $details[] = ['chat_title' => $chatTitle, 'status' => 'ok'];
            } else {
                $failedCount++;
                $errorDesc = $respData['description'] ?? 'Errore Telegram';
                $details[] = ['chat_title' => $chatTitle, 'status' => 'error', 'error' => $errorDesc];
            }
        }

        // Salva nell'archivio sent_news
        if ($sentCount > 0 && !empty($newsResult['source_url'])) {
            try {
                $stmtArchive = $pdo->prepare("INSERT INTO sent_news (podcast_id, article_title, article_url) VALUES (:podcast_id, :title, :url)");
                $stmtArchive->execute([
                    ':podcast_id' => $podcastId,
                    ':title' => $newsResult['article_title'] ?: 'Notizia del Giorno',
                    ':url' => $newsResult['source_url']
                ]);
            } catch (Exception $eArch) {}
        }

        echo json_encode([
            'status' => 'success',
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'news' => $newsResult,
            'details' => $details
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

function sendTelegramDirectRequest($botToken, $method, $params) {
    $url = "https://api.telegram.org/bot" . $botToken . "/" . $method;
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// --- LOGICA CRUD PODCAST ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $data = [
        ':token' => $_POST['token'],
        ':username' => $_POST['username'],
        ':yaml_file' => $_POST['yaml_file'],
        ':podcast_name' => $_POST['podcast_name'],
        ':experts' => $_POST['experts'],
        ':fallback_prefix' => $_POST['fallback_prefix'],
        ':search_photo' => $_POST['search_photo'],
        ':final_photo' => $_POST['final_photo'],
        ':emoji' => $_POST['emoji'],
        ':start_message' => $_POST['start_message'],
        ':waiting_caption' => $_POST['waiting_caption'],
        ':error_response' => $_POST['error_response'],
        ':final_caption_prefix' => $_POST['final_caption_prefix']
    ];

    try {
        if (!empty($_POST['id'])) {
            $data[':id'] = $_POST['id'];
            $sql = "UPDATE podcasts SET 
                    token = :token, username = :username, yaml_file = :yaml_file, 
                    podcast_name = :podcast_name, experts = :experts, fallback_prefix = :fallback_prefix, 
                    search_photo = :search_photo, final_photo = :final_photo, emoji = :emoji, 
                    start_message = :start_message, waiting_caption = :waiting_caption, 
                    error_response = :error_response, final_caption_prefix = :final_caption_prefix 
                    WHERE id = :id";
            $message = "Podcast aggiornato con successo!";
        } else {
            $sql = "INSERT INTO podcasts 
                    (token, username, yaml_file, podcast_name, experts, fallback_prefix, search_photo, final_photo, emoji, start_message, waiting_caption, error_response, final_caption_prefix) 
                    VALUES 
                    (:token, :username, :yaml_file, :podcast_name, :experts, :fallback_prefix, :search_photo, :final_photo, :emoji, :start_message, :waiting_caption, :error_response, :final_caption_prefix)";
            $message = "Nuovo podcast aggiunto con successo!";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        header("Location: manage_podcasts.php?msg=" . urlencode($message));
        exit;
    } catch (Exception $e) {
        $message = "Errore: " . $e->getMessage();
    }
}

if ($action === 'delete' && $editId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM podcasts WHERE id = :id");
        $stmt->execute([':id' => $editId]);
        header("Location: manage_podcasts.php?msg=" . urlencode("Podcast eliminato."));
        exit;
    } catch (Exception $e) {
        $message = "Errore eliminazione: " . $e->getMessage();
    }
}

$editData = null;
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $editData = $stmt->fetch();
}

$podcasts = $pdo->query("SELECT * FROM podcasts ORDER BY id ASC")->fetchAll();
$quizTargets = $pdo->query("SELECT qt.*, p.podcast_name, p.emoji as podcast_emoji FROM quiz_targets qt LEFT JOIN podcasts p ON qt.podcast_id = p.id ORDER BY qt.podcast_id ASC, qt.id DESC")->fetchAll();
$shortLinks = $pdo->query("SELECT sl.*, p.podcast_name, p.emoji as podcast_emoji FROM short_links sl LEFT JOIN podcasts p ON sl.podcast_id = p.id ORDER BY sl.clicks DESC, sl.id DESC LIMIT 50")->fetchAll();

$displayMsg = $_GET['msg'] ?? $message;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Podcast, Quiz & News Control Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0f19;
            --card: #151d30;
            --card-border: rgba(255, 255, 255, 0.08);
            --accent: #38bdf8;
            --accent-glow: rgba(56, 189, 248, 0.3);
            --purple: #a855f7;
            --wine: #e11d48;
            --wine-glow: rgba(225, 29, 72, 0.3);
            --amber: #f59e0b;
            --amber-glow: rgba(245, 158, 11, 0.3);
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #ef4444;
            --success: #10b981;
        }

        * { box-sizing: border-box; }
        body {
            background-color: var(--bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 2rem;
            line-height: 1.5;
        }

        .container {
            max-width: 1150px;
            margin: 0 auto;
        }

        h1 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 2.2rem;
            margin-bottom: 2rem;
            background: linear-gradient(135deg, #38bdf8, #a855f7 50%, #f43f5e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--text);
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid var(--success);
            color: #34d399;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card {
            background: var(--card);
            border-radius: 1.25rem;
            padding: 1.75rem;
            box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.5);
            border: 1px solid var(--card-border);
            margin-bottom: 2rem;
            position: relative;
        }

        .podcast-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.5rem;
        }

        .podcast-item {
            background: rgba(255, 255, 255, 0.025);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid var(--card-border);
            transition: all 0.25s ease;
            position: relative;
        }

        .podcast-item:hover {
            transform: translateY(-4px);
            border-color: var(--accent);
            box-shadow: 0 10px 25px -5px var(--accent-glow);
        }

        .podcast-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .podcast-emoji { font-size: 2rem; }
        .podcast-name { font-weight: 700; font-size: 1.2rem; }
        .podcast-user { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem; }
        
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.6rem;
            border: none;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #38bdf8, #0ea5e9);
            color: #04131f;
        }
        .btn-primary:hover { box-shadow: 0 0 15px var(--accent-glow); transform: translateY(-1px); }

        .btn-quiz {
            background: linear-gradient(135deg, #e11d48, #be123c);
            color: #fff;
        }
        .btn-quiz:hover { box-shadow: 0 0 18px var(--wine-glow); transform: translateY(-1px); }

        .btn-news {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
        }
        .btn-news:hover { box-shadow: 0 0 18px var(--amber-glow); transform: translateY(-1px); }

        .btn-secondary { background: rgba(255, 255, 255, 0.08); color: var(--text); }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.15); }

        .btn-webhook { background: rgba(168, 85, 247, 0.12); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .btn-webhook:hover { background: #a855f7; color: white; }

        .btn-danger { background: rgba(239, 68, 68, 0.12); color: #f87171; }
        .btn-danger:hover { background: var(--danger); color: white; }

        .btn-sm { padding: 0.35rem 0.75rem; font-size: 0.775rem; }

        /* Form Styling */
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.875rem; font-weight: 500; }
        input, select, textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
            border-radius: 0.6rem;
            padding: 0.75rem;
            color: var(--text);
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .table-responsive { overflow-x: auto; }

        .groups-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .groups-table th {
            text-align: left;
            padding: 0.75rem 0.85rem;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--card-border);
        }

        .groups-table td {
            padding: 0.9rem 0.85rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }

        .groups-table tr:hover { background: rgba(255, 255, 255, 0.02); }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .badge-group { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .badge-channel { background: rgba(168, 85, 247, 0.15); color: #c084fc; }
        .badge-private { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        .badge-clicks { background: rgba(16, 185, 129, 0.2); color: #34d399; font-weight: 700; }

        /* Switch Toggle Component */
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
        }

        .switch input { opacity: 0; width: 0; height: 0; }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.15);
            transition: .3s;
            border-radius: 22px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider { background-color: var(--success); }
        input:checked + .slider.slider-purple { background-color: var(--purple); }
        input:checked + .slider.slider-amber { background-color: var(--amber); }

        input:checked + .slider:before { transform: translateX(18px); }

        .code-box {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.4);
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.82rem;
            color: #38bdf8;
        }

        /* Modal styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 1.25rem;
            max-width: 650px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .preview-box {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin: 1rem 0;
        }
        .preview-box-quiz { border-left: 4px solid var(--wine); }
        .preview-box-news { border-left: 4px solid var(--amber); }

        .quiz-option-item {
            padding: 0.5rem 0.75rem;
            margin: 0.35rem 0;
            border-radius: 0.5rem;
            background: rgba(255, 255, 255, 0.04);
            font-size: 0.9rem;
        }
        .quiz-option-correct {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--success);
            color: #6ee7b7;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-actions">
        <h1>🍷 Podcast, Quiz & News Center</h1>
        <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary">+ Aggiungi Podcast</a>
        <?php else: ?>
            <a href="manage_podcasts.php" class="btn btn-secondary">← Torna alla Lista</a>
        <?php endif; ?>
    </div>

    <?php if ($displayMsg): ?>
        <div class="alert">
            <span>✨</span> <?php echo htmlspecialchars($displayMsg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- Sezione 1: Lista Podcast Configurate -->
        <div class="card">
            <div class="section-title">
                <span>🎙️ Podcast Attivi</span>
            </div>
            <?php if (empty($podcasts)): ?>
                <div class="empty-state">Nessun podcast configurato. Inizia aggiungendone uno!</div>
            <?php else: ?>
                <div class="podcast-grid">
                    <?php foreach ($podcasts as $p): ?>
                        <div class="podcast-item">
                            <div class="podcast-header">
                                <span class="podcast-emoji"><?php echo htmlspecialchars($p['emoji']); ?></span>
                                <div>
                                    <div class="podcast-name"><?php echo htmlspecialchars($p['podcast_name']); ?></div>
                                    <div class="podcast-user"><?php echo htmlspecialchars($p['username']); ?></div>
                                </div>
                            </div>
                            
                            <div class="actions">
                                <button type="button" class="btn btn-quiz btn-sm" onclick="triggerSendQuiz(<?php echo $p['id']; ?>, '<?php echo addslashes($p['podcast_name']); ?>')">
                                    🎲 Invia Quiz Ora
                                </button>
                                <button type="button" class="btn btn-news btn-sm" onclick="triggerSendNews(<?php echo $p['id']; ?>, '<?php echo addslashes($p['podcast_name']); ?>')">
                                    📰 Invia News Ora
                                </button>
                                <a href="?action=edit&id=<?php echo $p['id']; ?>" class="btn btn-secondary btn-sm">Modifica</a>
                                <a href="set_webhook.php?token=<?php echo urlencode($p['token']); ?>" class="btn btn-webhook btn-sm" title="Configura Webhook su Telegram">Webhook</a>
                                <a href="?action=delete&id=<?php echo $p['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Sei sicuro di voler eliminare questo podcast?')">Elimina</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sezione 2: Modulo Destinazioni Telegram (Quiz & News) -->
        <div class="card">
            <div class="section-title">
                <span>🎯 Destinazioni Telegram (Gruppi & Canali)</span>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">
                    Censimento automatico al primo messaggio ricevuto
                </span>
            </div>

            <?php if (empty($quizTargets)): ?>
                <div class="empty-state">
                    Nessun gruppo o canale Telegram ancora registrato.<br>
                    Aggiungi il bot a un gruppo Telegram e invia un messaggio, oppure inserisci manualmente il Chat ID qui sotto!
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="groups-table">
                        <thead>
                            <tr>
                                <th>Podcast</th>
                                <th>Gruppo / Canale</th>
                                <th>Tipo</th>
                                <th>Chat ID</th>
                                <th style="text-align:center;">Quiz Attivo</th>
                                <th style="text-align:center;">Anonimo</th>
                                <th style="text-align:center;">News Attivo</th>
                                <th>Ultimo Quiz</th>
                                <th>Ultima News</th>
                                <th style="text-align:right;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizTargets as $qt): ?>
                                <tr>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($qt['podcast_name']); ?>">
                                            <?php echo htmlspecialchars($qt['podcast_emoji'] ?? '🍷'); ?> <?php echo htmlspecialchars($qt['podcast_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($qt['chat_title']); ?></strong>
                                    </td>
                                    <td>
                                        <?php 
                                            $badgeClass = 'badge-group';
                                            if ($qt['chat_type'] === 'channel') $badgeClass = 'badge-channel';
                                            elseif ($qt['chat_type'] === 'private') $badgeClass = 'badge-private';
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($qt['chat_type']); ?></span>
                                    </td>
                                    <td>
                                        <span class="code-box"><?php echo htmlspecialchars($qt['chat_id']); ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <label class="switch" title="Abilita/Disabilita Quiz Automatico">
                                            <input type="checkbox" <?php echo $qt['is_active'] ? 'checked' : ''; ?> onchange="toggleGroupStatus(<?php echo $qt['id']; ?>, 'quiz_active')">
                                            <span class="slider"></span>
                                        </label>
                                    </td>
                                    <td style="text-align:center;">
                                        <label class="switch" title="Quiz Anonimo (spento = Pubblico)">
                                            <input type="checkbox" <?php echo $qt['is_anonymous'] ? 'checked' : ''; ?> onchange="toggleGroupStatus(<?php echo $qt['id']; ?>, 'quiz_anonymous')">
                                            <span class="slider slider-purple"></span>
                                        </label>
                                    </td>
                                    <td style="text-align:center;">
                                        <label class="switch" title="Abilita/Disabilita Rassegna News del Giorno">
                                            <input type="checkbox" <?php echo !empty($qt['is_news_active']) ? 'checked' : ''; ?> onchange="toggleGroupStatus(<?php echo $qt['id']; ?>, 'news_active')">
                                            <span class="slider slider-amber"></span>
                                        </label>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo $qt['last_quiz_sent_at'] ? date('d/m H:i', strtotime($qt['last_quiz_sent_at'])) : 'Mai'; ?>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo !empty($qt['last_news_sent_at']) ? date('d/m H:i', strtotime($qt['last_news_sent_at'])) : 'Mai'; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="?action=delete_quiz_target&target_id=<?php echo $qt['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Rimuovere questo gruppo?')">
                                            Rimuovi
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Form Aggiunta Manuale Gruppo -->
            <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--card-border);">
                <h3 style="font-size: 1.05rem; margin-bottom: 1rem; color: var(--accent);">➕ Aggiungi Gruppo / Canale Manualmente</h3>
                <form method="POST" action="manage_podcasts.php">
                    <input type="hidden" name="add_quiz_target" value="1">
                    <div class="form-row" style="grid-template-columns: 1.2fr 1.5fr 1.5fr 1fr auto;">
                        <div class="form-group" style="margin-bottom:0">
                            <label>Podcast</label>
                            <select name="podcast_id" required>
                                <?php foreach ($podcasts as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['emoji'] . ' ' . $p['podcast_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label>Chat ID / Username Canale</label>
                            <input type="text" name="chat_id" placeholder="es: -100123456789 o @mioCanale" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label>Nome / Titolo Gruppo</label>
                            <input type="text" name="chat_title" placeholder="es: Amici del Sommelier">
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label>Tipo</label>
                            <select name="chat_type">
                                <option value="group">Gruppo</option>
                                <option value="supergroup">Supergruppo</option>
                                <option value="channel">Canale</option>
                                <option value="private">Privato</option>
                            </select>
                        </div>
                        <div style="display:flex; align-items:flex-end;">
                            <button type="submit" class="btn btn-primary" style="height: 42px;">Salva Gruppo</button>
                        </div>
                    </div>
                    <div style="display:flex; gap:1.5rem; margin-top:0.75rem; font-size:0.875rem; color:var(--text-muted);">
                        <label style="display:inline-flex; align-items:center; gap:0.4rem; cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1" checked style="width:auto;"> Abilita Quiz
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:0.4rem; cursor:pointer;">
                            <input type="checkbox" name="is_news_active" value="1" checked style="width:auto;"> Abilita News
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:0.4rem; cursor:pointer;">
                            <input type="checkbox" name="is_anonymous" value="1" style="width:auto;"> Quiz Anonimo
                        </label>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sezione 3: Link Tracciati & Statistiche Click -->
        <div class="card">
            <div class="section-title">
                <span>📊 Link Tracciati & Statistiche Click</span>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">
                    Reindirizzamento tramite endpoint <code class="code-box">r.php?c=...</code>
                </span>
            </div>

            <?php if (empty($shortLinks)): ?>
                <div class="empty-state">Nessun link ancora tracciato. Verranno generati automaticamente all'invio delle News.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="groups-table">
                        <thead>
                            <tr>
                                <th>Podcast</th>
                                <th>Titolo / Descrizione</th>
                                <th>Link Corto (Tracciato)</th>
                                <th>Destinazione Originale</th>
                                <th style="text-align:center;">Click Totali</th>
                                <th>Ultimo Click</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shortLinks as $sl): ?>
                                <?php 
                                    $host = $_SERVER['HTTP_HOST'] ?? 'ulti.media';
                                    $scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                    $shortUrl = 'https://' . $host . $scriptDir . '/r.php?c=' . $sl['code'];
                                ?>
                                <tr>
                                    <td>
                                        <span><?php echo htmlspecialchars($sl['podcast_emoji'] ?? '🍷'); ?> <?php echo htmlspecialchars($sl['podcast_name']); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($sl['title'] ?: 'Notizia'); ?></strong>
                                    </td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($shortUrl); ?>" target="_blank" class="code-box" style="text-decoration:none;">
                                            🔗 r.php?c=<?php echo htmlspecialchars($sl['code']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($sl['target_url']); ?>" target="_blank" style="color:var(--text-muted); font-size:0.8rem; text-decoration:underline; max-width:220px; display:inline-block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?php echo htmlspecialchars($sl['target_url']); ?>
                                        </a>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="badge badge-clicks"><?php echo (int)$sl['clicks']; ?> click</span>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo $sl['last_clicked_at'] ? date('d/m/Y H:i', strtotime($sl['last_clicked_at'])) : 'Nessun click'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Informazioni Cron Job -->
            <div style="margin-top: 2rem; padding: 1.25rem; background: rgba(0,0,0,0.25); border-radius: 0.75rem; border: 1px solid var(--card-border);">
                <div style="font-weight: 600; margin-bottom: 0.5rem; color: #38bdf8;">⏰ Istruzioni per i Cron Job Automatici</div>
                <div style="font-size: 0.875rem; color: var(--text-muted); line-height: 1.7;">
                    <strong>1. Quiz Automatico (es. ogni 2 o 3 giorni):</strong><br>
                    • CLI: <span class="code-box">php <?php echo realpath(__DIR__ . '/quizCron.php'); ?> 1</span><br>
                    • Web: <span class="code-box">https://<?php echo $_SERVER['HTTP_HOST'] ?? 'tuosito.com'; ?><?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/quizCron.php?secret=<?php echo CRON_SECRET; ?>&podcast_id=1</span><br><br>
                    <strong>2. Rassegna News Giornaliera (es. ogni mattina alle 08:30):</strong><br>
                    • CLI: <span class="code-box">php <?php echo realpath(__DIR__ . '/newsCron.php'); ?> 1</span><br>
                    • Web: <span class="code-box">https://<?php echo $_SERVER['HTTP_HOST'] ?? 'tuosito.com'; ?><?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/newsCron.php?secret=<?php echo CRON_SECRET; ?>&podcast_id=1</span>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Form Add/Edit Podcast -->
        <div class="card">
            <h2 style="margin-top:0"><?php echo $editData ? 'Modifica Podcast' : 'Nuovo Podcast'; ?></h2>
            <form method="POST">
                <?php if ($editData): ?>
                    <input type="hidden" name="id" value="<?php echo $editData['id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Token Telegram</label>
                        <input type="text" name="token" value="<?php echo htmlspecialchars($editData['token'] ?? ''); ?>" required placeholder="8466115311:AAEjB...">
                    </div>
                    <div class="form-group">
                        <label>Username Bot</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($editData['username'] ?? ''); ?>" required placeholder="@MioBot">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nome Podcast</label>
                        <input type="text" name="podcast_name" value="<?php echo htmlspecialchars($editData['podcast_name'] ?? ''); ?>" required placeholder="Il Mio Fantastico Podcast">
                    </div>
                    <div class="form-group">
                        <label>File YAML (KB)</label>
                        <input type="text" name="yaml_file" value="<?php echo htmlspecialchars($editData['yaml_file'] ?? ''); ?>" required placeholder="kb.yaml">
                    </div>
                </div>

                <div class="form-group">
                    <label>Esperti (Descrizione per il prompt)</label>
                    <input type="text" name="experts" value="<?php echo htmlspecialchars($editData['experts'] ?? ''); ?>" required placeholder="di Mario Rossi e Luca Bianchi">
                </div>

                <div class="form-group">
                    <label>Fallback Prefix (Frase se non trova info)</label>
                    <textarea name="fallback_prefix" rows="2" required><?php echo htmlspecialchars($editData['fallback_prefix'] ?? 'Non ho trovato informazioni specifiche nel podcast, ma ecco cosa penso: '); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Immagine Ricerca (File o URL)</label>
                        <input type="text" name="search_photo" value="<?php echo htmlspecialchars($editData['search_photo'] ?? ''); ?>" placeholder="Search.jpg">
                    </div>
                    <div class="form-group">
                        <label>Immagine Finale (File o URL)</label>
                        <input type="text" name="final_photo" value="<?php echo htmlspecialchars($editData['final_photo'] ?? ''); ?>" placeholder="Bot.jpg">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Emoji Tematica</label>
                        <input type="text" name="emoji" value="<?php echo htmlspecialchars($editData['emoji'] ?? '🎙️'); ?>" style="width: 100px;">
                    </div>
                </div>

                <div class="form-group">
                    <label>Messaggio di Start (Benvenuto)</label>
                    <textarea name="start_message" rows="2"><?php echo htmlspecialchars($editData['start_message'] ?? 'Ciao %s! Benvenuto nel bot di...'); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Caption di Attesa</label>
                    <textarea name="waiting_caption" rows="2"><?php echo htmlspecialchars($editData['waiting_caption'] ?? 'Attendi un attimo %s...'); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Messaggio di Errore</label>
                    <textarea name="error_response" rows="2"><?php echo htmlspecialchars($editData['error_response'] ?? 'Ops! C\'è stato un problema...'); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Prefix Risposta Finale</label>
                    <textarea name="final_caption_prefix" rows="2"><?php echo htmlspecialchars($editData['final_caption_prefix'] ?? 'Ecco la risposta per %s:'); ?></textarea>
                </div>

                <div class="actions">
                    <button type="submit" name="save" class="btn btn-primary" style="padding: 1rem 2rem; font-size: 1rem;">Salva Configurazione</button>
                    <a href="manage_podcasts.php" class="btn btn-secondary" style="padding: 1rem 2rem;">Annulla</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Modal di anteprima in tempo reale (Quiz / News) -->
<div id="actionModal" class="modal">
    <div class="modal-content">
        <h2 id="modalTitle" style="margin-top:0; font-family:'Outfit', sans-serif; font-size:1.4rem;">Elaborazione in corso...</h2>
        <div id="modalBody">
            <p id="modalStatusText" style="color: var(--text-muted);">Contatto l'IA ed elaboro la richiesta...</p>
            <div id="actionResultPreview" style="display:none;"></div>
        </div>
        <div style="text-align: right; margin-top: 1.5rem;">
            <button type="button" class="btn btn-secondary" onclick="closeActionModal()">Chiudi</button>
        </div>
    </div>
</div>

<script>
// Toggle Asincrono per Checkbox Attivo / Anonimo / News
function toggleGroupStatus(targetId, field) {
    let action = 'toggle_quiz_active';
    if (field === 'quiz_anonymous') action = 'toggle_quiz_anonymous';
    else if (field === 'news_active') action = 'toggle_news_active';

    fetch(`manage_podcasts.php?action=${action}&target_id=${targetId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).catch(err => {
        console.error('Errore aggiornamento switch:', err);
    });
}

// Trigger Invio Immediato Quiz
function triggerSendQuiz(podcastId, podcastName) {
    openModal(`🎲 Generazione Quiz: ${podcastName}`, 'Generazione quiz con Gemini AI dal file YAML e invio Telegram sendPoll...');

    fetch(`manage_podcasts.php?action=send_quiz_now&podcast_id=${podcastId}`)
        .then(res => res.json())
        .then(data => {
            const preview = document.getElementById('actionResultPreview');
            document.getElementById('modalStatusText').style.display = 'none';
            preview.style.display = 'block';

            if (data.status === 'success') {
                document.getElementById('modalTitle').innerText = `✅ Quiz inviato con successo! (${data.sent_count} destinazioni)`;
                let html = `<div class="preview-box preview-box-quiz">
                    <strong style="color:#f8fafc; font-size:1.05rem;">${escapeHtml(data.quiz.question)}</strong>
                    <div style="margin-top:0.75rem;">`;
                
                data.quiz.options.forEach((opt, idx) => {
                    const isCorrect = idx === data.quiz.correct_option_id;
                    html += `<div class="quiz-option-item ${isCorrect ? 'quiz-option-correct' : ''}">
                        ${isCorrect ? '✓ ' : '• '} ${escapeHtml(opt)}
                    </div>`;
                });

                if (data.quiz.explanation) {
                    html += `<div style="margin-top:0.75rem; font-size:0.85rem; color:#94a3b8; font-style:italic;">💡 <strong>Spiegazione:</strong> ${escapeHtml(data.quiz.explanation)}</div>`;
                }
                html += `</div></div>`;
                html += `<div style="font-size:0.85rem; color:#94a3b8;"><strong>Destinatari:</strong> ${data.details.map(d => d.chat_title + ' (' + d.status + ')').join(', ')}</div>`;
                preview.innerHTML = html;
            } else {
                document.getElementById('modalTitle').innerText = `⚠️ Errore durante l'invio del Quiz`;
                preview.innerHTML = `<div class="alert" style="background:rgba(239,68,68,0.15); border-color:#ef4444; color:#f87171;">${escapeHtml(data.message || 'Errore sconosciuto')}</div>`;
            }
        })
        .catch(err => showErrorModal(err));
}

// Trigger Invio Immediato News
function triggerSendNews(podcastId, podcastName) {
    openModal(`📰 Generazione Rassegna News: ${podcastName}`, 'Scraping Google News RSS, estrazione immagine, link corto e invio Telegram...');

    fetch(`manage_podcasts.php?action=send_news_now&podcast_id=${podcastId}`)
        .then(res => res.json())
        .then(data => {
            const preview = document.getElementById('actionResultPreview');
            document.getElementById('modalStatusText').style.display = 'none';
            preview.style.display = 'block';

            if (data.status === 'success') {
                document.getElementById('modalTitle').innerText = `✅ News inviata con successo! (${data.sent_count} destinazioni)`;
                let html = `<div class="preview-box preview-box-news">`;
                
                if (data.news.image_url) {
                    html += `<div style="margin-bottom:0.75rem; text-align:center;">
                        <img src="${escapeHtml(data.news.image_url)}" alt="Copertina notizia" style="max-width:100%; max-height:220px; border-radius:0.5rem; object-fit:cover;">
                    </div>`;
                }

                html += `<div style="font-size:0.8rem; text-transform:uppercase; color:#f59e0b; font-weight:700; margin-bottom:0.5rem;">Fonte: ${escapeHtml(data.news.source_name)}</div>
                    <div style="font-size:0.95rem; line-height:1.6; white-space:pre-wrap;">${escapeHtml(data.news.editorial_post)}</div>`;

                if (data.news.short_url) {
                    html += `<div style="margin-top:0.75rem; font-size:0.85rem;">
                        <span style="color:#94a3b8;">Link Tracciato:</span> <a href="${escapeHtml(data.news.short_url)}" target="_blank" style="color:#38bdf8; font-weight:600;">${escapeHtml(data.news.short_url)}</a>
                    </div>`;
                }

                html += `</div>`;
                html += `<div style="font-size:0.85rem; color:#94a3b8;"><strong>Destinatari:</strong> ${data.details.map(d => d.chat_title + ' (' + d.status + ')').join(', ')}</div>`;
                preview.innerHTML = html;
            } else {
                document.getElementById('modalTitle').innerText = `⚠️ Errore durante l'invio delle News`;
                preview.innerHTML = `<div class="alert" style="background:rgba(239,68,68,0.15); border-color:#ef4444; color:#f87171;">${escapeHtml(data.message || 'Errore sconosciuto')}</div>`;
            }
        })
        .catch(err => showErrorModal(err));
}

function openModal(title, statusText) {
    const modal = document.getElementById('actionModal');
    document.getElementById('modalTitle').innerText = title;
    const st = document.getElementById('modalStatusText');
    st.style.display = 'block';
    st.innerText = statusText;
    const prev = document.getElementById('actionResultPreview');
    prev.style.display = 'none';
    prev.innerHTML = '';
    modal.style.display = 'flex';
}

function showErrorModal(err) {
    const prev = document.getElementById('actionResultPreview');
    document.getElementById('modalStatusText').style.display = 'none';
    prev.style.display = 'block';
    document.getElementById('modalTitle').innerText = `⚠️ Errore di Comunicazione`;
    prev.innerHTML = `<div class="alert" style="background:rgba(239,68,68,0.15); border-color:#ef4444; color:#f87171;">${escapeHtml(err.message)}</div>`;
}

function closeActionModal() {
    document.getElementById('actionModal').style.display = 'none';
    location.reload();
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>

</body>
</html>
