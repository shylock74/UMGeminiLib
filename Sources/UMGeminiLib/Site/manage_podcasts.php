<?php
/**
 * manage_podcasts.php
 * Console di gestione CRUD per i podcast e gestione gruppi per il Quiz Automatico Telegram
 */

require_once 'db.php';
require_once 'config.php';
require_once 'QuizCore.php';

ensureQuizTables($pdo);

$message = '';
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// --- GESTIONE AZIONI AJAX / POST PER IL MODULO QUIZ ---

// 1. Toggle Attivo / Disattivo per Gruppo Quiz (AJAX o GET)
if ($action === 'toggle_quiz_active' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    $stmt = $pdo->prepare("UPDATE quiz_targets SET is_active = NOT is_active WHERE id = :id");
    $stmt->execute([':id' => $targetId]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: manage_podcasts.php?msg=" . urlencode("Stato del gruppo aggiornato!"));
    exit;
}

// 2. Toggle Anonimo / Pubblico per Gruppo Quiz (AJAX o GET)
if ($action === 'toggle_quiz_anonymous' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    $stmt = $pdo->prepare("UPDATE quiz_targets SET is_anonymous = NOT is_anonymous WHERE id = :id");
    $stmt->execute([':id' => $targetId]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }
    header("Location: manage_podcasts.php?msg=" . urlencode("Modalità anonimato aggiornata!"));
    exit;
}

// 3. Aggiunta Manuale Gruppo Quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_quiz_target'])) {
    $pId = (int)($_POST['podcast_id'] ?? 1);
    $cId = trim($_POST['chat_id'] ?? '');
    $cTitle = trim($_POST['chat_title'] ?? '') ?: ('Chat ' . $cId);
    $cType = $_POST['chat_type'] ?? 'group';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;

    if (!empty($cId)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO quiz_targets (podcast_id, chat_id, chat_title, chat_type, is_active, is_anonymous) 
                VALUES (:podcast_id, :chat_id, :chat_title, :chat_type, :is_active, :is_anonymous)
                ON DUPLICATE KEY UPDATE 
                chat_title = VALUES(chat_title), 
                chat_type = VALUES(chat_type), 
                is_active = VALUES(is_active), 
                is_anonymous = VALUES(is_anonymous)");
            $stmt->execute([
                ':podcast_id' => $pId,
                ':chat_id' => $cId,
                ':chat_title' => $cTitle,
                ':chat_type' => $cType,
                ':is_active' => $isActive,
                ':is_anonymous' => $isAnonymous
            ]);
            header("Location: manage_podcasts.php?msg=" . urlencode("Gruppo aggiunto con successo alla lista quiz!"));
            exit;
        } catch (Exception $e) {
            $message = "Errore inserimento gruppo: " . $e->getMessage();
        }
    }
}

// 4. Eliminazione Gruppo Quiz
if ($action === 'delete_quiz_target' && isset($_GET['target_id'])) {
    $targetId = (int)$_GET['target_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM quiz_targets WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
        header("Location: manage_podcasts.php?msg=" . urlencode("Gruppo rimosso dalla lista quiz."));
        exit;
    } catch (Exception $e) {
        $message = "Errore eliminazione gruppo: " . $e->getMessage();
    }
}

// 5. Test Invio Immediato Quiz (Pulsante "Invia Quiz Ora")
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
            throw new Exception("Nessun gruppo attivo selezionato per questo podcast. Abilita almeno un gruppo prima di inviare.");
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

            // Chiamata Telegram sendPoll
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
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// --- LOGICA CRUD PODCAST ---

// Salvataggio Podcast (Nuovo o Modifica)
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

// Eliminazione Podcast
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

// Caricamento dati per Modifica
$editData = null;
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $editData = $stmt->fetch();
}

