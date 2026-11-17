import Foundation
import Contacts
import CoreLocation

// MARK: - Contacten

@MainActor
final class ContactsService {
    private let store = CNContactStore()

    func ensureAccess() async throws {
        if CNContactStore.authorizationStatus(for: .contacts) == .authorized { return }
        guard try await store.requestAccess(for: .contacts) else {
            throw ToolExecutor.ToolError.denied("je contacten")
        }
    }

    func search(_ query: String) async throws -> String {
        try await ensureAccess()
        let keys: [CNKeyDescriptor] = [
            CNContactGivenNameKey, CNContactFamilyNameKey, CNContactNicknameKey,
            CNContactOrganizationNameKey, CNContactPhoneNumbersKey,
            CNContactEmailAddressesKey, CNContactBirthdayKey, CNContactPostalAddressesKey,
        ].map { $0 as CNKeyDescriptor }

        var contacts = try store.unifiedContacts(matching: CNContact.predicateForContacts(matchingName: query),
                                                 keysToFetch: keys)
        if contacts.isEmpty {
            // Ook zoeken op bijnaam ("mama") en bedrijfsnaam.
            let request = CNContactFetchRequest(keysToFetch: keys)
            try store.enumerateContacts(with: request) { contact, stop in
                if contact.nickname.localizedCaseInsensitiveContains(query) ||
                    contact.organizationName.localizedCaseInsensitiveContains(query) {
                    contacts.append(contact)
                    if contacts.count >= 10 { stop.pointee = true }
                }
            }
        }
        if contacts.isEmpty { return "Geen contact gevonden voor '\(query)'." }

        return contacts.prefix(10).map { c in
            var parts = ["- \([c.givenName, c.familyName].filter { !$0.isEmpty }.joined(separator: " "))"]
            if !c.nickname.isEmpty { parts.append("bijnaam: \(c.nickname)") }
            if !c.organizationName.isEmpty { parts.append("bedrijf: \(c.organizationName)") }
            for phone in c.phoneNumbers {
                let label = phone.label.map { CNLabeledValue<NSString>.localizedString(forLabel: $0) } ?? "telefoon"
                parts.append("\(label): \(phone.value.stringValue)")
            }
            for mail in c.emailAddresses { parts.append("e-mail: \(mail.value)") }
            if let b = c.birthday, let month = b.month, let day = b.day {
                parts.append("verjaardag: \(day)-\(month)\(b.year.map { "-\($0)" } ?? "")")
            }
            if let address = c.postalAddresses.first?.value {
                parts.append("adres: \(address.street), \(address.postalCode) \(address.city)")
            }
            return parts.joined(separator: " | ")
        }.joined(separator: "\n")
    }
}

// MARK: - Locatie

@MainActor
final class LocationService: NSObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    private var locationContinuation: CheckedContinuation<CLLocation, Error>?
    private var authContinuation: CheckedContinuation<Void, Never>?

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyHundredMeters
    }

    func describeCurrentLocation() async throws -> String {
        if manager.authorizationStatus == .notDetermined {
            await withCheckedContinuation { continuation in
                authContinuation = continuation
                manager.requestWhenInUseAuthorization()
            }
        }
        guard manager.authorizationStatus == .authorizedWhenInUse || manager.authorizationStatus == .authorizedAlways else {
            throw ToolExecutor.ToolError.denied("je locatie")
        }
        let location: CLLocation = try await withCheckedThrowingContinuation { continuation in
            locationContinuation?.resume(throwing: CancellationError())
            locationContinuation = continuation
            manager.requestLocation()
        }
        var text = String(format: "Coördinaten: %.5f, %.5f", location.coordinate.latitude, location.coordinate.longitude)
        if let place = try? await CLGeocoder().reverseGeocodeLocation(location).first {
            let address = [place.thoroughfare, place.subThoroughfare].compactMap { $0 }.joined(separator: " ")
            text += "\nAdres: \(address), \(place.postalCode ?? "") \(place.locality ?? ""), \(place.country ?? "")"
        }
        return text
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let last = locations.last else { return }
        Task { @MainActor in
            self.locationContinuation?.resume(returning: last)
            self.locationContinuation = nil
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in
            self.locationContinuation?.resume(throwing: error)
            self.locationContinuation = nil
        }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status = manager.authorizationStatus
        Task { @MainActor in
            if status != .notDetermined {
                self.authContinuation?.resume()
                self.authContinuation = nil
            }
        }
    }
}

// MARK: - Geheugen

/// Feiten die de assistent over je onthoudt (bijv. "mijn vrouw heet Lisa", "ik werk in Utrecht").
@MainActor
final class MemoryStore: ObservableObject {
    static let shared = MemoryStore()
    @Published private(set) var facts: [String]

    private init() {
        facts = UserDefaults.standard.stringArray(forKey: "memory") ?? []
    }

    func add(_ fact: String) {
        facts.append(fact)
        save()
    }

    @discardableResult
    func remove(matching text: String) -> Int {
        let before = facts.count
        facts.removeAll { $0.localizedCaseInsensitiveContains(text) }
        save()
        return before - facts.count
    }

    func remove(atOffsets offsets: IndexSet) {
        facts.remove(atOffsets: offsets)
        save()
    }

    private func save() {
        UserDefaults.standard.set(facts, forKey: "memory")
    }
}
