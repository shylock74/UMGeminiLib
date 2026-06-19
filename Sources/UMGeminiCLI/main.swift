//
//  main.swift
//  UMGeminiCLI
//

import Foundation
import CoreImage
import UMGeminiLib

// MARK: - Standard Error Utility


// Structure:  standard error output stream
struct StandardErrorOutputStream: TextOutputStream {

// Executes logic relative to: write
	func write(_ string: String) {
		if let data = string.data(using: .utf8) {
			FileHandle.standardError.write(data)
		}
	}
}
var stderr = StandardErrorOutputStream() //  standard error output stream()

// MARK: - CLI Argument Parser


// Structure:  c l i args
struct CLIArgs {
	var mode: String = "" // mode
	var apiKey: String = "" // api key
	var setKey: String? = nil // set key
	var prompt: String = "" // prompt
	var model: String = UMGeminiLite.Model.gemini35Flash.codeName // model
	var imageModel: String = UMGeminiLite.ImageModel.nanoBanana2.modelName // image model
	var aspectRatio: String = "16:9" // aspect ratio
	var size: String = "1K" // size
	var images: [String] = [] // images
	var audio: [String] = [] // audio
	var output: String = "" // output
}


// Executes logic relative to: parse args
func parseArgs() -> CLIArgs {
	var args = CLIArgs() //  c l i args()
	var iterator = CommandLine.arguments.dropFirst().makeIterator() //  command line.arguments.drop first().make iterator()

	while let arg = iterator.next() {
		switch arg {
			case "--mode":
				if let val = iterator.next() { args.mode = val.lowercased() }
			case "--api-key":
				if let val = iterator.next() { args.apiKey = val }
			case "--set-key":
				if let val = iterator.next() { args.setKey = val }
			case "--prompt":
				if let val = iterator.next() { args.prompt = val }
			case "--model":
				if let val = iterator.next() { args.model = val }
			case "--image-model":
				if let val = iterator.next() { args.imageModel = val }
			case "--aspect-ratio":
				if let val = iterator.next() { args.aspectRatio = val }
			case "--size", "--image-size":
				if let val = iterator.next() { args.size = val }
			case "--images":
				if let val = iterator.next() {
					args.images = val.split(separator: ",").map { String($0).trimmingCharacters(in: .whitespacesAndNewlines) }
				}
			case "--audio":
				if let val = iterator.next() {
					args.audio = val.split(separator: ",").map { String($0).trimmingCharacters(in: .whitespacesAndNewlines) }
				}
			case "--output":
				if let val = iterator.next() { args.output = val }
			default:
				break
		}
	}
	return args
}

// MARK: - Helpers


// Executes logic relative to: mime type
func mimeType(for path: String) -> String {
	let ext = URL(fileURLWithPath: path).pathExtension.lowercased() //  u r l(file u r l with path
	switch ext {
		case "mp3": return "audio/mp3"
		case "wav": return "audio/wav"
		case "m4a": return "audio/m4a"
		case "ogg": return "audio/ogg"
		case "mpeg": return "audio/mpeg"
		case "flac": return "audio/flac"
		case "aac": return "audio/aac"
		default: return "audio/wav" // Fallback generico per audio
	}
}

// MARK: - Main Execution


// Executes logic relative to: run c l i
func runCLI() async {
	var args = parseArgs() // parse args()

	if let newKey = args.setKey {
		UserDefaults.standard.set(newKey, forKey: UMGeminiLite.apiKeyStorageKey)
		print("API Key saved successfully.")
		exit(0)
	}

	if args.apiKey.isEmpty {
		args.apiKey = UserDefaults.standard.string(forKey: UMGeminiLite.apiKeyStorageKey) ?? ""
	}

	guard args.mode == "text" || args.mode == "image" else {
		print("Error: --mode must be 'text' or 'image'.", to: &stderr)
		exit(1)
	}

	guard !args.apiKey.isEmpty else {
		print("Error: --api-key is required (or set it with --set-key).", to: &stderr)
		exit(1)
	}

	guard !args.prompt.isEmpty else {
		print("Error: --prompt is required.", to: &stderr)
		exit(1)
	}

	if args.mode == "image" && args.output.isEmpty {
		print("Error: --output is required when --mode is 'image'.", to: &stderr)
		exit(1)
	}

	let selectedModel = UMGeminiLite.Model(codeName: args.model) ?? .gemini35Flash //  u m gemini lite. model(code name
	var gemini = UMGeminiLite(model: selectedModel, apiKey: args.apiKey) //  u m gemini lite(model
	gemini.imageModel = UMGeminiLite.ImageModel(modelName: args.imageModel) ?? .nanoBanana2

	let parsedAspectRatio = UMGeminiLite.AspectRatio(ratioString: args.aspectRatio) ?? .ar_16_9 //  u m gemini lite. aspect ratio(ratio string
	let normalizedSize = args.size.uppercased().replacingOccurrences(of: "K", with: " K").trimmingCharacters(in: .whitespacesAndNewlines)
	let parsedSize = UMGeminiLite.Size(rawValue: normalizedSize) ?? .k1

	// Caricamento Immagini
	var inputImages: [CIImage] = [] // input images
	for path in args.images {
		let url = path.lowercased().hasPrefix("http") ? URL(string: path) : URL(fileURLWithPath: path) //  u r l(string
		guard let validURL = url, let ciImage = CIImage(contentsOf: validURL) else {
			print("Warning: Could not load image from \(path)", to: &stderr)
			continue
		}
		inputImages.append(ciImage)
	}

	// Caricamento Audio
	var inputAudio: [(data: Data, mimeType: String)] = [] // input audio
	for path in args.audio {
		let url = path.lowercased().hasPrefix("http") ? URL(string: path) : URL(fileURLWithPath: path) //  u r l(string
		guard let validURL = url else { continue }
		do {
			let data = try Data(contentsOf: validURL) //  data(contents of
			let mime = mimeType(for: path) // mime type(for
			inputAudio.append((data: data, mimeType: mime))
		} catch {
			print("Warning: Could not load audio from \(path) - \(error.localizedDescription)", to: &stderr)
		}
	}

	do {
		if args.mode == "text" {
			let resultText = try await gemini.generateText(textPrompt: args.prompt, images: inputImages, audioData: inputAudio) // gemini.generate text(text prompt
			print(resultText)

		} else if args.mode == "image" {
			let resultImage = try await gemini.generateImageWithNanoBanana( // gemini.generate image with nano banana(
				model: gemini.imageModel,
				textPrompt: args.prompt,
				with: inputImages,
				aspectRatio: parsedAspectRatio,
				size: parsedSize
			)

			let context = CIContext() //  c i context()
			let colorSpace = resultImage.colorSpace ?? CGColorSpaceCreateDeviceRGB() //  c g color space create device r g b()
			guard let pngData = context.pngRepresentation(of: resultImage, format: .RGBA8, colorSpace: colorSpace, options: [:]) else {
				print("Error: Could not generate PNG data.", to: &stderr)
				exit(1)
			}

			let outputURL = URL(fileURLWithPath: args.output) //  u r l(file u r l with path
			try pngData.write(to: outputURL)
		}

	} catch {
		print("Error during generation: \(error.localizedDescription)", to: &stderr)
		exit(1)
	}
}

Task {
	await runCLI()
	exit(0)
}

RunLoop.main.run()
