# UMGeminiLib

A high-performance Swift library, CLI tool, and AI automation suite powered by Google's **Gemini** and **Imagen** APIs.

`UMGeminiLib` provides a native `async`/`await` Swift client for multimodal generation (text, image, audio), a companion command-line interface (`UMGeminiCLI`), and a full-stack PHP & Telegram AI bot platform with automated knowledge base Q&A, interactive quizzes, RSS news curation, and link click tracking.

---

## Table of Contents

- [Overview](#overview)
- [Architecture & Components](#architecture--components)
- [Swift Library (`UMGeminiLib`)](#swift-library-umgeminilib)
  - [Requirements & Platforms](#requirements--platforms)
  - [Installation (SwiftPM & Xcode)](#installation-swiftpm--xcode)
  - [Initialization](#initialization)
  - [Public API Reference & Usage Examples](#public-api-reference--usage-examples)
    - [Text & Multimodal Generation](#text--multimodal-generation)
    - [Multi-Turn Chat History](#multi-turn-chat-history)
    - [Token Counting](#token-counting)
    - [Image Generation & Editing](#image-generation--editing)
    - [Image Description & Title Generation](#image-description--title-generation)
  - [Supported Models & Enums](#supported-models--enums)
- [Command-Line Interface (`UMGeminiCLI`)](#command-line-interface-umgeminicli)
  - [Build & Setup](#build--setup)
  - [Command Arguments & Flags](#command-arguments--flags)
  - [CLI Execution Examples](#cli-execution-examples)
- [AI Bot & Automation Platform (`Site/`)](#ai-bot--automation-platform-site)
  - [Knowledge Base & Sommelier Chatbot](#knowledge-base--sommelier-chatbot)
  - [Automated AI Quiz Module](#automated-ai-quiz-module)
  - [Daily News Editorial & OpenGraph Extraction](#daily-news-editorial--opengraph-extraction)
  - [URL Shortener & Click Tracking (`r.php`)](#url-shortener--click-tracking-rphp)
  - [Web Management Console (`manage_podcasts.php`)](#web-management-console-manage_podcastsphp)
  - [Database Schema & Migrations](#database-schema--migrations)
- [License & Authors](#license--authors)

---

## Overview

`UMGeminiLib` bridges modern Apple platform development (macOS, iOS, tvOS, visionOS) and backend web automation with Google Gemini:

1. **Swift Library**: Native Swift 5/6 asynchronous client wrapping Gemini text/multimodal endpoints and Imagen/NanoBanana image synthesis.
2. **CLI Executable**: Standalone terminal utility for text generation, batch media analysis, and image rendering.
3. **Web & Bot Automation Suite**: Ready-to-deploy PHP backend and Telegram Bot platform designed for podcast knowledge bases, automated quiz distribution via native polls, daily enology RSS news generation with image attachment, and click-tracked URL redirection.

---

## Architecture & Components

```
UMGeminiLib/
├── Package.swift                             # Swift Package manifest
├── README.md                                 # Project documentation
├── Sources/
│   ├── UMGeminiLib/                          # Swift Framework Target
│   │   ├── UMGeminiLite.swift                # Core Gemini client, text & chat APIs
│   │   ├── UMGeminiLite + Images.swift       # Nano Banana & Imagen extensions
│   │   └── Site/                             # Web & Telegram Bot Platform
│   │       ├── config.php / config.local.php # Configuration & API keys
│   │       ├── db.php / setup_db.php         # MySQL connection & migrations
│   │       ├── Gemini.php / OpenAI.php       # PHP AI client implementations
│   │       ├── PodcastCore.php               # Podcast KB loader & prompt engine
│   │       ├── SommelierCore.php             # Sommelier persona & Q&A logic
│   │       ├── QuizCore.php                  # AI quiz generator from YAML KB
│   │       ├── NewsCore.php                  # Google News RSS scraper & editor
│   │       ├── quizCron.php                  # Scheduled quiz poll broadcaster
│   │       ├── newsCron.php                  # Scheduled daily news broadcaster
│   │       ├── r.php                         # URL shortener & click redirector
│   │       ├── manage_podcasts.php           # Web admin console & live triggers
│   │       ├── vinoTelegram.php              # Telegram webhook handler
│   │       ├── vino.php / vinoBackend.php    # Web chat client interface
│   │       └── vinoKB.yaml                   # Wine knowledge base dataset
│   └── UMGeminiCLI/                          # Swift CLI Executable Target
│       └── main.swift                        # Command-line argument runner
```

---

## Swift Library (`UMGeminiLib`)

### Requirements & Platforms

| Platform | Minimum Deployment Target |
|---|---|
| **macOS** | 12.0+ |
| **iOS** | 15.0+ |
| **tvOS** | 15.0+ |
| **visionOS** | 1.0+ |
| **Swift Toolchain** | Swift 6.3+ (Swift 5 Language Mode) |

*(Note: `watchOS` is excluded because `CoreImage` is not supported on watchOS).*

### Installation (SwiftPM & Xcode)

Add `UMGeminiLib` to your `Package.swift`:

```swift
dependencies: [
    .package(url: "https://github.com/shylock74/UMGeminiLib.git", branch: "main")
]
```

Or in Xcode: **File → Add Package Dependencies...** and enter the repository URL.

---

### Initialization

```swift
import UMGeminiLib

// Initialize with a specific model and API Key
let gemini = UMGeminiLite(model: .gemini37Flash, apiKey: "YOUR_GEMINI_API_KEY")
```

---

### Public API Reference & Usage Examples

#### Text & Multimodal Generation

Generates text from prompts, optionally attaching input images (`CIImage`) and audio files.

```swift
func generateText(
    textPrompt: String,
    images: [CIImage] = [],
    audioData: [(data: Data, mimeType: String)] = []
) async throws -> String
```

**Example:**

```swift
// Pure text prompt
let response = try await gemini.generateText(textPrompt: "Explain malolactic fermentation in wine.")
print(response)

// Multimodal with image
if let image = CIImage(contentsOf: URL(fileURLWithPath: "wine_label.jpg")) {
    let analysis = try await gemini.generateText(
        textPrompt: "Identify this wine and recommend food pairings.",
        images: [image]
    )
    print(analysis)
}
```

#### Multi-Turn Chat History

Processes conversational turns with role-based message history.

```swift
func generateChat(
    history: [UMChatElement],
    temperature: Float? = nil
) async throws -> String
```

**Example:**

```swift
let history: [UMChatElement] = [
    UMChatElement(role: "user", textPrompt: "I like structured red wines."),
    UMChatElement(role: "model", textPrompt: "Great! Nebbiolo, Cabernet Sauvignon, and Aglianico are great options."),
    UMChatElement(role: "user", textPrompt: "Which of these pairs best with braised beef?")
]

let answer = try await gemini.generateChat(history: history)
print(answer)
```

#### Token Counting

Calculates the exact token usage before sending a request.

```swift
func countTokens(
    textPrompt: String,
    images: [CIImage] = [],
    audioData: [(data: Data, mimeType: String)] = []
) async throws -> Int
```

---

#### Image Generation & Editing

Generate new images or edit existing ones using Gemini Nano Banana or Google Imagen models.

```swift
// Nano Banana (Gemini Image Modality)
func generateImageWithNanoBanana(
    model: ImageModel = .nanoBanana2,
    textPrompt: String,
    with ciImages: [CIImage] = [],
    aspectRatio: AspectRatio = .ar_16_9,
    size: Size = .k1
) async throws -> CIImage

// Imagen 4 Direct API
func generateImageWithImagen(
    textPrompt: String,
    aspectRatio: AspectRatio = .ar_1_1,
    numberOfImages: Int = 1
) async throws -> [CIImage]
```

**Example:**

```swift
let generatedImage = try await gemini.generateImageWithNanoBanana(
    model: .nanoBanana2,
    textPrompt: "A vineyard in Tuscany during golden hour, cinematic lighting",
    aspectRatio: .ar_16_9,
    size: .k1
)
```

#### Image Description & Title Generation

```swift
// Generates a descriptive analysis of a visual asset
func describe(image: CIImage, prompt: String? = nil) async throws -> String

// Generates a concise title for an image
func title(for image: CIImage, prompt: String? = nil) async throws -> String
```

---

### Supported Models & Enums

#### Text Models (`UMGeminiLite.Model`)

| Enum Case | Display Name | API Identifier |
|---|---|---|
| `.gemini37Flash` *(default)* | Gemini 3.7 Flash | `gemini-3.7-flash` |
| `.gemini36Flash` | Gemini 3.6 Flash | `gemini-3.6-flash` |
| `.gemini35Flash` | Gemini 3.5 Flash | `gemini-3.5-flash` |
| `.gemini31FlashLite` | Gemini 3.1 Flash Lite | `gemini-3.1-flash-lite` |
| `.gemini31ProPreviw` | Gemini 3.1 Pro Preview | `gemini-3.1-pro-preview` |

#### Image Models (`UMGeminiLite.ImageModel`)

| Enum Case | Display Name | API Identifier | Referenceable (Img2Img) |
|---|---|---|---|
| `.nanoBanana2` *(default)* | Nano Banana 2 | `gemini-3.1-flash-image-preview` | Yes |
| `.nanoBananaPro` | Nano Banana Pro | `gemini-3-pro-image-preview` | Yes |
| `.nanoBanana` | Nano Banana | `gemini-2.5-flash-image` | Yes |
| `.imagen40` | Imagen 4 | `imagen-4.0-generate-001` | No |
| `.imagen40Ultra` | Imagen 4 Ultra | `imagen-4.0-ultra-generate-001` | No |
| `.imagen40Fast` | Imagen 4 Fast | `imagen-4.0-fast-generate-001` | No |

#### Aspect Ratios (`UMGeminiLite.AspectRatio`)
- `ar_1_1` (1:1), `ar_16_9` (16:9), `ar_9_16` (9:16), `ar_4_3` (4:3), `ar_3_4` (3:4), `ar_3_2` (3:2), `ar_2_3` (2:3), `ar_21_9` (21:9).

#### Output Sizes (`UMGeminiLite.Size`)
- `k1` (1K), `k2` (2K), `k4` (4K).

---

## Command-Line Interface (`UMGeminiCLI`)

`UMGeminiCLI` is a pre-configured terminal executable that enables text synthesis, multimodal inspection, and image rendering from the command line.

### Build & Setup

Build the CLI executable using Swift Package Manager:

```bash
swift build -c release
```

Save your API key once into persistent local storage:

```bash
swift run UMGeminiCLI --set-key "YOUR_GEMINI_API_KEY"
```

---

### Command Arguments & Flags

| Flag | Type | Description | Default |
|---|---|---|---|
| `--mode` | `string` | Execution mode: `text` or `image` (Required). | — |
| `--prompt` | `string` | The text prompt for generation (Required). | — |
| `--api-key` | `string` | Gemini API key (optional if saved with `--set-key`). | Stored Key |
| `--set-key` | `string` | Saves the API key to `UserDefaults` and exits. | — |
| `--model` | `string` | Gemini text model code name. | `gemini-3.7-flash` |
| `--image-model` | `string` | Model name for image mode. | `gemini-3.1-flash-image-preview` |
| `--images` | `string` | Comma-separated image paths or URLs for multimodal input. | — |
| `--audio` | `string` | Comma-separated audio paths or URLs (`mp3`, `wav`, `m4a`, `ogg`, `flac`). | — |
| `--aspect-ratio` | `string` | Image aspect ratio (`16:9`, `1:1`, `9:16`, `4:3`, `3:2`). | `16:9` |
| `--size`, `--image-size` | `string` | Output image resolution: `1K`, `2K`, `4K`. | `1K` |
| `--output` | `string` | Output file path for generated PNG (Required in `image` mode). | — |

---

### CLI Execution Examples

#### 1. Text Generation

```bash
swift run UMGeminiCLI --mode text --prompt "List 3 rules for pairing sparkling wine with desserts."
```

#### 2. Multimodal Image Analysis

```bash
swift run UMGeminiCLI --mode text \
  --prompt "What grape variety and vintage is visible on this bottle?" \
  --images "label.jpg,cork.png"
```

#### 3. Image Generation with Nano Banana

```bash
swift run UMGeminiCLI --mode image \
  --prompt "A glass of Barolo on an ancient oak barrel, warm candle light" \
  --aspect-ratio "16:9" \
  --size "2K" \
  --output "barolo.png"
```

---

## AI Bot & Automation Platform (`Site/`)

The `Site/` directory contains an end-to-end backend platform designed for podcast community engagement, automated Telegram broadcasts, and knowledge base retrieval.

```
Site/
├── config.php                 # Global config, model selection & secret keys
├── config.local.php           # Protected local API keys (Git-ignored)
├── db.php                     # PDO MySQL connection, table checks & helpers
├── setup_db.php               # Standalone DB initialization script
├── Gemini.php / OpenAI.php    # Multi-backend LLM clients with thinking model support
├── PodcastCore.php            # Contextual YAML parser and prompt assembler
├── SommelierCore.php          # Sommelier personality and Q&A engine
├── QuizCore.php               # AI quiz generator parsing YAML knowledge bases
├── NewsCore.php               # Google News RSS scraper & editorial writer
├── quizCron.php               # Scheduled cron job for native Telegram Polls
├── newsCron.php               # Scheduled cron job for daily wine news & photos
├── r.php                      # URL shortener & click tracking redirector
├── manage_podcasts.php        # Admin dashboard with live trigger modals & stats
└── vinoTelegram.php           # Production Telegram webhook router
```

---

### Knowledge Base & Sommelier Chatbot

- **Grounded Responses**: Ingests structured episode catalogs and wine data (`vinoKB.yaml`) directly into the prompt context to answer user questions using accurate citations.
- **Multimodal Web & Telegram Interface**: Accepts text queries, audio/voice messages (with automatic speech recognition), and wine label photos.
- **Telegram Bot Support**: Supports private chats, group mentions, inline searches, and automatic target group discovery.

---

### Automated AI Quiz Module

- **On-the-Fly Generation (`QuizCore.php`)**: Dynamically extracts topics from the podcast YAML knowledge base and instructs Gemini 3.7 Flash to formulate a 4-option quiz with 1 correct answer and a pedagogical explanation.
- **Telegram Constraints Compliance**: Strictly enforces Telegram `sendPoll` limits (question $\le 300$ chars, options $\le 100$ chars, explanation $\le 200$ chars).
- **Scheduled Poll Broadcast (`quizCron.php`)**:
  - CLI: `php quizCron.php 1`
  - Web Webhook: `https://your-domain.com/quizCron.php?secret=YOUR_CRON_SECRET&podcast_id=1`
- **Configurable Anonymity**: Groups can be individually configured for public or anonymous voting via the admin web console.

---

### Daily News Editorial & OpenGraph Extraction

- **Automated RSS Ingestion (`NewsCore.php`)**: Queries Google News RSS with curated enology filters (`vino OR enologia OR viticoltura OR sommelier OR cantine`).
- **De-duplication**: Filters out news already dispatched by tracking history in the `sent_news` database table.
- **OpenGraph & Media Scraping**: Extracts `og:image`, `twitter:image`, or RSS enclosures from the source article, falling back to podcast visuals.
- **First-Person Editorial**: Writes a structured sommelier commentary tailored for Telegram.
- **Scheduled Dispatch (`newsCron.php`)**:
  - CLI: `php newsCron.php 1`
  - Web Webhook: `https://your-domain.com/newsCron.php?secret=YOUR_CRON_SECRET&podcast_id=1`
  - Dispatches as `sendPhoto` with caption if text $\le 1024$ characters, or photo + full markdown message if longer.

---

### URL Shortener & Click Tracking (`r.php`)

All source links generated in news articles are automatically converted into tracked short URLs (`r.php?c=XXXXXX`).

- **Redirection**: Instant HTTP 302 redirect to the original article.
- **Metrics**: Automatically logs click counts and last access timestamps into the `short_links` table.

---

### Web Management Console (`manage_podcasts.php`)

The modern web management console provides real-time control over the platform:

1. **Podcast Settings**: Edit tokens, usernames, prompt behaviors, and fallback captions.
2. **Telegram Destinations Table**:
   - Toggle switches for **Quiz Active**, **Anonymous Quiz**, and **Daily News Active**.
   - Manual target addition (Group / Supergroup / Channel / Private).
3. **Link Tracker & Click Statistics**:
   - Real-time table displaying short link codes, original URLs, total click badges, and last clicked dates.
4. **Interactive Action Modals**:
   - **🎲 Invia Quiz Ora**: Triggers real-time AI quiz generation with live question/option preview and delivery report.
   - **📰 Invia News Ora**: Triggers real-time RSS scraping, editorial compilation, and delivery report.

---

### Database Schema & Migrations

Automatic schema initialization and safe version migrations are handled transparently by `ensureQuizTables($pdo)`:

```sql
-- Podcasts Configuration Table
CREATE TABLE podcasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    username VARCHAR(100),
    yaml_file VARCHAR(255) NOT NULL,
    podcast_name VARCHAR(255) NOT NULL,
    experts VARCHAR(255),
    fallback_prefix TEXT,
    search_photo VARCHAR(255),
    final_photo VARCHAR(255),
    emoji VARCHAR(10),
    start_message TEXT,
    waiting_caption TEXT,
    error_response TEXT,
    final_caption_prefix TEXT
);

-- Target Groups & Channels
CREATE TABLE quiz_targets (
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
);

-- Dispatched News History (De-duplication)
CREATE TABLE sent_news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    podcast_id INT NOT NULL,
    article_title VARCHAR(500) NOT NULL,
    article_url VARCHAR(500) NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_podcast_url (podcast_id, article_url(191))
);

-- Short Links & Click Tracking
CREATE TABLE short_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(16) UNIQUE NOT NULL,
    podcast_id INT NOT NULL,
    title VARCHAR(255),
    target_url TEXT NOT NULL,
    clicks INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_clicked_at DATETIME NULL,
    INDEX idx_podcast_code (podcast_id, code)
);
```

---

## License & Authors

- **Author**: Ulti.Media / Alex Raccuglia
- **Language & Frameworks**: Swift (Package Manager), PHP, MySQL, Telegram Bot API, Google Gemini API.
- **License**: MIT License.
