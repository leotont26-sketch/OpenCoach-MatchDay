Branding-Paket – JSG Haßmersheim / Hüffenhardt
===================================================

Dateien:
- logo.png                 -> Dein Logo (PNG)
- favicon.ico, favicon-32.png, apple-touch-icon.png
- site.webmanifest         -> PWA/Icons
- theme.css                -> Farb- und UI-Variablen
- head.inc.html            -> Fonts, Icons & CSS einbinden
- header.inc.html          -> Brand-Header (Logo + Titel)
- index_branding_sample.php-> Beispiel-Index mit Brand-Header

Einbindung in bestehende Seiten:
1) Lade alle Dateien in den gleichen Ordner wie index.php hoch.
2) Öffne deine *index.php* im Editor und füge im <head> **unterhalb** von Pico CSS ein:
   <?php include __DIR__ . '/head.inc.html'; ?>
3) Direkt unter <main class="container"> füge ein:
   <?php include __DIR__ . '/header.inc.html'; ?>

Optional: Farben anpassen
- theme.css -> Variablen anpassen (z.B. --brand-primary, --brand-accent).
