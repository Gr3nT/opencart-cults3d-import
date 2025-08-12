# Cults3D Import (OpenCart 3.0.4.1)
Verzió: **1.4.1** (build: 2025-08-12)

## Telepítés
1. Csomagold ki a ZIP-et.
2. Másold az `upload/` mappa tartalmát az OpenCart gyökérbe (a struktúra illeszkedik).
3. Admin → Extensions → Modules → **Cults3D Import**: Install, majd Edit.
4. Állítsd be az opciókat (URL-ek, kategória, szünet, OpenAI).

## Fő funkciók
- Soros import: **1 URL / kérés** (504 timeout ellenálló).
- **Model = Design number**, **SKU = Design number**, **Location = forrás URL**.
- Minden admin nyelvre adatok. OpenAI bekapcsolva: egy **globális prompt** a cél nyelven generál **nevet, leírást, meta leírást** (meta_title = név).
- Képek: **max 6**, csak **300×300 px-nél nagyobb** kerül mentésre; fő kép csak **JPG/PNG**.
- Riport a felületen, admin szerkesztési linkkel.

## Verzió és changelog
A modul tetején, az **„Verzió & változásnapló”** gomb alatt található.
