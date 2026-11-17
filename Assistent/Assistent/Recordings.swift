import Foundation
import AVFoundation
import UIKit

// MARK: - Model

struct Utterance: Codable, Identifiable, Hashable {
    let speaker: String
    let text: String
    let start: Int   // milliseconden
    let end: Int
    var id: String { "\(start)-\(speaker)" }
}

struct Recording: Codable, Identifiable {
    enum Status: String, Codable {
        case recording, recorded, uploading, transcribing, summarizing, done, failed
    }

    let id: UUID
    var title: String
    let createdAt: Date
    var duration: TimeInterval = 0
    var status: Status = .recording
    var errorMessage: String?
    var transcriptID: String?
    var utterances: [Utterance] = []
    var speakerNames: [String: String] = [:]
    var summary: String?

    var fileName: String { "\(id.uuidString).m4a" }

    func name(of speaker: String) -> String {
        if let name = speakerNames[speaker], !name.isEmpty { return name }
        return "Spreker \(speaker)"
    }

    var speakers: [String] {
        var seen: [String] = []
        for u in utterances where !seen.contains(u.speaker) { seen.append(u.speaker) }
        return seen
    }

    /// Volledig transcript als platte tekst met namen en tijdstempels.
    var transcriptText: String {
        utterances.map { "[\(Recording.timestamp($0.start))] \(name(of: $0.speaker)): \($0.text)" }
            .joined(separator: "\n")
    }

    var exportText: String {
        var text = "\(title)\n\(Recording.dateFormatter.string(from: createdAt)) · \(Recording.durationText(duration))\n\n"
        if let summary { text += "\(summary)\n\n" }
        text += "TRANSCRIPT\n\n" + transcriptText
        return text
    }

    var statusText: String {
        switch status {
        case .recording: return "Bezig met opnemen…"
        case .recorded: return "Wacht op uitschrijven"
        case .uploading: return "Uploaden…"
        case .transcribing: return "Uitschrijven met sprekers…"
        case .summarizing: return "Samenvatten…"
        case .done: return "Klaar"
        case .failed: return errorMessage ?? "Mislukt"
        }
    }

    static func timestamp(_ ms: Int) -> String {
        let s = ms / 1000
        return s >= 3600 ? String(format: "%d:%02d:%02d", s / 3600, s / 60 % 60, s % 60)
                         : String(format: "%02d:%02d", s / 60, s % 60)
    }

    static func durationText(_ t: TimeInterval) -> String {
        let s = Int(t)
        if s >= 3600 { return "\(s / 3600) u \(s / 60 % 60) min" }
        if s >= 60 { return "\(s / 60) min" }
        return "\(s) sec"
    }

    static let dateFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "nl_NL")
        f.dateStyle = .medium
        f.timeStyle = .short
        return f
    }()
}

// MARK: - Opslag en verwerking

@MainActor
final class RecordingStore: ObservableObject {
    static let shared = RecordingStore()

    @Published private(set) var recordings: [Recording] = []
    private var processing: Set<UUID> = []

