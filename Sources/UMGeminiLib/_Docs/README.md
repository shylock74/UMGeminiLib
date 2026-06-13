# UMGeminiLib & Sommelier AI

A comprehensive solution for AI-powered wine pairing and Gemini API integration, consisting of a high-performance Swift library and a specialized web/Telegram bot application.

---

## 1. UMGeminiLib (Swift Package)

A lightweight, efficient Swift library for interacting with Google's Gemini AI models. It supports text generation, vision tasks, and image generation.

### Core Components

#### `UMGeminiLite`
The main entry point for the library. It handles authentication, request throttling, and model communication.

*   **Models Supported**:
    *   Gemini 3.1 (Pro/Flash Lite)
    *   Gemini 3.0 Pro
    *   Gemini 2.5 (Pro/Flash/Flash Lite)
*   **Initialization**:
    ```swift
    let gemini = UMGeminiLite(model: .gemini31FlashLitePreview, apiKey: "YOUR_API_KEY")
    ```

### Main API Methods

#### Text & Vision Generation
```swift
func generateText(textPrompt: String, images: [CIImage] = [], audioData: [...]) async throws -> String
```
Generates a response based on text, multiple images, or audio data.

#### Image Analysis
*   **`describe(image:)`**: Returns keywords describing the image.
*   **`title(for:)`**: Generates a concise title for an image.

#### Image Generation
```swift
func generateImageWithNanoBanana(model: ImageModel, textPrompt: String, with images: [CIImage], aspectRatio: AspectRatio, size: Size) async throws -> CIImage
```
Supports multiple image generation models (Imagen 4 series, Nano Banana) with configurable aspect ratios.

---

## 2. Sommelier AI (Web & Telegram Application)

A specialized implementation of `UMGeminiLib` logic ported to PHP for a web backend and a Telegram bot. It serves as a digital sommelier for the "Il vino lo porto io" podcast.

### Architecture

The application is built on a modular PHP architecture:
- **`SommelierCore.php`**: The central brain. It handles Knowledge Base (KB) loading, system prompt construction, and LLM fallback logic.
- **`vinoBackend.php`**: REST API endpoint for the web frontend.
- **`vinoTelegram.php`**: Secure webhook for the Telegram Bot integration.
- **`vinoKB.yaml`**: A specialized dataset containing expert pairings from Sommelier Marco Barbetti and Chef Gabriele Palermo.

### Key Features
- **Podcast-First Knowledge**: Prioritizes expert advice from the podcast dataset.
- **Smart Fallback**: If the requested pairing is not in the KB, it uses general sommelier knowledge but explicitly notifies the user.
- **Rich Telegram UI**: Uses visual feedback (typing status, search images) and personalized responses.
- **Deduplication**: Prevents multiple responses to the same Telegram request during slow processing.

### API Specifications (Web Backend)

**Endpoint**: `vinoBackend.php`
**Method**: `POST` (JSON body)

**Input Parameters**:
| Parameter | Type | Description |
| :--- | :--- | :--- |
| `domanda` | String | The wine or food pairing request (Required). |
| `model` | String | The Gemini model ID (Optional, defaults to Gemini 3.1 Flash Lite). |

**Output Format**:
```json
{
    "status": "success",
    "domanda": "...",
    "model": "...",
    "output": "...",
    "usage": { "promptTokenCount": 0, "candidatesTokenCount": 0, "totalTokenCount": 0 }
}
```

---

## Technical Performance & Best Practices
- **Rate Limiting**: Built-in 2.0s throttling to respect API quotas.
- **Off-Main-Thread Processing**: Designed to be used with `async/await` in Swift and asynchronous-friendly patterns in PHP to prevent blocking.
- **Modular Prompting**: System instructions are centralized in `SommelierCore.php` for easy maintenance.
