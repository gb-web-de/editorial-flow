# Editorial Flow installieren

*[English version](README.md)*

Drei Schritte, egal wie Ihre Installation aussieht:

1. **Extension installieren**
2. **Einen Setup-Befehl** für Ihre Situation ausführen
3. **Ergebnis prüfen**

Jeder Befehl zeigt zuerst seinen Plan. Mit `--dry-run` sehen Sie nur den Plan, es wird nichts
geändert. Ein Befehl darf beliebig oft laufen: Er ergänzt nur, was fehlt.

> In einem DDEV-Projekt `ddev exec` vor jeden Befehl setzen (`ddev exec vendor/bin/typo3 …`).
> In der Entwicklungsumgebung dieses Repositories heißt das Programm `.Build/bin/typo3`.

## 1. Extension installieren

```bash
composer require gb-web/editorial-flow
vendor/bin/typo3 extension:setup
```

Voraussetzung: TYPO3 v14.3 mit `typo3/cms-workspaces` (wird als Abhängigkeit mitinstalliert).

## 2. Situation wählen

| Ihre Installation | Befehl |
|---|---|
| **Grüne Wiese / leere Datenbank** – noch nichts da | `typo3 editorialflow:setup fresh` |
| **Eine Redaktionsgruppe**, Name frei wählbar | `typo3 editorialflow:setup single-group --group="Redaktion"` |
| **Mehrere Gruppen, jede mit eigenem Workspace** | `typo3 editorialflow:setup group-per-workspace --group="News,Sport,Kultur"` |
| **Großer bestehender Gruppenbaum**, der bleiben soll wie er ist | `typo3 editorialflow:setup existing-groups --attach-to-editors --dry-run` |
| Unsicher | `typo3 editorialflow:setup` – fragt Schritt für Schritt |
| Nur ausprobieren | `typo3 editorialflow:democontent` – Demo-Workspaces und neun Demo-Benutzer |

Optionen, die überall gelten:

| Option | Bedeutung | Standard |
|---|---|---|
| `--stages="Lektorat,Freigabe"` | Prüfschritte in Reihenfolge, oder `none` | `Review,Approval` |
| `--owner=chefin` | Benutzer, denen der Workspace gehört – **nur Besitzer dürfen veröffentlichen** | nur Administratoren |
| `--owner-group="Chefredaktion"` | Dasselbe für eine ganze Gruppe (wird angelegt, falls sie fehlt) | – |
| `--editor=jana` | Gleich einen Redakteur anlegen (`jana:Sport` für eine bestimmte Gruppe) | – |
| `--editor-password=…` | Passwort für neue Redakteure, oder `EDITORIALFLOW_EDITOR_PASSWORD` | – |
| `--dry-run` | Plan zeigen, nichts ändern | – |

### Grüne Wiese / leere Datenbank

```bash
EDITORIALFLOW_EDITOR_PASSWORD='S3cure!Pass' \
  vendor/bin/typo3 editorialflow:setup fresh --group="Redaktion" --stages="Lektorat,Freigabe" --owner=admin --editor=jana
```

Legt an, falls es fehlt:

- eine Startseite *Home* und eine Site *main* (`--site-base=https://example.org/` für eine
  echte Domain) – nur wenn es überhaupt keine Seiten gibt; ein vorhandener Seitenbaum wird
  nie angefasst
- die Gruppe **Redaktion** mit Board, Seiten-, Listen- und Medienmodul, auf die Startseite
  gemountet, darf Seiten und Inhalte bearbeiten – **nur in Workspaces**, nicht in Live
- den Workspace **Redaktion** mit den Stufen *Lektorat → Freigabe*; die Gruppe ist Mitglied
  und für beide Stufen zuständig; `admin` ist Besitzer und darf veröffentlichen
- die Redakteurin **jana**, die beim ersten Login direkt in diesem Workspace landet

Ohne `--group` heißt die Gruppe *Editors*.

### Eine Redaktionsgruppe mit eigenem Namen

```bash
vendor/bin/typo3 editorialflow:setup single-group --group="Online-Redaktion" --owner=chefin
```

- **Gruppe gibt es schon:** Nur das Board-Modul kommt dazu. Alle anderen Module, Tabellen,
  Mounts und Seitenrechte bleiben genau so, wie sie sind.
- **Gruppe ist neu:** Sie wird angelegt wie bei *fresh*. Sie bekommt die Seiten, die noch
  keiner Gruppe gehören; Seiten einer anderen Gruppe bleiben unangetastet (der Befehl sagt,
  wie viele).
- Workspace: heißt wie die Gruppe, oder anders mit `--workspace="Entwurf"`. Ein vorhandener
  Workspace behält alle Mitglieder und Besitzer; die Gruppe kommt dazu.

### Mehrere Gruppen, jede mit eigenem Workspace

```bash
vendor/bin/typo3 editorialflow:setup group-per-workspace --group="News,Sport,Kultur" --owner-group="Chefredaktion"
```