    let directory: URL = {
        let dir = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Opnames", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }()
    private var indexURL: URL { directory.appendingPathComponent("index.json") }

    private init() {
        if let data = try? Data(contentsOf: indexURL),
           let list = try? Self.decoder.decode([Recording].self, from: data) {
            recordings = list
        }
        // Een opname die nog op "recording" staat, is onderbroken doordat de app werd afgesloten.
        for i in recordings.indices where recordings[i].status == .recording {
            recordings[i].status = .failed
            recordings[i].errorMessage = "Opname onderbroken (app afgesloten)."
        }
    }

    func audioURL(for recording: Recording) -> URL {
        directory.appendingPathComponent(recording.fileName)
    }

    func recording(_ id: UUID) -> Recording? { recordings.first { $0.id == id } }

    func create() -> Recording {
        let title = "Opname \(Recording.dateFormatter.string(from: Date()))"
        let rec = Recording(id: UUID(), title: title, createdAt: Date())
        recordings.insert(rec, at: 0)
        save()
        return rec
    }

    func update(_ id: UUID, _ change: (inout Recording) -> Void) {
        guard let i = recordings.firstIndex(where: { $0.id == id }) else { return }
        change(&recordings[i])
        save()
    }

    func delete(_ id: UUID) {
        if let rec = recording(id) { try? FileManager.default.removeItem(at: audioURL(for: rec)) }
        recordings.removeAll { $0.id == id }
        save()
    }

    private func save() {
        if let data = try? Self.encoder.encode(recordings) {
            try? data.write(to: indexURL, options: .atomic)
        }
    }

    /// Hervat opnames die nog niet volledig verwerkt zijn (bijv. na het sluiten van de app).
    func resumePending() {
        for rec in recordings where [.recorded, .uploading, .transcribing, .summarizing].contains(rec.status) {
            Task { await process(rec.id) }
        }
    }

    /// Upload -> AssemblyAI (uitschrijven + sprekers) -> Claude (samenvatting). Kan worden hervat.
    func process(_ id: UUID) async {
        guard !processing.contains(id), let rec = recording(id) else { return }
        guard let key = Keychain.load(account: "assemblyai") else {
            update(id) { $0.status = .failed; $0.errorMessage = "Voeg je AssemblyAI-sleutel toe in Instellingen en tik op Opnieuw." }
            return
        }
        processing.insert(id)
        var taskID = UIBackgroundTaskIdentifier.invalid
        taskID = UIApplication.shared.beginBackgroundTask(withName: "transcribe") {
            UIApplication.shared.endBackgroundTask(taskID)
            taskID = .invalid
        }
        defer {
            processing.remove(id)
            if taskID != .invalid { UIApplication.shared.endBackgroundTask(taskID) }
        }

        do {
            var transcriptID = rec.transcriptID
            if transcriptID == nil {
                update(id) { $0.status = .uploading; $0.errorMessage = nil }
                let uploadURL = try await AssemblyAI.upload(file: audioURL(for: rec), key: key)
                transcriptID = try await AssemblyAI.requestTranscript(audioURL: uploadURL, key: key)
                update(id) { $0.transcriptID = transcriptID }
            }
            update(id) { $0.status = .transcribing; $0.errorMessage = nil }
            let utterances = try await AssemblyAI.waitForTranscript(id: transcriptID!, key: key)
            update(id) { $0.utterances = utterances; $0.status = .summarizing }

            if let current = recording(id), !utterances.isEmpty {
                do {
                    try await summarize(current)
                } catch {
                    update(id) { $0.summary = "Samenvatting mislukt: \(error.localizedDescription)" }
                }
            }
            update(id) { $0.status = .done }
        } catch {
            update(id) { $0.status = .failed; $0.errorMessage = error.localizedDescription }
        }
    }

    func retry(_ id: UUID) {
        update(id) {
            // Bij een fout in AssemblyAI zelf opnieuw uploaden; anders verder waar we waren.
            if $0.errorMessage?.hasPrefix("AssemblyAI kon") == true { $0.transcriptID = nil }
            $0.status = .recorded
        }
        Task { await process(id) }
    }

    private func summarize(_ rec: Recording) async throws {
        guard let apiKey = Keychain.load() else { return }
        let model = UserDefaults.standard.string(forKey: "model") ?? ClaudeClient.defaultModel
        let client = ClaudeClient(apiKey: apiKey, model: model)
        let facts = MemoryStore.shared.facts.map { "- \($0)" }.joined(separator: "\n")

        let system = """
        Je verwerkt het transcript van een gesprek dat de gebruiker heeft opgenomen. De sprekers zijn \
        automatisch herkend als A, B, C enzovoort. Schrijf in het Nederlands.
        \(facts.isEmpty ? "" : "\nWat je over de gebruiker weet:\n\(facts)\n")
        Geef je antwoord exact in deze vorm:
        TITEL: <korte titel van het gesprek, max 8 woorden>
        SPREKERS: <per spreker de naam als die duidelijk uit het gesprek blijkt, bijv. A=Jan; B=Lisa; C=?>

        Samenvatting
        <3 tot 6 zinnen>

        Besluiten
        - …

        Actiepunten
        - <wie> – <wat> – <wanneer, als genoemd>

        Open vragen
        - …

        Laat een kopje weg als er niets onder valt. Gebruik geen markdown-opmaak behalve streepjes.
        """
        let output = try await client.complete(system: system,
                                               prompt: "Datum: \(Recording.dateFormatter.string(from: rec.createdAt))\n\nTranscript:\n\(rec.transcriptText)")

        var title: String?
        var names: [String: String] = [:]
        var bodyLines: [String] = []
        for line in output.components(separatedBy: "\n") {
            if line.hasPrefix("TITEL:") {
                title = line.dropFirst(6).trimmingCharacters(in: .whitespaces)
            } else if line.hasPrefix("SPREKERS:") {
                for pair in line.dropFirst(9).split(separator: ";") {
                    let parts = pair.split(separator: "=", maxSplits: 1).map { $0.trimmingCharacters(in: .whitespaces) }
                    if parts.count == 2, !parts[1].isEmpty, parts[1] != "?" { names[parts[0]] = parts[1] }
                }
            } else {
                bodyLines.append(line)
            }
        }
        update(rec.id) { r in
            if let title, !title.isEmpty { r.title = title }
            for (speaker, name) in names where (r.speakerNames[speaker] ?? "").isEmpty { r.speakerNames[speaker] = name }
            r.summary = bodyLines.joined(separator: "\n").trimmingCharacters(in: .whitespacesAndNewlines)
        }
    }

    private static let encoder: JSONEncoder = {
        let e = JSONEncoder()
        e.dateEncodingStrategy = .iso8601
        return e
    }()
    private static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601
        return d
    }()
}

