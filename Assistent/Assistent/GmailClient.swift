import Foundation

/// Gmail REST API: zoeken, lezen, versturen, archiveren.
@MainActor
final class GmailClient {
    private let auth = GoogleAuth.shared
    private let base = "https://gmail.googleapis.com/gmail/v1/users/me"

    enum GmailError: LocalizedError {
        case http(Int, String)
        var errorDescription: String? {
            switch self {
            case .http(let code, let body): return "Gmail-fout \(code): \(body.prefix(300))"
            }
        }
    }

    // MARK: - Zoeken

    func search(query: String, maxResults: Int) async throws -> String {
        let list = try await call("messages", query: [
            URLQueryItem(name: "q", value: query),
            URLQueryItem(name: "maxResults", value: String(min(max(maxResults, 1), 25))),
        ])
        let ids = (list["messages"] as? [[String: Any]] ?? []).compactMap { $0["id"] as? String }
        if ids.isEmpty { return "Geen e-mails gevonden voor: \(query)" }

        var lines: [String] = []
        for id in ids {
            let msg = try await call("messages/\(id)", query: [
                URLQueryItem(name: "format", value: "metadata"),
                URLQueryItem(name: "metadataHeaders", value: "From"),
                URLQueryItem(name: "metadataHeaders", value: "Subject"),
                URLQueryItem(name: "metadataHeaders", value: "Date"),
            ])
            let headers = Self.headers(of: msg)
            let unread = (msg["labelIds"] as? [String] ?? []).contains("UNREAD")
            let snippet = Self.decodeEntities(msg["snippet"] as? String ?? "")
            lines.append("- id: \(id) | Van: \(headers["from"] ?? "?") | Onderwerp: \(headers["subject"] ?? "(geen)") | Datum: \(headers["date"] ?? "?")\(unread ? " | ONGELEZEN" : "")\n  \(snippet)")
        }
        return lines.joined(separator: "\n")
    }

    // MARK: - Lezen

    func read(id: String) async throws -> String {
        let msg = try await call("messages/\(id)", query: [URLQueryItem(name: "format", value: "full")])
        let headers = Self.headers(of: msg)
        let payload = msg["payload"] as? [String: Any] ?? [:]
        var body = Self.extract(payload, mime: "text/plain")
        if body == nil, let html = Self.extract(payload, mime: "text/html") {
            body = Self.stripHTML(html)
        }
        var text = body ?? (msg["snippet"] as? String ?? "")
        if text.count > 15000 { text = String(text.prefix(15000)) + "\n…(ingekort)" }

        return """
        Van: \(headers["from"] ?? "?")
        Aan: \(headers["to"] ?? "?")
        Cc: \(headers["cc"] ?? "")
        Datum: \(headers["date"] ?? "?")
        Onderwerp: \(headers["subject"] ?? "")
        Message-ID: \(headers["message-id"] ?? "")
        thread_id: \(msg["threadId"] as? String ?? "")

        \(text)
        """
    }

    // MARK: - Versturen

    func send(to: [String], cc: [String], subject: String, body: String,
              threadID: String?, inReplyTo: String?) async throws -> String {
        var lines = [
            "To: \(to.joined(separator: ", "))",
            "Subject: =?UTF-8?B?\(Data(subject.utf8).base64EncodedString())?=",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ]
        if !cc.isEmpty { lines.insert("Cc: \(cc.joined(separator: ", "))", at: 1) }
        if let inReplyTo, !inReplyTo.isEmpty {
            lines.append("In-Reply-To: \(inReplyTo)")
            lines.append("References: \(inReplyTo)")
        }
        let raw = lines.joined(separator: "\r\n") + "\r\n\r\n" +
            Data(body.utf8).base64EncodedString(options: [.lineLength76Characters, .endLineWithCarriageReturn, .endLineWithLineFeed])

        var payload: [String: Any] = ["raw": GoogleAuth.base64URL(Data(raw.utf8))]
        if let threadID, !threadID.isEmpty { payload["threadId"] = threadID }
        _ = try await call("messages/send", method: "POST", body: payload)
        return "E-mail verstuurd naar \(to.joined(separator: ", "))."
    }

