import Foundation
import EventKit

struct EmailDraft: Identifiable {
    let id = UUID()
    var to: [String]
    var subject: String
    var body: String
}

/// Definieert de tools die Claude mag gebruiken en voert ze uit op de iPhone.
@MainActor
final class ToolExecutor {
    struct Outcome {
        let text: String
        var isError = false
    }

    private let store = EKEventStore()

    /// Wordt door de UI gezet; geeft `true` terug als het mailconcept getoond kan worden.
    var onComposeEmail: ((EmailDraft) -> Bool)?

    // MARK: - Tooldefinities

    static let toolDefinitions: [[String: Any]] = [
        [
            "name": "list_events",
            "description": "Haalt afspraken uit de agenda van de gebruiker binnen een periode. Gebruik dit voor vragen als 'wat staat er morgen op de planning?'.",
            "input_schema": [
                "type": "object",
                "properties": [
                    "start": ["type": "string", "description": "Begin van de periode, ISO 8601 met tijdzone."],
                    "end": ["type": "string", "description": "Einde van de periode, ISO 8601 met tijdzone."],
                ],
                "required": ["start", "end"],
            ],
        ],
        [
            "name": "create_event",
            "description": "Maakt een nieuwe afspraak in de standaardagenda van de gebruiker.",
            "input_schema": [
                "type": "object",
                "properties": [
                    "title": ["type": "string", "description": "Titel van de afspraak."],
                    "start": ["type": "string", "description": "Starttijd, ISO 8601 met tijdzone."],
                    "end": ["type": "string", "description": "Eindtijd, ISO 8601 met tijdzone. Standaard een uur na de start als de gebruiker niets zegt."],
                    "location": ["type": "string", "description": "Optionele locatie."],
                    "notes": ["type": "string", "description": "Optionele notities."],
                    "all_day": ["type": "boolean", "description": "true voor een hele-dag-afspraak."],
                ],
                "required": ["title", "start", "end"],
            ],
        ],
        [
            "name": "list_reminders",
            "description": "Geeft de openstaande herinneringen (taken) van de gebruiker, met hun id.",
            "input_schema": [
                "type": "object",
                "properties": [:] as [String: Any],
            ],
        ],
        [
            "name": "create_reminder",
            "description": "Maakt een herinnering aan in de app Herinneringen, optioneel met een tijdstip waarop de iPhone een melding geeft.",
            "input_schema": [
                "type": "object",
                "properties": [
                    "title": ["type": "string", "description": "Wat de gebruiker moet onthouden."],
                    "due": ["type": "string", "description": "Optioneel tijdstip voor de melding, ISO 8601 met tijdzone."],
                    "notes": ["type": "string", "description": "Optionele notities."],
                ],
                "required": ["title"],
            ],
        ],
        [
            "name": "complete_reminder",
            "description": "Markeert een herinnering als afgerond. Haal eerst het id op met list_reminders.",
            "input_schema": [
                "type": "object",
                "properties": [
                    "id": ["type": "string", "description": "Het id van de herinnering uit list_reminders."],
                ],
                "required": ["id"],
            ],
        ],
        [
            "name": "compose_email",
            "description": "Opent een e-mailconcept op de iPhone met ontvanger, onderwerp en tekst ingevuld. De gebruiker verstuurt het zelf.",
            "input_schema": [
                "type": "object",
                "properties": [
                    "to": ["type": "array", "items": ["type": "string"], "description": "E-mailadressen van de ontvangers."],
                    "subject": ["type": "string"],
                    "body": ["type": "string", "description": "De tekst van de e-mail."],
                ],
                "required": ["to", "subject", "body"],
            ],
        ],
        // Server-tool: Anthropic voert de zoekopdracht uit, geen code nodig in de app.
        [
            "type": "web_search_20260209",
            "name": "web_search",
            "max_uses": 3,
            "user_location": [
                "type": "approximate",
                "country": "NL",
                "timezone": "Europe/Amsterdam",
            ],
        ],
    ]

    // MARK: - Uitvoeren

    func run(name: String, input: [String: Any]) async -> Outcome {
        do {
            switch name {
            case "list_events": return try await listEvents(input)
            case "create_event": return try await createEvent(input)
            case "list_reminders": return try await listReminders()
            case "create_reminder": return try await createReminder(input)
            case "complete_reminder": return try await completeReminder(input)
            case "compose_email": return composeEmail(input)
            default: return Outcome(text: "Onbekende tool: \(name)", isError: true)
            }
        } catch {
            return Outcome(text: "Fout: \(error.localizedDescription)", isError: true)
        }
    }

    // MARK: - Toegang

    enum AccessError: LocalizedError {
        case denied(String)
        case invalidInput(String)
        var errorDescription: String? {
            switch self {
            case .denied(let what): return "Geen toegang tot \(what). De gebruiker kan dit aanzetten in Instellingen > Privacy."
            case .invalidInput(let msg): return msg
            }
        }
    }

