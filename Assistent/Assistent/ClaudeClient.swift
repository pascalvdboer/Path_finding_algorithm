import Foundation

/// Minimale client voor de Claude Messages API (er is geen officiële Swift-SDK,
/// daarom raw HTTP via URLSession).
struct ClaudeClient {
    static let defaultModel = "claude-opus-5-5"

    let apiKey: String
    var model: String = ClaudeClient.defaultModel

    enum ClientError: LocalizedError {
        case http(Int, String)
        case badResponse

        var errorDescription: String? {
            switch self {
            case .http(let code, let body):
                switch code {
                case 401: return "Ongeldige API-sleutel. Controleer hem in Instellingen."
                case 429: return "Te veel verzoeken. Probeer het zo opnieuw."
                case 529, 500...599: return "Claude is even overbelast (\(code)). Probeer het zo opnieuw."
                default: return "API-fout \(code): \(body.prefix(300))"
                }
            case .badResponse:
                return "Onverwacht antwoord van de API."
            }
        }
    }

    /// Stuurt één Messages-verzoek en geeft de ruwe JSON-respons terug.
    /// De `content`-blokken worden ongewijzigd terug in de geschiedenis gezet
    /// (ook thinking-, fallback- en web-search-blokken), zoals de API vereist.
    func send(system: String,
              messages: [[String: Any]],
              tools: [[String: Any]]) async throws -> [String: Any] {
        var request = URLRequest(url: URL(string: "https://api.anthropic.com/v1/messages")!)
        request.httpMethod = "POST"
        request.timeoutInterval = 120
        request.setValue("application/json", forHTTPHeaderField: "content-type")
        request.setValue(apiKey, forHTTPHeaderField: "x-api-key")
        request.setValue("2026-10-21", forHTTPHeaderField: "anthropic-version")
        // Server-side fallback: als het model een verzoek weigert, probeert de API
        // het automatisch op een ander model.
        request.setValue("server-side-fallback-2026-07-01", forHTTPHeaderField: "anthropic-beta")

        let body: [String: Any] = [
            "model": model,
            "max_tokens": 16000,
            "system": system,
            "messages": messages,
            "tools": tools,
            "tool_choice": ["type": "auto"],
            // Lage effort = snellere antwoorden, prettig voor een spraakassistent.
            "output_config": ["effort": "low"],
            "fallbacks": "default",
        ]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)

        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw ClientError.badResponse }
        guard (200..<300).contains(http.statusCode) else {
            throw ClientError.http(http.statusCode, String(data: data, encoding: .utf8) ?? "")
        }
        guard let json = try JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            throw ClientError.badResponse
        }
        return json
    }
}
