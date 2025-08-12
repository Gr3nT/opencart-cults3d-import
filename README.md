# Cults3D Import (OpenCart 3.0.4.1)
Verzió: **1.5.0** (build: 2025-08-12)

## Funkciók
- Kézi import (admin gomb): soros, 1 URL / kérés.
- **Cron mód**: várólistából dolgozik (queue tábla), limit/sleep paraméterekkel.
- Minden admin nyelvre tartalom; ha OpenAI be van kapcsolva, a cél nyelven generál **név + leírás + meta** (meta_title = név).
- Képek: max 6, csak 300×300 px felett, fő kép csak JPG/PNG.
- MODEL = Design number, SKU = Design number, Location = forrás URL.
- Verzió és changelog a modul tetején, összecsukható panelben.

## Telepítés
1. Csomagold ki a ZIP-et.
2. Az `upload/` tartalmát másold az OpenCart gyökérbe (mappaszerkezet illeszkedik).
3. Admin → Extensions → Modules → **Cults3D Import** → Install.
4. Admin → Extensions → Modules → **Cults3D Import** → Edit → beállítások mentése.

## Cron használat
- Állíts be **Cron kulcsot** (admin felület, majd ments).
- Töltsd fel az URL-eket a **Várólista** gombbal (admin).
- Időzített hívás például 5 percenként:
```
*/5 * * * * curl -s "https://SAJAT-DOMAIN/index.php?route=extension/module/cults3d_import_cron&key=YOUR_SECRET&limit=5&sleep=2" > /dev/null
```

## Fájlok
- `admin/controller/extension/module/cults3d_import.php`
- `admin/view/template/extension/module/cults3d_import.twig`
- `catalog/controller/extension/module/cults3d_import_cron.php`
- `system/library/openai_translate.php`
- `system/library/cults3d_importer.php`
- `system/library/cults3d_import_meta.php`

## Megjegyzések
- OpenAI kulcsot soha ne tedd repo-ba; admin beállításban tároljuk.
- Queue tábla: `oc_cults3d_queue` (prefix függő). Telepítéskor jön létre.
