import AppIntents

/// "Hé Siri, vraag Assistent": Siri vraagt wat je wilt weten, stuurt het naar Claude
/// en leest het antwoord voor. Agenda, herinneringen, contacten, Gmail lezen, locatie
/// en internet werken ook via Siri. Voor bellen, berichten en versturen opent de app.
struct AskAssistantIntent: AppIntent {
    static var title: LocalizedStringResource = "Vraag de assistent"
    static var description = IntentDescription("Stel een vraag aan je Claude-assistent.")
    static var openAppWhenRun = false

    @Parameter(title: "Vraag", requestValueDialog: "Wat wil je vragen?")
    var question: String

    @MainActor
    func perform() async throws -> some IntentResult & ReturnsValue<String> & ProvidesDialog {
        let agent = AssistantAgent(executor: ToolExecutor()) // geen UI: tools die een scherm nodig hebben melden dat netjes
        let answer: String
        do {
            answer = try await agent.ask(question)
        } catch {
            answer = "Dat lukte niet: \(error.localizedDescription)"
        }
        return .result(value: answer, dialog: "\(answer)")
    }
}

/// Opent de app en begint direct te luisteren. Handig voor de Actieknop.
struct OpenAssistantIntent: AppIntent {
    static var title: LocalizedStringResource = "Open de assistent"
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        ListenRequest.pending = true
        NotificationCenter.default.post(name: .startListening, object: nil)
        return .result()
    }
}

/// Opent de app op het tabblad Opnames en begint direct met opnemen.
struct StartRecordingIntent: AppIntent {
    static var title: LocalizedStringResource = "Start gespreksopname"
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        RecordRequest.pending = true
        NotificationCenter.default.post(name: .startRecording, object: nil)
        return .result()
    }
}

@MainActor
enum ListenRequest {
    static var pending = false
}

@MainActor
enum RecordRequest {
    static var pending = false
}

extension Notification.Name {
    static let startListening = Notification.Name("assistent.startListening")
    static let startRecording = Notification.Name("assistent.startRecording")
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
        AppShortcut(
            intent: OpenAssistantIntent(),
            phrases: ["Open \(.applicationName)", "Start \(.applicationName)"],
            shortTitle: "Open en luister",
            systemImageName: "mic.fill"
        )
        AppShortcut(
            intent: StartRecordingIntent(),
            phrases: ["Neem op met \(.applicationName)", "Start opname in \(.applicationName)"],
            shortTitle: "Start opname",
            systemImageName: "record.circle"
        )
    }
}
