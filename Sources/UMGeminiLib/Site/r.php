<?php
/**
 * r.php
 * Endpoint per URL Shortener & Click Tracking
 * Reindirizza alla destinazione originale registrando i click sul database.
 */

require_once 'db.php';

$code = trim($_GET['c'] ?? '');

if (empty($code)) {
    header("Location: vino.php");
    exit;
}

try {
    ensureQuizTables($pdo);
    
    // Trova il target_url associato al codice
    $stmt = $pdo->prepare("SELECT id, target_url FROM short_links WHERE code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $link = $stmt->fetch();

    if ($link && !empty($link['target_url'])) {
        // Incrementa conteggio click
        $stmtUpdate = $pdo->prepare("UPDATE short_links SET clicks = clicks + 1, last_clicked_at = NOW() WHERE id = :id");
        $stmtUpdate->execute([':id' => $link['id']]);

        // Reindirizzamento trasparente
        header("Location: " . $link['target_url'], true, 302);
        exit;
    }
} catch (Exception $e) {
    // In caso di errore DB, ignora e fai redirect fallback
}

// Se non trovato, reindirizza alla home
header("Location: vino.php");
exit;
