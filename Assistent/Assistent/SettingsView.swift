import SwiftUI

struct SettingsView: View {
    @Environment(\.dismiss) private var dismiss
    @State private var apiKey = Keychain.load() ?? ""
    @AppStorage("model") private var model = ClaudeClient.defaultModel
    @AppStorage("autoSpeak") private var autoSpeak = true

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
                    Text("Zeg \"Hé Siri, vraag Assistent\" en stel daarna je vraag. Je kunt de opdracht ook terugvinden in de app Opdrachten.")
                        .font(.callout)
                }
            }
            .navigationTitle("Instellingen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Bewaar") {
                        let trimmed = apiKey.trimmingCharacters(in: .whitespacesAndNewlines)
                        if trimmed.isEmpty { Keychain.delete() } else { Keychain.save(trimmed) }
                        dismiss()
                    }
                }
            }
        }
    }
}
