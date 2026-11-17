import Foundation
import EventKit

struct EmailDraft: Identifiable {
    let id = UUID()
    var to: [String]
    var subject: String
    var body: String
}

struct MessageDraft: Identifiable {
    let id = UUID()
    var recipients: [String]
    var body: String
}

/// Wat de tools van de interface nodig hebben. In Siri (zonder scherm) is dit `nil`.
@MainActor
protocol AssistantUI: AnyObject {
    func presentEmail(_ draft: EmailDraft) -> Bool
    func presentMessage(_ draft: MessageDraft) -> Bool
    func confirm(title: String, message: String, action: String) async -> Bool
    func open(_ url: URL) async -> Bool
}

/// Definieert de tools die Claude mag gebruiken en voert ze uit op de iPhone.
@MainActor
final class ToolExecutor {
    struct Outcome {
        let text: String
        var isError = false
    }

    enum ToolError: LocalizedError {
        case denied(String)
        case invalidInput(String)
        case needsApp(String)
        var errorDescription: String? {
            switch self {
            case .denied(let what): return "Geen toegang tot \(what). De gebruiker kan dit aanzetten in Instellingen > Privacy en beveiliging."
            case .invalidInput(let msg): return msg
            case .needsApp(let what): return "\(what) kan alleen als de app open is. Vraag de gebruiker de Assistent-app te openen."
            }
        }
    }

    weak var ui: AssistantUI?

    private let store = EKEventStore()
    private let contacts = ContactsService()
    private let location = LocationService()
    private let gmail = GmailClient()
    private let memory = MemoryStore.shared

    // MARK: - Tooldefinities

    nonisolated private static func enumStr(_ values: [String], _ description: String = "") -> [String: Any] {
        ["type": "string", "enum": values, "description": description]
    }
    nonisolated private static func object(_ properties: [String: Any], required: [String] = []) -> [String: Any] {
        ["type": "object", "properties": properties, "required": required]
    }
    nonisolated private static func str(_ description: String) -> [String: Any] { ["type": "string", "description": description] }
    nonisolated private static func strArray(_ description: String) -> [String: Any] {
        ["type": "array", "items": ["type": "string"], "description": description]
    }

