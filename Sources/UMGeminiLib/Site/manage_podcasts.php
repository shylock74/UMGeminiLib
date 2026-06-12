<?php
/**
 * manage_podcasts.php
 * Console di gestione CRUD per i podcast (Database MariaDB)
 */

require_once 'db.php';

$message = '';
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// --- LOGICA CRUD ---

// 1. Salvataggio (Nuovo o Modifica)
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
            // UPDATE
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
            // INSERT
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

// 2. Eliminazione
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

// 3. Caricamento dati per Modifica
$editData = null;
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $editData = $stmt->fetch();
}

// 4. Caricamento Lista
$podcasts = $pdo->query("SELECT * FROM podcasts ORDER BY id DESC")->fetchAll();

$displayMsg = $_GET['msg'] ?? $message;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Podcast Control Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --accent: #38bdf8;
            --accent-glow: rgba(56, 189, 248, 0.3);
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #ef4444;
            --success: #22c55e;
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
            max-width: 1000px;
            margin: 0 auto;
        }

        h1 {
            font-weight: 600;
            font-size: 2rem;
            margin-bottom: 2rem;
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .card {
            background: var(--card);
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 2rem;
        }

        .podcast-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .podcast-item {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 0.75rem;
            padding: 1.25rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: transform 0.2s, border-color 0.2s;
            position: relative;
        }

        .podcast-item:hover {
            transform: translateY(-4px);
            border-color: var(--accent);
        }

        .podcast-emoji { font-size: 1.5rem; }
        .podcast-name { font-weight: 600; font-size: 1.1rem; margin: 0.5rem 0; }
        .podcast-user { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem; }
        
        .actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: none;
            font-family: inherit;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background: var(--accent);
            color: #000;
            font-weight: 600;
        }
        .btn-primary:hover { box-shadow: 0 0 15px var(--accent-glow); }

        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: var(--text); }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.2); }

        .btn-webhook { background: rgba(168, 85, 247, 0.1); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.2); }
        .btn-webhook:hover { background: #a855f7; color: white; }

        .btn-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }

        /* Form Styling */
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.875rem; }
        input, textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            padding: 0.75rem;
            color: var(--text);
            font-family: inherit;
            transition: border-color 0.2s;
        }
        input:focus, textarea:focus {
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
            padding: 3rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-actions">
        <h1>🎙️ Podcast Control Center</h1>
        <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary">+ Aggiungi Podcast</a>
        <?php else: ?>
            <a href="manage_podcasts.php" class="btn btn-secondary">← Torna alla Lista</a>
        <?php endif; ?>
    </div>

    <?php if ($displayMsg): ?>
        <div class="alert"><?php echo htmlspecialchars($displayMsg); ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="card">
            <?php if (empty($podcasts)): ?>
                <div class="empty-state">Nessun podcast configurato. Inizia aggiungendone uno!</div>
            <?php else: ?>
                <div class="podcast-grid">
                    <?php foreach ($podcasts as $p): ?>
                        <div class="podcast-item">
                            <div class="podcast-emoji"><?php echo htmlspecialchars($p['emoji']); ?></div>
                            <div class="podcast-name"><?php echo htmlspecialchars($p['podcast_name']); ?></div>
                            <div class="podcast-user"><?php echo htmlspecialchars($p['username']); ?></div>
                            <div class="actions">
                                <a href="?action=edit&id=<?php echo $p['id']; ?>" class="btn btn-secondary">Modifica</a>
                                <a href="set_webhook.php?token=<?php echo urlencode($p['token']); ?>" class="btn btn-webhook" title="Configura Webhook su Telegram">Set Webhook</a>
                                <a href="?action=delete&id=<?php echo $p['id']; ?>" class="btn btn-danger" onclick="return confirm('Sei sicuro?')">Elimina</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Form Add/Edit -->
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

</body>
</html>
