import SwiftUI

@main
struct AssistentApp: App {
    @StateObject private var viewModel = AssistantViewModel()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(viewModel)
        }
    }
}
