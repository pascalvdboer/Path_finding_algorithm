import SwiftUI

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
                MailComposer(draft: draft) { vm.pendingEmail = nil }
                    .ignoresSafeArea()
            }
            .task {
                speechObserver.bind(vm.speech)
                await vm.onAppear()
                if !vm.hasApiKey { showSettings = true }
            }
        }
    }

    private var messageList: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 12) {
                    if vm.messages.isEmpty {
                        emptyState
                    }
                    ForEach(vm.messages) { message in
                        MessageBubble(message: message).id(message.id)
                    }
                    if vm.isThinking {
                        HStack(spacing: 8) {
                            ProgressView()
                            Text("Even denken…").foregroundStyle(.secondary)
                        }
                        .id("thinking")
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
                "Wat staat er morgen in mijn agenda?",
                "Zet vrijdag om 10 uur een afspraak met de tandarts.",
                "Herinner me vanavond om 8 uur aan de vuilnisbak.",
                "Mail Jan dat ik later kom.",
                "Wat voor weer wordt het morgen in Utrecht?",
            ], id: \.self) { Text("• \($0)").foregroundStyle(.secondary) }
        }
        .padding(.top, 40)
    }

    private var inputBar: some View {
        VStack(spacing: 10) {
            if speechObserver.isRecording {
                Text(speechObserver.transcript.isEmpty ? "Ik luister…" : speechObserver.transcript)
                    .font(.callout)
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            HStack(spacing: 12) {
                TextField("Typ een vraag…", text: $vm.input, axis: .vertical)
                    .textFieldStyle(.roundedBorder)
                    .lineLimit(1...4)
                    .onSubmit { vm.sendTyped() }
                if !vm.input.isEmpty {
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

/// Doorgeeflik zodat de view meeverandert met de SpeechManager (genest ObservableObject).
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
            Text(message.text)
                .textSelection(.enabled)
                .padding(12)
                .background(background, in: RoundedRectangle(cornerRadius: 16))
                .foregroundStyle(message.role == .user ? .white : .primary)
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
