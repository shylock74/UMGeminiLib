//
//  UMGeminiLite+Images.swift
//  UMGeminiLib
//

import Foundation
import CoreImage


// Object:  element
extension UMGeminiLite {


// Enum:  image error
	public enum ImageError: Error {
		case imageGenerationFailed
	}


// Enum:  image model
	public enum ImageModel: String, Codable, CaseIterable {
		case imagen40 =         "Imagen 4"
		case imagen40Ultra =    "Imagen 4 Ultra"
		case imagen40Fast =     "Imagen 4 fast"
		case nanoBanana =       "Nano Banana"
		case nanoBanana2 =      "Nano Banana 2"
		case nanoBananaPro =    "Nano Banana Pro"

		public var displayName: String { // display name
			rawValue.capitalized
		}

		public var modelName: String { // model name
			switch self {
				case .nanoBanana:       return "gemini-2.5-flash-image"
				case .nanoBanana2:      return "gemini-3.1-flash-image-preview"
				case .nanoBananaPro:    return "gemini-3-pro-image-preview"
				case .imagen40:         return "imagen-4.0-generate-001"
				case .imagen40Ultra:    return "imagen-4.0-ultra-generate-001"
				case .imagen40Fast:     return "imagen-4.0-fast-generate-001"
			}
		}

		public init?(modelName: String) {
			guard let match = Self.allCases.first(where: { $0.modelName.lowercased() == modelName.lowercased() }) else {
				return nil
			}
			self = match
		}

		public var referenceable: Bool { // referenceable
			switch self {
				case .nanoBanana, .nanoBanana2, .nanoBananaPro: return true
				case .imagen40, .imagen40Ultra, .imagen40Fast: return false
			}
		}

		public static var referenceableModelList: [ImageModel] {
			Self.allCases.filter { $0.referenceable }
		}
	}


// Enum:  aspect ratio
	public enum AspectRatio: String, Codable, CaseIterable {
		case ar_1_1, ar_9_16, ar_16_9, ar_3_4, ar_4_3, ar_2_3, ar_3_2, ar_21_9

		public var promptString: String { // prompt string
			rawValue.replacingOccurrences(of: "ar_", with: "")
		}

		public var displayName: String { // display name
			promptString.replacingOccurrences(of: "_", with: ":")
		}

		public init?(ratioString: String) {
			let normalized = ratioString.replacingOccurrences(of: ":", with: "_").replacingOccurrences(of: " ", with: "") // ratio string.replacing occurrences(of
			let targetRawValue = "ar_" + normalized // normalized
			guard let match = Self.allCases.first(where: { $0.rawValue == targetRawValue }) else {
				return nil
			}
			self = match
		}
	}


// Enum:  size
	public enum Size: String, Codable, CaseIterable {
		case k1 = "1 K"
		case k2 = "2 K"
		case k4 = "4 K"

		public var promptString: String { // prompt string
			rawValue.replacingOccurrences(of: " ", with: "")
		}

		public var displayName: String { // display name
			rawValue
		}
	}


// Enum:  image format
	public enum ImageFormat {
		case png
		case jpeg(compression: CGFloat = 0.8)

		public var mimeType: String { // mime type
			switch self {
				case .png:  return "image/png"
				case .jpeg: return "image/jpeg"
			}
		}
	}


// Executes logic relative to: images and mime type
	public func imagesAndMimeType(for ciImageList: [CIImage], format: ImageFormat = .png) throws -> [(data: Data, mimeType: String)] {
		let context = CIContext() //  c i context()
		var imagesData: [(data: Data, mimeType: String)] = [] // images data

		for ciImage in ciImageList {
			let colorSpace = ciImage.colorSpace ?? CGColorSpaceCreateDeviceRGB() //  c g color space create device r g b()
			let imageData: Data? // image data

			switch format {
				case .png:
					imageData = context.pngRepresentation(of: ciImage, format: .RGBA8, colorSpace: colorSpace, options: [:])
				case .jpeg(let compression):
					imageData = context.jpegRepresentation(of: ciImage, colorSpace: colorSpace, options: [(kCGImageDestinationLossyCompressionQuality as CIImageRepresentationOption): compression])
			}

			guard let validData = imageData else {
				throw NSError(domain: "ImageConversionError", code: -1, userInfo: [NSLocalizedDescriptionKey: "Failed to convert CIImage"])
			}
			imagesData.append((data: validData, mimeType: format.mimeType))
		}
		return imagesData
	}


// Executes logic relative to: generate p n g data with nano banana
	public func generatePNGDataWithNanoBanana(textPrompt: String,
											  model: ImageModel,
											  ciImages: [CIImage],
											  aspectRatio: AspectRatio,
											  size: Size = .k1) async throws -> Data {
		let imagesData = try imagesAndMimeType(for: ciImages) // images and mime type(for
		return try await generateImageWithGemini(
			model: model,
			textPrompt: textPrompt,
			sourceImagesData: imagesData,
			aspectRatio: aspectRatio,
			size: size
		)
	}


// Executes logic relative to: parts
	public func parts(forTextPrompt textPrompt: String,
					  sourceImagesData: [(data: Data, mimeType: String)] = [],
					  audioData: [(data: Data, mimeType: String)] = []) -> [[String: Any]] {
		var parts: [[String: Any]] = [] // parts

		for imageInfo in sourceImagesData {
			let base64Image = imageInfo.data.base64EncodedString() // image info.data.base64 encoded string()
			parts.append([
				"inline_data": [
					"mime_type": imageInfo.mimeType,
					"data": base64Image
				]
			])
		}

		for audioInfo in audioData {
			let base64Audio = audioInfo.data.base64EncodedString() // audio info.data.base64 encoded string()
			parts.append([
				"inline_data": [
					"mime_type": audioInfo.mimeType,
					"data": base64Audio
				]
			])
		}

		parts.append(["text": textPrompt])
		return parts
	}


// Executes logic relative to: parts
	public func parts(forTextPrompt textPrompt: String, images: [CIImage], audioData: [(data: Data, mimeType: String)] = []) throws -> [[String: Any]] {
		try parts(forTextPrompt: textPrompt, sourceImagesData: imagesAndMimeType(for: images), audioData: audioData)
	}

