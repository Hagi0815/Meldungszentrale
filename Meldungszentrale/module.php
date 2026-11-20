<?php

declare(strict_types=1);

/**
 * Meldungszentrale
 *
 * Sammelt Meldungen aus Variablen (Quellen-Liste) und aus Skripten (MZ_Hinzufuegen / MZ_Entfernen)
 * und stellt sie als Laufschrift, höchste Stufe, Anzahl und optional als HTML-Liste bereit.
 *
 * Stufen: 0 = keine, 1 = Information (gelb), 2 = Hinweis (orange), 3 = Warnung (rot)
 */
class Meldungszentrale extends IPSModule
{
    private const STUFE_KEINE = 0;
    private const STUFE_INFO = 1;
    private const STUFE_HINWEIS = 2;
    private const STUFE_WARNUNG = 3;

    private const PROFIL_STUFE = 'MZ.Stufe';

    private const STUFEN = [
        self::STUFE_KEINE   => ['Keine Meldung', 0x8D9AAB],
        self::STUFE_INFO    => ['Information',   0xF2C14E],
        self::STUFE_HINWEIS => ['Hinweis',       0xF08A24],
        self::STUFE_WARNUNG => ['Warnung',       0xEF5A4C],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Quellen', '[]');
        $this->RegisterPropertyString('Trennzeichen', ' · ');
        $this->RegisterPropertyString('LeerText', '');
        $this->RegisterPropertyBoolean('HTMLListe', false);
        $this->RegisterPropertyBoolean('StummAufheben', false);
        // Klartexte für Codes, z. B. Buderus-Wartungscodes: [{"Code": "H20", "Text": "..."}]
        $this->RegisterPropertyString('Codes', '[]');

        // Meldungen aus Skripten: {"<id>": {"Text": "...", "Stufe": 1..3, "Zeit": 1700000000}}
        $this->RegisterAttributeString('Manuell', '{}');
        // Schlüssel der zuletzt aktiven Meldungen (zum Erkennen neuer Meldungen)
        $this->RegisterAttributeString('Aktiv', '[]');
    }

    public function Destroy()
    {
        // Das Profil bleibt bestehen, falls andere Instanzen es nutzen
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->ProfilAnlegen();

        $this->MaintainVariable('Laufschrift', $this->Translate('Laufschrift'), VARIABLETYPE_STRING, '', 10, true);
        $this->MaintainVariable('HoechsteStufe', $this->Translate('Höchste Stufe'), VARIABLETYPE_INTEGER, self::PROFIL_STUFE, 20, true);
        $this->MaintainVariable('Anzahl', $this->Translate('Anzahl'), VARIABLETYPE_INTEGER, '', 30, true);
        $this->MaintainVariable('Stumm', $this->Translate('Stumm'), VARIABLETYPE_BOOLEAN, '~Switch', 40, true);
        $this->EnableAction('Stumm');
        $this->MaintainVariable('Liste', $this->Translate('Liste'), VARIABLETYPE_STRING, '~HTMLBox', 50, $this->ReadPropertyBoolean('HTMLListe'));

        // alte Registrierungen lösen (außer Kernel-Start)
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }

        $fehler = false;
        foreach ($this->Quellen() as $q) {
            $vid = (int) $q['VariableID'];
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                $this->RegisterReference($vid);
            } else {
                $fehler = true;
            }
        }
        $this->SetStatus($fehler ? 201 : 102);

        $this->Aktualisieren();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
            case VM_UPDATE:
                // nur bei geänderten Werten neu berechnen ($Data[1] = Wert geändert)
                if (!isset($Data[1]) || $Data[1]) {
                    $this->Aktualisieren();
                }
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Stumm':
                $this->SetValue('Stumm', (bool) $Value);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // ---------------------------------------------------------------- öffentliche Funktionen (MZ_*)

    /** Meldung aus einem Skript setzen oder ändern. $Stufe: 1 Information, 2 Hinweis, 3 Warnung */
    public function Hinzufuegen(string $ID, string $Text, int $Stufe)
    {
        if ($ID === '' || $Text === '') {
            throw new Exception('ID und Text dürfen nicht leer sein');
        }
        if ($Stufe < self::STUFE_INFO || $Stufe > self::STUFE_WARNUNG) {
            throw new Exception('Stufe muss 1 (Information), 2 (Hinweis) oder 3 (Warnung) sein');
        }
        $m = json_decode($this->ReadAttributeString('Manuell'), true) ?: [];
        $m[$ID] = ['Text' => $Text, 'Stufe' => $Stufe, 'Zeit' => time()];
        $this->WriteAttributeString('Manuell', json_encode((object) $m));
        $this->Aktualisieren();
    }

    /** Meldung aus einem Skript entfernen. Gibt true zurück, wenn es sie gab. */
    public function Entfernen(string $ID)
    {
        $m = json_decode($this->ReadAttributeString('Manuell'), true) ?: [];
        $gab = array_key_exists($ID, $m);
        unset($m[$ID]);
        $this->WriteAttributeString('Manuell', json_encode((object) $m));
        $this->Aktualisieren();
        return $gab;
    }

    /** Alle aktiven Meldungen als JSON-Text (Stufe absteigend), im Skript: json_decode(MZ_HoleMeldungen($id), true) */
    public function HoleMeldungen()
    {
        return json_encode($this->Sammeln());
    }

    /** Neu berechnen und Ausgabe-Variablen schreiben. */
    public function Aktualisieren()
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $liste = $this->Sammeln();

        $texte = array_column($liste, 'Text');
        $laufschrift = count($texte) ? implode($this->ReadPropertyString('Trennzeichen'), $texte) : $this->ReadPropertyString('LeerText');
        $hoechste = count($liste) ? max(array_column($liste, 'Stufe')) : self::STUFE_KEINE;

        $this->Setzen('Laufschrift', $laufschrift);
        $this->Setzen('HoechsteStufe', $hoechste);
        $this->Setzen('Anzahl', count($liste));
        if ($this->ReadPropertyBoolean('HTMLListe')) {
            $this->Setzen('Liste', $this->Html($liste));
        }

        // neue Meldung? -> Stummschaltung optional aufheben
        $jetzt = array_column($liste, 'Schluessel');
        $vorher = json_decode($this->ReadAttributeString('Aktiv'), true) ?: [];
        if ($this->ReadPropertyBoolean('StummAufheben') && count(array_diff($jetzt, $vorher)) > 0) {
            $this->Setzen('Stumm', false);
        }
        $this->WriteAttributeString('Aktiv', json_encode(array_values($jetzt)));
    }

    // ---------------------------------------------------------------- intern

    private function Quellen(): array
    {
        $q = json_decode($this->ReadPropertyString('Quellen'), true);
        return is_array($q) ? $q : [];
    }

    private function Sammeln(): array
    {
        $liste = [];
        $n = 0;
        foreach ($this->Quellen() as $q) {
            $vid = (int) ($q['VariableID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            if (!$this->IstAktiv($vid, (string) ($q['Bedingung'] ?? 'ne'), (string) ($q['Wert'] ?? ''))) {
                continue;
            }
            $text = (string) ($q['Text'] ?? '');
            if ($text === '') {
                $text = IPS_GetName($vid) . ': {wert}';
            }
            $text = str_replace('{wert}', GetValueFormatted($vid), $text);
            if (strpos($text, '{code}') !== false) {
                [$c, $z] = $this->CodeLesen((string) GetValue($vid));
                $text = str_replace('{code}', $z !== '' ? $c . ' (' . $z . ')' : $c, $text);
            }
            if (strpos($text, '{klartext}') !== false) {
                $k = $this->Klartext((string) GetValue($vid));
                // kein Klartext bekannt: Platzhalter samt davorstehendem Strich weglassen
                $text = $k === '' ? trim(preg_replace('/\s*[-–:]?\s*\{klartext\}/u', '', $text)) : str_replace('{klartext}', $k, $text);
            }
            $liste[] = ['Schluessel' => 'v' . $vid, 'Text' => $text, 'Stufe' => (int) ($q['Stufe'] ?? self::STUFE_INFO),
                        'Zeit' => IPS_GetVariable($vid)['VariableChanged'], 'Nr' => $n++];
        }
        $m = json_decode($this->ReadAttributeString('Manuell'), true) ?: [];
        foreach ($m as $id => $e) {
            $liste[] = ['Schluessel' => 's' . $id, 'Text' => (string) $e['Text'], 'Stufe' => (int) $e['Stufe'],
                        'Zeit' => (int) $e['Zeit'], 'Nr' => $n++];
        }
        // Warnung zuerst, innerhalb einer Stufe Reihenfolge der Liste
        usort($liste, function ($a, $b) {
            return [$b['Stufe'], $a['Nr']] <=> [$a['Stufe'], $b['Nr']];
        });
        return $liste;
    }

    /**
     * Bedingung: 'eq' = gleich, 'ne' = ungleich, 'in' = Text enthält, 'notin' = Text enthält nicht.
     * Mehrere Werte mit | trennen (z. B. "|-" = leer oder -).
     */
    private function IstAktiv(int $vid, string $bedingung, string $wert): bool
    {
        $enthaelt = in_array($bedingung, ['in', 'notin'], true);
        $gleich = false;
        foreach (explode('|', $wert) as $w) {
            $treffer = $enthaelt
                ? ($w !== '' && mb_stripos(GetValueFormatted($vid), trim($w)) !== false)
                : $this->Gleich($vid, $w);
            if ($treffer) {
                $gleich = true;
                break;
            }
        }
        return in_array($bedingung, ['eq', 'in'], true) ? $gleich : !$gleich;
    }

    private function Gleich(int $vid, string $wert): bool
    {
        $ist = GetValue($vid);
        switch (IPS_GetVariable($vid)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $soll = in_array(strtolower(trim($wert)), ['true', '1', 'ja', 'an', 'wahr'], true);
                $gleich = ((bool) $ist === $soll);
                break;
            case VARIABLETYPE_INTEGER:
            case VARIABLETYPE_FLOAT:
                $gleich = abs((float) $ist - (float) str_replace(',', '.', trim($wert))) < 1e-9;
                break;
            default:
                $gleich = (trim((string) $ist) === trim($wert));
        }
        return $gleich;
    }

    /**
     * Klartext zum Code am Anfang des Werts, z. B. "H08(1018) 13.11. ..." oder " 6A(577) ...".
     * Gesucht wird zuerst "Code/Zusatzcode" (z. B. "6A/577"), dann nur "Code" (z. B. "6A"). Unbekannt -> leer.
     */
    public function Klartext(string $Wert)
    {
        [$code, $zusatz] = $this->CodeLesen($Wert);
        $codes = json_decode($this->ReadPropertyString('Codes'), true) ?: [];
        $tabelle = [];
        foreach ($codes as $c) {
            $tabelle[strtoupper(str_replace(' ', '', (string) $c['Code']))] = (string) $c['Text'];
        }
        if ($zusatz !== '' && isset($tabelle[$code . '/' . $zusatz])) {
            return $tabelle[$code . '/' . $zusatz];
        }
        return $tabelle[$code] ?? '';
    }

    /** Code und Zusatzcode vom Wertanfang lesen: "H08(1018) 13.11. - now" -> ["H08", "1018"], "H20" -> ["H20", ""] */
    private function CodeLesen(string $Wert): array
    {
        if (preg_match('/^\s*([0-9A-Za-z=\-]{1,4})\s*(?:\((\d+)\))?/', $Wert, $m)) {
            return [strtoupper($m[1]), $m[2] ?? ''];
        }
        return [strtoupper(trim($Wert)), ''];
    }

    private function Setzen(string $ident, $wert): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id && GetValue($id) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    private function Html(array $liste): string
    {
        if (!count($liste)) {
            return '<div style="color:#8d9aab;font-family:sans-serif">' . htmlspecialchars(self::STUFEN[0][0]) . '</div>';
        }
        $h = '<table style="width:100%;border-collapse:collapse;font-family:sans-serif">';
        foreach ($liste as $e) {
            $farbe = sprintf('#%06X', self::STUFEN[$e['Stufe']][1] ?? 0x8D9AAB);
            $h .= '<tr><td style="width:6px;background:' . $farbe . '"></td>'
                . '<td style="padding:4px 8px;color:' . $farbe . '">' . htmlspecialchars(self::STUFEN[$e['Stufe']][0] ?? '') . '</td>'
                . '<td style="padding:4px 8px">' . htmlspecialchars($e['Text']) . '</td>'
                . '<td style="padding:4px 8px;color:#8d9aab;white-space:nowrap">' . date('d.m. H:i', $e['Zeit']) . '</td></tr>';
        }
        return $h . '</table>';
    }

    private function ProfilAnlegen(): void
    {
        if (!IPS_VariableProfileExists(self::PROFIL_STUFE)) {
            IPS_CreateVariableProfile(self::PROFIL_STUFE, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues(self::PROFIL_STUFE, 0, 3, 1);
        IPS_SetVariableProfileIcon(self::PROFIL_STUFE, 'Warning');
        foreach (self::STUFEN as $wert => [$text, $farbe]) {
            IPS_SetVariableProfileAssociation(self::PROFIL_STUFE, $wert, $text, '', $farbe);
        }
    }
}