// Caricamento Lista Podcast e Gruppi Quiz
$podcasts = $pdo->query("SELECT * FROM podcasts ORDER BY id ASC")->fetchAll();
$quizTargets = $pdo->query("SELECT qt.*, p.podcast_name, p.emoji as podcast_emoji FROM quiz_targets qt LEFT JOIN podcasts p ON qt.podcast_id = p.id ORDER BY qt.podcast_id ASC, qt.id DESC")->fetchAll();

$displayMsg = $_GET['msg'] ?? $message;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Podcast & Quiz Control Center</title>
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
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
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
            max-width: 1100px;
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
            font-size: 1.4rem;
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
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
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
            padding: 0.55rem 1.1rem;
            border-radius: 0.6rem;
            border: none;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
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

        /* Quiz Groups Table & Toggles */
        .table-responsive {
            overflow-x: auto;
        }

        .groups-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .groups-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--card-border);
        }

        .groups-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }

        .groups-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-group { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .badge-channel { background: rgba(168, 85, 247, 0.15); color: #c084fc; }
        .badge-private { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }

        /* Switch Toggle Component */
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.15);
            transition: .3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--success);
        }

        input:checked + .slider.slider-purple {
            background-color: var(--purple);
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

        .code-box {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.4);
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.85rem;
            color: #38bdf8;
        }

        /* Modal styling for Quiz preview */
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
            max-width: 600px;
            width: 90%;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .quiz-preview-box {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin: 1rem 0;
            border-left: 4px solid var(--wine);
        }

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
        <h1>🍷 Podcast & Quiz Control Center</h1>
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
                                <a href="?action=edit&id=<?php echo $p['id']; ?>" class="btn btn-secondary btn-sm">Modifica</a>
                                <a href="set_webhook.php?token=<?php echo urlencode($p['token']); ?>" class="btn btn-webhook btn-sm" title="Configura Webhook su Telegram">Webhook</a>
                                <a href="?action=delete&id=<?php echo $p['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Sei sicuro di voler eliminare questo podcast?')">Elimina</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sezione 2: Modulo Quiz Automatico & Gruppi Telegram -->
        <div class="card">
            <div class="section-title">
                <span>🎯 Destinazioni Quiz Telegram (Gruppi & Canali)</span>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">
                    I gruppi vengono censiti automaticamente quando il bot riceve messaggi o viene aggiunto
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
                                <th>Nome Gruppo / Canale</th>
                                <th>Tipo</th>
                                <th>Chat ID</th>
                                <th style="text-align:center;">Quiz Attivo</th>
                                <th style="text-align:center;">Anonimo</th>
                                <th>Ultimo Invio</th>
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
                                            <input type="checkbox" <?php echo $qt['is_active'] ? 'checked' : ''; ?> onchange="toggleGroupStatus(<?php echo $qt['id']; ?>, 'active')">
                                            <span class="slider"></span>
                                        </label>
                                    </td>
                                    <td style="text-align:center;">
                                        <label class="switch" title="Quiz Anonimo (spento = Pubblico)">
                                            <input type="checkbox" <?php echo $qt['is_anonymous'] ? 'checked' : ''; ?> onchange="toggleGroupStatus(<?php echo $qt['id']; ?>, 'anonymous')">
                                            <span class="slider slider-purple"></span>
                                        </label>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);">
                                        <?php echo $qt['last_quiz_sent_at'] ? date('d/m/Y H:i', strtotime($qt['last_quiz_sent_at'])) : 'Mai inviato'; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="?action=delete_quiz_target&target_id=<?php echo $qt['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Rimuovere questo gruppo dalla lista quiz?')">
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
                </form>
            </div>

            <!-- Informazioni Cron Job -->
            <div style="margin-top: 2rem; padding: 1.25rem; background: rgba(0,0,0,0.25); border-radius: 0.75rem; border: 1px solid var(--card-border);">
                <div style="font-weight: 600; margin-bottom: 0.5rem; color: #38bdf8;">⏰ Istruzioni per il Cron Job Automatico</div>
                <div style="font-size: 0.875rem; color: var(--text-muted); line-height: 1.6;">
                    Per programmare l'invio periodico del Quiz tramite Cron, puoi configurare una delle seguenti modalità nel tuo server o servizio di cron:<br>
                    • <strong>Esecuzione CLI (Crontab server):</strong> <span class="code-box">php <?php echo realpath(__DIR__ . '/quizCron.php'); ?> 1</span><br>
                    • <strong>Esecuzione Web (Webhook Cron):</strong> <span class="code-box">https://<?php echo $_SERVER['HTTP_HOST'] ?? 'tuosito.com'; ?><?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/quizCron.php?secret=<?php echo CRON_SECRET; ?>&podcast_id=1</span>
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