Jede Gruppe bekommt einen gleichnamigen Workspace. Mitglieder von *Sport* sehen und
bearbeiten nur in *Sport*. Die Besitzergruppe (wird angelegt, falls sie fehlt) besitzt alle
drei Workspaces und darf überall veröffentlichen.

Eine Seite kann nur einer Gruppe gehören. Deshalb teilen sich neue Gruppen ihre Seiten- und
Bearbeitungsrechte über eine zusätzliche Gruppe **Editorial Flow Editors (shared)**. Sie hat
kein Board und keinen eigenen Workspace; *News*, *Sport* und *Kultur* binden sie ein und
bekommen selbst nur das Board. Vorhandene Gruppen bekommen nur das Board-Modul.

Redakteure für eine bestimmte Gruppe: `--editor=jana:Sport --editor=tom:News`.

### Großer bestehender Gruppenbaum

Ihre Gruppen, Rechte und Seiteneigentümer sind über Jahre gewachsen – dieses Szenario fasst
sie nicht an. Es legt nur eine kleine Gruppe **Editorial Flow Access** an, die nichts außer
dem Board-Modul hat, und hängt sie als Untergruppe in Ihre Gruppen.

```bash
# 1. erst ansehen
vendor/bin/typo3 editorialflow:setup existing-groups --attach-to-editors --dry-run
# 2. dann ausführen
vendor/bin/typo3 editorialflow:setup existing-groups --attach-to-editors
```

- `--attach-to-editors` nimmt jede Gruppe, die selbst das Seitenmodul hat.
- Oder die Gruppen nennen: `--attach-to="Redaktion,Online-Desk"` (Titel oder uid; uid
  nehmen, wenn mehrere Gruppen gleich heißen). Alle Mitglieder dieser Gruppen bekommen das
  Board. TYPO3 vererbt Rechte von einer Untergruppe *nach oben* an die Gruppen, die sie
  einbinden, nicht nach unten – wer nur in einer Untergruppe von *Redaktion* ist, braucht
  auch diese Untergruppe in der Liste.
- Workspaces bleiben, wie sie sind. Um den ganzen Baum in einen Workspace zu bringen:
  `--workspace="Redaktion" --owner=chefin` dazunehmen.

## 3. Ergebnis prüfen

```bash
vendor/bin/typo3 editorialflow:check
```

```
  ✓ 7 page(s).
  ✓ Workspace "Redaktion" (uid 1): stages Lektorat > Freigabe.
  ✓ 1 group(s) can open the board: Redaktion.

 [OK] The board is ready to use.
```

Bei einem Fehler endet der Befehl mit Fehlercode, ein Deployment kann ihn also einbauen.
Was er findet und was hilft:

| Meldung | Bedeutung | Abhilfe |
|---|---|---|
| *There is no workspace* | Es entsteht nie von selbst eine Aufgabe | ein Setup-Szenario ausführen |
| *stage counter says 0* | Nur Besitzer kommen über *Editing* hinaus | `editorialflow:check --fix` |
| *N editor(s) may edit Live* | Was sie in Live ändern, wird nie zur Aufgabe | `editorialflow:check --fix-live-editing` |
| *Group … is not a member of any workspace* | Sieht das Board, kann aber nicht darauf arbeiten | `editorialflow:setup single-group --group="…"` |
| *… has no owner* | Nur Administratoren können veröffentlichen | Setup mit `--owner=…` erneut ausführen |

### Später Redakteure hinzufügen

Am einfachsten über Setup (der Redakteur landet im Workspace, nicht in Live):

```bash
EDITORIALFLOW_EDITOR_PASSWORD='…' vendor/bin/typo3 editorialflow:setup single-group --group="Redaktion" --editor=tom
```

oder den Benutzer im Backend bzw. mit `typo3 backend:user:create --groups=<uid>` anlegen und
danach:

```bash
vendor/bin/typo3 editorialflow:check --fix-live-editing
```

Das Backend-Formular für Benutzer setzt *Live bearbeiten* standardmäßig – genau dieser eine
Haken hält jede Änderung des Redakteurs vom Board fern.

## Was Setup nie tut

- einer Gruppe ein Modul, eine Tabelle, einen Mount oder eine Untergruppe wegnehmen
- eine Seite übernehmen, die schon einer anderen Gruppe gehört
- ein Mitglied oder einen Besitzer aus einem Workspace entfernen
- Passwort oder Einstellungen eines vorhandenen Benutzers ändern
- irgendetwas löschen

Rückgängig machen: die angelegte Gruppe, den Workspace oder Benutzer im Backend löschen
(*Administration → Backend-Benutzer*, *Workspaces*).

## Automatisierte Deployments

Mit Szenario-Namen und `--no-interaction` laufen alle Szenarien ohne Rückfragen:

```bash
vendor/bin/typo3 extension:setup
vendor/bin/typo3 editorialflow:setup single-group --group="Redaktion" --owner=chefin --no-interaction
vendor/bin/typo3 editorialflow:check
```