    func ensureCalendarAccess() async throws {
        if EKEventStore.authorizationStatus(for: .event) == .fullAccess { return }
        guard try await store.requestFullAccessToEvents() else { throw AccessError.denied("de agenda") }
    }

    func ensureRemindersAccess() async throws {
        if EKEventStore.authorizationStatus(for: .reminder) == .fullAccess { return }
        guard try await store.requestFullAccessToReminders() else { throw AccessError.denied("herinneringen") }
    }

    // MARK: - Agenda

    private func listEvents(_ input: [String: Any]) async throws -> Outcome {
        try await ensureCalendarAccess()
        let start = try date(input, "start")
        let end = try date(input, "end")
        let predicate = store.predicateForEvents(withStart: start, end: end, calendars: nil)
        let events = store.events(matching: predicate).sorted { $0.startDate < $1.startDate }
        if events.isEmpty { return Outcome(text: "Geen afspraken in deze periode.") }
        let lines = events.prefix(50).map { e -> String in
            var line = "- \(e.title ?? "(zonder titel)"): "
            line += e.isAllDay ? "hele dag \(Self.dayFormatter.string(from: e.startDate))"
                               : "\(Self.isoOut.string(from: e.startDate)) tot \(Self.isoOut.string(from: e.endDate))"
            if let loc = e.location, !loc.isEmpty { line += ", locatie: \(loc)" }
            line += " [agenda: \(e.calendar.title)]"
            return line
        }
        return Outcome(text: lines.joined(separator: "\n"))
    }

    private func createEvent(_ input: [String: Any]) async throws -> Outcome {
        try await ensureCalendarAccess()
        guard let title = input["title"] as? String, !title.isEmpty else {
            throw AccessError.invalidInput("title ontbreekt")
        }
        let event = EKEvent(eventStore: store)
        event.title = title
        event.startDate = try date(input, "start")
        event.endDate = try date(input, "end")
        event.isAllDay = input["all_day"] as? Bool ?? false
        event.location = input["location"] as? String
        event.notes = input["notes"] as? String
        event.calendar = store.defaultCalendarForNewEvents
        try store.save(event, span: .thisEvent, commit: true)
        return Outcome(text: "Afspraak '\(title)' aangemaakt op \(Self.isoOut.string(from: event.startDate)).")
    }

    // MARK: - Herinneringen

    private func listReminders() async throws -> Outcome {
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
        return Outcome(text: lines.isEmpty ? "Geen openstaande herinneringen." : lines.joined(separator: "\n"))
    }

    private func createReminder(_ input: [String: Any]) async throws -> Outcome {
        try await ensureRemindersAccess()
        guard let title = input["title"] as? String, !title.isEmpty else {
            throw AccessError.invalidInput("title ontbreekt")
        }
        let reminder = EKReminder(eventStore: store)
        reminder.title = title
        reminder.notes = input["notes"] as? String
        reminder.calendar = store.defaultCalendarForNewReminders()
        var confirmation = "Herinnering '\(title)' aangemaakt"
        if input["due"] != nil {
            let due = try date(input, "due")
            reminder.dueDateComponents = Calendar.current.dateComponents(
                [.year, .month, .day, .hour, .minute], from: due)
            reminder.addAlarm(EKAlarm(absoluteDate: due))
            confirmation += " voor \(Self.isoOut.string(from: due))"
        }
        try store.save(reminder, commit: true)
        return Outcome(text: confirmation + ".")
    }

    private func completeReminder(_ input: [String: Any]) async throws -> Outcome {
        try await ensureRemindersAccess()
        guard let id = input["id"] as? String,
              let reminder = store.calendarItem(withIdentifier: id) as? EKReminder else {
            throw AccessError.invalidInput("Herinnering niet gevonden; gebruik eerst list_reminders.")
        }
        reminder.isCompleted = true
        try store.save(reminder, commit: true)
        return Outcome(text: "Herinnering '\(reminder.title ?? "")' afgevinkt.")
    }

    // MARK: - E-mail

    private func composeEmail(_ input: [String: Any]) -> Outcome {
        let draft = EmailDraft(
            to: input["to"] as? [String] ?? [],
            subject: input["subject"] as? String ?? "",
            body: input["body"] as? String ?? "")
        guard let present = onComposeEmail, present(draft) else {
            return Outcome(text: "Het e-mailconcept kan hier niet worden geopend. Vraag de gebruiker de app te openen.", isError: true)
        }
        return Outcome(text: "E-mailconcept geopend. De gebruiker moet het zelf versturen.")
    }

    // MARK: - Datums

    private func date(_ input: [String: Any], _ key: String) throws -> Date {
        guard let raw = input[key] as? String, let d = Self.parseDate(raw) else {
            throw AccessError.invalidInput("'\(key)' ontbreekt of is geen geldige ISO 8601-datum.")
        }
        return d
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
