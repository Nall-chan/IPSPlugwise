<?

require_once(__DIR__ . "/../libs/Plugwise.php");  // diverse Klassen

/*
 * @addtogroup plugwise
 * @{
 *
 * @package       Plugwise
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2016 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.1
 * @example <b>Ohne</b>
 */

/**
 * PlugwiseDevice Klasse für die Nodes des Plugwise-Netzwerk.
 * Erweitert IPSModule.
 *
 * @package       Plugwise
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2016 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.1
 * @example <b>Ohne</b>
 * @property Plugwise_Typ $Type Typ des Gerätes
 * @property float $gainA 
 * @property float $gainB 
 * @property float $offTot
 * @property float $offRuis
 * @property int $CurrentLogAddress
 */
class PlugwiseDevice extends IPSModule
{

    use BufferHelper,
        DebugHelper,
        VariableHelper,
        VariableProfile,
        InstanceStatus
    {
        InstanceStatus::MessageSink as IOMessageSink;
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();
        $this->ConnectParent("{7C20491F-F145-4F1C-A69C-AAE1F60F5BD5}");
        $this->RegisterPropertyString("NodeMAC", "");
        $this->RegisterPropertyInteger("Interval", 5);
        $this->RegisterPropertyBoolean("showState", true);
        $this->RegisterPropertyBoolean("showCurrent", true);
        $this->RegisterPropertyBoolean("showAverage", true);
        $this->RegisterPropertyBoolean("showOverall", true);
        $this->RegisterPropertyBoolean("showFirmware", true);
        $this->RegisterPropertyBoolean("showHardware", true);
        $this->RegisterPropertyBoolean("showFrequenz", true);
        $this->RegisterPropertyBoolean("showTime", true);
        $this->RegisterTimer('RequestState', 0, 'PLUGWISE_TimerEvent($_IPS["TARGET"]);');
        $this->Type = 0;
        $this->gainA = 0;
        $this->gainB = 0;
        $this->offTot = 0;
        $this->offRuis = 0;
    }

    /**
     * Interne Funktion des SDK.
     * 
     * @access public
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();
//        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->RegisterMessage($this->InstanceID, DM_CONNECT);
        $this->RegisterMessage($this->InstanceID, DM_DISCONNECT);
        $NodeMAC = $this->ReadPropertyString('NodeMAC');
        if ($NodeMAC == "")
            $Filter = '.*"NodeMAC":"999999999".*';
        else
            $Filter = '.*"NodeMAC":"' . $NodeMAC . '".*';
        $this->SetReceiveDataFilter($Filter);
        $this->SendDebug("SetFilter", $Filter, 0);
        // Wenn Kernel nicht bereit, dann warten... KR_READY über Splitter kommt ja gleich
        $this->RegisterParent();
        if (IPS_GetKernelRunlevel() <> KR_READY)
            return;

        $this->RegisterProfileFloat('Plugwise.kwh', 'Electricity', '', ' kWh', 0, 0, 0, 3);
        $this->SetStatus(IS_ACTIVE);
        // Wenn Parent aktiv, dann Anmeldung an der Hardware bzw. Datenabgleich starten

        if ($this->HasActiveParent())
            $this->IOChangeState(IS_ACTIVE);
        else
            $this->SetTimerInterval('RequestState', 0);
    }

    /**
     * Interne Funktion des SDK.
     * 
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {

        $this->IOMessageSink($TimeStamp, $SenderID, $Message, $Data);
//
//        switch ($Message)
//        {
//            case IPS_KERNELSTARTED:
//                $this->KernelReady();
//                break;
//        }
    }

    /**
     * Wird ausgeführt wenn der Kernel hochgefahren wurde.
     */
    protected function KernelReady()
    {
        $this->RegisterParent();
        if ($this->HasActiveParent())
            $this->IOChangeState(IS_ACTIVE);
    }

    /**
     * Wird ausgeführt wenn sich der Status vom Parent ändert.
     * @access protected
     */
    protected function IOChangeState($State)
    {
        $this->SetTimerInterval('RequestState', 0);
        if ($State == IS_ACTIVE)
        {
            $this->RequestState();
            $this->RequestCalibration();
            $this->RequestEnergy();
            if ($this->ReadPropertyInteger('Interval') > 0)
                $this->SetTimerInterval('RequestState', $this->ReadPropertyInteger('Interval') * 1000);
        }
    }

################## PRIVATE     

    /**
     * Dekodiert die empfangenen Events und Anworten.
     *
     * @param Plugwise_Frame $PlugwiseData Der zu dekodierende Datensatz als Objekt.
     */
    protected function Decode(Plugwise_Frame $PlugwiseData)
    {
        $this->SendDebug('EVENT', $PlugwiseData, 0);

        switch ($PlugwiseData->Command)
        {
            default:
                break;
        }
    }

################## ActionHandler