// MARK: - AssemblyAI

enum AssemblyAI {
    enum AAIError: LocalizedError {
        case http(Int, String), failed(String), badResponse
        var errorDescription: String? {
            switch self {
            case .http(401, _): return "Ongeldige AssemblyAI-sleutel."
            case .http(let code, let body): return "AssemblyAI-fout \(code): \(body.prefix(200))"
            case .failed(let msg): return "AssemblyAI kon de opname niet uitschrijven: \(msg)"
            case .badResponse: return "Onverwacht antwoord van AssemblyAI."
            }
        }
    }

    private static var base: String {
        UserDefaults.standard.bool(forKey: "assemblyEU") ? "https://api.eu.assemblyai.com" : "https://api.assemblyai.com"
    }

    static func upload(file: URL, key: String) async throws -> String {
        var request = URLRequest(url: URL(string: "\(base)/v2/upload")!)
        request.httpMethod = "POST"
        request.timeoutInterval = 3600
        request.setValue(key, forHTTPHeaderField: "authorization")
        request.setValue("application/octet-stream", forHTTPHeaderField: "content-type")
        let (data, response) = try await URLSession.shared.upload(for: request, fromFile: file)
        let json = try check(data, response)
        guard let url = json["upload_url"] as? String else { throw AAIError.badResponse }
        return url
    }

    static func requestTranscript(audioURL: String, key: String) async throws -> String {
        var body: [String: Any] = [
            "audio_url": audioURL,
            "speaker_labels": true,
            "punctuate": true,
            "format_text": true,
        ]
        switch UserDefaults.standard.string(forKey: "recordingLanguage") ?? "nl" {
        case "auto": body["language_detection"] = true
        case let code: body["language_code"] = code
        }
        let json = try await call("/v2/transcript", method: "POST", body: body, key: key)
        guard let id = json["id"] as? String else { throw AAIError.badResponse }
        return id
    }

    /// Wacht tot het transcript klaar is (kan bij uren audio enkele minuten duren).
    static func waitForTranscript(id: String, key: String) async throws -> [Utterance] {
        while true {
            let json = try await call("/v2/transcript/\(id)", key: key)
            switch json["status"] as? String {
            case "completed":
                let list = json["utterances"] as? [[String: Any]] ?? []
                let utterances = list.compactMap { u -> Utterance? in
                    guard let text = u["text"] as? String else { return nil }
                    return Utterance(speaker: u["speaker"] as? String ?? "?", text: text,
                                     start: u["start"] as? Int ?? 0, end: u["end"] as? Int ?? 0)
                }
                if utterances.isEmpty, let text = json["text"] as? String, !text.isEmpty {
                    return [Utterance(speaker: "A", text: text, start: 0, end: 0)]
                }
                return utterances
            case "error":
                throw AAIError.failed(json["error"] as? String ?? "onbekende fout")
            default:
                try await Task.sleep(nanoseconds: 4_000_000_000)
            }
        }
    }

    private static func call(_ path: String, method: String = "GET",
                             body: [String: Any]? = nil, key: String) async throws -> [String: Any] {
        var request = URLRequest(url: URL(string: base + path)!)
        request.httpMethod = method
        request.setValue(key, forHTTPHeaderField: "authorization")
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "content-type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        let (data, response) = try await URLSession.shared.data(for: request)
        return try check(data, response)
    }