    static let toolDefinitions: [[String: Any]] = [
        // Agenda
        ["name": "list_events",
         "description": "Haalt afspraken uit de agenda binnen een periode, met hun id.",
         "input_schema": object(["start": str("Begin, ISO 8601 met tijdzone."),
                                 "end": str("Einde, ISO 8601 met tijdzone.")], required: ["start", "end"])],
        ["name": "create_event",
         "description": "Maakt een afspraak in de standaardagenda.",
         "input_schema": object(["title": str("Titel."),
                                 "start": str("Start, ISO 8601 met tijdzone."),
                                 "end": str("Einde, ISO 8601 met tijdzone. Standaard een uur na de start."),
                                 "location": str("Optionele locatie."),
                                 "notes": str("Optionele notities."),
                                 "all_day": ["type": "boolean", "description": "true voor een hele-dag-afspraak."]],
                                required: ["title", "start", "end"])],
        ["name": "delete_event",
         "description": "Verwijdert een afspraak (de gebruiker moet dit op het scherm bevestigen). Haal eerst het id op met list_events.",
         "input_schema": object(["id": str("Het id uit list_events.")], required: ["id"])],

        // Herinneringen
        ["name": "list_reminders",
         "description": "Geeft de openstaande herinneringen (taken) met hun id.",
         "input_schema": object([:])],
        ["name": "create_reminder",
         "description": "Maakt een herinnering, optioneel met een tijdstip waarop de iPhone een melding geeft.",
         "input_schema": object(["title": str("Wat de gebruiker moet onthouden."),
                                 "due": str("Optioneel tijdstip, ISO 8601 met tijdzone."),
                                 "notes": str("Optionele notities.")], required: ["title"])],
        ["name": "complete_reminder",
         "description": "Vinkt een herinnering af. Haal eerst het id op met list_reminders.",
         "input_schema": object(["id": str("Het id uit list_reminders.")], required: ["id"])],

        // Contacten, bellen, berichten
        ["name": "search_contacts",
         "description": "Zoekt in de contacten van de gebruiker op naam, bijnaam of bedrijf. Geeft telefoonnummers, e-mailadressen, adres en verjaardag.",
         "input_schema": object(["query": str("Naam of deel van de naam, bijv. 'Jan' of 'mama'.")], required: ["query"])],
        ["name": "call",
         "description": "Belt een telefoonnummer (iOS vraagt de gebruiker nog om te bevestigen). Zoek het nummer eerst op met search_contacts.",
         "input_schema": object(["phone_number": str("Telefoonnummer."),
                                 "method": enumStr(["phone", "facetime", "facetime_audio"], "Standaard 'phone'.")],
                                required: ["phone_number"])],
        ["name": "send_message",
         "description": "Zet een SMS/iMessage of WhatsApp-bericht klaar. De gebruiker tikt zelf op versturen.",
         "input_schema": object(["recipients": strArray("Telefoonnummers (of e-mailadressen voor iMessage). WhatsApp: precies één nummer."),
                                 "body": str("De tekst van het bericht."),
                                 "app": enumStr(["sms", "whatsapp"], "Standaard 'sms' (Berichten-app).")],
                                required: ["recipients", "body"])],

        // E-mail
        ["name": "gmail_search",
         "description": "Zoekt e-mails in Gmail met Gmail-zoeksyntax, bijv. 'is:unread', 'from:jan newer_than:7d', 'subject:factuur'. Geeft id, afzender, onderwerp, datum en een fragment.",
         "input_schema": object(["query": str("Gmail-zoekopdracht. Gebruik 'in:inbox' voor de inbox."),
                                 "max_results": ["type": "integer", "description": "Maximaal aantal (standaard 10, max 25)."]],
                                required: ["query"])],
        ["name": "gmail_read",
         "description": "Leest de volledige inhoud van één e-mail (met Message-ID en thread_id om te kunnen antwoorden).",
         "input_schema": object(["id": str("Het id uit gmail_search.")], required: ["id"])],
        ["name": "gmail_send",
         "description": "Verstuurt een e-mail vanuit Gmail. De gebruiker moet het op het scherm bevestigen. Gebruik voor een antwoord thread_id en in_reply_to uit gmail_read en begin het onderwerp met 'Re: '.",
         "input_schema": object(["to": strArray("Ontvangers."),
                                 "cc": strArray("Optionele cc-ontvangers."),
                                 "subject": str("Onderwerp."),
                                 "body": str("Tekst van de e-mail, inclusief aanhef en afsluiting."),
                                 "thread_id": str("Optioneel: thread_id bij een antwoord."),
                                 "in_reply_to": str("Optioneel: Message-ID van de mail waarop je antwoordt.")],
                                required: ["to", "subject", "body"])],
        ["name": "gmail_modify",
         "description": "Past een e-mail aan: als gelezen/ongelezen markeren, archiveren, ster geven of naar de prullenbak.",
         "input_schema": object(["id": str("Het id uit gmail_search."),
                                 "action": enumStr(["mark_read", "mark_unread", "archive", "star", "unstar", "trash"])],
                                required: ["id", "action"])],
        ["name": "compose_email",
         "description": "Opent een e-mailconcept in de Mail-app van de iPhone. Gebruik dit alleen als Gmail niet gekoppeld is.",
         "input_schema": object(["to": strArray("Ontvangers."), "subject": str("Onderwerp."), "body": str("Tekst.")],
                                required: ["to", "subject", "body"])],

        // Locatie en navigatie
        ["name": "get_location",
         "description": "Geeft de huidige locatie en het adres van de gebruiker.",
         "input_schema": object([:])],
        ["name": "open_maps",
         "description": "Opent Apple Kaarten met een route naar een bestemming.",
         "input_schema": object(["destination": str("Adres of plaatsnaam."),
                                 "mode": enumStr(["driving", "walking", "transit"], "Standaard 'driving'.")],
                                required: ["destination"])],

        // Andere apps en iPhone-instellingen
        ["name": "run_shortcut",
         "description": "Voert een Opdracht (Shortcut) van de gebruiker uit. Zo kun je de wekker zetten, een timer starten, Focus of Niet storen aanzetten, de helderheid aanpassen, slimme lampen bedienen, enzovoort. Gebruik alleen namen uit de lijst met opdrachten in je instructies.",
         "input_schema": object(["name": str("Exacte naam van de opdracht."),
                                 "input": str("Optionele tekst die als invoer aan de opdracht wordt gegeven, bijv. '07:00'.")],
                                required: ["name"])],
        ["name": "open_url",
         "description": "Opent een webpagina of een andere app via een link, bijv. 'https://…', 'spotify:', 'music://', 'photos-redirect://', 'mobilenotes://', 'calshow://'.",
         "input_schema": object(["url": str("De URL.")], required: ["url"])],

        // Geheugen
        ["name": "remember",
         "description": "Onthoudt een blijvend feit of een voorkeur van de gebruiker voor latere gesprekken, bijv. 'Partner heet Lisa' of 'Wil antwoorden kort'.",
         "input_schema": object(["fact": str("Het feit, kort geformuleerd.")], required: ["fact"])],
        ["name": "forget",
         "description": "Vergeet onthouden feiten die deze tekst bevatten.",
         "input_schema": object(["text": str("Tekst om op te zoeken.")], required: ["text"])],

        // Server-tools: worden bij Anthropic uitgevoerd.
        ["type": "web_search_20260209", "name": "web_search", "max_uses": 5,
         "user_location": ["type": "approximate", "country": "NL", "timezone": "Europe/Amsterdam"]],
        ["type": "web_fetch_20260209", "name": "web_fetch", "max_uses": 3],
    ]

