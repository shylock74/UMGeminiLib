<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gemini Text Generator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Outfit:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0a0a0c;
            --card-bg: #16161a;
            --accent-color: #6366f1;
            --accent-hover: #4f46e5;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #2d2d35;
            --input-bg: #1f1f23;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-primary);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 800px;
            background: var(--card-bg);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease;
        }

        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #fff 0%, #818cf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p.subtitle {
            color: var(--text-secondary);
            margin-bottom: 32px;
            font-weight: 300;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        select, textarea {
            width: 100%;
            padding: 16px;
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        button {
            width: 100%;
            padding: 16px;
            background-color: var(--accent-color);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }

        button:hover {
            background-color: var(--accent-hover);
        }

        button:active {
            transform: scale(0.98);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .result-container {
            margin-top: 32px;
            padding-top: 32px;
            border-top: 1px solid var(--border-color);
            display: none;
        }

        .result-title {
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .result-content {
            background-color: var(--input-bg);
            padding: 20px;
            border-radius: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            border-left: 4px solid var(--accent-color);
        }

        .loader {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .error-message {
            color: #ef4444;
            margin-top: 12px;
            font-size: 0.9rem;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Gemini AI</h1>
        <p class="subtitle">Text-to-text generation powered by Google Gemini</p>

        <form id="geminiForm">
            <div class="form-group">
                <label for="model">Model</label>
                <select id="model" name="model">
                    <?php foreach ($GEMINI_MODELS as $code => $name): ?>
                        <option value="<?php echo $code; ?>" <?php echo ($code === DEFAULT_MODEL) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="prompt">Prompt</label>
                <textarea id="prompt" name="prompt" placeholder="Enter your instruction here..." required></textarea>
            </div>

            <button type="submit" id="submitBtn">
                <span id="btnText">Generate Response</span>
                <div class="loader" id="loader"></div>
            </button>
            <div id="errorMessage" class="error-message"></div>
        </form>

        <div class="result-container" id="resultContainer">
            <div class="result-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                AI Response
            </div>
            <div class="result-content" id="resultContent"></div>
        </div>
    </div>

    <script>
        const form = document.getElementById('geminiForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const loader = document.getElementById('loader');
        const resultContainer = document.getElementById('resultContainer');
        const resultContent = document.getElementById('resultContent');
        const errorMessage = document.getElementById('errorMessage');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // UI State: Loading
            submitBtn.disabled = true;
            btnText.style.display = 'none';
            loader.style.display = 'block';
            errorMessage.style.display = 'none';
            resultContainer.style.display = 'none';

            const formData = new FormData(form);
            const data = {
                prompt: formData.get('prompt'),
                model: formData.get('model')
            };

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (response.ok) {
                    resultContent.textContent = result.output;
                    resultContainer.style.display = 'block';
                } else {
                    throw new Error(result.message || 'Something went wrong');
                }
            } catch (error) {
                errorMessage.textContent = error.message;
                errorMessage.style.display = 'block';
            } finally {
                // UI State: Reset
                submitBtn.disabled = false;
                btnText.style.display = 'block';
                loader.style.display = 'none';
            }
        });
    </script>
</body>
</html>
