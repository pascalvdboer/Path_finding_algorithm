import SwiftUI

struct SettingsView: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject private var google = GoogleAuth.shared
    @ObservedObject private var memory = MemoryStore.shared

    @State private var apiKey = Keychain.load() ?? ""
    @State private var googleError: String?
    @State private var connecting = false
    @AppStorage("model") private var model = ClaudeClient.defaultModel
    @AppStorage("autoSpeak") private var autoSpeak = true
    @AppStorage("googleClientID") private var googleClientID = ""
    @AppStorage("shortcutNames") private var shortcutNames = ""

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    SecureField("sk-ant-…", text: $apiKey)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                } header: {
                    Text("Claude API-sleutel")
                } footer: {
                    Text("Maak een sleutel aan op platform.claude.com. Hij wordt alleen in de sleutelhanger van je iPhone bewaard.")
                }

                Section {
                    if google.isConnected {
                        Label("Gmail gekoppeld", systemImage: "checkmark.circle.fill").foregroundStyle(.green)
                        Button("Ontkoppel Gmail", role: .destructive) { google.signOut() }
                    } else {
                        TextField("…apps.googleusercontent.com", text: $googleClientID)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        Button {
                            Task {
                                connecting = true
                                defer { connecting = false }
                                do { try await google.signIn(); googleError = nil }
                                catch { googleError = error.localizedDescription }
                            }
                        } label: {
                            if connecting { ProgressView() } else { Text("Koppel Gmail") }
                        }
                        .disabled(googleClientID.isEmpty || connecting)
                        if let googleError {
                            Text(googleError).font(.footnote).foregroundStyle(.red)
                        }
                    }
                } header: {
                    Text("Gmail")
                } footer: {
                    Text("Nodig om mail te lezen en te versturen. Maak een iOS OAuth Client ID aan in Google Cloud (stappen in de README).")
                }

                Section {
                    TextEditor(text: $shortcutNames)
                        .frame(minHeight: 100)
                        .font(.body.monospaced())
                } header: {
                    Text("Mijn opdrachten (Shortcuts)")
                } footer: {
                    Text("Eén naam per regel, precies zoals in de app Opdrachten, bijv. \"Wekker zetten\", \"Timer\", \"Niet storen\". De assistent kan ze dan voor je uitvoeren.")
                }

                Section {
                    if memory.facts.isEmpty {
                        Text("Nog niets. Zeg bijvoorbeeld: \"Onthoud dat mijn vrouw Lisa heet.\"")
                            .foregroundStyle(.secondary)
                    }
                    ForEach(memory.facts, id: \.self) { Text($0) }
                        .onDelete { memory.remove(atOffsets: $0) }
                } header: {
                    Text("Wat de assistent over je weet")
                } footer: {
                    Text("Veeg naar links om iets te laten vergeten.")
                }

                Section("Model") {
                    Picker("Model", selection: $model) {
                        Text("Claude Opus 5.5 (slimst)").tag("claude-opus-5-5")
                        Text("Claude Sonnet 5.5 (sneller, goedkoper)").tag("claude-sonnet-5-5")
                    }
                    .pickerStyle(.inline)
                    .labelsHidden()
                }

                Section {
                    Toggle("Antwoorden altijd voorlezen", isOn: $autoSpeak)
                } footer: {
                    Text("Als je met je stem vraagt, wordt het antwoord altijd voorgelezen.")
                }

                Section("Siri") {
                    Text("Zeg \"Hé Siri, vraag Assistent\" en stel daarna je vraag. Koppel de opdracht \"Vraag de assistent\" ook aan de Actieknop of de dubbeltik op de achterkant (Toegankelijkheid > Aanraken > Tik op achterkant).")
                        .font(.callout)
                }
            }
            .navigationTitle("Instellingen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Klaar") {
                        let trimmed = apiKey.trimmingCharacters(in: .whitespacesAndNewlines)
                        if trimmed.isEmpty { Keychain.delete() } else { Keychain.save(trimmed) }
                        dismiss()
                    }
                }
            }
        }
    }
}
