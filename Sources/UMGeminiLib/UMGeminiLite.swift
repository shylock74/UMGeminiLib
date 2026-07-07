//
//  UMGeminiLite.swift
//  UMGeminiLib
//

import Foundation
import CoreImage


// Structure:  u m chat element
public struct UMChatElement {
	public var role: String // "user", "model", etc.
	public var textPrompt: String
	public var images: [CIImage]
	public var audioData: [(data: Data, mimeType: String)]

	public init(role: String = "user", textPrompt: String, images: [CIImage] = [], audioData: [(data: Data, mimeType: String)] = []) {
		self.role = role
		self.textPrompt = textPrompt
		self.images = images
		self.audioData = audioData
	}
}


// Structure:  u m gemini lite
public struct UMGeminiLite: Codable {

	public static let apiKeyStorageKey = "UMGeminiLite_APIKey"


// Enum:  model
	public enum Model: String, Codable, CaseIterable {
		case gemini31ProPreviw =        "Gemini 3.1 Pro Preview"
		case gemini31FlashLite = 		"Gemini 3.1 Flash Lite "
		case gemini35Flash =			"Gemini 3.5 Flash"

		public var displayName: String { // display name
			rawValue
		}

		public var codeName: String { // code name
			switch self {
//				case .gemini25Flash:             return "gemini-2.5-flash"
//				case .gemini25FlashLite:         return "gemini-2.5-flash-lite"
//				case .gemini25Pro:               return "gemini-2.5-pro"
//				case .gemini30ProPreview:        return "gemini-3-pro-preview"
				case .gemini31ProPreviw:         return "gemini-3.1-pro-preview"
//				case .gemini31FlashLitePreview:  return "gemini-3.1-flash-lite-preview"
				case .gemini31FlashLite:
					return "gemini-3.1-flash-lite"
				case .gemini35Flash:
					return "gemini-3.5-flash"
			}
		}

		public init?(codeName: String) {
			guard let model = Self.allCases.first(where: { $0.codeName.lowercased() == codeName.lowercased() }) else {
				return nil
			}
			self = model
		}
	}


// Executes logic relative to: startup
	public static func startup() {
	}

	public var model: Model = .gemini35Flash // model
	public var imageModel: ImageModel = .nanoBanana2 // image model
	public var apiKey: String = "" // api key


// Enum:  coding keys
	private enum CodingKeys: String, CodingKey {
		case model
		case apiKey
	}


// Initializer
	public init(model: Model = .gemini35Flash, apiKey: String = "") {
		self.model = model
		self.apiKey = apiKey
	}


// Initializer
	public init(from decoder: Decoder) throws {
		let container = try decoder.container(keyedBy: CodingKeys.self) // decoder.container(keyed by
		self.model = (try? container.decodeIfPresent(Model.self, forKey: .model)) ?? .gemini35Flash
		self.apiKey = (try? container.decodeIfPresent(String.self, forKey: .apiKey)) ?? ""
	}

	public static let ultiMedia = UMGeminiLite(model: .gemini35Flash,
											   apiKey: "")