    // MARK: - Uitvoeren

    func run(name: String, input: [String: Any]) async -> Outcome {
        do {
            switch name {
            case "list_events": return Outcome(text: try await listEvents(input))
            case "create_event": return Outcome(text: try await createEvent(input))
            case "delete_event": return Outcome(text: try await deleteEvent(input))
            case "list_reminders": return Outcome(text: try await listReminders())
            case "create_reminder": return Outcome(text: try await createReminder(input))
            case "complete_reminder": return Outcome(text: try await completeReminder(input))
            case "search_contacts": return Outcome(text: try await contacts.search(try string(input, "query")))
            case "call": return Outcome(text: try await call(input))
            case "send_message": return Outcome(text: try await sendMessage(input))
            case "gmail_search":
                return Outcome(text: try await gmail.search(query: try string(input, "query"),
                                                            maxResults: input["max_results"] as? Int ?? 10))
            case "gmail_read": return Outcome(text: try await gmail.read(id: try string(input, "id")))
            case "gmail_send": return Outcome(text: try await gmailSend(input))
            case "gmail_modify":
                return Outcome(text: try await gmail.modify(id: try string(input, "id"), action: try string(input, "action")))
            case "compose_email": return Outcome(text: try composeEmail(input))
            case "get_location": return Outcome(text: try await location.describeCurrentLocation())
            case "open_maps": return Outcome(text: try await openMaps(input))
            case "run_shortcut": return Outcome(text: try await runShortcut(input))
            case "open_url": return Outcome(text: try await openURL(input))
            case "remember":
                memory.add(try string(input, "fact"))
                return Outcome(text: "Onthouden.")
            case "forget":
                let n = memory.remove(matching: try string(input, "text"))
                return Outcome(text: n == 0 ? "Niets gevonden om te vergeten." : "\(n) feit(en) vergeten.")
            default:
                return Outcome(text: "Onbekende tool: \(name)", isError: true)
            }
        } catch {
            return Outcome(text: "Fout: \(error.localizedDescription)", isError: true)
        }
    }

    // MARK: - Toegang

    func ensureCalendarAccess() async throws {
        if EKEventStore.authorizationStatus(for: .event) == .fullAccess { return }
        guard try await store.requestFullAccessToEvents() else { throw ToolError.denied("de agenda") }
    }

