import SwiftUI

struct RecordingsView: View {
    @ObservedObject private var store = RecordingStore.shared
    @ObservedObject private var recorder = MeetingRecorder.shared
    @EnvironmentObject private var vm: AssistantViewModel
    @State private var error: String?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    recorderPanel
                } footer: {
                    if Keychain.load(account: "assemblyai") == nil {
                        Text("Voeg je AssemblyAI-sleutel toe in Instellingen (tab Assistent → ⚙️) om opnames uit te laten schrijven met sprekers.")
                    } else {
                        Text("Geen tijdslimiet. Opnemen gaat door met het scherm uit. Vertel de anderen dat je opneemt.")
                    }
                }

                if !store.recordings.isEmpty {
                    Section("Opnames") {
                        ForEach(store.recordings) { rec in
                            NavigationLink(value: rec.id) { RecordingRow(recording: rec) }
                                .disabled(rec.status == .recording)
                        }
                        .onDelete { offsets in
                            let ids = offsets.map { store.recordings[$0] }.filter { $0.status != .recording }.map(\.id)
                            for id in ids { store.delete(id) }
                        }
                    }
                }
            }
            .navigationTitle("Opnames")
            .navigationDestination(for: UUID.self) { RecordingDetailView(id: $0) }
            .alert("Opnemen mislukt", isPresented: Binding(get: { error != nil }, set: { if !$0 { error = nil } })) {
                Button("OK", role: .cancel) {}
            } message: { Text(error ?? "") }
            .onReceive(NotificationCenter.default.publisher(for: .startRecording)) { _ in startIfRequested() }
            .task { startIfRequested() }
        }
    }

    private func startIfRequested() {
        guard RecordRequest.pending else { return }
        RecordRequest.pending = false
        if !recorder.isRecording { start() }
    }

    private func start() {
        // Zorg dat de assistent niet meepraat of meeluistert in de opname.
        vm.speech.stopSpeaking()
        if vm.speech.isRecording { vm.speech.stopRecording() }
        Task {
            do { try await recorder.start() }
            catch { self.error = error.localizedDescription }
        }
    }

    @ViewBuilder
    private var recorderPanel: some View {
        if recorder.isRecording {
            VStack(spacing: 16) {
                Text(Recording.timestamp(Int(recorder.elapsed * 1000)))
                    .font(.system(size: 52, weight: .light, design: .monospaced))
                    .contentTransition(.numericText())
                Text(recorder.isPaused ? "Gepauzeerd" : "Bezig met opnemen")
                    .foregroundStyle(recorder.isPaused ? Color.orange : Color.red)
                LevelMeter(level: recorder.isPaused ? 0 : recorder.level)
                    .frame(height: 8)
                HStack(spacing: 40) {
                    Button { recorder.togglePause() } label: {
                        Image(systemName: recorder.isPaused ? "play.circle.fill" : "pause.circle.fill")
                            .font(.system(size: 56))
                    }
                    .accessibilityLabel(recorder.isPaused ? "Verder opnemen" : "Pauzeren")
                    Button { recorder.stop() } label: {
                        Image(systemName: "stop.circle.fill")
                            .font(.system(size: 56))
                            .foregroundStyle(Color.red)
                    }
                    .accessibilityLabel("Stop en uitschrijven")
                }
                .buttonStyle(.plain)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
        } else {
            Button { start() } label: {
                HStack(spacing: 14) {
                    Image(systemName: "record.circle.fill")
                        .font(.system(size: 44))
                        .foregroundStyle(Color.red)
                    VStack(alignment: .leading) {
                        Text("Gesprek opnemen").font(.headline)
                        Text("Uitschrijven met wie wat zegt, plus samenvatting en actiepunten")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                .padding(.vertical, 6)
            }
            .buttonStyle(.plain)
        }
    }
}

struct LevelMeter: View {
    let level: Float
    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(Color.secondary.opacity(0.2))
                Capsule().fill(Color.red)
                    .frame(width: geo.size.width * CGFloat(level))
                    .animation(.linear(duration: 0.2), value: level)
            }
        }
    }
}