	static var lastRequest: Date = .distantPast


// Executes logic relative to: delay request
	public static func delayRequest() async {
		let delta = Date().timeIntervalSince(lastRequest) //  date().time interval since(last request)
		if delta < 2.0 {
			try? await Task.sleep(nanoseconds: UInt64((2.0 - delta) * 1_000_000_000))
		}
		lastRequest = Date()
	}


// Executes logic relative to: generate text
	public func generateText(textPrompt: String, images: [CIImage] = [], audioData: [(data: Data, mimeType: String)] = []) async throws -> String {
		let parts = try self.parts(forTextPrompt: textPrompt, images: images, audioData: audioData) // self.parts(for text prompt

		let requestPayload: [String: Any] = [ // request payload
			"contents": [
				["parts": parts]
			],
			"generationConfig": [
				"responseModalities": [
					["TEXT"]
				]
			]
		]

		let jsonData = try JSONSerialization.data(withJSONObject: requestPayload) //  j s o n serialization.data(with j s o n object

		let url = URL(string: "https://generativelanguage.googleapis.com/v1beta/models/\(model.codeName):generateContent")! //  u r l(string
		var request = URLRequest(url: url) //  u r l request(url
		request.timeoutInterval = 600.0
		request.httpMethod = "POST"
		request.setValue("application/json", forHTTPHeaderField: "Content-Type")
		request.setValue(apiKey, forHTTPHeaderField: "x-goog-api-key")
		request.httpBody = jsonData

		let (data, response) = try await URLSession.shared.data(for: request) //  u r l session.shared.data(for

		guard let httpResponse = response as? HTTPURLResponse, httpResponse.statusCode == 200 else {
			let statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
			var apiErrorMessage: String? = nil
			if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
			   let errorDict = json["error"] as? [String: Any],
			   let message = errorDict["message"] as? String {
				apiErrorMessage = message
			}
			
			print("--------------------------------------------------")
			print("[UMGeminiLite] API Response Status Code: \(statusCode)")
			if let apiErrorMessage = apiErrorMessage {
				print("[UMGeminiLite] API Error Message: \(apiErrorMessage)")
			}
			if let errorString = String(data: data, encoding: .utf8) {
				print("[UMGeminiLite] Server Error Body: \(errorString)")
			}
			print("--------------------------------------------------")
			
			let displayError = apiErrorMessage ?? "Bad server response (Status code: \(statusCode))"
			throw NSError(domain: "GeminiError", code: statusCode, userInfo: [NSLocalizedDescriptionKey: displayError])
		}

		guard let responseJSON = try JSONSerialization.jsonObject(with: data) as? [String: Any],
			  let candidates = responseJSON["candidates"] as? [[String: Any]], // [[ string
			  let firstCandidate = candidates.first, // candidates.first,
			  let content = firstCandidate["content"] as? [String: Any], // [ string
			  let parts = content["parts"] as? [[String: Any]], // [[ string
			  let firstPartText = parts.first?["text"] as? String else { // {
			if let responseString = String(data: data, encoding: .utf8) {
				print("--------------------------------------------------")
				print("[UMGeminiLite] Invalid response structure. Raw response body:")
				print(responseString)
				print("--------------------------------------------------")
			}
			throw NSError(domain: "GeminiError", code: -1, userInfo: [NSLocalizedDescriptionKey: "Invalid response format"])
		}

		return firstPartText
	}


// Executes logic relative to: generate chat
	public func generateChat(elements: [UMChatElement]) async throws -> String {
		var contentsArray: [[String: Any]] = []

		for element in elements {
			let parts = try self.parts(forTextPrompt: element.textPrompt, images: element.images, audioData: element.audioData)
			contentsArray.append([
				"role": element.role,
				"parts": parts
			])
		}

		let requestPayload: [String: Any] = [ // request payload
			"contents": contentsArray,
			"generationConfig": [
				"responseModalities": [
					["TEXT"]
				]
			]
		]

		let jsonData = try JSONSerialization.data(withJSONObject: requestPayload) //  j s o n serialization.data(with j s o n object

		let url = URL(string: "https://generativelanguage.googleapis.com/v1beta/models/\(model.codeName):generateContent")! //  u r l(string
		var request = URLRequest(url: url) //  u r l request(url
		request.timeoutInterval = 600.0
		request.httpMethod = "POST"
		request.setValue("application/json", forHTTPHeaderField: "Content-Type")
		request.setValue(apiKey, forHTTPHeaderField: "x-goog-api-key")
		request.httpBody = jsonData

		let (data, response) = try await URLSession.shared.data(for: request) //  u r l session.shared.data(for

		guard let httpResponse = response as? HTTPURLResponse, httpResponse.statusCode == 200 else {
			let statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
			var apiErrorMessage: String? = nil
			if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
			   let errorDict = json["error"] as? [String: Any],
			   let message = errorDict["message"] as? String {
				apiErrorMessage = message
			}
			
			print("--------------------------------------------------")
			print("[UMGeminiLite] API Response Status Code: \(statusCode)")
			if let apiErrorMessage = apiErrorMessage {
				print("[UMGeminiLite] API Error Message: \(apiErrorMessage)")
			}
			if let errorString = String(data: data, encoding: .utf8) {
				print("[UMGeminiLite] Server Error Body: \(errorString)")
			}
			print("--------------------------------------------------")
			
			let displayError = apiErrorMessage ?? "Bad server response (Status code: \(statusCode))"
			throw NSError(domain: "GeminiError", code: statusCode, userInfo: [NSLocalizedDescriptionKey: displayError])
		}

		guard let responseJSON = try JSONSerialization.jsonObject(with: data) as? [String: Any],
			  let candidates = responseJSON["candidates"] as? [[String: Any]], // [[ string
			  let firstCandidate = candidates.first, // candidates.first,
			  let content = firstCandidate["content"] as? [String: Any], // [ string
			  let parts = content["parts"] as? [[String: Any]], // [[ string
			  let firstPartText = parts.first?["text"] as? String else { // {
			if let responseString = String(data: data, encoding: .utf8) {
				print("--------------------------------------------------")
				print("[UMGeminiLite] Invalid response structure. Raw response body:")
				print(responseString)
				print("--------------------------------------------------")
			}
			throw NSError(domain: "GeminiError", code: -1, userInfo: [NSLocalizedDescriptionKey: "Invalid response format"])
		}

		return firstPartText
	}


// Executes logic relative to: generate interaction
	public func generateInteraction(input textPrompt: String, images: [CIImage] = [], audioData: [(data: Data, mimeType: String)] = [], previousInteractionId: String? = nil) async throws -> (text: String, interactionId: String) {
		
		var requestPayload: [String: Any] = [ // request payload
			"model": model.codeName
		]
		
		if images.isEmpty && audioData.isEmpty {
			requestPayload["input"] = textPrompt
		} else {
			let parts = try self.parts(forTextPrompt: textPrompt, images: images, audioData: audioData)
			requestPayload["input"] = parts
		}
		
		if let prevId = previousInteractionId {
			requestPayload["previous_interaction_id"] = prevId
		}

		let jsonData = try JSONSerialization.data(withJSONObject: requestPayload) //  j s o n serialization.data(with j s o n object

		let url = URL(string: "https://generativelanguage.googleapis.com/v1beta/interactions")! //  u r l(string
		var request = URLRequest(url: url) //  u r l request(url
		request.timeoutInterval = 600.0
		request.httpMethod = "POST"
		request.setValue("application/json", forHTTPHeaderField: "Content-Type")
		request.setValue(apiKey, forHTTPHeaderField: "x-goog-api-key")
		request.httpBody = jsonData

		let (data, response) = try await URLSession.shared.data(for: request) //  u r l session.shared.data(for

		guard let httpResponse = response as? HTTPURLResponse, httpResponse.statusCode == 200 else {
			let statusCode = (response as? HTTPURLResponse)?.statusCode ?? 0
			var apiErrorMessage: String? = nil
			if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
			   let errorDict = json["error"] as? [String: Any],
			   let message = errorDict["message"] as? String {
				apiErrorMessage = message
			}
			
			print("--------------------------------------------------")
			print("[UMGeminiLite] API Response Status Code: \(statusCode)")
			if let apiErrorMessage = apiErrorMessage {
				print("[UMGeminiLite] API Error Message: \(apiErrorMessage)")
			}
			if let errorString = String(data: data, encoding: .utf8) {
				print("[UMGeminiLite] Server Error Body: \(errorString)")
			}
			print("--------------------------------------------------")
			
			let displayError = apiErrorMessage ?? "Bad server response (Status code: \(statusCode))"
			throw NSError(domain: "GeminiError", code: statusCode, userInfo: [NSLocalizedDescriptionKey: displayError])
		}

		guard let responseJSON = try JSONSerialization.jsonObject(with: data) as? [String: Any],
			  let interactionId = responseJSON["id"] as? String,
			  let steps = responseJSON["steps"] as? [[String: Any]],
			  let outputStep = steps.first(where: { $0["type"] as? String == "model_output" }),
			  let content = outputStep["content"] as? [[String: Any]],
			  let firstContent = content.first,
			  let text = firstContent["text"] as? String else {
			if let responseString = String(data: data, encoding: .utf8) {
				print("--------------------------------------------------")
				print("[UMGeminiLite] Invalid response structure. Raw response body:")
				print(responseString)
				print("--------------------------------------------------")
			}
			throw NSError(domain: "GeminiError", code: -1, userInfo: [NSLocalizedDescriptionKey: "Invalid response format"])
		}

		return (text, interactionId)
	}
}


// Object:  element
extension UMGeminiLite: Equatable {

// Executes logic relative to: lhs
	public static func == (lhs: UMGeminiLite, rhs: UMGeminiLite) -> Bool {
		lhs.model == rhs.model && lhs.apiKey == rhs.apiKey
	}
}