    /**
     * Actionhandler der Statusvariablen. Interne SDK-Funktion.
     * 
     * @access public
     * @param string $Ident Der Ident der Statusvariable.
     * @param bool|float|int|string $Value Der angeforderte neue Wert.
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident)
        {
            case "State":
                return $this->SwitchMode($Value);
            default:
                trigger_error('Invalid Ident.', E_USER_NOTICE);
        }
    }

################## PUBLIC

    public function TimerEvent()
    {
        $this->RequestState();
        $this->RequestEnergy();
    }

    /**
     * IPS-Instanz-Funktion 'PLUGWISE_SwitchMode'. Schaltet den Circle ein oder aus.
     *
     * @access public
     * @param bool $Value True für Ein,  False für aus.
     * @return bool true bei erfolgreicher Ausführung, sonst false.
     */
    public function SwitchMode(bool $Value)
    {
        if (!is_bool($Value))
        {
            trigger_error('Value must be boolean', E_USER_NOTICE);
            return false;
        }
        if (!$this->ReadPropertyBoolean("showState"))
        {
            trigger_error('State not active in instance', E_USER_NOTICE);
            return false;
        }
        $PlugwiseData = new Plugwise_Frame(Plugwise_Command::SwitchRequest, null, ($Value ? Plugwise_Switch::ON : Plugwise_Switch::OFF));
        $ret = $this->Send($PlugwiseData);
        if ($ret === false)
            return false;
        $Ok = (substr($ret->Data, 0, 4) == ($Value ? Plugwise_AckMsg::SWITCHON : Plugwise_AckMsg::SWITCHOFF));
        if ($Ok)
        {
            $this->SetValueBoolean("State", $Value);
            return true;
        }
        trigger_error('Error on SwitchMode.', E_USER_NOTICE);
        return false;
    }

    /**
     * IPS-Instanz-Funktion 'PLUGWISE_RequestState'.
     *
     * @access public
     * @return bool true bei erfolgreicher Ausführung, sonst false.
     */
    public function RequestState()
    {
        $PlugwiseData = new Plugwise_Frame(Plugwise_Command::InfoRequest);
//        $PlugwiseData->GetState();
        $Result = $this->Send($PlugwiseData);
        if ($Result === false)
            return false;

        if ($this->ReadPropertyBoolean("showHardware"))
        {
            $Hardware = sprintf("%s-%s-%s", substr($Result->Data, 20, 4), substr($Result->Data, 24, 4), substr($Result->Data, 28, 4));
            $this->SetValueString('Hardware', $Hardware);
        }

        if ($this->ReadPropertyBoolean("showFirmware"))
        {
            $Firmware = intval(hexdec(substr($Result->Data, 32, 8)));
            $this->SetValueInteger('Firmware', $Firmware, '~UnixTimestampDate');
        }

        $this->Type = Plugwise_Typ::$Type[substr($Result->Data, 40, 2)];
        $this->SetSummary(Plugwise_Typ::ToString($this->Type));
        $this->SendDebug('Type', Plugwise_Typ::ToString($this->Type), 0);

        if ($this->Type == Plugwise_Typ::Cricle)
        {
            if ($this->ReadPropertyBoolean("showState"))
            {

                $Value = (substr($Result->Data, 16, 2) == Plugwise_Switch::ON);
                $this->SetValueBoolean("State", $Value, "~Switch");
                $this->EnableAction("State");
            }

            if ($this->ReadPropertyBoolean("showFrequenz"))
                $this->SetValueFloat("Frequenz", Plugwise_Switch::$Hertz[hexdec(intval(substr($Result->Data, 18, 2)))], "~Hertz");

            if ($this->ReadPropertyBoolean("showTime"))
            {
                $Timestamp = Plugwise_Frame::Hex2Timestamp(substr($Result->Data, 0, 8));
                $this->SetValueInteger('Timestamp', $Timestamp, '~UnixTimestamp');
                $this->SendDebug('Timestamp', $Timestamp, 0);
                $this->SendDebug('Timestamp', gmdate('H:i:s d.m.Y', $Timestamp), 0);
            }
            $CurrentLogAddress = hexdec(substr($Result->Data, 8, 8));
            $this->CurrentLogAddress = $CurrentLogAddress;
            $this->SendDebug('CurrentLogAddress', dechex($CurrentLogAddress), 0);
            $this->SendDebug('CurrentLogAddress', $CurrentLogAddress, 0);
        }

        return true;
    }

