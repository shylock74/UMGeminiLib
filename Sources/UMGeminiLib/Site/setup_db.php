<?php
/**
 * setup_db.php
 * Inizializza la tabella 'podcasts' nel database MariaDB.
 */

require_once 'db.php';

try {
    // 1. Creazione Tabella
    $sqlTable = "CREATE TABLE IF NOT EXISTS podcasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(255) UNIQUE NOT NULL,
        username VARCHAR(100),
        yaml_file VARCHAR(255),
        podcast_name VARCHAR(255),
        experts TEXT,
        fallback_prefix TEXT,
        search_photo VARCHAR(255),
        final_photo VARCHAR(255),
        emoji VARCHAR(10),
        start_message TEXT,
        waiting_caption TEXT,
        error_response TEXT,
        final_caption_prefix TEXT
    )";
    
    $pdo->exec($sqlTable);
    echo "Tabella 'podcasts' verificata/creata correttamente.\n";

    // 2. Inserimento dati "Il vino lo porto io" (se non esistono)
    $sqlInsert = "INSERT IGNORE INTO podcasts 
    (token, username, yaml_file, podcast_name, experts, fallback_prefix, search_photo, final_photo, emoji, start_message, waiting_caption, error_response, final_caption_prefix)
    VALUES 
    (:token, :username, :yaml_file, :podcast_name, :experts, :fallback_prefix, :search_photo, :final_photo, :emoji, :start_message, :waiting_caption, :error_response, :final_caption_prefix)";

    $stmt = $pdo->prepare($sqlInsert);
    $stmt->execute([
        ':token' => '8466115311:AAEjB-dRka3zEqybZfZFPdjjXQFAVSIEj_c',
        ':username' => 'IVLPITest1_bot',
        ':yaml_file' => 'vinoKB.yaml',
        ':podcast_name' => 'Il vino lo porto io',
        ':experts' => 'del Sommelier Marco Barbetti e dello Chef/sommelier Gabriele Palermo',
        ':fallback_prefix' => 'Nel podcast non ci sono informazioni relative a questo vino o a questo piatto, questo è il risultato della mia ricerca: ',
        ':search_photo' => 'VinoBot_Search.jpg',
        ':final_photo' => 'VinoBot.jpg',
        ':emoji' => '🍷',
        ':start_message' => "Ciao %s! Sono il Sommelier AI del podcast 'Il vino lo porto io'. Chiedimi pure un consiglio su cosa abbinare al tuo prossimo piatto! 🍷",
        ':waiting_caption' => "Attendi un attimo %s, sto cercando le informazioni... 🍷",
        ':error_response' => "Ops! Ho avuto un piccolo problema tecnico nel versare il vino. Riprova tra poco! 🍷",
        ':final_caption_prefix' => "🍷 Ecco il mio consiglio per %s:"
    ]);

    echo "Dati del podcast del vino inseriti correttamente.\n";

} catch (Exception $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
}
