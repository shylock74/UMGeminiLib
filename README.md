# UMGeminiLib

A lightweight Swift package that wraps Google's **Gemini** and **Imagen** APIs behind a clean `async`/`await` API. Ships with a bundled command-line tool (`UMGeminiCLI`) for automation and shell scripting.

- **Text generation** (with optional image and audio input)
- **Image generation** (Nano Banana & Imagen 4 model families)
- **Multi-modal prompts** (text + images + audio in a single call)
- **Image-aware helpers** (`describe(image:)`, `title(for:)`)
- Cross-platform: **macOS · iOS · tvOS · visionOS**

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Library Usage](#library-usage)
    - [Initialisation](#initialisation)
    - [Text Generation](#text-generation)
    - [Text from Images](#text-from-images)
    - [Text from Audio](#text-from-audio)
    - [Chat Generation](#chat-generation)
    - [Stateful Interactions](#stateful-interactions)
    - [Image Generation](#image-generation)
    - [Image-to-Image](#image-to-image)
    - [Image Description & Title](#image-description--title)
    - [Rate-Limit Helper](#rate-limit-helper)
- [CLI Usage (`UMGeminiCLI`)](#cli-usage-umgeminicli)
    - [Build](#build)
    - [Saving the API Key](#saving-the-api-key)
    - [CLI Examples](#cli-examples)
    - [CLI Arguments Reference](#cli-arguments-reference)
- [API Reference](#api-reference)
- [Error Handling](#error-handling)
- [Project Structure](#project-structure)
- [Credits & License](#credits--license)

---

## Requirements

| | |
|---|---|
| Swift | 6.3 or later (Swift 5 language mode) |
| macOS | 12.0+ |
| iOS | 15.0+ |
| tvOS | 15.0+ |
| visionOS | 1.0+ |
| Gemini API key | [aistudio.google.com/apikey](https://aistudio.google.com/apikey) |

> **Note:** `watchOS` is not supported because `CoreImage` — used for image encoding — is unavailable on that platform.

---

## Installation

### Swift Package Manager (`Package.swift`)

```swift
dependencies: [
    .package(url: "https://github.com/<your-org>/UMGeminiLib.git", from: "1.0.0")
]
```

Then add the product to the target that needs it:

```swift
.target(
    name: "YourTarget",
    dependencies: [
        .product(name: "UMGeminiLib", package: "UMGeminiLib")
    ]
)
```

### Xcode

1. **File → Add Package Dependencies…**
2. Paste the repository URL.
3. Select the **UMGeminiLib** product and add it to your target.

---

## Quick Start

```swift
import UMGeminiLib

let gemini = UMGeminiLite(model: .gemini25Flash, apiKey: "YOUR_API_KEY")
let reply  = try await gemini.generateText(textPrompt: "Say hi in one sentence.")
print(reply)
```

---

## Library Usage

### Initialisation

`UMGeminiLite` is a `Codable`, `Equatable` value type. It carries the chosen text model, image model, and API key.

```swift
let gemini = UMGeminiLite(
    model: .gemini25Pro,        // text model (optional, default: .gemini31FlashLitePreview)
    apiKey: "YOUR_API_KEY"      // your Gemini API key
)
```

You can also switch the image model after init:

```swift
var gemini = UMGeminiLite(apiKey: "YOUR_API_KEY")
gemini.imageModel = .nanoBananaPro
```

### Text Generation

```swift
let answer = try await gemini.generateText(
    textPrompt: "Explain quantum gravity in one paragraph."
)
```

### Text from Images

Send one or more `CIImage` instances alongside the prompt — the selected text model will reason over them.

```swift
import CoreImage

guard let image = CIImage(contentsOf: URL(fileURLWithPath: "/tmp/photo.jpg")) else { return }

let description = try await gemini.generateText(
    textPrompt: "Describe what is happening in this picture.",
    images: [image]
)
```

### Text from Audio

Pass raw audio bytes together with the correct MIME type:

```swift
let data = try Data(contentsOf: URL(fileURLWithPath: "/tmp/interview.mp3"))
let audio: [(data: Data, mimeType: String)] = [(data, "audio/mp3")]

let summary = try await gemini.generateText(
    textPrompt: "Summarise the key points discussed.",
    audioData: audio
)
```

Supported MIME types: `audio/mp3`, `audio/wav`, `audio/m4a`, `audio/ogg`, `audio/mpeg`, `audio/flac`, `audio/aac`.

### Chat Generation

For multi-turn conversations, use `UMChatElement` to define roles (`"user"`, `"model"`, `"system"`) and optionally attach images/audio.

```swift
let elements = [
    UMChatElement(role: "user", textPrompt: "Hi, I'm Bob."),
    UMChatElement(role: "model", textPrompt: "Hello Bob!"),
    UMChatElement(role: "user", textPrompt: "What is my name?")
]

let reply = try await gemini.generateChat(elements: elements)
print(reply) // "Your name is Bob."
```

### Stateful Interactions

The `generateInteraction` method interacts with the `v1beta/interactions` endpoint. It returns an `interactionId` that you can pass back in subsequent calls to maintain context automatically on the server side.

```swift
// First interaction
let (reply1, id1) = try await gemini.generateInteraction(
    input: "I have 2 dogs in my house."
)

// Second interaction (passing previous interaction ID)
let (reply2, id2) = try await gemini.generateInteraction(
    input: "How many paws are in my house?",
    previousInteractionId: id1
)
print(reply2) // "There are 8 paws..."
```

### Image Generation

Generate a new image from a text prompt:

```swift
var gemini = UMGeminiLite(apiKey: "YOUR_API_KEY")
gemini.imageModel = .nanoBanana

let image = try await gemini.generateImageWithNanoBanana(
    model: gemini.imageModel,
    textPrompt: "A dog astronaut on the moon, photorealistic",
    with: [],                    // no source images
    aspectRatio: .ar_16_9,
    size: .k2                    // output resolution (optional, default: .k1)
)
```

Writing the resulting `CIImage` to disk as PNG:

```swift
let ctx = CIContext()
let colorSpace = image.colorSpace ?? CGColorSpaceCreateDeviceRGB()
if let png = ctx.pngRepresentation(of: image, format: .RGBA8, colorSpace: colorSpace, options: [:]) {
    try png.write(to: URL(fileURLWithPath: "/tmp/moon_dog.png"))
}
```

### Image-to-Image

Nano Banana models (`nanoBanana`, `nanoBanana2`, `nanoBananaPro`) accept source images as visual references. Only these models are **referenceable** — Imagen 4 models are not.

```swift
let source = CIImage(contentsOf: URL(fileURLWithPath: "/tmp/style.jpg"))!

let remix = try await gemini.generateImageWithNanoBanana(
    model: .nanoBananaPro,
    textPrompt: "Reinterpret this image as an impressionist painting",
    with: [source],
    aspectRatio: .ar_1_1,
    size: .k1
)
```

To get the list of referenceable models at runtime:

```swift
let refModels = UMGeminiLite.ImageModel.referenceableModelList
```

### Image Description & Title

Two convenience helpers wrap common prompts:

```swift
let keywords = try await gemini.describe(image: image)   // 10–30 comma-separated keywords
let title    = try await gemini.title(for: image)        // very short title
```

### Rate-Limit Helper

If you hit Gemini rate limits in tight loops, the built-in throttle enforces a minimum 2-second gap between calls:

```swift
for prompt in prompts {
    await UMGeminiLite.delayRequest()
    let text = try await gemini.generateText(textPrompt: prompt)
    print(text)
}
```

---

## CLI Usage (`UMGeminiCLI`)

The executable target `UMGeminiCLI` exposes the library to the terminal. It writes the **result to stdout** and all logs/warnings/errors to **stderr**, so it composes cleanly in shell pipelines and scripts.

### Build

```bash
swift build -c release
```

The binary will be at:

```
.build/release/UMGeminiCLI
```

Copy it somewhere on your `$PATH` (e.g. `/usr/local/bin/UMGeminiCLI`) or run it in place.

### Saving the API Key

Save your Gemini API key once — it is persisted via `UserDefaults` so every subsequent call works without `--api-key`:

```bash
./UMGeminiCLI --set-key "YOUR_API_KEY"
```

### CLI Examples

**1. Simple text generation**

```bash
./UMGeminiCLI \
  --mode text \
  --model "gemini-3.1-flash-lite-preview" \
  --prompt "Explain concisely how quantum gravity works."
```

**2. Text generation based on input images**

```bash
./UMGeminiCLI \
  --mode text \
  --model "gemini-2.5-pro" \
  --images "/Users/alex/photo.jpg,https://example.com/img2.png" \
  --prompt "Describe what is in these images."
```

**3. Text generation based on audio input**

```bash
./UMGeminiCLI \
  --mode text \
  --model "gemini-2.5-pro" \
  --audio "/Users/alex/interview.mp3" \
  --prompt "Summarize the key points discussed in this audio recording."
```

**4. Generating a new image (16:9) and saving it**

```bash
./UMGeminiCLI \
  --mode image \
  --prompt "A dog astronaut on the moon, photorealistic style" \
  --image-model "gemini-3.1-flash-image-preview" \
  --aspect-ratio "16:9" \
  --output "/Users/alex/Desktop/moon_dog.png"
```

### CLI Arguments Reference

| Flag | Required | Default | Description |
|------|----------|---------|-------------|
| `--mode <text\|image>` | ✔︎ | — | Generation mode |
| `--prompt <text>` | ✔︎ | — | Instruction for the model. Quote strings with spaces. |
| `--api-key <key>` | ✘* | — | Gemini API key |
| `--set-key <key>` | ✘ | — | Persist the key and exit |
| `--model <codename>` | ✘ | `gemini-2.5-flash-lite` | Text model codename |
| `--image-model <codename>` | ✘ | `gemini-3.1-flash-image-preview` | Image model codename |
| `--aspect-ratio <ratio>` | ✘ | `16:9` | `1:1`, `9:16`, `16:9`, `3:4`, `4:3`, `2:3`, `3:2`, `21:9` |
| `--size <resolution>` | ✘ | `1K` | Output resolution: `1K`, `2K`, `4K` (or `1 K`, `2 K`, `4 K`) |
| `--images <url1,url2,…>` | ✘ | — | Comma-separated list of local paths or HTTP URLs |
| `--audio <url1,url2,…>` | ✘ | — | Comma-separated list of local paths or HTTP URLs |
| `--output <path.png>` | ✔︎ (when `--mode image`) | — | Destination for the generated PNG |

\* Required unless a key has been saved via `--set-key`.

---

## API Reference

### `UMGeminiLite`

```swift
public struct UMGeminiLite: Codable, Equatable {

    public static let apiKeyStorageKey: String
    public static let ultiMedia: UMGeminiLite

    public var model: Model
    public var imageModel: ImageModel
    public var apiKey: String

    public init(model: Model = .gemini31FlashLitePreview, apiKey: String = "")

    // Text
    public func generateText(textPrompt: String,
                             images: [CIImage] = [],
                             audioData: [(data: Data, mimeType: String)] = []) async throws -> String

    // Chat & Stateful
    public func generateChat(elements: [UMChatElement]) async throws -> String
    
    public func generateInteraction(input textPrompt: String,
                                    images: [CIImage] = [],
                                    audioData: [(data: Data, mimeType: String)] = [],
                                    previousInteractionId: String? = nil) async throws -> (text: String, interactionId: String)

    // Images
    public func generateImageWithNanoBanana(model: ImageModel,
                                            textPrompt: String,
                                            with images: [CIImage],
                                            aspectRatio: AspectRatio,
                                            size: Size) async throws -> CIImage

    public func generateImageWithGemini(model: ImageModel,
                                        textPrompt: String,
                                        sourceImagesData: [(data: Data, mimeType: String)],
                                        aspectRatio: AspectRatio,
                                        size: Size) async throws -> Data

    public func generatePNGDataWithNanoBanana(textPrompt: String,
                                              model: ImageModel,
                                              ciImages: [CIImage],
                                              aspectRatio: AspectRatio,
                                              size: Size) async throws -> Data

    // Convenience
    public func describe(image: CIImage) async throws -> String?
    public func title(for image: CIImage) async throws -> String?

    // Helpers
    public func imagesAndMimeType(for ciImageList: [CIImage],
                                  format: ImageFormat = .png) throws -> [(data: Data, mimeType: String)]

    public static func delayRequest() async
    public static func startup()
}
```

### `UMGeminiLite.Model`

| Case | `codeName` |
|------|------------|
| `.gemini25Flash` | `gemini-2.5-flash` |
| `.gemini25FlashLite` | `gemini-2.5-flash-lite` |
| `.gemini25Pro` | `gemini-2.5-pro` |
| `.gemini30ProPreview` | `gemini-3-pro-preview` |
| `.gemini31ProPreviw` | `gemini-3.1-pro-preview` |
| `.gemini31FlashLitePreview` | `gemini-3.1-flash-lite-preview` |

Construct from a raw codename:

```swift
let m = UMGeminiLite.Model(codeName: "gemini-2.5-pro")   // -> .gemini25Pro
```

### `UMGeminiLite.ImageModel`

| Case | `modelName` | Referenceable |
|------|-------------|---------------|
| `.imagen40` | `imagen-4.0-generate-001` | ✘ |
| `.imagen40Ultra` | `imagen-4.0-ultra-generate-001` | ✘ |
| `.imagen40Fast` | `imagen-4.0-fast-generate-001` | ✘ |
| `.nanoBanana` | `gemini-2.5-flash-image` | ✔︎ |
| `.nanoBanana2` | `gemini-3.1-flash-image-preview` | ✔︎ |
| `.nanoBananaPro` | `gemini-3-pro-image-preview` | ✔︎ |

*Referenceable* means the model accepts source images as input (image-to-image).

### `UMGeminiLite.AspectRatio`

| Case | `displayName` / `promptString` |
|------|-------------------------------|
| `.ar_1_1` | `1:1` |
| `.ar_9_16` | `9:16` |
| `.ar_16_9` | `16:9` |
| `.ar_3_4` | `3:4` |
| `.ar_4_3` | `4:3` |
| `.ar_2_3` | `2:3` |
| `.ar_3_2` | `3:2` |
| `.ar_21_9` | `21:9` |

Parse from a string: `UMGeminiLite.AspectRatio(ratioString: "16:9")`.

### `UMGeminiLite.Size`

| Case | `displayName` / `promptString` |
|------|-------------------------------|
| `.k1` | `1 K` / `1K` |
| `.k2` | `2 K` / `2K` |
| `.k4` | `4 K` / `4K` |

### `UMGeminiLite.ImageFormat`

Output format used when encoding a `CIImage`:

- `.png`
- `.jpeg(compression: CGFloat = 0.8)`

### Request / Response Types

All types below are `public` and live inside `UMGeminiLite`. They mirror the Gemini REST schema and are exposed so callers can build or inspect raw payloads if they need to.

`GeminiRequest`, `GeminiGenerationConfig`, `GeminiImageConfig`, `GeminiContent`, `GeminiPart`, `GeminiInlineData`, `GeminiResponse`, `GeminiCandidate`, `GeminiUsageMetadata`.

---

## Error Handling

All calls are `throws` and use Swift's standard error machinery. You may encounter:

| Error | Meaning |
|-------|---------|
| `UMGeminiLite.ImageError.imageGenerationFailed` | Image returned by Gemini could not be decoded into a `CIImage`. |
| `URLError.badURL` | Could not build a valid request URL (check model name / API key). |
| `URLError.badServerResponse` | Non-`200` HTTP response from Gemini (the raw body is printed to stdout). |
| `NSError(domain: "GeminiError")` | Response JSON is valid but does not contain the expected `candidates` / `parts` structure. |
| `NSError(domain: "ImageConversionError")` | A source `CIImage` could not be re-encoded to PNG/JPEG. |

Recommended pattern:

```swift
do {
    let text = try await gemini.generateText(textPrompt: "Hello")
    print(text)
} catch {
    print("Gemini call failed: \(error.localizedDescription)")
}
```

---

## Project Structure

```
UMGeminiLib/
├── Package.swift
├── README.md
└── Sources/
    ├── UMGeminiLib/                      # Library target
    │   ├── UMGeminiLite.swift            # Core struct + text generation
    │   └── UMGeminiLite + Images.swift   # Image generation + DTOs
    └── UMGeminiCLI/                      # Executable target
        └── main.swift                    # CLI entry point
```

---

## Credits & License

Created by **Alex Raccuglia** for **UltiMedia**.

> The hard-coded API key inside `UMGeminiLite.ultiMedia` is intended for internal UltiMedia use — set `apiKey` explicitly when consuming the package from elsewhere.

License: _TBD_ — add a `LICENSE` file at the repository root.