struct RecordingRow: View {
    let recording: Recording

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(recording.title).font(.headline).lineLimit(2)
            HStack(spacing: 6) {
                Text(Recording.dateFormatter.string(from: recording.createdAt))
                Text("·")
                Text(Recording.durationText(recording.duration))
                if !recording.speakers.isEmpty {
                    Text("·")
                    Text("\(recording.speakers.count) sprekers")
                }
            }
            .font(.caption)
            .foregroundStyle(.secondary)
            if recording.status != .done {
                HStack(spacing: 6) {
                    if [.uploading, .transcribing, .summarizing].contains(recording.status) {
                        ProgressView().controlSize(.mini)
                    }
                    Text(recording.statusText)
                        .font(.caption)
                        .foregroundStyle(recording.status == .failed ? Color.red : Color.secondary)
                        .lineLimit(2)
                }
            }
        }
        .padding(.vertical, 2)
    }
}

struct RecordingDetailView: View {
    let id: UUID
    @ObservedObject private var store = RecordingStore.shared
    @State private var editingSpeakers = false

    private static let palette: [Color] = [.blue, .orange, .green, .purple, .pink, .teal, .brown, .indigo]

    var body: some View {
        if let rec = store.recording(id) {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    TextField("Titel", text: Binding(
                        get: { rec.title },
                        set: { value in store.update(id) { $0.title = value } }))
                        .font(.title2.bold())

                    Text("\(Recording.dateFormatter.string(from: rec.createdAt)) · \(Recording.durationText(rec.duration))")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)

                    if rec.status != .done {
                        HStack {
                            if rec.status != .failed { ProgressView() }
                            Text(rec.statusText)
                                .foregroundStyle(rec.status == .failed ? Color.red : Color.secondary)
                            Spacer()
                            if rec.status == .failed {
                                Button("Opnieuw") { store.retry(id) }.buttonStyle(.borderedProminent)
                            }
                        }
                    }

                    if let summary = rec.summary, !summary.isEmpty {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Samenvatting", systemImage: "sparkles").font(.headline)
                            Text(summary).textSelection(.enabled)
                        }
                        .padding()
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 12))
                    }

                    if !rec.speakers.isEmpty {
                        VStack(alignment: .leading, spacing: 8) {
                            HStack {
                                Text("Sprekers").font(.headline)
                                Spacer()
                                Button(editingSpeakers ? "Klaar" : "Namen wijzigen") { editingSpeakers.toggle() }
                                    .font(.subheadline)
                            }
                            ForEach(rec.speakers, id: \.self) { speaker in
                                HStack {
                                    Circle().fill(color(for: speaker, in: rec)).frame(width: 12, height: 12)
                                    if editingSpeakers {
                                        TextField("Spreker \(speaker)", text: Binding(
                                            get: { rec.speakerNames[speaker] ?? "" },
                                            set: { value in store.update(id) { $0.speakerNames[speaker] = value } }))
                                            .textFieldStyle(.roundedBorder)
                                    } else {
                                        Text(rec.name(of: speaker))
                                    }
                                }
                            }
                        }
                    }

                    if !rec.utterances.isEmpty {
                        VStack(alignment: .leading, spacing: 14) {
                            Text("Transcript").font(.headline)
                            ForEach(rec.utterances) { u in
                                VStack(alignment: .leading, spacing: 3) {
                                    HStack(spacing: 6) {
                                        Text(rec.name(of: u.speaker))
                                            .font(.subheadline.bold())
                                            .foregroundStyle(color(for: u.speaker, in: rec))
                                        Text(Recording.timestamp(u.start))
                                            .font(.caption.monospacedDigit())
                                            .foregroundStyle(.secondary)
                                    }
                                    Text(u.text).textSelection(.enabled)
                                }
                            }
                        }
                    }
                }
                .padding()
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        ShareLink(item: rec.exportText) { Label("Deel tekst", systemImage: "doc.text") }
                        ShareLink(item: store.audioURL(for: rec)) { Label("Deel audio", systemImage: "waveform") }
                    } label: {
                        Image(systemName: "square.and.arrow.up")
                    }
                }
            }
        } else {
            Text("Opname niet gevonden.").foregroundStyle(.secondary)
        }
    }

    private func color(for speaker: String, in rec: Recording) -> Color {
        let index = rec.speakers.firstIndex(of: speaker) ?? 0
        return Self.palette[index % Self.palette.count]
    }
}
