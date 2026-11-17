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

    private static let basePrompt = """
    Je bent de persoonlijke assistent van de gebruiker en draait als app op zijn of haar iPhone, zoals Siri, \
    maar slimmer en behulpzamer. Je antwoorden worden vaak hardop voorgelezen. Antwoord daarom in het Nederlands, \
    kort en natuurlijk gesproken: meestal één tot drie zinnen, zonder markdown, opsommingstekens, emoji of URL's. \
    Vraagt de gebruiker om uitleg of advies, dan mag het langer, maar blijf helder en spreekbaar.

    Wat je kunt:
    - Agenda en herinneringen lezen, aanmaken, afvinken en verwijderen.
    - Contacten opzoeken, iemand bellen of FaceTimen, en een SMS/iMessage of WhatsApp-bericht klaarzetten.
    - Gmail doorzoeken, e-mails lezen en samenvatten, antwoorden opstellen en versturen, archiveren en markeren.
    - De locatie van de gebruiker opvragen en een route openen in Kaarten.
    - Andere apps openen en Opdrachten (Shortcuts) uitvoeren, waarmee je de wekker, timers, Focus, \
    helderheid, slimme lampen en meer kunt bedienen.
    - Op internet zoeken en webpagina's lezen voor actuele informatie (weer, nieuws, openingstijden, prijzen).
    - Foto's en schermafbeeldingen bekijken die de gebruiker meestuurt.
    - Feiten en voorkeuren onthouden met remember, zodat je de gebruiker steeds beter leert kennen.

    Werkwijze:
    - Gebruik je tools zelfstandig als een verzoek duidelijk is; vraag niet eerst om toestemming. \
    Zoek bijvoorbeeld zelf het nummer op als iemand zegt "bel Jan". Zijn er meerdere passende contacten \
    of nummers, vraag dan kort welke.
    - Bij versturen namens de gebruiker (e-mail) vraagt de app zelf om bevestiging op het scherm. \
    Schrijf e-mails in de toon van de gebruiker, met passende aanhef en afsluiting.
    - Berichten en WhatsApp worden alleen klaargezet; de gebruiker tikt zelf op versturen.
    - Kun je iets niet (bijvoorbeeld een instelling waarvoor geen opdracht bestaat), zeg dat eerlijk en \
    leg in één zin uit hoe de gebruiker het zelf kan doen of welke opdracht hij kan aanmaken.
    - Hoor je iets wat de moeite waard is om te onthouden (namen van naasten, werk, voorkeuren), \
    sla het dan op met remember.
    - Bevestig na een actie kort wat je hebt gedaan.

    Datums en tijden geef je aan tools door als ISO 8601 met tijdzone, bijvoorbeeld 2026-11-18T14:00:00+01:00. \
    Elk bericht van de gebruiker eindigt met de huidige datum en tijd.
    """

    /// De systeemprompt bevat ook wat de assistent onthouden heeft en welke Opdrachten er zijn.
    static func systemPrompt() -> String {
        var prompt = basePrompt
        let facts = MemoryStore.shared.facts
        if !facts.isEmpty {
            prompt += "\n\nWat je over de gebruiker weet:\n" + facts.map { "- \($0)" }.joined(separator: "\n")
        }
        let shortcuts = (UserDefaults.standard.string(forKey: "shortcutNames") ?? "")
            .split(whereSeparator: \.isNewline)
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
        if shortcuts.isEmpty {
            prompt += "\n\nDe gebruiker heeft nog geen Opdrachten opgegeven. run_shortcut werkt dus nog niet; leg uit dat ze die in Instellingen van de app kunnen toevoegen."
        } else {
            prompt += "\n\nOpdrachten (Shortcuts) die je met run_shortcut kunt uitvoeren:\n" + shortcuts.map { "- \($0)" }.joined(separator: "\n")
        }
        if !GoogleAuth.shared.isConnected {
            prompt += "\n\nGmail is nog niet gekoppeld. Voor e-mail lezen moet de gebruiker dat eerst in Instellingen doen; voor versturen kun je compose_email gebruiken."
        }
        return prompt
    }

    func reset() { history.removeAll() }

    /// Stelt een vraag (eventueel met een foto) en geeft het uiteindelijke tekstantwoord terug.
    func ask(_ text: String, imageJPEG: Data? = nil) async throws -> String {
        guard let apiKey = Keychain.load() else {
            return "Stel eerst je Claude API-sleutel in via Instellingen in de app."
        }
        let model = UserDefaults.standard.string(forKey: "model") ?? ClaudeClient.defaultModel
        let client = ClaudeClient(apiKey: apiKey, model: model)

        let snapshot = history
        let stamped = "\(text.isEmpty ? "Wat zie je hier?" : text)\n\n(Nu: \(Self.weekdayFormatter.string(from: Date())) \(Self.nowFormatter.string(from: Date())))"
        if let imageJPEG {
            let imageBlock: [String: Any] = [
                "type": "image",
                "source": ["type": "base64", "media_type": "image/jpeg", "data": imageJPEG.base64EncodedString()],
            ]
            let textBlock: [String: Any] = ["type": "text", "text": stamped]
            let message: [String: Any] = ["role": "user", "content": [imageBlock, textBlock]]
            history.append(message)
        } else {
            history.append(["role": "user", "content": stamped])
        }

        do {
            for _ in 0..<20 {
                let response = try await client.send(system: Self.systemPrompt(),
                                                     messages: history,
                                                     tools: ToolExecutor.toolDefinitions)
                let content = response["content"] as? [[String: Any]] ?? []
                let stopReason = response["stop_reason"] as? String ?? ""
                history.append(["role": "assistant", "content": content])

                switch stopReason {
                case "tool_use":
                    var results: [[String: Any]] = []
                    for block in content where block["type"] as? String == "tool_use" {
                        let outcome = await executor.run(name: block["name"] as? String ?? "",
                                                         input: block["input"] as? [String: Any] ?? [:])
                        var result: [String: Any] = [
                            "type": "tool_result",
                            "tool_use_id": block["id"] as? String ?? "",
                            "content": outcome.text,
                        ]
                        if outcome.isError { result["is_error"] = true }
                        results.append(result)
                    }
                    history.append(["role": "user", "content": results]) // alle resultaten in één bericht
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