    // MARK: - Labels

    func modify(id: String, action: String) async throws -> String {
        switch action {
        case "trash":
            _ = try await call("messages/\(id)/trash", method: "POST", body: [:])
            return "E-mail naar de prullenbak verplaatst."
        default:
            let (add, remove): ([String], [String]) = {
                switch action {
                case "mark_read": return ([], ["UNREAD"])
                case "mark_unread": return (["UNREAD"], [])
                case "archive": return ([], ["INBOX"])
                case "star": return (["STARRED"], [])
                case "unstar": return ([], ["STARRED"])
                default: return ([], [])
                }
            }()
            guard !(add.isEmpty && remove.isEmpty) else { return "Onbekende actie: \(action)" }
            _ = try await call("messages/\(id)/modify", method: "POST",
                               body: ["addLabelIds": add, "removeLabelIds": remove])
            return "Klaar: \(action)."
        }
    }

    // MARK: - HTTP

    private func call(_ path: String, method: String = "GET",
                      query: [URLQueryItem] = [], body: [String: Any]? = nil) async throws -> [String: Any] {
        var components = URLComponents(string: "\(base)/\(path)")!
        if !query.isEmpty { components.queryItems = query }
        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        let token = try await auth.validAccessToken()
        request.setValue("Bearer \(token)", forHTTPHeaderField: "authorization")
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "content-type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw GmailError.http((response as? HTTPURLResponse)?.statusCode ?? 0,
                                  String(data: data, encoding: .utf8) ?? "")
        }
        return (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
    }

    // MARK: - Parsing

    private static func headers(of msg: [String: Any]) -> [String: String] {
        let list = (msg["payload"] as? [String: Any])?["headers"] as? [[String: Any]] ?? []
        var result: [String: String] = [:]
        for h in list {
            if let name = h["name"] as? String, let value = h["value"] as? String {
                result[name.lowercased()] = value
            }
        }
        return result
    }

    private static func extract(_ part: [String: Any], mime: String) -> String? {
        if (part["mimeType"] as? String) == mime,
           let data = (part["body"] as? [String: Any])?["data"] as? String,
           let decoded = decodeBase64URL(data) {
            return decoded
        }
        for sub in part["parts"] as? [[String: Any]] ?? [] {
            if let found = extract(sub, mime: mime) { return found }
        }
        return nil
    }

    private static func decodeBase64URL(_ s: String) -> String? {
        var b64 = s.replacingOccurrences(of: "-", with: "+").replacingOccurrences(of: "_", with: "/")
        while b64.count % 4 != 0 { b64 += "=" }
        guard let data = Data(base64Encoded: b64) else { return nil }
        return String(data: data, encoding: .utf8) ?? String(data: data, encoding: .isoLatin1)
    }

    private static func stripHTML(_ html: String) -> String {
        var text = html.replacingOccurrences(of: "(?is)<(script|style)[^>]*>.*?</\\1>", with: " ", options: .regularExpression)
        text = text.replacingOccurrences(of: "(?i)<br\\s*/?>|</p>|</div>|</tr>", with: "\n", options: .regularExpression)
        text = text.replacingOccurrences(of: "<[^>]+>", with: " ", options: .regularExpression)
        text = decodeEntities(text)
        text = text.replacingOccurrences(of: "[ \\t]+", with: " ", options: .regularExpression)
        text = text.replacingOccurrences(of: "\\n\\s*\\n+", with: "\n\n", options: .regularExpression)
        return text.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private static func decodeEntities(_ s: String) -> String {
        s.replacingOccurrences(of: "&nbsp;", with: " ")
            .replacingOccurrences(of: "&amp;", with: "&")
            .replacingOccurrences(of: "&lt;", with: "<")
            .replacingOccurrences(of: "&gt;", with: ">")
            .replacingOccurrences(of: "&quot;", with: "\"")
            .replacingOccurrences(of: "&#39;", with: "'")
    }
}
