<?php
class Cults3DImportMeta {
    const VERSION = '1.6.4';

    public static function getChangelog() {
        return [
            [
                'version' => '1.6.4',
                'date'    => '2025-08-13',
                'items'   => [
                    'Duplikátum-kezelés: modell (Design number) alapján keres, felülírható vagy kihagyható.',
                    'Új beállítás: „Felülírhatja a meglévő terméket (azonos modell esetén)”.'
                ]
            ],
            [
                'version' => '1.6.3-hotfix',
                'date'    => '2025-08-13',
                'items'   => [
                    'Admin controller és TWIG visszaállítva teljes verzióra (Class not found hiba javítva).',
                    'Várólista: batch automatikusan a kategória neve.'
                ]
            ]
        ];
    }
}
