<?php
/**
 * Frontend per il Sommelier AI - "Il vino lo porto io"
 * Design premium con glassmorphism e palette colori vinaccia/oro.
 */
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Il vino lo porto io - Sommelier AI</title>
    
    <!-- SEO Optimization -->
    <meta name="description" content="Chiedi al nostro Sommelier AI consigli su abbinamenti vino e cibo basati sul podcast 'Il vino lo porto io'.">
    <meta property="og:title" content="Il vino lo porto io - Sommelier AI">
    <meta property="og:description" content="Esplora gli abbinamenti perfetti tra vino e cucina gourmet.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --wine-primary: #4a0e0e;
            --wine-dark: #2d0a0a;
            --wine-light: #8b1e1e;
            --gold: #c19a6b;
            --cream: #fdf6e3;
            --glass-bg: rgba(22, 22, 26, 0.85);
            --glass-border: rgba(255, 255, 255, 0.1);
            --accent-glow: rgba(193, 154, 107, 0.3);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('assets/img/background.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: var(--cream);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow-x: hidden;
        }

        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 40px;
            padding: 60px;
            width: 100%;
            max-width: 900px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.8);
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.8s ease-out;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
            opacity: 0.1;
            pointer-events: none;
        }

        header {
            text-align: center;
            margin-bottom: 50px;
        }

        h1 {
            font-family: 'Playfair Display', serif;
            font-size: 4rem;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #fff 0%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1.5px;
            font-weight: 700;
        }

        .tagline {
            font-size: 1.1rem;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 5px;
            font-weight: 700;
            opacity: 0.9;
        }

        .debug-info {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 20px;
            font-size: 0.75rem;
            color: var(--gold);
            opacity: 0.5;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .input-group {
            margin-bottom: 40px;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 15px;
            font-size: 1rem;
            color: var(--gold);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        textarea {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 25px;
            color: var(--cream);
            font-size: 1.2rem;
            font-family: inherit;
            min-height: 180px;
            resize: none;
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            line-height: 1.6;
        }

        textarea:focus {
            outline: none;
            border-color: var(--gold);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 30px rgba(193, 154, 107, 0.2);
        }

        .actions {
            display: flex;
            justify-content: center;
        }

        button {
            background: linear-gradient(135deg, var(--wine-primary) 0%, var(--wine-dark) 100%);
            color: white;
            border: 1px solid var(--gold);
            padding: 20px 60px;
            border-radius: 60px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            gap: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        button:hover {
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.5);
            border-color: white;
        }

        button:active {
            opacity: 0.8;
        }

        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .result-section {
            margin-top: 50px;
            display: none;
            border-top: 1px solid var(--glass-border);
            padding-top: 40px;
            animation: fadeIn 0.6s ease-out;
        }

        .result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 25px;
            color: var(--gold);
            font-weight: 700;
            font-size: 1.3rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            width: 100%;
        }

        .model-badge {
            font-size: 0.7rem;
            background: rgba(193, 154, 107, 0.1);
            padding: 4px 12px;
            border-radius: 20px;
            color: var(--gold);
            text-transform: none;
            letter-spacing: 0.5px;
            font-weight: 400;
        }

        .token-info {
            display: flex;
            justify-content: center;
            gap: 15px;
            font-size: 0.75rem;
            color: var(--gold);
            opacity: 0.6;
            margin-top: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .result-box {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 25px;
            padding: 40px;
            line-height: 2;
            font-size: 1.2rem;
            border-left: 5px solid var(--gold);
            color: rgba(253, 246, 227, 0.95);
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }

        .secondary-btn {
            background: transparent;
            color: var(--gold);
            border: 1px solid var(--gold);
            padding: 14px 35px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .secondary-btn:hover {
            background: rgba(193, 154, 107, 0.1);
        }

        .copy-btn {
            padding: 8px 15px;
            font-size: 0.8rem;
            border-radius: 12px;
            background: rgba(193, 154, 107, 0.1);
            color: var(--gold);
            border: 1px solid rgba(193, 154, 107, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .copy-btn:hover {
            background: rgba(193, 154, 107, 0.2);
            border-color: var(--gold);
        }

        .cost-info {
            color: #4CAF50;
            font-weight: 600;
        }

        .pricing-link {
            color: var(--gold);
            text-decoration: none;
            border-bottom: 1px dotted var(--gold);
            margin-left: 5px;
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .pricing-link:hover {
            opacity: 1;
        }

        /* Error Modal */
        .error-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            display: none; /* Inizialmente nascosto */
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.4s ease;
        }

        .error-card {
            background: var(--glass-bg);
            border: 1px solid #ff4d4d;
            border-radius: 30px;
            padding: 40px;
            max-width: 450px;
            width: 90%;
            text-align: center;
            box-shadow: 0 30px 60px rgba(0,0,0,0.5);
        }

        .error-card h2 {
            color: #ff4d4d;
            margin-bottom: 15px;
            font-family: 'Playfair Display', serif;
        }

        .error-card p {
            margin-bottom: 30px;
            line-height: 1.6;
            opacity: 0.9;
        }

        .error-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn-error-ok {
            background: transparent;
            border: 1px solid var(--cream);
            color: var(--cream);
            padding: 12px 30px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-error-retry {
            background: #ff4d4d;
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
        }

        .loader {
            display: none;
            width: 28px;
            height: 28px;
            border: 4px solid rgba(193, 154, 107, 0.3);
            border-radius: 50%;
            border-top-color: var(--gold);
            animation: spin 1s linear infinite;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }


        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .wine-icon {
            font-size: 1.8rem;
            filter: drop-shadow(0 2px 5px rgba(0,0,0,0.5));
        }

        @media (max-width: 768px) {
            .glass-card {
                padding: 40px 25px;
                border-radius: 30px;
            }
            h1 {
                font-size: 2.8rem;
            }
            button {
                padding: 18px 40px;
                width: 100%;
                justify-content: center;
            }
        }

        /* Micro-animations */
        textarea::placeholder {
            color: rgba(253, 246, 227, 0.3);
            transition: opacity 0.3s ease;
        }

        textarea:focus::placeholder {
            opacity: 0.1;
        }
    </style>
</head>
<body>
    <div class="glass-card" id="mainCard">
        <header>
            <div class="tagline">Il vino lo porto io</div>
            <h1 id="mainTitle">Sommelier AI</h1>
        </header>

        <?php
            $apiKeyPrefix = substr(GEMINI_API_KEY, 0, 4);
            $currentModel = $GEMINI_MODELS[DEFAULT_MODEL] ?? DEFAULT_MODEL;
        ?>
        <div class="debug-info">
            <span>Modello: <?php echo $currentModel; ?></span>
            <span>API Key: <?php echo $apiKeyPrefix; ?>***</span>
        </div>

        <form id="wineForm">
            <div class="input-group">
                <label for="domanda">Inserisci la tua richiesta</label>
                <textarea id="domanda" name="domanda" placeholder="Esempio: Vorrei un consiglio per abbinare un vino rosso strutturato a un brasato di manzo con castagne..." required></textarea>
            </div>

            <div class="actions">
                <button type="submit" id="submitBtn">
                    <span id="btnText">Consulta il Sommelier</span>
                    <div class="loader" id="loader"></div>
                    <span class="wine-icon">🍷</span>
                </button>
            </div>
        </form>

        <div class="result-section" id="resultSection">
            <div class="result-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        Il Responso del Sommelier
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button id="copyBtn" class="copy-btn" title="Copia risposta">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        <span>Copia</span>
                    </button>
                    <span class="model-badge" id="resultModel"></span>
                </div>
            </div>
            <div class="result-box" id="resultBox"></div>
            <div class="token-info" id="tokenInfo" style="display: none;">
                <span>Token Domanda: <span id="promptTokens">-</span></span>
                <span>Token Risposta: <span id="replyTokens">-</span></span>
                <span>Costo Stimato: <span id="estimatedCost" class="cost-info">-</span> <a href="https://ai.google.dev/gemini-api/docs/pricing?hl=it" target="_blank" class="pricing-link">Info Prezzi</a></span>
            </div>
            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="secondary-btn" id="newQuestionBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    Nuova Domanda
                </button>
            </div>
        </div>
    </div>

    <div class="error-overlay" id="errorOverlay">
        <div class="error-card">
            <h2>Ouch! C'è un intoppo</h2>
            <p id="errorMessage">Si è verificato un errore durante la consultazione del Sommelier. Per favore, riprova più tardi.</p>
            <div class="error-actions">
                <button class="btn-error-ok" id="errorOkBtn">OK</button>
                <button class="btn-error-retry" id="errorRetryBtn">Riprova</button>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('wineForm');
        const submitBtn = document.getElementById('submitBtn');
        const loader = document.getElementById('loader');
        const btnText = document.getElementById('btnText');
        const resultSection = document.getElementById('resultSection');
        const resultBox = document.getElementById('resultBox');
        const newQuestionBtn = document.getElementById('newQuestionBtn');
        const domandaTextarea = document.getElementById('domanda');
        const copyBtn = document.getElementById('copyBtn');
        const estimatedCost = document.getElementById('estimatedCost');

        // Modelli e relativi prezzi per 1.000.000 di token (Maggio 2026)
        const modelPricing = {
            'gemini-3.1-pro-preview': { input: 2.00, output: 12.00 },
            'gemini-3.1-flash-lite-preview': { input: 0.25, output: 1.50 },
            'gemini-3.1-flash-lite': { input: 0.25, output: 1.50 },
            'gemini-3-pro-preview': { input: 2.00, output: 12.00 },
            'gemini-2.5-pro': { input: 1.25, output: 10.00 },
            'gemini-2.5-flash': { input: 0.30, output: 2.50 },
            'gemini-2.5-flash-lite': { input: 0.15, output: 1.00 },
            'default': { input: 0.25, output: 1.50 }
        };

        function calculateCost(model, inputTokens, outputTokens) {
            const pricing = modelPricing[model] || modelPricing['default'];
            const inputCost = (inputTokens / 1000000) * pricing.input;
            const outputCost = (outputTokens / 1000000) * pricing.output;
            const totalCost = inputCost + outputCost;
            
            if (totalCost < 0.0001) return "< $0.0001";
            return "$" + totalCost.toFixed(4);
        }

        copyBtn.addEventListener('click', async () => {
            const textToCopy = resultBox.innerText;
            try {
                await navigator.clipboard.writeText(textToCopy);
                const originalContent = copyBtn.innerHTML;
                copyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg><span>Copiato!</span>';
                copyBtn.style.borderColor = '#4CAF50';
                copyBtn.style.color = '#4CAF50';
                
                setTimeout(() => {
                    copyBtn.innerHTML = originalContent;
                    copyBtn.style.borderColor = '';
                    copyBtn.style.color = '';
                }, 2000);
            } catch (err) {
                console.error('Errore durante la copia:', err);
            }
        });

        domandaTextarea.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.requestSubmit();
            }
        });

        newQuestionBtn.addEventListener('click', () => {
            resultSection.style.display = 'none';
            domandaTextarea.value = '';
            domandaTextarea.focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        const errorOverlay = document.getElementById('errorOverlay');
        const errorMessage = document.getElementById('errorMessage');
        const errorOkBtn = document.getElementById('errorOkBtn');
        const errorRetryBtn = document.getElementById('errorRetryBtn');

        errorOkBtn.addEventListener('click', () => {
            errorOverlay.style.display = 'none';
        });

        errorRetryBtn.addEventListener('click', () => {
            errorOverlay.style.display = 'none';
            form.requestSubmit();
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // UI State: Processing
            submitBtn.disabled = true;
            loader.style.display = 'block';
            btnText.textContent = 'Degustazione...';
            resultSection.style.display = 'none';
            errorOverlay.style.display = 'none';

            const formData = new FormData(form);
            const data = {
                domanda: formData.get('domanda')
            };

            try {
                const response = await fetch('vinoBackend.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (response.ok && result.status === 'success') {
                    // Success logic
                    resultBox.innerHTML = result.output.replace(/\n/g, '<br>');
                    document.getElementById('resultModel').textContent = result.model;
                    
                    if (result.usage) {
                        document.getElementById('promptTokens').textContent = result.usage.promptTokenCount;
                        document.getElementById('replyTokens').textContent = result.usage.candidatesTokenCount;
                        
                        const cost = calculateCost(result.model, result.usage.promptTokenCount, result.usage.candidatesTokenCount);
                        estimatedCost.textContent = cost;
                        
                        document.getElementById('tokenInfo').style.display = 'flex';
                    } else {
                        document.getElementById('tokenInfo').style.display = 'none';
                    }

                    resultSection.style.display = 'block';
                    
                    // Smooth scroll to results
                    setTimeout(() => {
                        resultSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                } else {
                    throw new Error(result.message || 'Errore nella comunicazione con il Sommelier.');
                }
            } catch (error) {
                // Custom error handling
                errorMessage.textContent = error.message;
                errorOverlay.style.display = 'flex';
            } finally {
                // UI State: Reset
                submitBtn.disabled = false;
                loader.style.display = 'none';
                btnText.textContent = 'Consulta il Sommelier';
            }
        });

    </script>
</body>
</html>
