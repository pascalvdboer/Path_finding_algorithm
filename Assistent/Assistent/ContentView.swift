import SwiftUI
import PhotosUI

struct ContentView: View {
    @EnvironmentObject private var vm: AssistantViewModel
    @StateObject private var speechObserver = SpeechManagerObserver()
    @State private var showSettings = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                messageList
                Divider()
                inputBar
            }
            .navigationTitle("Assistent")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button { vm.newConversation() } label: { Image(systemName: "square.and.pencil") }
                        .accessibilityLabel("Nieuw gesprek")
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showSettings = true } label: { Image(systemName: "gearshape") }
                        .accessibilityLabel("Instellingen")
                }
            }
            .sheet(isPresented: $showSettings) { SettingsView() }
            .sheet(item: $vm.pendingEmail) { draft in
                MailComposer(draft: draft) { vm.pendingEmail = nil }.ignoresSafeArea()
            }
            .sheet(item: $vm.pendingMessage) { draft in
                MessageComposer(draft: draft) { vm.pendingMessage = nil }.ignoresSafeArea()
            }
            .alert(vm.pendingConfirmation?.title ?? "",
                   isPresented: Binding(
                       get: { vm.pendingConfirmation != nil },
                       set: { if !$0 { vm.resolveConfirmation(false) } }),
                   presenting: vm.pendingConfirmation) { request in
                Button(request.action) { vm.resolveConfirmation(true) }
                Button("Annuleren", role: .cancel) { vm.resolveConfirmation(false) }
            } message: { request in
                Text(request.message)
            }
            .task {
                speechObserver.bind(vm.speech)
                await vm.onAppear()
                if !vm.hasApiKey { showSettings = true } else { startListeningIfRequested() }
            }
            .onReceive(NotificationCenter.default.publisher(for: .startListening)) { _ in
                startListeningIfRequested()
            }
        }
    }

    private func startListeningIfRequested() {
        guard ListenRequest.pending else { return }
        ListenRequest.pending = false
        if !speechObserver.isRecording && !vm.isThinking { vm.toggleListening() }
    }

    private var messageList: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 12) {
                    if vm.messages.isEmpty { emptyState }
                    ForEach(vm.messages) { message in
                        MessageBubble(message: message).id(message.id)
                    }
                    if vm.isThinking {
                        HStack(spacing: 8) {
                            ProgressView()
                            Text("Even bezig…").foregroundStyle(.secondary)
                        }
                    }
                }
                .padding()
            }
            .onChange(of: vm.messages.count) {
                withAnimation { proxy.scrollTo(vm.messages.last?.id, anchor: .bottom) }
            }
        }
    }

    private var emptyState: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Tik op de microfoon en vraag bijvoorbeeld:")
                .font(.headline)
            ForEach([
                "Heb ik belangrijke mail? Lees de laatste van Jan voor.",
                "Antwoord hem dat donderdag goed is.",
                "Bel mama.",
                "App Lisa dat ik over tien minuten thuis ben.",
                "Wat staat er morgen in mijn agenda?",
                "Zet de wekker op 7 uur.",
                "Navigeer naar het station.",
                "Welke wijn past bij risotto?",
            ], id: \.self) { Text("• \($0)").foregroundStyle(.secondary) }
            Text("Je kunt ook een foto of schermafbeelding meesturen met 📎.")
                .font(.footnote)
                .foregroundStyle(.secondary)
                .padding(.top, 8)
        }
        .padding(.top, 30)
    }

    private var inputBar: some View {
        VStack(spacing: 10) {
            if let image = vm.attachedImage {
                HStack {
                    Image(uiImage: image)
                        .resizable().scaledToFill()
                        .frame(width: 56, height: 56)
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    Button { vm.attachedImage = nil; vm.pickedPhoto = nil } label: {
                        Image(systemName: "xmark.circle.fill").foregroundStyle(.secondary)
                    }
                    .accessibilityLabel("Foto verwijderen")
                    Spacer()
                }
            }
            if speechObserver.isRecording {
                Text(speechObserver.transcript.isEmpty ? "Ik luister…" : speechObserver.transcript)
                    .font(.callout)
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 12) {
                PhotosPicker(selection: $vm.pickedPhoto, matching: .images) {
                    Image(systemName: "paperclip").font(.title2)
                }
                .accessibilityLabel("Foto toevoegen")
                TextField("Typ een vraag…", text: $vm.input, axis: .vertical)
                    .textFieldStyle(.roundedBorder)
                    .lineLimit(1...4)
                    .onSubmit { vm.sendTyped() }
                if !vm.input.isEmpty || vm.attachedImage != nil {
                    Button { vm.sendTyped() } label: {
                        Image(systemName: "arrow.up.circle.fill").font(.title)
                    }
                    .disabled(vm.isThinking)
                }
                Button { vm.toggleListening() } label: {
                    Image(systemName: speechObserver.isRecording ? "stop.circle.fill" : "mic.circle.fill")
                        .font(.system(size: 44))
                        .foregroundStyle(speechObserver.isRecording ? .red : .accentColor)
                        .symbolEffect(.pulse, isActive: speechObserver.isRecording)
                }
                .disabled(vm.isThinking)
                .accessibilityLabel(speechObserver.isRecording ? "Stop met luisteren" : "Begin met praten")
            }
        }
        .padding()
        .background(.bar)
    }
}

/// Zorgt dat de view meeverandert met de SpeechManager (genest ObservableObject).
@MainActor
final class SpeechManagerObserver: ObservableObject {
    @Published var isRecording = false
    @Published var transcript = ""
    private var tasks: [Task<Void, Never>] = []

    func bind(_ speech: SpeechManager) {
        guard tasks.isEmpty else { return }
        tasks.append(Task { [weak self] in
            for await value in speech.$isRecording.values { self?.isRecording = value }
        })
        tasks.append(Task { [weak self] in
            for await value in speech.$transcript.values { self?.transcript = value }
        })
    }
}

struct MessageBubble: View {
    let message: ChatMessage

    var body: some View {
        HStack {
            if message.role == .user { Spacer(minLength: 40) }
            VStack(alignment: message.role == .user ? .trailing : .leading, spacing: 6) {
                if let image = message.image {
                    Image(uiImage: image)
                        .resizable().scaledToFit()
                        .frame(maxWidth: 200, maxHeight: 200)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                if !message.text.isEmpty {
                    Text(message.text)
                        .textSelection(.enabled)
                        .padding(12)
                        .background(background, in: RoundedRectangle(cornerRadius: 16))
                        .foregroundStyle(message.role == .user ? .white : .primary)
                }
            }
            if message.role != .user { Spacer(minLength: 40) }
        }
    }

    private var background: Color {
        switch message.role {
        case .user: return .accentColor
        case .assistant: return Color(.secondarySystemBackground)
        case .error: return .red.opacity(0.15)
        }
    }
}
