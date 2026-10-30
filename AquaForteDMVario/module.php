<?php

declare(strict_types=1);

/**
 * AquaForte DM Vario WiFi - lokale Anbindung über das Gizwits-LAN-Protokoll (TCP 12416).
 * Parent: Client Socket. Protokoll und Datenpunkt-Layout nach brix29/ha-aquaforte-dm-vario-local
 * bzw. geelenbert/aquaforte-mqtt.
 */
class AquaForteDMVario extends IPSModule
{
    private const CLIENT_SOCKET  = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DATA_TO_PARENT = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const TCP_PORT       = 12416;

    // Nachrichtentypen
    private const MSG_PASSCODE_REQ = 0x0006;
    private const MSG_PASSCODE_RSP = 0x0007;
    private const MSG_LOGIN_REQ    = 0x0008;
    private const MSG_LOGIN_RSP    = 0x0009;
    private const MSG_PING_REQ     = 0x0015;
    private const MSG_DATA_REQ     = 0x0090;
    private const MSG_DATA_RSP     = 0x0091;
    private const MSG_CTRL_REQ     = 0x0093;

    // p0-Aktionen
    private const P0_CONTROL = 0x01;
    private const P0_READ    = 0x02;
    private const P0_REPLY   = 0x03;
    private const P0_REPORT  = 0x04;

    // Größen laut HA-Integration
    private const CTRL_CACHE_SIZE = 5;
    private const VALUE_SIZE      = 301;
    private const FLAG_SIZE       = 8;
    private const FAULT_BYTE      = 301;

    // Schreibbare Datenpunkte: id = Endpoint-ID, byte/bit = Lage im Statuspuffer
    private const ENDPOINTS = [
        'SwitchON'   => ['id' => 0, 'byte' => 0, 'bit' => 0, 'len' => 1, 'bits' => true],
        'FeedSwitch' => ['id' => 2, 'byte' => 0, 'bit' => 2, 'len' => 1, 'bits' => true],
        'AutoMode'   => ['id' => 4, 'byte' => 0, 'bit' => 4, 'len' => 2, 'bits' => true],
        'MotorSpeed' => ['id' => 5, 'byte' => 1, 'bit' => 0, 'len' => 1, 'bits' => false],
        'FeedTime'   => ['id' => 6, 'byte' => 2, 'bit' => 0, 'len' => 1, 'bits' => false],
    ];

    // Störungen: Bit in Byte 301
    private const FAULTS = [
        'FaultOvercurrent'  => [0, 'Störung Überstrom'],
        'FaultOvervoltage'  => [1, 'Störung Überspannung'],
        'FaultOverTemp'     => [2, 'Störung Übertemperatur'],
        'FaultUndervoltage' => [3, 'Störung Unterspannung'],
        'FaultLockedRotor'  => [4, 'Störung Rotor blockiert'],
        'FaultNoLoad'       => [5, 'Störung Leerlauf'],
        'FaultUART'         => [6, 'Störung UART'],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('PollInterval', 60);

        $this->RequireParent(self::CLIENT_SOCKET);

        $this->RegisterTimer('Ping', 0, 'AQFDM_Ping($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Poll', 0, 'AQFDM_ReadStatus($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Refresh', 0, 'AQFDM_DeferredRefresh($_IPS[\'TARGET\']);');
    }

