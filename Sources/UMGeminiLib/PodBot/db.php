<?php
// db.php
$host = 'localhost';
$db   = 'kultiqhc_gpdb';
$user = 'kultiqhc_gpdb';
$pass = 'gAntani66;6!';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Errore di connessione: " . $e->getMessage()]);
    exit;
}

/**
 * Assicura la presenza e l'allineamento delle tabelle quiz_targets e sent_news
 */
function ensureQuizTables($pdo) {
    static $checked = false;
    if ($checked || !$pdo) return;
    try {
        // Tabella gruppi / destinazioni Telegram
        $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_targets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            podcast_id INT NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            chat_title VARCHAR(255),
            chat_type VARCHAR(50) DEFAULT 'group',
            is_active TINYINT(1) DEFAULT 1,
            is_anonymous TINYINT(1) DEFAULT 0,
            is_news_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_quiz_sent_at DATETIME NULL,
            last_news_sent_at DATETIME NULL,
            UNIQUE KEY uniq_podcast_chat (podcast_id, chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Verifica ed eventuale aggiunta colonne per installazioni esistenti
        try {
            $stmtCols = $pdo->query("SHOW COLUMNS FROM quiz_targets");
            $existingCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

            if (!in_array('is_news_active', $existingCols)) {
                $pdo->exec("ALTER TABLE quiz_targets ADD COLUMN is_news_active TINYINT(1) DEFAULT 1 AFTER is_anonymous");
            }
            if (!in_array('last_news_sent_at', $existingCols)) {
                $pdo->exec("ALTER TABLE quiz_targets ADD COLUMN last_news_sent_at DATETIME NULL AFTER last_quiz_sent_at");
            }
        } catch (\Exception $colEx) {
            // Ignora se non accessibile
        }

        // Tabella archivio notizie già inviate
        $pdo->exec("CREATE TABLE IF NOT EXISTS sent_news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            podcast_id INT NOT NULL,
            article_title VARCHAR(500) NOT NULL,
            article_url VARCHAR(500) NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_podcast_url (podcast_id, article_url(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Tabella link corti tracciati
        $pdo->exec("CREATE TABLE IF NOT EXISTS short_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(16) UNIQUE NOT NULL,
            podcast_id INT NOT NULL,
            title VARCHAR(255),
            target_url TEXT NOT NULL,
            clicks INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_clicked_at DATETIME NULL,
            INDEX idx_podcast_code (podcast_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $checked = true;
    } catch (\Exception $e) {
        // Ignora se la tabella esiste già o non abbiamo permessi DDL runtime
    }
}


/**
 * Registra o aggiorna automaticamente una chat/gruppo Telegram nel database
 */
function registerTelegramChat($pdo, $podcastId, $chatId, $chatTitle, $chatType = 'group') {
    if (!$pdo || empty($chatId) || empty($podcastId)) return;
    try {
        ensureQuizTables($pdo);
        $stmt = $pdo->prepare("INSERT INTO quiz_targets 
            (podcast_id, chat_id, chat_title, chat_type, is_active, is_anonymous) 
            VALUES (:podcast_id, :chat_id, :chat_title, :chat_type, 1, 0)
            ON DUPLICATE KEY UPDATE 
            chat_title = VALUES(chat_title), 
            chat_type = VALUES(chat_type)");
        $stmt->execute([
            ':podcast_id' => $podcastId,
            ':chat_id' => (string)$chatId,
            ':chat_title' => $chatTitle ?: ('Chat ' . $chatId),
            ':chat_type' => $chatType
        ]);
    } catch (\Exception $e) {
        // Ignora errori di registrazione chat per non bloccare il webhook
    }
}

/**
 * Crea o recupera un link corto tracciato per un URL
 */
function createTrackedLink($pdo, $podcastId, $targetUrl, $title = '') {
    if (empty($targetUrl) || !$pdo) return $targetUrl;
    ensureQuizTables($pdo);

    try {
        $stmt = $pdo->prepare("SELECT code FROM short_links WHERE podcast_id = :podcast_id AND target_url = :target_url LIMIT 1");
        $stmt->execute([':podcast_id' => $podcastId, ':target_url' => $targetUrl]);
        $existing = $stmt->fetch();
        if ($existing && !empty($existing['code'])) {
            $code = $existing['code'];
        } else {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $stmtInsert = $pdo->prepare("INSERT INTO short_links (code, podcast_id, title, target_url) VALUES (:code, :podcast_id, :title, :target_url)");
            $stmtInsert->execute([
                ':code' => $code,
                ':podcast_id' => $podcastId,
                ':title' => $title ?: substr($targetUrl, 0, 100),
                ':target_url' => $targetUrl
            ]);
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'ulti.media';
        $scriptDir = isset($_SERVER['PHP_SELF']) ? rtrim(dirname($_SERVER['PHP_SELF']), '/\\') : '/UMGemini';
        return 'https://' . $host . $scriptDir . '/r.php?c=' . $code;
    } catch (\Exception $e) {
        return $targetUrl;
    }
}
?>