    func ensureRemindersAccess() async throws {
        if EKEventStore.authorizationStatus(for: .reminder) == .fullAccess { return }
        guard try await store.requestFullAccessToReminders() else { throw ToolError.denied("herinneringen") }
    }

    func ensureContactsAccess() async throws { try await contacts.ensureAccess() }

    private func requireUI(_ what: String) throws -> AssistantUI {
        guard let ui else { throw ToolError.needsApp(what) }
        return ui
    }

    // MARK: - Agenda

    private func listEvents(_ input: [String: Any]) async throws -> String {
        try await ensureCalendarAccess()
        let predicate = store.predicateForEvents(withStart: try date(input, "start"), end: try date(input, "end"), calendars: nil)
        let events = store.events(matching: predicate).sorted { $0.startDate < $1.startDate }
        if events.isEmpty { return "Geen afspraken in deze periode." }
        return events.prefix(50).map { e in
            var line = "- [id: \(e.eventIdentifier ?? "")] \(e.title ?? "(zonder titel)"): "
            line += e.isAllDay ? "hele dag \(Self.dayFormatter.string(from: e.startDate))"
                               : "\(Self.isoOut.string(from: e.startDate)) tot \(Self.isoOut.string(from: e.endDate))"
            if let loc = e.location, !loc.isEmpty { line += ", locatie: \(loc)" }
            return line + " [agenda: \(e.calendar.title)]"
        }.joined(separator: "\n")
    }

    private func createEvent(_ input: [String: Any]) async throws -> String {
        try await ensureCalendarAccess()
        let event = EKEvent(eventStore: store)
        event.title = try string(input, "title")
        event.startDate = try date(input, "start")
        event.endDate = try date(input, "end")
        event.isAllDay = input["all_day"] as? Bool ?? false
        event.location = input["location"] as? String
        event.notes = input["notes"] as? String
        event.calendar = store.defaultCalendarForNewEvents
        try store.save(event, span: .thisEvent, commit: true)
        return "Afspraak '\(event.title ?? "")' aangemaakt op \(Self.isoOut.string(from: event.startDate))."
    }

    private func deleteEvent(_ input: [String: Any]) async throws -> String {
        try await ensureCalendarAccess()
        let ui = try requireUI("Een afspraak verwijderen")
        guard let event = store.event(withIdentifier: try string(input, "id")) else {
            throw ToolError.invalidInput("Afspraak niet gevonden; gebruik eerst list_events.")
        }
        let ok = await ui.confirm(title: "Afspraak verwijderen?",
                                  message: "\(event.title ?? "")\n\(Self.isoOut.string(from: event.startDate))",
                                  action: "Verwijderen")
        guard ok else { return "De gebruiker heeft het verwijderen geannuleerd." }
        try store.remove(event, span: .thisEvent, commit: true)
        return "Afspraak verwijderd."
    }

    // MARK: - Herinneringen

    private func listReminders() async throws -> String {
        try await ensureRemindersAccess()
        let predicate = store.predicateForIncompleteReminders(withDueDateStarting: nil, ending: nil, calendars: nil)
        let lines: [String] = await withCheckedContinuation { continuation in
            store.fetchReminders(matching: predicate) { reminders in
                let lines = (reminders ?? []).prefix(50).map { r -> String in
                    var line = "- [id: \(r.calendarItemIdentifier)] \(r.title ?? "(zonder titel)")"
                    if let comps = r.dueDateComponents, let due = Calendar.current.date(from: comps) {
                        line += ", gepland: \(Self.isoOut.string(from: due))"
                    }
                    return line
                }
                continuation.resume(returning: lines)
            }
        }
        return lines.isEmpty ? "Geen openstaande herinneringen." : lines.joined(separator: "\n")
    }

