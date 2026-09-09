<?php
/**
 * set_webhook.php
 * Utility per registrare l'URL del webhook su Telegram.
 */

$token = $_GET['token'] ?? null;
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
// Rimuove il nome dello script attuale dal path per ottenere la cartella base
$dir = dirname($_SERVER['PHP_SELF']);
$baseUrl = $protocol . "://" . $domain . $dir;

if (!$token) {
    die("Errore: Token mancante. Usa set_webhook.php?token=IL_TUO_TOKEN");
}

$webhookUrl = $baseUrl . "/podcastTelegram.php?token=" . $token;
$telegramApiUrl = "https://api.telegram.org/bot$token/setWebhook?url=" . urlencode($webhookUrl);

// Chiamata a Telegram
$response = @file_get_contents($telegramApiUrl);
$data = json_decode($response, true);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Configurazione Webhook</title>
    <style>
        body { font-family: sans-serif; background: #0f172a; color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; max-width: 500px; }
        .success { color: #22c55e; }
        .error { color: #ef4444; }
        pre { background: #000; padding: 1rem; border-radius: 0.5rem; text-align: left; overflow-x: auto; font-size: 0.8rem; }
        .btn { display: inline-block; margin-top: 1.5rem; padding: 0.75rem 1.5rem; background: #38bdf8; color: #000; text-decoration: none; border-radius: 0.5rem; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($data && $data['ok']): ?>
            <h1 class="success">✅ Webhook Impostato!</h1>
            <p>Telegram invierà ora i messaggi a:</p>
            <pre><?php echo htmlspecialchars($webhookUrl); ?></pre>
        <?php else: ?>
            <h1 class="error">❌ Errore</h1>
            <p>Non è stato possibile impostare il webhook. Verifica il token.</p>
            <pre><?php echo htmlspecialchars($response ?: 'Nessuna risposta da Telegram'); ?></pre>
        <?php endif; ?>
        
        <a href="manage_podcasts.php" class="btn">Torna alla Console</a>
    </div>
</body>
</html>
