<?php

declare(strict_types=1);

/**
 * Minimaler IP-Symcon-Ersatz für die Prüfstände.
 *
 * Bildet so viel nach, dass eine Modulklasse wirklich ausgeführt werden kann: Properties,
 * Attribute, Buffer, Variablen, Timer, Status. Kein Objektbaum, keine Persistenz.
 *
 * Anlass für diesen Aufwand: `php -l` findet nur Syntaxfehler. Die Fehler, die in diesem
 * Modul wehtun, sind Laufzeitfehler im Empfangspfad — falsch dekodierte Werte, ein Filter
 * der nie trifft, ein SetValue auf eine Variable, die es noch nicht gibt.
 */

define('IS_ACTIVE', 102);
define('KL_ERROR', 3);
define('KL_WARNING', 2);
define('KL_NOTIFY', 1);
define('KL_MESSAGE', 0);
define('IS_INACTIVE', 104);
define('VM_UPDATE', 10603);

define('VARIABLETYPE_BOOLEAN', 0);
define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT', 2);
define('VARIABLETYPE_STRING', 3);

define('VARIABLE_PRESENTATION_VALUE_PRESENTATION', 'VALUE_PRESENTATION');
define('VARIABLE_PRESENTATION_SLIDER', 'SLIDER');
define('VARIABLE_PRESENTATION_SWITCH', 'SWITCH');
define('VARIABLE_PRESENTATION_ENUMERATION', 'ENUMERATION');
define('VARIABLE_PRESENTATION_VALUE_INPUT', 'VALUE_INPUT');

/** Sammelt alles, was das Modul nach außen tut, damit Prüfungen es nachsehen können. */
class IPSTestState
{
    public static array $instances    = [];
    public static array $sentPackets  = [];
    public static array $debug        = [];
    public static array $logMessages  = [];

    /** Fremde Variablen anderer Instanzen: objectId => ['instance'=>int,'ident'=>string,'value'=>mixed] */
    public static array $objects = [];
    /** Zuletzt an die Visualisierung geschickte Nutzlast. */
    public static ?string $visualization = null;
    public static array $registeredMessages = [];

    /** Übersetzungstabelle des geprüften Moduls, oder null zum Durchreichen. */
    public static ?array $locale = null;

    /** Lädt die locale.json eines Moduls, damit Translate() wirklich übersetzt. */
    public static function useLocale(string $modulVerzeichnis, string $sprache = 'de'): void
    {
        $datei = $modulVerzeichnis . '/locale.json';
        if (!is_file($datei)) {
            throw new RuntimeException('locale.json nicht gefunden: ' . $datei);
        }
        $json = json_decode((string) file_get_contents($datei), true);
        self::$locale = $json['translations'][$sprache] ?? [];
    }

    public static function reset(): void
    {
        self::$instances         = [];
        self::$sentPackets       = [];
        self::$debug             = [];
        self::$logMessages       = [];
        self::$objects           = [];
        self::$visualization     = null;
        self::$registeredMessages = [];
        // $locale bleibt bewusst stehen: Sie beschreibt das Modul, nicht den Prüffall.
    }

    /** Legt eine Variable an einer fremden Instanz an und gibt ihre Objekt-ID zurück. */
    public static function addObject(int $instanceId, string $ident, $value): int
    {
        $objectId = 10000 + count(self::$objects) + 1;
        self::$objects[$objectId] = ['instance' => $instanceId, 'ident' => $ident, 'value' => $value];
        return $objectId;
    }
}

function IPS_GetInstance(int $id): array
{
    return IPSTestState::$instances[$id] ?? [
        'InstanceID'     => $id,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_INACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{TEST}']
    ];
}

function IPS_GetModule(string $guid): array
{
    return ['ModuleID' => $guid, 'LibraryID' => '{TESTLIB}'];
}

function IPS_GetLibrary(string $guid): array
{
    return ['LibraryID' => $guid, 'Version' => '0.1.0', 'Build' => 1];
}

