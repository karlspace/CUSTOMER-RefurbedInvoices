# Stellungnahme: Refurbed Invoice Connector — Sicherheitshärtung

**Datum:** 09.04.2026
**Betreff:** Sicherheitsbewertung und Härtung des Refurbed-Invoice-Connectors
**Erstellt durch:** BAUER GROUP — IT Operations

---

## Zusammenfassung

Der von Magedia bereitgestellte Refurbed-Invoice-Connector wurde von uns für den Produktivbetrieb auf Ihrer Infrastruktur vorbereitet. **Die Software war im Originalzustand weder einsatzbereit noch sicher genug für den Betrieb.**

Wir haben die **technische Infrastruktur und die Sicherheit** überarbeitet, damit die Software verantwortbar betrieben werden kann. **Die eigentliche Geschäftslogik** (also: wie Rechnungen verarbeitet, zugeordnet und hochgeladen werden) **wurde von uns nicht geprüft** — dafür ist der Entwickler der Software (Magedia) verantwortlich.

Unsere Verbesserungen werden als Beitrag an das Originalprojekt zurückgegeben.

---

## Ausgangslage: Was war das Problem?

Wir haben die Software vor dem Deployment geprüft und dabei fünf schwerwiegende Probleme festgestellt:

### 1. Fehlende Prüfung der Zugangsdaten beim Start

Die Software hat beim Start **nicht geprüft, ob alle Zugangsdaten vorhanden sind** (Shopify, Refurbed, E-Mail-Postfach). Fehlte ein Zugangsdatensatz, lief die Anwendung trotzdem — aber ohne Funktion und ohne Fehlermeldung. Man hätte also nicht bemerkt, dass etwas nicht stimmt.

### 2. Anwendung lief mit vollen Systemrechten

Die Software lief mit den **höchstmöglichen Berechtigungen** (vergleichbar mit einem Administrator-Konto auf einem PC). Das ist ein erhebliches Sicherheitsrisiko: Hätte ein Angreifer eine Schwachstelle gefunden, hätte er sofort die volle Kontrolle über das System gehabt. Die Software braucht diese umfangreichen Rechte nicht — sie soll nur Rechnungen verarbeiten.

### 3. Passwörter und Zugangsdaten offen lesbar gespeichert

Beim Start der Anwendung wurden **alle Zugangsdaten** (Shopify-Token, E-Mail-Passwort, Refurbed-Token) in eine Datei geschrieben, die **für jeden Prozess auf dem System lesbar** war. Im Falle eines Angriffs hätte ein Angreifer sofort Zugriff auf alle hinterlegten Zugangsdaten gehabt — inklusive Ihres Shopify-Backends und Ihres E-Mail-Postfachs.

### 4. Keine Prüfung der Verbindungssicherheit

Die Verbindungen zu Shopify und Refurbed haben **nicht überprüft, ob die Gegenseite tatsächlich Shopify bzw. Refurbed ist**. Vergleichbar mit einem Brief ohne Siegel: Ein Angreifer hätte den Datenverkehr abfangen und mitlesen können — inklusive Rechnungsdaten und Zugangsdaten.

### 5. Keine Absicherung gegen Rechteausweitung

Es gab **keinen Schutz dagegen, dass sich ein kompromittierter Prozess höhere Rechte verschafft**. Ein kleiner Sicherheitsvorfall hätte so zu einem großen werden können.

---

## Warum wir die Software nicht im Originalzustand betreiben konnten

Als Ihr IT-Dienstleister tragen wir eine **Mitverantwortung für die Sicherheit der Anwendungen auf Ihrer Infrastruktur**. Die oben beschriebenen Mängel machten einen Betrieb aus folgenden Gründen nicht vertretbar:

- **Haftung:** Bei einem Sicherheitsvorfall (z.B. Zugriff auf Ihr Shopify-Backend oder Ihr E-Mail-Postfach durch Dritte) wäre die Frage aufgekommen, warum eine erkennbar unsichere Anwendung wissentlich in Betrieb genommen wurde.
- **Datenschutz:** Die Software verarbeitet Rechnungsdaten und hat Zugriff auf Ihr Shopify-Backend sowie ein E-Mail-Postfach. Ein Vorfall könnte personenbezogene Daten betreffen und wäre meldepflichtig (DSGVO).
- **Sorgfaltspflicht:** Die festgestellten Mängel sind keine Grenzfälle, sondern gehören zu den häufigsten und vermeidbarsten Sicherheitslücken in der Softwareentwicklung.

---

## Was wir gemacht haben

Wir haben insgesamt **23 Sicherheitsverbesserungen** vorgenommen, aufgeteilt nach Schweregrad:

| Schweregrad | Anzahl | Was wurde behoben? |
|-------------|--------|---------------------|
| **Kritisch** | 4 | Systemrechte eingeschränkt, Verbindungssicherheit erzwungen, Zugangsdaten-Speicherung abgesichert, Datei-Schwachstelle behoben |
| **Hoch** | 4 | Zeitlimits für Verbindungen gesetzt, Eingabeprüfungen hinzugefügt, keine sensiblen Daten mehr in Logdateien |
| **Mittel** | 15 | Rechnungsdateien werden geprüft, Zugangsdaten werden beim Start validiert, automatische Wiederholungsversuche bei Fehlern, Protokollierung verbessert |

### Strukturelle Verbesserungen

| Vorher | Nachher | Warum? |
|--------|---------|--------|
| Alle Dateien durcheinander | Ordnerstruktur: Software und Konfiguration getrennt | Übersichtlichkeit und Wartbarkeit |
| Passwörter für alle lesbar | Passwörter nur noch für die Anwendung selbst zugänglich | Schutz der Zugangsdaten |
| Volle Systemrechte | Eigenes eingeschränktes Benutzerkonto für die Anwendung | Minimale Rechte = minimales Risiko |

### Was wir NICHT gemacht haben

Der Vollständigkeit halber:

- **Keine inhaltliche Prüfung der Rechnungsverarbeitung** — ob die richtigen Rechnungen den richtigen Bestellungen zugeordnet werden, haben wir nicht geprüft
- **Keine umfassende Verbesserung des Fehlerverhaltens** — wie sich die Software bei unerwarteten Situationen verhält (z.B. fehlerhafte E-Mails, API-Ausfälle), wurde nicht grundlegend überarbeitet
- **Keine automatischen Tests** — es gibt weiterhin keine automatisierten Prüfungen, ob die Software korrekt funktioniert
- **Keine Prüfung der Shopify- und Refurbed-Anbindung** — ob die Schnittstellen korrekt angesprochen werden, liegt in der Verantwortung von Magedia

Diese Punkte sollten vom Entwickler der Software (Magedia) adressiert werden.

---

## Rückgabe an den Entwickler

Unsere Verbesserungen werden als Beitrag an das Originalprojekt von Magedia zurückgegeben, damit auch andere Nutzer der Software davon profitieren können.

---

## Empfehlung

Die Software ist nach unserer Überarbeitung **einsatzbereit**. Wir empfehlen darüber hinaus:

1. **Magedia bitten, die Rechnungsverarbeitung zu prüfen** und eine offizielle, abgesicherte Version bereitzustellen
2. **Software regelmäßig aktualisieren** lassen
3. **Betrieb überwachen** — wir können die Protokolle der Anwendung auf Auffälligkeiten prüfen
4. **Zugangsdaten regelmäßig ändern** (Shopify, Refurbed, E-Mail-Passwort)

---

*Bei Rückfragen stehen wir Ihnen gerne zur Verfügung.*

**BAUER GROUP — IT Operations**
