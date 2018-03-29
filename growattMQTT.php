#!/usr/bin/php
<?php  
// This php program reads data from a growatt inverter
// 
// Thanks to Lennart Kuhlmeier for providing PVOUT_GROWATT.PY on http://www.sisand.dk/?page_id=139 
//

include(realpath(dirname(__FILE__))."/../PHP-Serial/src/PhpSerial.php");
require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");

$devicename = "growatt";
$serialdevice = "/dev/ttyUSB1";
$server = "192.168.2.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = uniqid($devicename."_");; // make sure this is unique for connecting to sever - you could use uniqid()
echo ("Growatt MQTT publisher started...\n");
$mqttTopicPrefix = "home/".$devicename."/";
$iniarray = parse_ini_file($devicename."MQTT.ini",true);
if (($tmp = $iniarray[$devicename]["serialdevice"]) != "") $serialdevice = $tmp;
if (($tmp = $iniarray[$devicename]["mqttserver"]) != "") $server = $tmp;
if (($tmp = $iniarray[$devicename]["mqttport"]) != "") $tcpport = $tmp;
if (($tmp = $iniarray[$devicename]["mqttusername"]) != "") $username = $tmp;
if (($tmp = $iniarray[$devicename]["mqttpassword"]) != "") $password = $tmp;


$mqtt = new phpMQTT($server, $port, $client_id);


$will = array (
	"topic" => $mqttTopicPrefix."status",
	"content" => "offline",
	"qos" => 0,
	"retain" => 1
);
$mqtt->connect(true, $will, $username, $password);
publishmqtt("status","online");


echo "Setting Serial Port Device ".$serialdevice."...\n";

$data = array();

exec ('stty -F '.$serialdevice.'  1:0:8bd:0:3:1c:7f:15:4:5:1:0:11:13:1a:0:12:f:17:16:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0');

$serial = new PhpSerial;

echo "Opening Serial Port '".$serialdevice."'...\n";

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
$serial->deviceSet($serialdevice);

// We can change the baud rate, parity, length, stop bits, flow control
$serial->confBaudRate(9600);
$serial->confParity("none");
$serial->confCharacterLength(8);
$serial->confStopBits(1);
$serial->confFlowControl("none");

if (!$serial->deviceOpen())
{
   echo ("Serial Port could not be opened...\n");
   exit (1);
}

echo "Opened Serial Port.\n";

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
$timeout = 1;
$sendtimer = 0;
$dataready = 0;
while(1)
{
        $readmask = array();
        array_push($readmask, $serial->_dHandle);
        $writemask = NULL;
        $errormask = NULL;
        $nroffd = stream_select($readmask, $writemask, $errormask, 1);
        $mqtt->proc();
        if ($nroffd == 0)
        {
              if ($sendtimer == 0)
              {
                $TxBuffer = sprintf ("%c%c%c%c%c%c", 0x3F, 0x23, 1, 0x32, 0x41, 0);
                $wStringSum = 0;
                for($i=0;$i<strlen($TxBuffer);$i++)
                {
                  $wStringSum += (ord($TxBuffer[$i]) ^ $i);
                  if($wStringSum==0||$wStringSum>0xFFFF)$wStringSum = 0xFFFF;
                }
                $TxBuffer .= sprintf ("%c%c", $wStringSum >> 8, $wStringSum & 0xFF);
                echo "Sending: '".bin2hex($TxBuffer)."'\n" ;
                
                publishmqtt("status","querying");

                $serial->sendMessage($TxBuffer, 2);
                $sendtimer = 10;
              }
              $sendtimer--;
        }        
        foreach ($readmask as $i) 
        {
            if ($i === $serial->_dHandle) 
            {
              // After midnight reset kwh_today counter
              if (date('H') < 1) 
              {
                $data["sunelectricity"]["today"]["kwh"]=0;
              }
              
              
              $message = $serial->readPort();
              
              if (strlen($message) > 0) echo ("Received: '".bin2hex($message)."'\n");

              if (strlen($message) > 20 && (ord($message[0]) == 0x23) && (ord($message[1]) == 0x3f) && (ord($message[2]) == 0x01) && (ord($message[3]) == 0x32) && (ord($message[4]) == 0x41))
              { 
                publishmqtt("pv/1/volt", number_format(((ord($message[7]) << 8) | ord($message[8]))/10,1,'.', ''));
                publishmqtt("pv/2/volt", number_format(((ord($message[9]) << 8) | ord($message[10]))/10,1,'.', ''));
                publishmqtt("pv/watt", number_format(((ord($message[11]) << 8) | ord($message[12]))/10,1,'.', ''));
                publishmqtt("grid/watt", number_format(((ord($message[19]) << 8) | ord($message[20]))/10,1,'.', ''));
                publishmqtt("grid/frequency", number_format(((ord($message[17]) << 8) | ord($message[18]))/100,2,'.', ''));
                publishmqtt("grid/volt", number_format(((ord($message[13]) << 8) | ord($message[14]))/10,1,'.', ''));
                publishmqtt("grid/amp", number_format(((ord($message[15]) << 8) | ord($message[16]))/10,1,'.', ''));

                // Request next data from growatt
                $TxBuffer = sprintf ("%c%c%c%c%c%c", 0x3F, 0x23, 1, 0x32, 0x42, 0);
                $wStringSum = 0;
                for($i=0;$i<strlen($TxBuffer);$i++)
                {
                  $wStringSum += (ord($TxBuffer[$i]) ^ $i);
                  if($wStringSum==0||$wStringSum>0xFFFF)$wStringSum = 0xFFFF;
                }
                $TxBuffer .= sprintf ("%c%c", $wStringSum >> 8, $wStringSum & 0xFF);
    
                echo "Sending: '".bin2hex($TxBuffer)."'\n" ;

                $serial->sendMessage($TxBuffer,1);
              }


              if (strlen($message) > 20 && (ord($message[0]) == 0x23) && (ord($message[1]) == 0x3f) && (ord($message[2]) == 0x01) && (ord($message[3]) == 0x32) && (ord($message[4]) == 0x42) )
              { 
                publishmqtt("grid/today/kwh", number_format((ord($message[13]) << 8 | ord($message[14]))/10,1,'.', ''));
                publishmqtt("grid/total/kwh", number_format((ord($message[15]) << 24 | ord($message[16]) << 16 | ord($message[17]) << 8 | ord($message[18])) / 10,1,'.', ''));
                publishmqtt("status", "ready");
              }
            }
          }
}

$serial->deviceClose();
exit(1);

function publishmqtt ($topic, $msg)
{
        global $mqtt;
        global $mqttTopicPrefix;
        echo ($topic.": ".$msg."\n");
        $mqtt->publishwhenchanged($mqttTopicPrefix.$topic,$msg,0,1);
}


?>  