function IPS_LogMessage(string $sender, string $message): void
{
    IPSTestState::$logMessages[] = $sender . ': ' . $message;
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    $ids = [];
    foreach (IPSTestState::$instances as $id => $instance) {
        if (($instance['ModuleInfo']['ModuleID'] ?? '') === $guid) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Liefert false, wenn es den Ident nicht gibt — genau wie das Original. Die Module fragen
 * mit @ davor ab und prüfen auf false; ein Stub, der stattdessen 0 liefert, würde diesen
 * Pfad nie testen.
 */
function IPS_GetObjectIDByIdent(string $ident, int $parentId)
{
    foreach (IPSTestState::$objects as $objectId => $object) {
        if ($object['instance'] === $parentId && $object['ident'] === $ident) {
            return $objectId;
        }
    }
    return false;
}

function GetValue(int $objectId)
{
    if (!isset(IPSTestState::$objects[$objectId])) {
        trigger_error('Variable ' . $objectId . ' existiert nicht', E_USER_WARNING);
        return null;
    }
    return IPSTestState::$objects[$objectId]['value'];
}

function SetValue(int $objectId, $value): void
{
    IPSTestState::$objects[$objectId]['value'] = $value;
}

function IPS_GetProperty(int $id, string $name)
{
    return IPSTestState::$instances[$id]['Properties'][$name] ?? null;
}

function IPS_InstanceExists(int $id): bool
{
    return isset(IPSTestState::$instances[$id]);
}

function IPS_GetName(int $id): string
{
    return IPSTestState::$instances[$id]['Name'] ?? ('Instanz ' . $id);
}

/**
 * Ersatz für IPSModule. Bewusst schlank — nur was die Module wirklich benutzen.
 */
abstract class IPSModule
{
    public int $InstanceID;

    protected array $properties = [];
    protected array $attributes = [];
    protected array $buffers    = [];

    /** ident => ['type'=>int,'caption'=>string,'presentation'=>mixed,'position'=>int,'value'=>mixed] */
    public array $variables = [];
    public array $enabledActions = [];
    public array $timers = [];
    public string $receiveFilter = '';
    public int $status = IS_INACTIVE;
    public array $formFieldUpdates = [];

    public function __construct(int $InstanceID)
    {
        $this->InstanceID = $InstanceID;
    }

    /* ------------------------------------------------------------ Lebenszyklus */

    public function Create()
    {
    }

    public function ApplyChanges()
    {
    }

    /* -------------------------------------------------------------- Properties */

    protected function RegisterPropertyString(string $name, string $value): void
    {
        $this->properties[$name] = $value;
    }

    protected function RegisterPropertyInteger(string $name, int $value): void
    {
        $this->properties[$name] = $value;
    }

    protected function RegisterPropertyFloat(string $name, float $value): void
    {
        $this->properties[$name] = $value;
    }

    protected function RegisterPropertyBoolean(string $name, bool $value): void
    {
        $this->properties[$name] = $value;
    }

    public function ReadPropertyString(string $name): string
    {
        return (string) ($this->properties[$name] ?? '');
    }

    public function ReadPropertyInteger(string $name): int
    {
        return (int) ($this->properties[$name] ?? 0);
    }

    public function ReadPropertyFloat(string $name): float
    {
        return (float) ($this->properties[$name] ?? 0.0);
    }

    public function ReadPropertyBoolean(string $name): bool
    {
        return (bool) ($this->properties[$name] ?? false);
    }

    /** Nur für den Prüfstand: Property setzen wie im Formular. */
    /**
     * Legt eine Variable von außen an — für Prüfstände, die einen Altzustand herstellen
     * müssen (etwa eine verwaiste RAW_-Variable aus einer früheren Modulversion).
     */
    public function TEST_MaintainVariable(string $ident, string $caption, int $type, $presentation, int $position, bool $keep): void
    {
        $this->MaintainVariable($ident, $caption, $type, $presentation, $position, $keep);
    }

    public function TEST_SetProperty(string $name, $value): void
    {
        $this->properties[$name] = $value;
    }

    /* -------------------------------------------------------------- Attribute */

    protected function RegisterAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function RegisterAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function RegisterAttributeBoolean(string $name, bool $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function ReadAttributeString(string $name): string
    {
        return (string) ($this->attributes[$name] ?? '');
    }

    public function ReadAttributeInteger(string $name): int
    {
        return (int) ($this->attributes[$name] ?? 0);
    }

    public function ReadAttributeBoolean(string $name): bool
    {
        return (bool) ($this->attributes[$name] ?? false);
    }

    public function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function WriteAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function WriteAttributeBoolean(string $name, bool $value): void
    {
        $this->attributes[$name] = $value;
    }

    /* ----------------------------------------------------------------- Buffer */

    public function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }

    public function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    /* -------------------------------------------------------------- Variablen */

    protected function MaintainVariable(
        string $ident,
        string $caption,
        int $type,
        $presentation,
        int $position,
        bool $keep
    ): void {
        if (!$keep) {
            unset($this->variables[$ident]);
            return;
        }
        if (isset($this->variables[$ident])) {
            // Idempotenz: vorhandener Wert bleibt erhalten, nur die Beschreibung wird gepflegt.
            $this->variables[$ident]['caption']      = $caption;
            $this->variables[$ident]['type']         = $type;
            $this->variables[$ident]['presentation'] = $presentation;
            $this->variables[$ident]['position']     = $position;
            return;
        }
        $this->variables[$ident] = [
            'caption'      => $caption,
            'type'         => $type,
            'presentation' => $presentation,
            'position'     => $position,
            'value'        => $this->defaultForType($type)
        ];
    }

    private function defaultForType(int $type)
    {
        switch ($type) {
            case VARIABLETYPE_BOOLEAN: return false;
            case VARIABLETYPE_INTEGER: return 0;
            case VARIABLETYPE_FLOAT:   return 0.0;
            default:                   return '';
        }
    }

    protected function EnableAction(string $ident): void
    {
        if (!isset($this->variables[$ident])) {
            throw new Exception("EnableAction auf unbekannte Variable: {$ident}");
        }
        $this->enabledActions[$ident] = true;
    }

    public function SetValue(string $ident, $value): void
    {
        if (!isset($this->variables[$ident])) {
            throw new Exception("SetValue auf nicht angelegte Variable: {$ident}");
        }
        $this->variables[$ident]['value'] = $value;
    }

    public function GetValue(string $ident)
    {
        if (!isset($this->variables[$ident])) {
            throw new Exception("GetValue auf nicht angelegte Variable: {$ident}");
        }
        return $this->variables[$ident]['value'];
    }

    /* ------------------------------------------------------------------ Timer */

    protected function RegisterTimer(string $name, int $interval, string $script): void
    {
        $this->timers[$name] = ['interval' => $interval, 'script' => $script];
    }

    protected function SetTimerInterval(string $name, int $interval): void
    {
        if (!isset($this->timers[$name])) {
            throw new Exception("SetTimerInterval auf unbekannten Timer: {$name}");
        }
        $this->timers[$name]['interval'] = $interval;
    }

    /* ----------------------------------------------------------------- Status */

    protected function SetStatus(int $status): void
    {
        $this->status = $status;
    }

    public function GetStatus(): int
    {
        return $this->status;
    }

    /* -------------------------------------------------------- Kommunikation */

    protected function SetReceiveDataFilter(string $filter): void
    {
        $this->receiveFilter = $filter;
    }

    protected function SendDataToParent(string $data)
    {
        IPSTestState::$sentPackets[] = json_decode($data, true);
        return true;
    }

    protected function SendDebug(string $message, string $data, int $format): void
    {
        IPSTestState::$debug[] = $message . ': ' . $data;
    }

    /* --------------------------------------------------------------- Formular */

    protected function UpdateFormField(string $name, string $field, $value): void
    {
        $this->formFieldUpdates[] = [$name, $field, $value];
    }

    /**
     * Übersetzt über die echte locale.json des jeweiligen Moduls.
     *
     * Der Stub könnte den Quellstring einfach durchreichen — dann prüfte aber kein Test je,
     * ob es den Eintrag überhaupt gibt. Ein fehlender Eintrag fällt sonst erst beim Nutzer
     * auf, der plötzlich englische Variablennamen sieht. IPSTestState::$locale sagt, welche
     * Datei gilt; ohne Angabe wird durchgereicht wie zuvor.
     */
    protected function Translate(string $text): string
    {
        if (IPSTestState::$locale === null) {
            return $text;
        }
        return IPSTestState::$locale[$text] ?? $text;
    }

    /* ------------------------------------------------- Echte SDK-Methodennamen
     *
     * Diese Methoden benutzt unser Code teils gar nicht — sie stehen hier, damit eine
     * gleichnamige Eigenbau-Methode SOFORT auffällt statt erst auf dem Zielsystem.
     *
     * Anlass: Ein selbst geschriebenes `private function hasActiveParent()` kollidierte mit
     * dem geerbten `HasActiveParent()` (PHP vergleicht Methodennamen ohne Rücksicht auf
     * Groß-/Kleinschreibung) und verengte dessen Sichtbarkeit. Die Folge war ein Fatal Error
     * beim Laden, Symcon verwarf beide Module kommentarlos, und in der Konsole ließ sich
     * schlicht keine Instanz anlegen. Sichtbar war die Ursache nur in Symcons eigener
     * Logdatei. Die Sichtbarkeit (protected) ist hier entscheidend — sie ist es, die den
     * Fehler auslöst.
     */

    protected function HasActiveParent(): bool
    {
        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parentId === 0) {
            return false;
        }
        return (IPS_GetInstance($parentId)['InstanceStatus'] ?? 0) === IS_ACTIVE;
    }

    protected function ConnectParent(string $guid): void
    {
    }

    protected function ForceParent(string $guid): void
    {
    }

    protected function RequireParent(string $guid): void
    {
    }

    protected function GetIDForIdent(string $ident): int
    {
        return isset($this->variables[$ident]) ? crc32($ident) : 0;
    }

    protected function MaintainAction(string $ident, bool $enable): void
    {
        if ($enable) {
            $this->enabledActions[$ident] = true;
        } else {
            unset($this->enabledActions[$ident]);
        }
    }

    protected function DisableAction(string $ident): void
    {
        unset($this->enabledActions[$ident]);
    }

    protected function SendDataToChildren(string $data)
    {
        return true;
    }

    protected function SetForwardDataFilter(string $filter): void
    {
    }

    protected function RegisterOnceTimer(string $name, string $script): void
    {
    }

    protected function RegisterMessage(int $senderId, int $message): void
    {
        IPSTestState::$registeredMessages[$senderId][$message] = true;
    }

    protected function UnregisterMessage(int $senderId, int $message): void
    {
        unset(IPSTestState::$registeredMessages[$senderId][$message]);
        if (empty(IPSTestState::$registeredMessages[$senderId])) {
            unset(IPSTestState::$registeredMessages[$senderId]);
        }
    }

    protected function GetMessageList(): array
    {
        $list = [];
        foreach (IPSTestState::$registeredMessages as $senderId => $messages) {
            $list[$senderId] = array_keys($messages);
        }
        return $list;
    }

    protected function SetVisualizationType(int $type): void
    {
    }

    protected function UpdateVisualizationValue(string $value): void
    {
        IPSTestState::$visualization = $value;
    }

    protected function RegisterReference(int $id): void
    {
    }

    protected function UnregisterReference(int $id): void
    {
    }

    protected function ReloadForm(): void
    {
    }

    protected function LogMessage(string $message, int $type): void
    {
        IPSTestState::$logMessages[] = $message;
    }
}
