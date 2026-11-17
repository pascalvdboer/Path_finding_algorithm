import Foundation

/// Houdt het gesprek bij en voert de tool-use-lus uit:
/// vraag -> Claude -> (tools uitvoeren -> Claude)* -> antwoord.
@MainActor
final class AssistantAgent {
    private(set) var history: [[String: Any]] = []
    let executor: ToolExecutor

    init(executor: ToolExecutor) {
        self.executor = executor
    }

    static let systemPrompt = """
    Je bent een persoonlijke assistent die op de iPhone van de gebruiker draait, vergelijkbaar met Siri.
    Je antwoorden worden vaak hardop voorgelezen. Antwoord daarom in het Nederlands, kort en natuurlijk
    gesproken: meestal één tot drie zinnen, zonder markdown, opsommingstekens, emoji of URL's.

    Je kunt de agenda en herinneringen van de gebruiker lezen en aanpassen, een e-mail klaarzetten
    en op internet zoeken. Gebruik je tools wanneer een vraag daarom vraagt; vraag niet eerst om
    toestemming als het verzoek duidelijk is. Ontbreekt er essentiële informatie (bijvoorbeeld een
    tijdstip voor een afspraak), vraag daar dan kort naar. Bevestig na een actie kort wat je hebt gedaan.

    Datums en tijden geef je aan tools door als ISO 8601 met tijdzone, bijvoorbeeld
    2026-11-18T14:00:00+01:00. Elk bericht van de gebruiker eindigt met de huidige datum en tijd.
    Een e-mail wordt nooit automatisch verstuurd: je opent een concept en de gebruiker tikt zelf op versturen.
    """

    func reset() { history.removeAll() }

    /// Stelt een vraag en geeft het uiteindelijke tekstantwoord terug.
    func ask(_ text: String) async throws -> String {
        guard let apiKey = Keychain.load() else {
            return "Stel eerst je Claude API-sleutel in via Instellingen in de app."
        }
        let model = UserDefaults.standard.string(forKey: "model") ?? ClaudeClient.defaultModel
        let client = ClaudeClient(apiKey: apiKey, model: model)

        let snapshot = history
        let now = Self.nowFormatter.string(from: Date())
        let weekday = Self.weekdayFormatter.string(from: Date())
        history.append(["role": "user", "content": "\(text)\n\n(Nu: \(weekday) \(now))"])

        do {
            for _ in 0..<12 {
                let response = try await client.send(system: Self.systemPrompt,
                                                     messages: history,
                                                     tools: ToolExecutor.toolDefinitions)
                let content = response["content"] as? [[String: Any]] ?? []
                let stopReason = response["stop_reason"] as? String ?? ""
                history.append(["role": "assistant", "content": content])

                switch stopReason {
                case "tool_use":
                    var results: [[String: Any]] = []
                    for block in content where block["type"] as? String == "tool_use" {
                        let id = block["id"] as? String ?? ""
                        let name = block["name"] as? String ?? ""
                        let input = block["input"] as? [String: Any] ?? [:]
                        let outcome = await executor.run(name: name, input: input)
                        var result: [String: Any] = [
                            "type": "tool_result",
                            "tool_use_id": id,
                            "content": outcome.text,
                        ]
                        if outcome.isError { result["is_error"] = true }
                        results.append(result)
                    }
                    // Alle resultaten in één user-bericht.
                    history.append(["role": "user", "content": results])
                case "pause_turn":
                    continue // server-tool (web search) wil verder: opnieuw versturen
                case "refusal":
                    return "Daar kan ik je helaas niet mee helpen."
                case "max_tokens":
                    return Self.text(of: content) + " …(antwoord afgekapt)"
                default:
                    let answer = Self.text(of: content)
                    return answer.isEmpty ? "Klaar." : answer
                }
            }
            return "Dat lukte niet binnen een redelijk aantal stappen. Probeer het anders te vragen."
        } catch {
            history = snapshot // gesprek niet in een halve toestand achterlaten
            throw error
        }
    }

    private static func text(of content: [[String: Any]]) -> String {
        content
            .filter { $0["type"] as? String == "text" }
            .compactMap { $0["text"] as? String }
            .joined()
            .trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private static let nowFormatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = .current
        return f
    }()

    private static let weekdayFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "nl_NL")
        f.dateFormat = "EEEE"
        return f
    }()
}