	// MARK: - Request & Response Models


// Structure:  gemini response
	public struct GeminiResponse: Decodable {
		public let candidates: [GeminiCandidate]? // candidates
		public let usageMetadata: GeminiUsageMetadata? // usage metadata
		public let modelVersion: String? // model version
		public let responseId: String? // response id
	}


// Structure:  gemini candidate
	public struct GeminiCandidate: Decodable {
		public let content: GeminiContent? // content
		public let finishReason: String? // finish reason
		public let index: Int? // index
	}


// Structure:  gemini content
	public struct GeminiContent: Codable {
		public let role: String? // role
		public let parts: [GeminiPart]? // parts


// Initializer
		public init(role: String? = "user", parts: [GeminiPart]?) {
			self.role = role
			self.parts = parts
		}
	}


// Structure:  gemini part
	public struct GeminiPart: Codable {
		public let text: String? // text
		public let inlineData: GeminiInlineData? // inline data


// Initializer
		public init(text: String? = nil, inlineData: GeminiInlineData? = nil) {
			self.text = text
			self.inlineData = inlineData
		}
	}


// Structure:  gemini inline data
	public struct GeminiInlineData: Codable {
		public let mimeType: String // mime type
		public let data: String // data
	}


// Structure:  gemini usage metadata
	public struct GeminiUsageMetadata: Decodable {
		public let promptTokenCount: Int? // prompt token count
		public let candidatesTokenCount: Int? // candidates token count
		public let totalTokenCount: Int? // total token count
	}


// Structure:  gemini request
	public struct GeminiRequest: Encodable {
		public let contents: [GeminiContent] // contents
		public let generationConfig: GeminiGenerationConfig // generation config
	}


// Structure:  gemini generation config
	public struct GeminiGenerationConfig: Encodable {
		public let responseModalities: [String] // response modalities
		public let imageConfig: GeminiImageConfig? // image config


// Initializer
		public init(responseModalities: [String], imageConfig: GeminiImageConfig? = nil) {
			self.responseModalities = responseModalities
			self.imageConfig = imageConfig
		}
	}


// Structure:  gemini image config
	public struct GeminiImageConfig: Encodable {
		public let aspectRatio: String // aspect ratio
		public let imageSize: String? // image size
	}


// Executes logic relative to: generate image with gemini
	public func generateImageWithGemini(model: ImageModel,
										textPrompt: String,
										sourceImagesData: [(data: Data, mimeType: String)],
										aspectRatio: AspectRatio,
										size: Size = .k1) async throws -> Data {

		var parts: [GeminiPart] = [] // parts
		parts.append(GeminiPart(text: textPrompt))

		for sourceImage in sourceImagesData {
			let base64String = sourceImage.data.base64EncodedString() // source image.data.base64 encoded string()
			let inlineData = GeminiInlineData(mimeType: sourceImage.mimeType, data: base64String) //  gemini inline data(mime type
			parts.append(GeminiPart(inlineData: inlineData))
		}

		let generationConfig = GeminiGenerationConfig( //  gemini generation config(
			responseModalities: ["IMAGE", "TEXT"],
			imageConfig: GeminiImageConfig(aspectRatio: aspectRatio.displayName, imageSize: size.promptString)
		)

		let requestBody = GeminiRequest( //  gemini request(
			contents: [GeminiContent(role: "user", parts: parts)],
			generationConfig: generationConfig
		)

		guard let url = URL(string: "https://generativelanguage.googleapis.com/v1beta/models/\(model.modelName):streamGenerateContent?key=\(apiKey)") else {
			throw URLError(.badURL)
		}

		var request = URLRequest(url: url) //  u r l request(url
		request.httpMethod = "POST"
		request.setValue("application/json", forHTTPHeaderField: "Content-Type")
		
		let encoder = JSONEncoder()
		let requestData = try encoder.encode(requestBody)
		request.httpBody = requestData

		// Log request information
		print("--------------------------------------------------")
		print("[UMGeminiLite] API Request URL: \(url.absoluteString)")
		if let _ = String(data: requestData, encoding: .utf8) {
			print("[UMGeminiLite] Request Body (Summary):")
			print("  Prompt: \"\(textPrompt)\"")
			print("  Number of parts: \(parts.count)")
			for (index, part) in parts.enumerated() {
				if let text = part.text {
					print("    Part [\(index)]: Text = \"\(text)\"")
				} else if let inline = part.inlineData {
					print("    Part [\(index)]: Image = MIME: \(inline.mimeType), Base64 Length: \(inline.data.count) characters")
				}
			}
			print("  Aspect Ratio Config: \(aspectRatio.displayName)")
			print("  Size Config: \(size.displayName)")
		}
		print("--------------------------------------------------")

		let (data, response) = try await URLSession.shared.data(for: request) //  u r l session.shared.data(for

		guard let httpResponse = response as? HTTPURLResponse else {
			throw URLError(.badServerResponse)
		}

		print("--------------------------------------------------")
		print("[UMGeminiLite] API Response Status Code: \(httpResponse.statusCode)")

		if httpResponse.statusCode != 200 {
			var apiErrorMessage: String? = nil
			if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
			   let errorDict = json["error"] as? [String: Any],
			   let message = errorDict["message"] as? String {
				apiErrorMessage = message
			}
			
			if let apiErrorMessage = apiErrorMessage {
				print("[UMGeminiLite] API Error Message: \(apiErrorMessage)")
			}
			if let errorString = String(data: data, encoding: .utf8) {
				print("[UMGeminiLite] Server Error Body: \(errorString)")
			}
			print("--------------------------------------------------")
			
			let displayError = apiErrorMessage ?? "Bad server response (Status code: \(httpResponse.statusCode))"
			throw NSError(domain: "GeminiError", code: httpResponse.statusCode, userInfo: [NSLocalizedDescriptionKey: displayError])
		}

		// Log response information
		if let responseString = String(data: data, encoding: .utf8) {
			print("[UMGeminiLite] Response Body Length: \(responseString.count) characters")
			if let decoded = try? JSONDecoder().decode([GeminiResponse].self, from: data),
			   let firstResponse = decoded.first,
			   let candidates = firstResponse.candidates,
			   let firstCandidate = candidates.first {
				print("  Finish Reason: \(firstCandidate.finishReason ?? "nil")")
				if let content = firstCandidate.content, let respParts = content.parts {
					print("  Response parts count: \(respParts.count)")
					for (index, p) in respParts.enumerated() {
						if let t = p.text {
							print("    Response Part [\(index)]: Text = \"\(t)\"")
						} else if let inline = p.inlineData {
							print("    Response Part [\(index)]: Image = MIME: \(inline.mimeType), Base64 Length: \(inline.data.count) characters")
						}
					}
				}
			} else {
				print("[UMGeminiLite] Raw Response (Truncated to 500 chars): \(String(responseString.prefix(500)))")
			}
		}
		print("--------------------------------------------------")

		let decodedArray = try JSONDecoder().decode([GeminiResponse].self, from: data) // from

		guard let firstResponse = decodedArray.first,
			  let candidates = firstResponse.candidates, // first response.candidates,
			  let firstCandidate = candidates.first, // candidates.first,
			  let content = firstCandidate.content, // first candidate.content,
			  let parts = content.parts, // content.parts,
			  let firstPart = parts.first, // parts.first,
			  let inlineData = firstPart.inlineData, // first part.inline data,
			  let imageData = Data(base64Encoded: inlineData.data) else { //  data(base64 encoded

			if let responseString = String(data: data, encoding: .utf8) {
				print("[UMGeminiLite] Invalid image response structure. Raw response body:")
				print(responseString)
			}
			throw NSError(domain: "GeminiError",
						  code: -1,
						  userInfo: [NSLocalizedDescriptionKey: "Invalid response structure or missing image"])
		}

		return imageData
	}


// Executes logic relative to: generate image with nano banana
	public func generateImageWithNanoBanana(model: ImageModel,
											textPrompt: String,
											with images: [CIImage],
											aspectRatio: AspectRatio,
											size: Size = .k1) async throws -> CIImage {

		let data = try await generatePNGDataWithNanoBanana(textPrompt: textPrompt, // generate p n g data with nano banana(text prompt
														   model: model,
														   ciImages: images,
														   aspectRatio: aspectRatio,
														   size: size)
		guard let ciImage = CIImage(data: data) else { throw ImageError.imageGenerationFailed }
		return ciImage
	}


// Executes logic relative to: describe
	public func describe(image: CIImage) async throws -> String? {
		let prompt = "Write at least 10 keywords and at maximum 30 keywords that describe the image and the actions performed, comma separated, only keywords, no preface or explanation" // explanation"
		return try await generateText(textPrompt: prompt, images: [image])
	}


// Executes logic relative to: title
	public func title(for image: CIImage) async throws -> String? {
		let prompt = "Write a very concise title for the image" // image"
		return try await generateText(textPrompt: prompt, images: [image])
	}
}