    public function GetConfigurationForParent()
    {
        $host = $this->ReadPropertyString('Host');
        return json_encode([
            'Host' => $host,
            'Port' => self::TCP_PORT,
            'Open' => $host !== ''
        ]);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('SwitchON', 'Pumpe', '~Switch', 10);
        $this->RegisterVariableBoolean('FeedSwitch', 'Fütterungsmodus', '~Switch', 20);
        $this->RegisterVariableInteger('AutoMode', 'Betriebsmodus', 'AQFDM.Mode', 30);
        $this->RegisterVariableInteger('MotorSpeed', 'Drehzahl', 'AQFDM.Speed', 40);
        $this->RegisterVariableInteger('FeedTime', 'Fütterungsdauer', 'AQFDM.FeedTime', 50);
        foreach (['SwitchON', 'FeedSwitch', 'AutoMode', 'MotorSpeed', 'FeedTime'] as $ident) {
            $this->EnableAction($ident);
        }
        $pos = 60;
        foreach (self::FAULTS as $ident => $def) {
            $this->RegisterVariableBoolean($ident, $def[1], '~Alert', $pos);
            $pos += 10;
        }

        $host = $this->ReadPropertyString('Host');
        $parentID = $this->GetParentID();

        // Parent-Überwachung
        $old = (int) $this->GetBuffer('ParentID');
        if ($old > 0 && $old !== $parentID) {
            $this->UnregisterMessage($old, IM_CHANGESTATUS);
        }
        if ($parentID > 0) {
            $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        }
        $this->SetBuffer('ParentID', (string) $parentID);

        // Client Socket passend einstellen
        if ($parentID > 0) {
            $changed = false;
            if (IPS_GetProperty($parentID, 'Host') !== $host) {
                IPS_SetProperty($parentID, 'Host', $host);
                $changed = true;
            }
            if (IPS_GetProperty($parentID, 'Port') !== self::TCP_PORT) {
                IPS_SetProperty($parentID, 'Port', self::TCP_PORT);
                $changed = true;
            }
            $open = ($host !== '');
            if (IPS_GetProperty($parentID, 'Open') !== $open) {
                IPS_SetProperty($parentID, 'Open', $open);
                $changed = true;
            }
            if ($changed) {
                IPS_ApplyChanges($parentID);
            }
        }

        $this->SetBuffer('LoggedIn', '0');

        if ($host === '') {
            $this->SetTimerInterval('Ping', 0);
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('Ping', 5000);
        $poll = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('Poll', $poll > 0 ? $poll * 1000 : 0);

        if ($this->HasActiveParent()) {
            $this->StartLogin();
        } else {
            $this->SetStatus(201);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === (int) $this->GetBuffer('ParentID')) {
            if ($Data[0] === 102) {
                $this->StartLogin();
            } else {
                $this->SetBuffer('LoggedIn', '0');
                $this->SetStatus($Data[0] === 104 ? 104 : 201);
            }
        }
    }

    // ---------------------------------------------------------------- Aktionen

    public function RequestAction($Ident, $Value)
    {
        if (!isset(self::ENDPOINTS[$Ident])) {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        switch ($Ident) {
            case 'SwitchON':
            case 'FeedSwitch':
                $value = $Value ? 1 : 0;
                break;
            case 'AutoMode':
                $value = max(0, min(2, (int) $Value));
                break;
            case 'MotorSpeed':
                $value = max(30, min(100, (int) $Value));
                break;
            case 'FeedTime':
                $value = max(1, min(60, (int) $Value));
                break;
            default:
                return;
        }
        $this->SendControl(self::ENDPOINTS[$Ident], $value);
    }

    public function ReadStatus()
    {
        if ($this->GetBuffer('LoggedIn') === '1') {
            $this->SendFrame(self::MSG_DATA_REQ, chr(self::P0_READ));
        }
    }

    public function Reconnect()
    {
        $this->SetBuffer('LoggedIn', '0');
        $parentID = $this->GetParentID();
        if ($parentID > 0) {
            IPS_SetProperty($parentID, 'Open', false);
            IPS_ApplyChanges($parentID);
            IPS_SetProperty($parentID, 'Open', true);
            IPS_ApplyChanges($parentID);
        }
    }

    public function Ping()
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $now = time();
        if ($this->GetBuffer('LoggedIn') === '1') {
            if ($now - (int) $this->GetBuffer('LastRx') > 20) {
                $this->LogMessage('Pumpe antwortet nicht mehr, verbinde neu.', KL_WARNING);
                $this->SetStatus(201);
                $this->Reconnect();
                return;
            }
            $this->SendFrame(self::MSG_PING_REQ);
        } elseif ($now - (int) $this->GetBuffer('LoginStart') > 15) {
            $this->StartLogin();
        }
    }

    public function DeferredRefresh()
    {
        $this->SetTimerInterval('Refresh', 0);
        $this->ReadStatus();
    }

    // ------------------------------------------------------------- Empfang

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if (!isset($data->Buffer)) {
            return '';
        }
        $chunk = $this->FromUtf8($data->Buffer);

        $semaphore = 'AQFDM_RX_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 2000)) {
            return '';
        }
        $buf = hex2bin($this->GetBuffer('Rx')) . $chunk;
        $frames = [];
        while (($frame = $this->ExtractFrame($buf)) !== null) {
            $frames[] = $frame;
        }
        if (strlen($buf) > 4096) {
            $buf = '';
        }
        $this->SetBuffer('Rx', bin2hex($buf));
        IPS_SemaphoreLeave($semaphore);

