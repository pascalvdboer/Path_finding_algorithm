import SwiftUI
import MessageUI

/// Toont het standaard iOS-scherm voor een SMS/iMessage met ingevulde tekst.
struct MessageComposer: UIViewControllerRepresentable {
    let draft: MessageDraft
    let onFinish: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onFinish: onFinish) }

    func makeUIViewController(context: Context) -> MFMessageComposeViewController {
        let vc = MFMessageComposeViewController()
        vc.messageComposeDelegate = context.coordinator
        vc.recipients = draft.recipients
        vc.body = draft.body
        return vc
    }

    func updateUIViewController(_ uiViewController: MFMessageComposeViewController, context: Context) {}

    final class Coordinator: NSObject, MFMessageComposeViewControllerDelegate {
        let onFinish: () -> Void
        init(onFinish: @escaping () -> Void) { self.onFinish = onFinish }

        func messageComposeViewController(_ controller: MFMessageComposeViewController,
                                          didFinishWith result: MessageComposeResult) {
            controller.dismiss(animated: true)
            onFinish()
        }
    }
}
