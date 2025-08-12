<?php
class Cults3DImportMeta {
    const VERSION = '1.5.0';

    public static function getChangelog() {
        return [
            [
                'version' => '1.5.0',
                'date'    => '2025-08-12',
                'items'   => [
                    'Cron alapú soros feldolgozás (catalog controller).',
                    'Várólista (DB tábla: cults3d_queue) és adminból URL-ek felvétele.',
                    'Cron kulcs védelem, limit és sleep paraméterek.',
                    'Kézi 1 URL / kérés import változatlanul elérhető.'
                ]
            ],
            [
                'version' => '1.4.1',
                'date'    => '2025-08-12',
                'items'   => [
                    'Verzió & változásnapló összehajtható gomb alatt a modul tetején.'
                ]
            ],
            [
                'version' => '1.4.0',
                'date'    => '2025-08-12',
                'items'   => [
                    'Soros import: 1 URL / kérés (504 nginx timeout elkerülése).',
                    'Képek: csak a 300×300 px-nél nagyobbak menthetők. Sikertelen/kicsi kép nem kerül adatbázisba.',
                    'SKU = Design number; Location = forrás URL.',
                    'OpenAI: globális prompt minden admin nyelvre; név + leírás + meta leírás generálás a cél nyelven; meta_title = generált név.',
                    'Fő kép csak JPG/PNG lehet; max. 6 kép termékenként.',
                    'AJAX URL &amp;→& javítás (admin login visszadobás megszűnt).'
                ]
            ],
            [
                'version' => '1.3.0',
                'date'    => '2025-08-11',
                'items'   => [
                    'Minden nyelvre töltés; ha OpenAI ki, angol tartalom tükrözése.',
                    'Design number a Model mezőbe; névből felesleges szóközök eltávolítása.',
                    'Leírás robusztusabb kinyerése (több selector + meta fallback).'
                ]
            ],
            [
                'version' => '1.2.0',
                'date'    => '2025-08-11',
                'items'   => [
                    'Riport: siker/hiba soronként, szerkesztési link az adminba.',
                    'Késleltetés beállítás (client-side wait) az URL-ek között.'
                ]
            ],
            [
                'version' => '1.1.0',
                'date'    => '2025-08-10',
                'items'   => [
                    'Alap import: név, leírás, képek letöltése; kategória hozzárendelés.',
                    'OpenAI integráció (gpt-4o) – opcionális fordítás/generálás.'
                ]
            ]
        ];
    }
}
