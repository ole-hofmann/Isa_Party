# 🎃 Grusel-Einlass

Kleine Web-App für den Einlass bei Isas und Lauras Halloween-Party: Name suchen, prüfen ob er auf der Gästeliste steht, 5 € kassieren und abhaken. Läuft im Handy-Browser, mehrere Handys gleichzeitig sind kein Problem.

## Funktionen

- **Suche** ohne Rücksicht auf Groß-/Kleinschreibung und Umlaute (`jaeckel`, `jackel` und `Jäckel` finden dasselbe), Vor- und Nachname in beliebiger Reihenfolge
- **Tippfehler-Vorschläge** („Meintest du …“), wenn nichts passt
- **Einlass bestätigen** mit einem Tipp: „5 € erhalten – rein!“
- **Warnung bei doppeltem Einlass** („Schon drin seit 21:14 Uhr“), auch wenn ein anderes Handy den Gast schon abgehakt hat
- **Rückgängig machen**, falls man sich vertippt hat
- **Gäste nachtragen**, die nicht auf der Liste stehen (werden mit ＋ markiert)
- **Umbenennen und Löschen** direkt im Gast-Dialog (✏️ / 🗑️) – wirkt sofort auf allen Handys
- **Zähler** oben: wie viele drin sind, wie viele noch fehlen, wie viel in der Kasse sein sollte
- **Export** als CSV (Menü ⋮) für die Abrechnung danach
- Doppelte Einträge in der Gästeliste werden automatisch zusammengefasst
- Alle Handys aktualisieren sich alle 5 Sekunden

## Einrichtung

Voraussetzung: Webspace mit **PHP 7.4 oder neuer** (mit `mbstring`, ist praktisch überall dabei). Keine Datenbank nötig.

1. Dateien auf den Webspace hochladen (z. B. in einen Ordner `/einlass`).
2. `config.example.php` nach `config.php` kopieren und ein **Passwort** eintragen.
3. Die Gästeliste als `data/teilnehmerinnen.csv` hochladen (ein Name pro Zeile).
4. Der Ordner `data/` muss für PHP **beschreibbar** sein – dort landen der Einlass-Status (`guests.json`) und ein Protokoll (`log.txt`).
5. Seite auf dem Handy öffnen, Passwort eingeben, fertig. Tipp: „Zum Startbildschirm hinzufügen“.

Die Gästeliste und `config.php` sind absichtlich **nicht** im Git-Repo (Namen sind personenbezogene Daten).

### Lokal ausprobieren

```sh
php -S 0.0.0.0:8000
```

Dann am Handy im selben WLAN `http://<IP-des-Rechners>:8000` öffnen.

## Gut zu wissen

- **Gästeliste nachträglich ändern:** am einfachsten direkt in der App. Alternativ `data/teilnehmerinnen.csv` ersetzen – neue Namen werden automatisch übernommen, wer schon drin ist, bleibt drin. Aus der CSV entfernte Namen bleiben aber in der App; zum Löschen also die App nehmen. In der App gelöschte oder umbenannte Gäste tauchen durch die CSV nicht wieder auf.
- **Protokoll:** Jeder Einlass, jedes Rückgängigmachen, Hinzufügen, Umbenennen und Löschen steht mit Uhrzeit in `data/log.txt`.
- **Alles zurücksetzen** (z. B. nach dem Testen): `data/guests.json` löschen. Die Liste wird dann neu aus der CSV eingelesen.
- **Schutz der Daten:** Die `.htaccess`-Dateien sperren `data/` und die Konfiguration für Apache. Auf **nginx** muss man den Zugriff auf `data/` selbst in der Server-Konfiguration sperren.
- Das Handy braucht am Eingang **Internet** (mobile Daten reichen). Bei Verbindungsproblemen erscheint oben ein roter Hinweis.