    private func createReminder(_ input: [String: Any]) async throws -> String {
        try await ensureRemindersAccess()
        let reminder = EKReminder(eventStore: store)
        reminder.title = try string(input, "title")
        reminder.notes = input["notes"] as? String
        reminder.calendar = store.defaultCalendarForNewReminders()
        var confirmation = "Herinnering '\(reminder.title ?? "")' aangemaakt"
        if input["due"] != nil {
            let due = try date(input, "due")
            reminder.dueDateComponents = Calendar.current.dateComponents([.year, .month, .day, .hour, .minute], from: due)
            reminder.addAlarm(EKAlarm(absoluteDate: due))
            confirmation += " voor \(Self.isoOut.string(from: due))"
        }
        try store.save(reminder, commit: true)
        return confirmation + "."
    }

    private func completeReminder(_ input: [String: Any]) async throws -> String {
        try await ensureRemindersAccess()
        guard let reminder = store.calendarItem(withIdentifier: try string(input, "id")) as? EKReminder else {
            throw ToolError.invalidInput("Herinnering niet gevonden; gebruik eerst list_reminders.")
        }
        reminder.isCompleted = true
        try store.save(reminder, commit: true)
        return "Herinnering '\(reminder.title ?? "")' afgevinkt."
    }

    // MARK: - Bellen en berichten

    private func call(_ input: [String: Any]) async throws -> String {
        let ui = try requireUI("Bellen")
        let number = Self.dialable(try string(input, "phone_number"))
        guard !number.isEmpty else { throw ToolError.invalidInput("Ongeldig telefoonnummer.") }
        let scheme: String
        switch input["method"] as? String {
        case "facetime": scheme = "facetime"
        case "facetime_audio": scheme = "facetime-audio"
        default: scheme = "tel"
        }
        guard let url = URL(string: "\(scheme):\(number)"), await ui.open(url) else {
            throw ToolError.invalidInput("Bellen lukte niet.")
        }
        return "Gesprek naar \(number) wordt gestart (iOS vraagt om bevestiging)."
    }

    private func sendMessage(_ input: [String: Any]) async throws -> String {
        let ui = try requireUI("Een bericht versturen")
        let recipients = input["recipients"] as? [String] ?? []
        let body = try string(input, "body")
        guard !recipients.isEmpty else { throw ToolError.invalidInput("Geen ontvanger opgegeven.") }

        if (input["app"] as? String) == "whatsapp" {
            let number = Self.whatsappNumber(recipients[0])
            var components = URLComponents(string: "https://wa.me/\(number)")!
            components.queryItems = [URLQueryItem(name: "text", value: body)]
            guard let url = components.url, await ui.open(url) else {
                throw ToolError.invalidInput("WhatsApp kon niet worden geopend.")
            }
            return "WhatsApp geopend met het bericht klaar. De gebruiker moet zelf op versturen tikken."
        }
        guard ui.presentMessage(MessageDraft(recipients: recipients, body: body)) else {
            throw ToolError.invalidInput("Berichten kan op dit apparaat niet worden geopend.")
        }
        return "Bericht klaargezet in Berichten. De gebruiker moet zelf op versturen tikken."
    }

    // MARK: - E-mail

    private func gmailSend(_ input: [String: Any]) async throws -> String {
        let ui = try requireUI("Een e-mail versturen")
        let to = input["to"] as? [String] ?? []
        let cc = input["cc"] as? [String] ?? []
        let subject = try string(input, "subject")
        let body = try string(input, "body")
        guard !to.isEmpty else { throw ToolError.invalidInput("Geen ontvanger opgegeven.") }

        var preview = "Aan: \(to.joined(separator: ", "))"
        if !cc.isEmpty { preview += "\nCc: \(cc.joined(separator: ", "))" }
        preview += "\nOnderwerp: \(subject)\n\n\(body.prefix(600))"
        guard await ui.confirm(title: "E-mail versturen?", message: preview, action: "Versturen") else {
            return "De gebruiker heeft het versturen geannuleerd."
        }
        return try await gmail.send(to: to, cc: cc, subject: subject, body: body,
                                    threadID: input["thread_id"] as? String,
                                    inReplyTo: input["in_reply_to"] as? String)
    }

