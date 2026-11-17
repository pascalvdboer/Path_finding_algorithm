import AppIntents

/// Maakt "Hé Siri, vraag Assistent" mogelijk. Siri vraagt daarna wat je wilt weten,
/// stuurt dat naar Claude (met dezelfde agenda/herinneringen-tools) en leest het antwoord voor.
struct AskAssistantIntent: AppIntent {
    static var title: LocalizedStringResource = "Vraag de assistent"
    static var description = IntentDescription("Stel een vraag aan je Claude-assistent.")
    static var openAppWhenRun = false

    @Parameter(title: "Vraag", requestValueDialog: "Wat wil je vragen?")
    var question: String

    @MainActor
    func perform() async throws -> some IntentResult & ReturnsValue<String> & ProvidesDialog {
        let agent = AssistantAgent(executor: ToolExecutor())
        let answer: String
        do {
            answer = try await agent.ask(question)
        } catch {
            answer = "Dat lukte niet: \(error.localizedDescription)"
        }
        return .result(value: answer, dialog: "\(answer)")
    }
}

struct AssistentShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: AskAssistantIntent(),
            phrases: [
                "Vraag \(.applicationName)",
                "Vraag het aan \(.applicationName)",
                "Hé \(.applicationName)",
            ],
            shortTitle: "Vraag de assistent",
            systemImageName: "sparkles"
        )
    }
}
