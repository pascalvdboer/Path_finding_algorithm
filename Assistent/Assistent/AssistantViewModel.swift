import Foundation
import SwiftUI
import UIKit
import MessageUI

struct ChatMessage: Identifiable {
    enum Role { case user, assistant, error }
    let id = UUID()
    let role: Role
    let text: String
}

@MainActor
final class AssistantViewModel: ObservableObject {
    @Published var messages: [ChatMessage] = []
    @Published var input = ""
    @Published var isThinking = false
    @Published var pendingEmail: EmailDraft?

    var autoSpeak: Bool { UserDefaults.standard.object(forKey: "autoSpeak") as? Bool ?? true }

    let speech = SpeechManager()
    private let executor = ToolExecutor()
    private lazy var agent = AssistantAgent(executor: executor)

    init() {
        executor.onComposeEmail = { [weak self] draft in
            guard let self else { return false }
            if MFMailComposeViewController.canSendMail() {
                self.pendingEmail = draft
                return true
            }
            // Geen Mail-account ingesteld: val terug op een mailto-link (bijv. Gmail-app).
            var components = URLComponents()
            components.scheme = "mailto"
            components.path = draft.to.joined(separator: ",")
            components.queryItems = [
                URLQueryItem(name: "subject", value: draft.subject),
                URLQueryItem(name: "body", value: draft.body),
            ]
            if let url = components.url {
                UIApplication.shared.open(url)
                return true
            }
            return false
        }
    }

    var hasApiKey: Bool { Keychain.load() != nil }

    func onAppear() async {
        _ = await speech.requestPermissions()
        try? await executor.ensureCalendarAccess()
        try? await executor.ensureRemindersAccess()
    }

    func toggleListening() {
        if speech.isRecording {
            let text = speech.stopRecording()
            if !text.isEmpty { send(text, spoken: true) }
        } else {
            do { try speech.startRecording() }
            catch { messages.append(ChatMessage(role: .error, text: "Microfoon kon niet starten: \(error.localizedDescription)")) }
        }
    }

    func sendTyped() {
        let text = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }
        input = ""
        send(text, spoken: false)
    }

    func newConversation() {
        speech.stopSpeaking()
        agent.reset()
        messages.removeAll()
    }

    private func send(_ text: String, spoken: Bool) {
        guard !isThinking else { return }
        messages.append(ChatMessage(role: .user, text: text))
        isThinking = true
        Task {
            defer { isThinking = false }
            do {
                let answer = try await agent.ask(text)
                messages.append(ChatMessage(role: .assistant, text: answer))
                if autoSpeak || spoken { speech.speak(answer) }
            } catch {
                messages.append(ChatMessage(role: .error, text: error.localizedDescription))
            }
        }
    }
}