    private func composeEmail(_ input: [String: Any]) throws -> String {
        let ui = try requireUI("Een e-mailconcept openen")
        let draft = EmailDraft(to: input["to"] as? [String] ?? [],
                               subject: input["subject"] as? String ?? "",
                               body: input["body"] as? String ?? "")
        guard ui.presentEmail(draft) else { throw ToolError.invalidInput("De Mail-app kon niet worden geopend.") }
        return "E-mailconcept geopend. De gebruiker verstuurt het zelf."
    }

    // MARK: - Kaarten, opdrachten, links

    private func openMaps(_ input: [String: Any]) async throws -> String {
        let ui = try requireUI("Kaarten openen")
        let flag: String
        switch input["mode"] as? String {
        case "walking": flag = "w"
        case "transit": flag = "r"
        default: flag = "d"
        }
        var components = URLComponents(string: "https://maps.apple.com/")!
        components.queryItems = [URLQueryItem(name: "daddr", value: try string(input, "destination")),
                                 URLQueryItem(name: "dirflg", value: flag)]
        guard let url = components.url, await ui.open(url) else { throw ToolError.invalidInput("Kaarten kon niet worden geopend.") }
        return "Route geopend in Kaarten."
    }

    private func runShortcut(_ input: [String: Any]) async throws -> String {
        let ui = try requireUI("Een opdracht uitvoeren")
        var components = URLComponents()
        components.scheme = "shortcuts"
        components.host = "run-shortcut"
        var items = [URLQueryItem(name: "name", value: try string(input, "name"))]
        if let text = input["input"] as? String, !text.isEmpty {
            items.append(URLQueryItem(name: "input", value: "text"))
            items.append(URLQueryItem(name: "text", value: text))
        }
        components.queryItems = items
        guard let url = components.url, await ui.open(url) else {
            throw ToolError.invalidInput("De app Opdrachten kon niet worden geopend.")
        }
        return "Opdracht gestart. De app Opdrachten neemt het even over."
    }

    private func openURL(_ input: [String: Any]) async throws -> String {
        let ui = try requireUI("Een app openen")
        guard let url = URL(string: try string(input, "url")), url.scheme != nil else {
            throw ToolError.invalidInput("Ongeldige URL.")
        }
        guard await ui.open(url) else { throw ToolError.invalidInput("Deze link kon niet worden geopend (is de app geïnstalleerd?).") }
        return "Geopend."
    }

    // MARK: - Hulpfuncties

    private func string(_ input: [String: Any], _ key: String) throws -> String {
        guard let value = input[key] as? String, !value.trimmingCharacters(in: .whitespaces).isEmpty else {
            throw ToolError.invalidInput("'\(key)' ontbreekt.")
        }
        return value
    }

    private func date(_ input: [String: Any], _ key: String) throws -> Date {
        guard let raw = input[key] as? String, let d = Self.parseDate(raw) else {
            throw ToolError.invalidInput("'\(key)' ontbreekt of is geen geldige ISO 8601-datum.")
        }
        return d
    }

    static func dialable(_ s: String) -> String {
        String(s.filter { $0.isNumber || $0 == "+" })
    }

    /// WhatsApp wil een internationaal nummer zonder '+', bijv. 31612345678.
    static func whatsappNumber(_ s: String) -> String {
        var digits = dialable(s).replacingOccurrences(of: "+", with: "")
        if digits.hasPrefix("00") { digits.removeFirst(2) }
        else if digits.hasPrefix("0") { digits = "31" + digits.dropFirst() } // aanname: Nederlands nummer
        return digits
    }

    static func parseDate(_ s: String) -> Date? {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime]
        if let d = iso.date(from: s) { return d }
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let d = iso.date(from: s) { return d }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = .current
        for format in ["yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd'T'HH:mm", "yyyy-MM-dd HH:mm", "yyyy-MM-dd"] {
            f.dateFormat = format
            if let d = f.date(from: s) { return d }
        }
        return nil
    }

    private static let isoOut: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = .current
        return f
    }()

    private static let dayFormatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()
}