<!-- Modal di anteprima / invio Quiz in tempo reale -->
<div id="quizModal" class="modal">
    <div class="modal-content">
        <h2 id="modalTitle" style="margin-top:0; font-family:'Outfit', sans-serif; font-size:1.4rem;">Generazione Quiz in Corso...</h2>
        <div id="modalBody">
            <p id="modalStatusText" style="color: var(--text-muted);">Sto contattando l'IA per formulare il quiz basato sul file YAML e inviarlo via Telegram sendPoll...</p>
            <div id="quizResultPreview" style="display:none;"></div>
        </div>
        <div style="text-align: right; margin-top: 1.5rem;">
            <button type="button" class="btn btn-secondary" onclick="closeQuizModal()">Chiudi</button>
        </div>
    </div>
</div>

<script>
// Toggle Asincrono per Checkbox Attivo / Anonimo
function toggleGroupStatus(targetId, field) {
    const action = field === 'active' ? 'toggle_quiz_active' : 'toggle_quiz_anonymous';
    fetch(`manage_podcasts.php?action=${action}&target_id=${targetId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).catch(err => {
        console.error('Errore aggiornamento switch:', err);
    });
}

// Trigger Invio Immediato Quiz
function triggerSendQuiz(podcastId, podcastName) {
    const modal = document.getElementById('quizModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalStatusText = document.getElementById('modalStatusText');
    const quizResultPreview = document.getElementById('quizResultPreview');

    modalTitle.innerText = `🎲 Generazione Quiz: ${podcastName}`;
    modalStatusText.style.display = 'block';
    modalStatusText.innerText = 'Elaborazione in corso con Gemini AI (ingestione YAML e generazione Poll)...';
    quizResultPreview.style.display = 'none';
    quizResultPreview.innerHTML = '';
    modal.style.display = 'flex';

    fetch(`manage_podcasts.php?action=send_quiz_now&podcast_id=${podcastId}`)
        .then(res => res.json())
        .then(data => {
            modalStatusText.style.display = 'none';
            quizResultPreview.style.display = 'block';

            if (data.status === 'success') {
                modalTitle.innerText = `✅ Quiz inviato con successo! (${data.sent_count} destinazioni)`;
                let html = `<div class="quiz-preview-box">
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

                html += `<div style="font-size:0.85rem; color:#94a3b8;">
                    <strong>Destinatari:</strong> ${data.details.map(d => d.chat_title + ' (' + d.status + ')').join(', ')}
                </div>`;

                quizResultPreview.innerHTML = html;
            } else {
                modalTitle.innerText = `⚠️ Errore durante l'invio del Quiz`;
                quizResultPreview.innerHTML = `<div class="alert" style="background:rgba(239,68,68,0.15); border-color:#ef4444; color:#f87171;">
                    ${escapeHtml(data.message || 'Errore sconosciuto')}
                </div>`;
            }
        })
        .catch(err => {
            modalStatusText.style.display = 'none';
            quizResultPreview.style.display = 'block';
            modalTitle.innerText = `⚠️ Errore di Rete`;
            quizResultPreview.innerHTML = `<div class="alert" style="background:rgba(239,68,68,0.15); border-color:#ef4444; color:#f87171;">
                ${escapeHtml(err.message)}
            </div>`;
        });
}

function closeQuizModal() {
    document.getElementById('quizModal').style.display = 'none';
    location.reload();
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>

</body>
</html>

