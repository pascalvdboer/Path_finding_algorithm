import SwiftUI

@main
struct AssistentApp: App {
    @StateObject private var viewModel = AssistantViewModel()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(viewModel)
        }
        .onChange(of: scenePhase) {
            if scenePhase == .active {
                RecordingStore.shared.resumePending()
            }
        }
    }
}

struct RootView: View {
    enum Tab { case assistant, recordings }
    @State private var tab: Tab = .assistant

    var body: some View {
        TabView(selection: $tab) {
            ContentView()
                .tabItem { Label("Assistent", systemImage: "sparkles") }
                .tag(Tab.assistant)
            RecordingsView()
                .tabItem { Label("Opnames", systemImage: "waveform") }
                .tag(Tab.recordings)
        }
        .onReceive(NotificationCenter.default.publisher(for: .startRecording)) { _ in tab = .recordings }
        .onReceive(NotificationCenter.default.publisher(for: .startListening)) { _ in tab = .assistant }
        .onAppear { if RecordRequest.pending { tab = .recordings } }
    }
}
