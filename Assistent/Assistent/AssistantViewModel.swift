import Foundation
import SwiftUI
import UIKit
import MessageUI
import PhotosUI

struct ChatMessage: Identifiable {
    enum Role { case user, assistant, error }
    let id = UUID()
    let role: Role
    let text: String
    var image: UIImage? = nil
}

struct ConfirmationRequest: Identifiable {
    let id = UUID()
    let title: String
    let message: String
    let action: String
    let resolve: (Bool) -> Void
}

@MainActor
final class AssistantViewModel: ObservableObject, AssistantUI {
    @Published var messages: [ChatMessage] = []
    @Published var input = ""
    @Published var isThinking = false
    @Published var pendingEmail: EmailDraft?
    @Published var pendingMessage: MessageDraft?
    @Published var pendingConfirmation: ConfirmationRequest?
    @Published var attachedImage: UIImage?
    @Published var pickedPhoto: PhotosPickerItem? {
        didSet { if let pickedPhoto { Task { await loadPhoto(pickedPhoto) } } }
    }

    var autoSpeak: Bool { UserDefaults.standard.object(forKey: "autoSpeak") as? Bool ?? true }

    let speech = SpeechManager()
    private let executor = ToolExecutor()
    private lazy var agent = AssistantAgent(executor: executor)

    init() {
        executor.ui = self
    }

    var hasApiKey: Bool { Keychain.load() != nil }

    func onAppear() async {
        _ = await speech.requestPermissions()
        try? await executor.ensureCalendarAccess()
        try? await executor.ensureRemindersAccess()
        try? await executor.ensureContactsAccess()
    }

    // MARK: - Invoer

    func toggleListening() {
        if MeetingRecorder.shared.isRecording && !speech.isRecording {
            messages.append(ChatMessage(role: .error, text: "Er loopt een gespreksopname. Typ je vraag, of stop eerst de opname."))
            return
        }
        if speech.isRecording {
            let text = speech.stopRecording()
            if !text.isEmpty || attachedImage != nil { send(text, spoken: true) }
        } else {
            do { try speech.startRecording() }
            catch { messages.append(ChatMessage(role: .error, text: "Microfoon kon niet starten: \(error.localizedDescription)")) }
        }
    }

    func sendTyped() {
        let text = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty || attachedImage != nil else { return }
        input = ""
        send(text, spoken: false)
    }

    func newConversation() {
        speech.stopSpeaking()
        agent.reset()
        messages.removeAll()
        attachedImage = nil
    }

    private func send(_ text: String, spoken: Bool) {
        guard !isThinking else { return }
        let image = attachedImage
        attachedImage = nil
        pickedPhoto = nil
        messages.append(ChatMessage(role: .user, text: text, image: image))
        isThinking = true
        Task {
            defer { isThinking = false }
            do {
                let answer = try await agent.ask(text, imageJPEG: image.flatMap { Self.jpegForUpload($0) })
                messages.append(ChatMessage(role: .assistant, text: answer))
                if (autoSpeak || spoken) && !MeetingRecorder.shared.isRecording { speech.speak(answer) }
            } catch {
                messages.append(ChatMessage(role: .error, text: error.localizedDescription))
            }
        }
    }

    // MARK: - Proactief

    static let briefingPrompt = """
    Geef me een korte, proactieve briefing voor dit moment. Kijk zelf naar mijn agenda (vandaag en morgen), \
    openstaande herinneringen, ongelezen of belangrijke mail van de laatste dag (als Gmail gekoppeld is), \
    recente gespreksopnames met open actiepunten, en wat je over mij weet. Noem alleen wat er nu toe doet, \
    zoals de eerstvolgende afspraak en of ik op tijd moet vertrekken, iets wat botst, een mail die antwoord \
    nodig heeft of een verjaardag. Sluit af met één concreet voorstel wat je voor me kunt doen. Maximaal vijf zinnen.
    """

    /// Automatisch bij openen (hooguit eens per 3 uur) of als je op de briefing-knop tikt.
    func briefing(automatic: Bool) {
        guard hasApiKey, !isThinking else { return }
        if automatic {
            guard UserDefaults.standard.object(forKey: "autoBriefing") as? Bool ?? true else { return }
            let last = UserDefaults.standard.double(forKey: "lastBriefing")
            guard Date().timeIntervalSince1970 - last > 3 * 3600 else { return }
        }
        UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: "lastBriefing")
        isThinking = true
        Task {
            defer { isThinking = false }
            do {
                let answer = try await agent.ask(Self.briefingPrompt)
                messages.append(ChatMessage(role: .assistant, text: answer))
                if !automatic && autoSpeak && !MeetingRecorder.shared.isRecording { speech.speak(answer) }
            } catch {
                if !automatic { messages.append(ChatMessage(role: .error, text: error.localizedDescription)) }
            }
        }
    }

    private func loadPhoto(_ item: PhotosPickerItem) async {
        if let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) {
            attachedImage = image
        }
    }

    /// Verkleint naar max. 1568 px (aanbevolen formaat voor Claude) en comprimeert als JPEG.
    private static func jpegForUpload(_ image: UIImage) -> Data? {
        let maxSide: CGFloat = 1568
        let scale = min(1, maxSide / max(image.size.width, image.size.height))
        let size = CGSize(width: image.size.width * scale, height: image.size.height * scale)
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        let resized = UIGraphicsImageRenderer(size: size, format: format).image { _ in
            image.draw(in: CGRect(origin: .zero, size: size))
        }
        return resized.jpegData(compressionQuality: 0.8)
    }

    // MARK: - AssistantUI

    func presentEmail(_ draft: EmailDraft) -> Bool {
        if MFMailComposeViewController.canSendMail() {
            pendingEmail = draft
            return true
        }
        var components = URLComponents()
        components.scheme = "mailto"
        components.path = draft.to.joined(separator: ",")
        components.queryItems = [URLQueryItem(name: "subject", value: draft.subject),
                                 URLQueryItem(name: "body", value: draft.body)]
        guard let url = components.url else { return false }
        UIApplication.shared.open(url)
        return true
    }

    func presentMessage(_ draft: MessageDraft) -> Bool {
        guard MFMessageComposeViewController.canSendText() else { return false }
        pendingMessage = draft
        return true
    }

    func confirm(title: String, message: String, action: String) async -> Bool {
        speech.stopSpeaking()
        return await withCheckedContinuation { continuation in
            pendingConfirmation?.resolve(false)
            pendingConfirmation = ConfirmationRequest(title: title, message: message, action: action) {
                continuation.resume(returning: $0)
            }
        }
    }

    func resolveConfirmation(_ approved: Bool) {
        guard let request = pendingConfirmation else { return }
        pendingConfirmation = nil
        request.resolve(approved)
    }

    func open(_ url: URL) async -> Bool {
        speech.stopSpeaking()
        return await UIApplication.shared.open(url)
    }
}
