// swift-tools-version: 6.3
// The swift-tools-version declares the minimum version of Swift required to build this package.

import PackageDescription

let package = Package(
    name: "UMGeminiLib",
    platforms: [
        .macOS(.v12),
        .iOS(.v15),
        .tvOS(.v15),
        .visionOS(.v1)
    ],
    products: [
        .library(
            name: "UMGeminiLib",
            targets: ["UMGeminiLib"]
        ),
        .executable(
            name: "UMGeminiCLI",
            targets: ["UMGeminiCLI"]
        ),
    ],
    targets: [
        .target(
            name: "UMGeminiLib"
        ),
        .executableTarget(
            name: "UMGeminiCLI",
            dependencies: ["UMGeminiLib"]
        ),
    ],
    swiftLanguageModes: [.v5]
)