    /**
     * IPS-Instanz-Funktion 'PLUGWISE_RequestCalibration'.
     *
     * @access public
     * @return bool true bei erfolgreicher Ausführung, sonst false.
     */
    public function RequestCalibration()
    {
        $PlugwiseData = new Plugwise_Frame(Plugwise_Command::CalibrationRequest);
        $Result = $this->Send($PlugwiseData);
        if ($Result === false)
            return false;

        $this->gainA = Plugwise_Frame::Hex2Float(substr($Result->Data, 0, 8));
        $this->gainB = Plugwise_Frame::Hex2Float(substr($Result->Data, 8, 8));
        $this->offTot = Plugwise_Frame::Hex2Float(substr($Result->Data, 16, 8));
        $this->offRuis = Plugwise_Frame::Hex2Float(substr($Result->Data, 24, 8));
        $this->SendDebug('gainA', $this->gainA, 0);
        $this->SendDebug('gainB', $this->gainB, 0);
        $this->SendDebug('offTot', $this->offTot, 0);
        $this->SendDebug('offRuis', $this->offRuis, 0);
        return true;
    }

    /**
     * IPS-Instanz-Funktion 'PLUGWISE_RequestEnergy'.
     *
     * @access public
     * @return bool true bei erfolgreicher Ausführung, sonst false.
     */
    public function RequestEnergy()
    {
        $PlugwiseData = new Plugwise_Frame(Plugwise_Command::PowerUsageRequest);
        $Result = $this->Send($PlugwiseData);
        if ($Result === false)
            return false;

        if ($this->ReadPropertyBoolean("showCurrent"))
        {
            $pulses_1 = intval(hexdec(substr($Result->Data, 0, 4)));
            $pulses1 = Plugwise_Frame::pulsesCorrection(
                            $pulses_1, 1, $this->offRuis, $this->offTot, $this->gainA, $this->gainB
            );
            $watt1 = Plugwise_Frame::pulsesToWatt($pulses1);
            $this->SendDebug('watt1', $watt1, 0);
            if ($watt1 >= 0)
                $this->SetValueFloat('PowerCurrent', $watt1, '~Watt.3680');
        }
        if ($this->ReadPropertyBoolean("showAverage"))
        {
            $pulses_8 = intval(hexdec(substr($Result->Data, 4, 4)));
            $pulses8 = Plugwise_Frame::pulsesCorrection(
                            $pulses_8, 8, $this->offRuis, $this->offTot, $this->gainA, $this->gainB
            );
            $watt8 = Plugwise_Frame::pulsesToWatt($pulses8);
            $this->SendDebug('watt8', $watt8, 0);
            if ($watt8 >= 0)
                $this->SetValueFloat('PowerAverage', $watt8, '~Watt.3680');
        }
        if ($this->ReadPropertyBoolean("showOverall"))
        {
            $pulses_total = intval(hexdec(substr($Result->Data, 8, 8)));
            $pulsestotal = Plugwise_Frame::pulsesCorrection(
                            $pulses_total, 3600, $this->offRuis, $this->offTot, $this->gainA, $this->gainB
            );
            $watttotal = Plugwise_Frame::pulsesToKwh($pulsestotal);
            $this->SendDebug('watttotal', $watttotal, 0);
            if ($watttotal >= 0)
                $this->SetValueFloat('PowerOverall', $watttotal, 'Plugwise.kwh');
        }
        return true;
    }

################## Datapoints

    /**
     * Konvertiert $Data zu einem JSONString und versendet diese an den Splitter.
     *
     * @access protected
     * @param Plugwise_Frame $PlugwiseData Zu versendende Daten.
     * @return Plugwise_Frame Objekt mit der Antwort. NULL im Fehlerfall.
     */
    protected function Send(Plugwise_Frame $PlugwiseData)
    {
        $NodeMAC = trim($this->ReadPropertyString('NodeMAC'));
        if ($NodeMAC == '')
            return false;
        $this->SendDebug('Send', $PlugwiseData, 0);
        $PlugwiseData->NodeMAC = $NodeMAC;
        $JSONData = $PlugwiseData->ToJSONStringForSplitter();
        $ResultString = @$this->SendDataToParent($JSONData);
        $this->SendDebug('Send', $JSONData, 0);
        if ($ResultString === false)
        {
            $this->SendDebug('Receive', 'Error receive data', 0);
            echo 'Error receive data';
            return false;
        }
        $Result = @unserialize($ResultString);
        $this->SendDebug('Response', $Result, 0);
        if (($Result === NULL) or ( $Result === false))
        {
            $this->SendDebug('Receive', 'Error receive data', 0);
            echo 'Error on send data';
            return false;
        }
        if ($Result->Data == '00E1')
        {
            trigger_error('Node not reachable', E_USER_NOTICE);
            return false;
        }

        return $Result;
    }

    /**
     * Interne SDK-Funktion. Empfängt Datenpakete vom KodiSplitter.
     *
     * @access public
     * @param string $JSONString Das Datenpaket als JSON formatierter String.
     * @return bool true bei erfolgreicher Datenannahme, sonst false.
     */
    public function ReceiveData($JSONString)
    {

        $Data = json_decode($JSONString);
        $PlugwiseData = new Plugwise_Frame($Data);
        $this->Decode($PlugwiseData);
        return false;
    }

}

/** @} */
?>