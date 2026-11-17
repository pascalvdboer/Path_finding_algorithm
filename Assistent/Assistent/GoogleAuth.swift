import Foundation
import AuthenticationServices
import CryptoKit
import UIKit

/// Inloggen bij Google (OAuth 2.0 met PKCE) zodat de assistent je Gmail kan lezen en versturen.
/// Je hebt een eigen "iOS OAuth client ID" uit Google Cloud nodig (zie README).
@MainActor
final class GoogleAuth: NSObject, ObservableObject {
    static let shared = GoogleAuth()
    static let scope = "https://www.googleapis.com/auth/gmail.modify"
    private static let refreshAccount = "google.refresh"

    @Published private(set) var isConnected = Keychain.load(account: GoogleAuth.refreshAccount) != nil

    private var accessToken: String?
    private var expiry = Date.distantPast
    private var session: ASWebAuthenticationSession?

    enum AuthError: LocalizedError {
        case noClientID, cancelled, noCode, noRefreshToken, notConnected, token(String)
        var errorDescription: String? {
            switch self {
            case .noClientID: return "Vul eerst je Google Client ID in bij Instellingen."
            case .cancelled: return "Inloggen geannuleerd."
            case .noCode: return "Google gaf geen autorisatiecode terug."
            case .noRefreshToken: return "Google gaf geen refresh-token terug. Probeer opnieuw te koppelen."
            case .notConnected: return "Gmail is niet gekoppeld. De gebruiker kan dit doen via Instellingen in de app."
            case .token(let msg): return "Google-token fout: \(msg)"
            }
        }
    }

    var clientID: String {
        (UserDefaults.standard.string(forKey: "googleClientID") ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    }

    /// "123-abc.apps.googleusercontent.com" -> "com.googleusercontent.apps.123-abc"
    private var redirectScheme: String {
        "com.googleusercontent.apps." + clientID.replacingOccurrences(of: ".apps.googleusercontent.com", with: "")
    }
    private var redirectURI: String { "\(redirectScheme):/oauthredirect" }

    func signIn() async throws {
        guard !clientID.isEmpty else { throw AuthError.noClientID }

        let verifier = Self.randomURLSafeString(length: 64)
        let challenge = Self.base64URL(Data(SHA256.hash(data: Data(verifier.utf8))))

        var components = URLComponents(string: "https://accounts.google.com/o/oauth2/v2/auth")!
        components.queryItems = [
            URLQueryItem(name: "client_id", value: clientID),
            URLQueryItem(name: "redirect_uri", value: redirectURI),
            URLQueryItem(name: "response_type", value: "code"),
            URLQueryItem(name: "scope", value: Self.scope),
            URLQueryItem(name: "code_challenge", value: challenge),
            URLQueryItem(name: "code_challenge_method", value: "S256"),
            URLQueryItem(name: "access_type", value: "offline"),
            URLQueryItem(name: "prompt", value: "consent"),
        ]

        let callback: URL = try await withCheckedThrowingContinuation { continuation in
            let session = ASWebAuthenticationSession(url: components.url!, callbackURLScheme: redirectScheme) { url, error in
                if let url {
                    continuation.resume(returning: url)
                } else {
                    continuation.resume(throwing: error ?? AuthError.cancelled)
                }
            }
            session.presentationContextProvider = self
            self.session = session
            session.start()
        }
        session = nil

        guard let code = URLComponents(url: callback, resolvingAgainstBaseURL: false)?
            .queryItems?.first(where: { $0.name == "code" })?.value else { throw AuthError.noCode }

        let tokens = try await tokenRequest([
            "code": code,
            "client_id": clientID,
            "redirect_uri": redirectURI,
            "grant_type": "authorization_code",
            "code_verifier": verifier,
        ])
        guard let refresh = tokens["refresh_token"] as? String else { throw AuthError.noRefreshToken }
        Keychain.save(refresh, account: Self.refreshAccount)
        apply(tokens)
        isConnected = true
    }

    func signOut() {
        Keychain.delete(account: Self.refreshAccount)
        accessToken = nil
        expiry = .distantPast
        isConnected = false
    }

    func validAccessToken() async throws -> String {
        if let token = accessToken, expiry > Date().addingTimeInterval(60) { return token }
        guard let refresh = Keychain.load(account: Self.refreshAccount) else { throw AuthError.notConnected }
        let tokens = try await tokenRequest([
            "refresh_token": refresh,
            "client_id": clientID,
            "grant_type": "refresh_token",
        ])
        apply(tokens)
        guard let token = accessToken else { throw AuthError.token("geen access token") }
        return token
    }

    private func apply(_ tokens: [String: Any]) {
        accessToken = tokens["access_token"] as? String
        let seconds = tokens["expires_in"] as? Double ?? 3600
        expiry = Date().addingTimeInterval(seconds)
    }

    private func tokenRequest(_ params: [String: String]) async throws -> [String: Any] {
        var request = URLRequest(url: URL(string: "https://oauth2.googleapis.com/token")!)
        request.httpMethod = "POST"
        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "content-type")
        var allowed = CharacterSet.alphanumerics
        allowed.insert(charactersIn: "-._~")
        request.httpBody = params
            .map { "\($0.key)=\($0.value.addingPercentEncoding(withAllowedCharacters: allowed) ?? "")" }
            .joined(separator: "&")
            .data(using: .utf8)
        let (data, response) = try await URLSession.shared.data(for: request)
        let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            if (json["error"] as? String) == "invalid_grant" { signOut() } // token ingetrokken of verlopen
            throw AuthError.token(json["error_description"] as? String ?? json["error"] as? String ?? "onbekend")
        }
        return json
    }

    // MARK: - Hulpfuncties

    static func base64URL(_ data: Data) -> String {
        data.base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }

    private static func randomURLSafeString(length: Int) -> String {
        var bytes = [UInt8](repeating: 0, count: length)
        _ = SecRandomCopyBytes(kSecRandomDefault, length, &bytes)
        return String(base64URL(Data(bytes)).prefix(length))
    }
}

extension GoogleAuth: ASWebAuthenticationPresentationContextProviding {
    nonisolated func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        MainActor.assumeIsolated {
            UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .flatMap { $0.windows }
                .first { $0.isKeyWindow } ?? ASPresentationAnchor()
        }
    }
}
