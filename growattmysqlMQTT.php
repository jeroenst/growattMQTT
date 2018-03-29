#!/usr/bin/php
<?php

require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");


$server = "127.0.0.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = uniqid("growattmysql_"); // make sure this is unique for connecting to sever - you could use uniqid()
$topicprefix = 'home/growatt/';

$settings = array(
"mysqlserver" => "localhost",
"mysqlusername" => "casaan",
"mysqlpassword" => "gWdtGxQDnq6NhSeG",
"mysqldatabase" => "casaan");


$mqttdata = array();

$mqtt = new phpMQTT($server, $port, $client_id);

$lastgasdatetime = "";

if(!$mqtt->connect(true, NULL, $username, $password)) {
	exit(1);
}

echo "Connected to mqtt server...\n";
$topics = array();
$topics[$topicprefix.'#'] = array("qos" => 0, "function" => "newvalue");
$mqtt->subscribe($topics, 0);

while($mqtt->proc()){
}


$mqtt->close();

function newvalue($topic, $msg){
	echo "$topic = $msg\n";
	static $mqttdata;
	global $mqtt;
	global $topicprefix;
	global $settings;
	static $lastgasdatetime;
	
	
	$mqttdata[$topic] = $msg;
	
	
        if (($topic == $topicprefix.'status') && ($msg == 'ready'))
        {
                echo "Calculating new growatt values...\n";
	        $mysqli = mysqli_connect($settings["mysqlserver"],$settings["mysqlusername"],$settings["mysqlpassword"],$settings["mysqldatabase"]);

	        if (!$mysqli->connect_errno)
	        {
	        	$sql = "INSERT INTO `sunelectricity` (pv_watt, pv_1_volt, pv_2_volt, grid_watt, grid_volt, grid_amp, grid_freq, kwh_today, kwh_total) VALUES ('".
                                                $mqttdata[$topicprefix.'pv/watt'] ."','".
                                                $mqttdata[$topicprefix.'pv/1/volt']."','".
                                                $mqttdata[$topicprefix.'pv/2/volt'] ."','".
                                                $mqttdata[$topicprefix.'grid/watt'] ."','".
                                                $mqttdata[$topicprefix.'grid/volt']."','".
                                                $mqttdata[$topicprefix.'grid/amp']."','".
                                                $mqttdata[$topicprefix.'grid/frequency']."','".
                                                $mqttdata[$topicprefix.'grid/today/kwh']."','".
                                                $mqttdata[$topicprefix.'grid/total/kwh']."');";
                                                
			echo $sql."\n";
			
			$result = $mysqli->query ($sql);
                        // write values from database
                        if (!$result)
                        {
                                echo "error writing sunelectricty values to database ".$mysqli->error."\n";
                        }
                                                	

			// Read values from database
        	        if ($result = $mysqli->query("SELECT * FROM `sunelectricity` WHERE timestamp >= CURDATE() - INTERVAL 1 DAY AND timestamp < CURDATE() ORDER BY timestamp ASC LIMIT 1")) 
        	        {
	                	$row = $result->fetch_object();
				if ($row)
				{
					$mqttdata[$topicprefix.'grid/yesterday/kwh'] = round($mqttdata[$topicprefix.'grid/total/kwh'] - $row->kwh_total - $mqttdata[$topicprefix.'grid/today/kwh'],1);
				}
				else
				{
					$mqttdata[$topicprefix.'grid/yesterday/kwh'] = ""; 
				}
				$mqtt->publishwhenchanged($topicprefix.'grid/yesterday/kwh', $mqttdata[$topicprefix.'grid/yesterday/kwh'], 0, 1);
			}
			else
			{
                	       	echo "error reading sunelectricty values from database ".$mysqli->error."\n"; 
	                }


			// Read values from database
        	        if ($result = $mysqli->query("SELECT * FROM `sunelectricity` WHERE timestamp >= DATE_FORMAT(NOW() ,'%Y-%m-01') AND timestamp < DATE_FORMAT(NOW() ,'%Y-%m-02') ORDER BY timestamp ASC LIMIT 1")) 
        	        {
	                	$row = $result->fetch_object();
	                	if ($row)
	                	{
	                		$mqttdata[$topicprefix.'grid/month/kwh'] = round($mqttdata[$topicprefix.'grid/total/kwh'] - $row->kwh_total, 1);
				}
				else
				{
					$mqttdata[$topicprefix.'grid/month/kwh'] = ""; 
				}
				$mqtt->publishwhenchanged($topicprefix.'grid/month/kwh', $mqttdata[$topicprefix.'grid/month/kwh'], 0, 1);
			}
			else
			{
                	       	echo "error reading sunelectricty values from database ".$mysqli->error."\n"; 
	                }

                        // Read values from database
                        if ($result = $mysqli->query("SELECT * FROM `sunelectricity` WHERE timestamp >= DATE_FORMAT(NOW() ,'%Y-01-01') AND timestamp < DATE_FORMAT(NOW() ,'%Y-01-02') ORDER BY timestamp ASC LIMIT 1"))
                        {
                                $row = $result->fetch_object();
                                $mqttdata[$topicprefix.'grid/year/kwh'] = round($mqttdata[$topicprefix.'grid/total/kwh'] - $row->kwh_total, 1);
				$mqtt->publishwhenchanged($topicprefix.'grid/year/kwh', $mqttdata[$topicprefix.'grid/year/kwh'], 0, 1);
                        }
                        else
                        {
                                echo "error reading sunelectricty values from database ".$mysqli->error."\n";
                        }
                        

                        // Read values from database
        	        if ($result = $mysqli->query("SELECT * FROM `sunelectricity` WHERE timestamp >= DATE_FORMAT(NOW() ,'%Y-%m-01') - INTERVAL 1 MONTH  AND timestamp < DATE_FORMAT(NOW() ,'%Y-%m-02') - INTERVAL 1 MONTH ORDER BY timestamp DESC LIMIT 1")) 
        	        {
	                	$row = $result->fetch_object();
				$mqttdata[$topicprefix.'grid/lastmonth/kwh'] = round($mqttdata[$topicprefix.'grid/total/kwh'] - $row->kwh_total - $mqttdata[$topicprefix.'grid/month/kwh'], 1);
				$mqtt->publishwhenchanged($topicprefix.'grid/lastmonth/kwh', $mqttdata[$topicprefix.'grid/lastmonth/kwh'], 0, 1);
			}
			else
			{
                	       	echo "error reading sunelectricty values from database ".$mysqli->error."\n"; 
	                }

                        // Read values from database
                        if ($result = $mysqli->query("SELECT * FROM `sunelectricity` WHERE timestamp >= DATE_FORMAT(NOW() ,'%Y-01-01') - INTERVAL 1 YEAR AND timestamp < DATE_FORMAT(NOW() ,'%Y-12-31') - INTERVAL 1 YEAR ORDER BY timestamp ASC LIMIT 1"))
                        {
                                $row = $result->fetch_object();
                                if ($row)
                                {
                                	$mqttdata[$topicprefix.'grid/lastyear/kwh'] = round($mqttdata[$topicprefix.'grid/total/kwh'] - $row->kwh_total - $mqttdata[$topicprefix.'grid/year/kwh'], 1);
				}
				else
				{
					$mqttdata[$topicprefix.'grid/lastyear/kwh'] = ""; 
				}
                                $mqtt->publishwhenchanged($topicprefix.'grid/lastyear/kwh', $mqttdata[$topicprefix.'grid/lastyear/kwh'], 0, 1);
                        }
                        else
                        {
                                echo "error reading sunelectricty values from database ".$mysqli->error."\n";
                        }
                        


                	$mysqli->close();
		}
		else
		{
                	echo ("Error while writing water values to database: ".$mysqli->connect_error ."\n");
		}



        }

}