        $this->SetBuffer('LastRx', (string) time());
        foreach ($frames as $frame) {
            $this->HandleFrame($frame['cmd'], $frame['payload']);
        }
        return '';
    }

    /**
     * Zerlegt den Puffer in Gizwits-Pakete:
     * [4 Byte Präfix][Varint Länge][Flag][2 Byte Kommando BE][Nutzdaten]
     * Das Präfix ist 00 00 00 03 (Anfrage/Antwort) oder EE EE EE EE (Push vom Gerät).
     */
    private function ExtractFrame(string &$buf)
    {
        // Auf gültiges Präfix synchronisieren
        while (strlen($buf) >= 4) {
            $prefix = substr($buf, 0, 4);
            if ($prefix === "\x00\x00\x00\x03" || $prefix === "\xEE\xEE\xEE\xEE") {
                break;
            }
            $buf = substr($buf, 1);
        }
        if (strlen($buf) < 5) {
            return null;
        }

        $length = 0;
        $shift = 0;
        $pos = 4;
        do {
            if ($pos >= strlen($buf)) {
                return null; // Länge noch unvollständig
            }
            $b = ord($buf[$pos]);
            $pos++;
            $length |= ($b & 0x7F) << $shift;
            $shift += 7;
        } while ($b & 0x80);

        if (strlen($buf) < $pos + $length) {
            return null; // Paket noch unvollständig
        }
        $body = substr($buf, $pos, $length);
        $buf = substr($buf, $pos + $length);

        if (strlen($body) < 3) {
            return ['cmd' => -1, 'payload' => ''];
        }
        $cmd = (ord($body[1]) << 8) | ord($body[2]);
        return ['cmd' => $cmd, 'payload' => substr($body, 3)];
    }

    private function HandleFrame(int $cmd, string $payload): void
    {
        $this->SendDebug('RX', sprintf('0x%04X %s', $cmd, bin2hex(substr($payload, 0, 40))), 0);

        switch ($cmd) {
            case self::MSG_PASSCODE_RSP:
                $len = strlen($payload) >= 2 ? ((ord($payload[0]) << 8) | ord($payload[1])) : 0;
                $passcode = substr($payload, 2, $len);
                if ($passcode === '') {
                    $this->SetStatus(201);
                    $this->SendDebug('Login', 'Pumpe lieferte keinen Passcode', 0);
                    return;
                }
                $this->SendFrame(self::MSG_LOGIN_REQ, pack('n', strlen($passcode)) . $passcode);
                break;

            case self::MSG_LOGIN_RSP:
                if ($payload !== '' && ord($payload[0]) === 0) {
                    $this->OnLoggedIn();
                } else {
                    $this->SetStatus(201);
                    $this->SendDebug('Login', 'Login von der Pumpe abgelehnt', 0);
                }
                break;

            case self::MSG_DATA_RSP:
                if ($payload !== '') {
                    $p0 = ord($payload[0]);
                    if ($p0 === self::P0_REPLY || $p0 === self::P0_REPORT) {
                        $this->ApplyStatus(substr($payload, 1));
                    }
                }
                break;

            case self::MSG_CTRL_REQ:
                // Ein anderer Client (z. B. die App) hat etwas gesteuert: Status kurz danach neu lesen.
                $this->SetTimerInterval('Refresh', 1500);
                break;
        }
    }

    private function StartLogin(): void
    {
        $this->SetBuffer('Rx', '');
        $this->SetBuffer('LoggedIn', '0');
        $this->SetBuffer('LoginStart', (string) time());
        $this->SetBuffer('LastRx', (string) time());
        $this->SendFrame(self::MSG_PASSCODE_REQ);
    }

    private function OnLoggedIn(): void
    {
        $this->SetBuffer('LoggedIn', '1');
        $this->SetBuffer('LastRx', (string) time());
        $this->SetStatus(102);
        $this->SendFrame(self::MSG_DATA_REQ, chr(self::P0_READ));
    }

    private function ApplyStatus(string $raw): void
    {
        if (strlen($raw) < self::CTRL_CACHE_SIZE) {
            return;
        }
        $b0 = ord($raw[0]);
        $this->SetValue('SwitchON', (bool) ($b0 & 0x01));
        $this->SetValue('FeedSwitch', (bool) (($b0 >> 2) & 0x01));
        $this->SetValue('AutoMode', min(2, ($b0 >> 4) & 0x03));
        $this->SetValue('MotorSpeed', ord($raw[1]));
        $this->SetValue('FeedTime', ord($raw[2]));

        if (strlen($raw) > self::FAULT_BYTE) {
            $faults = ord($raw[self::FAULT_BYTE]);
            foreach (self::FAULTS as $ident => $def) {
                $this->SetValue($ident, (bool) (($faults >> $def[0]) & 0x01));
            }
        }

        // Die ersten Bytes werden beim Schreiben als Basis benötigt
        $this->SetBuffer('Ctrl', bin2hex(substr($raw, 0, self::CTRL_CACHE_SIZE)));
    }

    // -------------------------------------------------------------- Senden

    private function SendControl(array $ep, int $value): void
    {
        if ($this->GetBuffer('LoggedIn') !== '1') {
            throw new Exception('Pumpe ist nicht verbunden.');
        }

        $values = str_repeat("\x00", self::VALUE_SIZE);
        $cache = hex2bin($this->GetBuffer('Ctrl'));
        if (strlen($cache) >= self::CTRL_CACHE_SIZE) {
            $values = substr($cache, 0, self::CTRL_CACHE_SIZE) . substr($values, self::CTRL_CACHE_SIZE);
        }

        if ($ep['bits']) {
            $mask = ((1 << $ep['len']) - 1) << $ep['bit'];
            $byte = ord($values[$ep['byte']]);
            $byte = ($byte & (~$mask & 0xFF)) | (($value << $ep['bit']) & $mask);
            $values[$ep['byte']] = chr($byte);
        } else {
            $values[$ep['byte']] = chr($value & 0xFF);
        }

        // Flags: Bitmaske, Bit-Position = Endpoint-ID (8 Byte, Big Endian)
        $flags = str_repeat("\x00", self::FLAG_SIZE - 1) . chr(1 << $ep['id']);

        $counter = (int) $this->GetBuffer('Counter') + 1;
        $this->SetBuffer('Counter', (string) $counter);

        $payload = pack('N', $counter) . chr(self::P0_CONTROL) . $flags . $values;
        $this->SendFrame(self::MSG_CTRL_REQ, $payload);

        $this->SetBuffer('Ctrl', bin2hex(substr($values, 0, self::CTRL_CACHE_SIZE)));
        // Pumpe übernimmt den Befehl asynchron: Status nach kurzer Pause prüfen
        $this->SetTimerInterval('Refresh', 1500);
    }

    private function SendFrame(int $cmd, string $payload = ''): bool
    {
        if (!$this->HasActiveParent()) {
            return false;
        }
        $body = "\x00" . pack('n', $cmd) . $payload;
        $frame = "\x00\x00\x00\x03" . $this->EncodeVarint(strlen($body)) . $body;

        $this->SendDebug('TX', sprintf('0x%04X %s', $cmd, bin2hex(substr($payload, 0, 40))), 0);
        $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_TO_PARENT,
            'Buffer' => $this->ToUtf8($frame)
        ]));
        return true;
    }

    private function EncodeVarint(int $n): string
    {
        $out = '';
        do {
            $b = $n & 0x7F;
            $n >>= 7;
            if ($n > 0) {
                $b |= 0x80;
            }
            $out .= chr($b);
        } while ($n > 0);
        return $out;
    }

    // ------------------------------------------------------------- Hilfen

    private function GetParentID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    /** Binärdaten -> UTF-8-String (wie utf8_encode, ohne die in PHP 8.2 veraltete Funktion) */
    private function ToUtf8(string $bin): string
    {
        $out = '';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($bin[$i]);
            $out .= $c < 0x80 ? $bin[$i] : chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
        }
        return $out;
    }

    /** UTF-8-String -> Binärdaten (wie utf8_decode) */
    private function FromUtf8(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            if ($c < 0x80) {
                $out .= $s[$i];
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $i++;
                $out .= chr((($c & 0x03) << 6) | (ord($s[$i]) & 0x3F));
            }
        }
        return $out;
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('AQFDM.Mode')) {
            IPS_CreateVariableProfile('AQFDM.Mode', 1);
            IPS_SetVariableProfileValues('AQFDM.Mode', 0, 2, 0);
            IPS_SetVariableProfileIcon('AQFDM.Mode', 'Gear');
            IPS_SetVariableProfileAssociation('AQFDM.Mode', 0, 'Abschalten', '', -1);
            IPS_SetVariableProfileAssociation('AQFDM.Mode', 1, 'Automatik', '', -1);
            IPS_SetVariableProfileAssociation('AQFDM.Mode', 2, 'Fütterung', '', -1);
        }
        if (!IPS_VariableProfileExists('AQFDM.Speed')) {
            IPS_CreateVariableProfile('AQFDM.Speed', 1);
            IPS_SetVariableProfileValues('AQFDM.Speed', 30, 100, 1);
            IPS_SetVariableProfileText('AQFDM.Speed', '', ' %');
            IPS_SetVariableProfileIcon('AQFDM.Speed', 'Speedo');
        }
        if (!IPS_VariableProfileExists('AQFDM.FeedTime')) {
            IPS_CreateVariableProfile('AQFDM.FeedTime', 1);
            IPS_SetVariableProfileValues('AQFDM.FeedTime', 1, 60, 1);
            IPS_SetVariableProfileText('AQFDM.FeedTime', '', ' s');
            IPS_SetVariableProfileIcon('AQFDM.FeedTime', 'Clock');
        }
    }
}
