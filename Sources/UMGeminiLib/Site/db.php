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
 * Assicura la presenza e l'allineamento della tabella quiz_targets
 */
function ensureQuizTables($pdo) {
    static $checked = false;
    if ($checked || !$pdo) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_targets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            podcast_id INT NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            chat_title VARCHAR(255),
            chat_type VARCHAR(50) DEFAULT 'group',
            is_active TINYINT(1) DEFAULT 1,
            is_anonymous TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_quiz_sent_at DATETIME NULL,
            UNIQUE KEY uniq_podcast_chat (podcast_id, chat_id)
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
?>