    private static func check(_ data: Data, _ response: URLResponse) throws -> [String: Any] {
        guard let http = response as? HTTPURLResponse else { throw AAIError.badResponse }
        guard (200..<300).contains(http.statusCode) else {
            throw AAIError.http(http.statusCode, String(data: data, encoding: .utf8) ?? "")
        }
        return (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
    }
}

// MARK: - Opnemen

/// Neemt gesprekken op zonder tijdslimiet, ook met het scherm uit (achtergrond-audio).
@MainActor
final class MeetingRecorder: NSObject, ObservableObject {
    static let shared = MeetingRecorder()

    @Published private(set) var isRecording = false
    @Published private(set) var isPaused = false
    @Published private(set) var elapsed: TimeInterval = 0
    @Published private(set) var level: Float = 0

    private var recorder: AVAudioRecorder?
    private var timer: Timer?
    private var currentID: UUID?
    private var userPaused = false

    override private init() {
        super.init()
        NotificationCenter.default.addObserver(self, selector: #selector(handleInterruption(_:)),
                                               name: AVAudioSession.interruptionNotification, object: nil)
    }

    func start() async throws {
        guard !isRecording else { return }
        guard await AVAudioApplication.requestRecordPermission() else {
            throw ToolExecutor.ToolError.denied("de microfoon")
        }
        let session = AVAudioSession.sharedInstance()
        try session.setCategory(.playAndRecord, mode: .default, options: [.defaultToSpeaker, .allowBluetooth])
        try session.setActive(true)

        let rec = RecordingStore.shared.create()
        let settings: [String: Any] = [
            AVFormatIDKey: Int(kAudioFormatMPEG4AAC),
            AVSampleRateKey: 16_000,         // genoeg voor spraak, ±15 MB per uur
            AVNumberOfChannelsKey: 1,
            AVEncoderBitRateKey: 32_000,
            AVEncoderAudioQualityKey: AVAudioQuality.high.rawValue,
        ]
        let recorder = try AVAudioRecorder(url: RecordingStore.shared.audioURL(for: rec), settings: settings)
        recorder.isMeteringEnabled = true
        guard recorder.record() else {
            RecordingStore.shared.delete(rec.id)
            throw ToolExecutor.ToolError.invalidInput("Opnemen kon niet starten.")
        }
        self.recorder = recorder
        currentID = rec.id
        isRecording = true
        isPaused = false
        userPaused = false
        elapsed = 0

        timer = Timer.scheduledTimer(withTimeInterval: 0.25, repeats: true) { [weak self] _ in
            Task { @MainActor in self?.tick() }
        }
    }

    func togglePause() {
        guard let recorder else { return }
        if isPaused {
            try? AVAudioSession.sharedInstance().setActive(true)
            recorder.record()
            isPaused = false
            userPaused = false
        } else {
            recorder.pause()
            isPaused = true
            userPaused = true
        }
    }

    func stop() {
        guard let recorder, let id = currentID else { return }
        let duration = recorder.currentTime
        recorder.stop()
        timer?.invalidate()
        timer = nil
        self.recorder = nil
        currentID = nil
        isRecording = false
        isPaused = false
        level = 0
        RecordingStore.shared.update(id) { $0.duration = duration; $0.status = .recorded }
        Task { await RecordingStore.shared.process(id) }
    }

    private func tick() {
        guard let recorder else { return }
        elapsed = recorder.currentTime
        recorder.updateMeters()
        let db = recorder.averagePower(forChannel: 0)      // -160...0
        level = max(0, min(1, (db + 50) / 50))
        if let id = currentID, Int(elapsed) % 10 == 0 {
            RecordingStore.shared.update(id) { $0.duration = recorder.currentTime }
        }
    }

    /// Bij een telefoontje pauzeert iOS de opname; daarna gaat hij automatisch verder.
    @objc nonisolated private func handleInterruption(_ note: Notification) {
        guard let raw = note.userInfo?[AVAudioSessionInterruptionTypeKey] as? UInt,
              let type = AVAudioSession.InterruptionType(rawValue: raw) else { return }
        Task { @MainActor in
            guard let recorder = self.recorder else { return }
            switch type {
            case .began:
                self.isPaused = true
            case .ended:
                if !self.userPaused {
                    try? AVAudioSession.sharedInstance().setActive(true)
                    recorder.record()
                    self.isPaused = false
                }
            @unknown default: break
            }
        }
    }
}
