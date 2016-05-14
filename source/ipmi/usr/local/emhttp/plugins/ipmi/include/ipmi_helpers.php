<?
require_once '/usr/local/emhttp/plugins/ipmi/include/fan_helpers.php';

/* get highest temp of hard drives */
function get_highest_temp(){
    $hdds = parse_ini_file('/var/local/emhttp/disks.ini',true);
    $highest_temp = "0";
    foreach ($hdds as $hdd) {
        $temp = $hdd['temp'];
        if(is_numeric($temp))
            $highest_temp = ($temp > $highest_temp) ? $temp : $highest_temp;
    }
    return $highest_temp;
}

$hdd_temp = get_highest_temp();

// get options for high or low temp thresholds
function get_temp_range($range, $selected=null){
    $temps = [20,80];
    if ($range == 'HI')
      rsort($temps);
    $options = "";
    foreach(range($temps[0], $temps[1], 5) as $temp){
        $options .= "<option value='$temp'";

        // set saved option as selected
        if ($selected == $temp)
            $options .= " selected";

        $options .= ">$temp</option>";
    }
    return $options;
}

/* get an array of all sensors and their values */
function ipmi_sensors() {
    global $netopts, $hdd_temp;
    $cmd= "/usr/sbin/ipmi-sensors --output-sensor-thresholds --comma-separated-output --output-sensor-state --no-header-output --interpret-oem-data $netopts 2>/dev/null"; // --non-abbreviated-units
    exec($cmd, $output, $return_var=null);

    if ($return_var)
        return []; // return empty array if error

    // add highest hard drive temp sensor
    $hdd = "99,HDD Temperature,Temperature,Nominal,$hdd_temp,C,N/A,N/A,N/A,N/A,N/A,N/A,Ok";
    if(!empty($netopts))
        $hdd = '127.0.0.1:'.$hdd;
    $output[] = $hdd;

    // key names for ipmi sensors output
    $keys = ['ID','Name','Type','State','Reading','Units','LowerNR','LowerC','LowerNC','UpperNC','UpperC','UpperNR','Event'];
    $sensors = [];

    foreach($output as $line){

        $sensor_raw = explode(",", $line);
        $size_raw = sizeof($sensor_raw);

        // add sensor keys as keys to ipmi sensor output
        $sensor = ($size_raw < 13) ? []: array_combine($keys, array_slice($sensor_raw,0,13,true));

        if(empty($netopts))
            $sensors[$sensor['ID']] = $sensor;
        else{

            //split id into host and id
            $id = explode(':',$sensor['ID']);
            $sensor['IP'] = trim($id[0]);
            $sensor['ID'] = trim($id[1]);
            if ($sensor['IP'] == 'localhost')
                $sensor['IP'] = '127.0.0.1';

            // add sensor to array of sensors
            $sensors[ip2long($sensor['IP']).'_'.$sensor['ID']] = $sensor;
        }
    }
    return $sensors;
}

/* get array of events and their values */
function ipmi_events($archive=null){
    global $netopts;
    if($archive) {
        $filename = "/boot/config/plugins/ipmi/archived_events.log";
        $output = is_file($filename) ? file($filename, FILE_IGNORE_NEW_LINES) : [] ;
    } else {
        $cmd = "/usr/sbin/ipmi-sel --comma-separated-output --output-event-state --no-header-output --interpret-oem-data $netopts 2>/dev/null";
        exec($cmd, $output, $return_var=null);
    }
    if ($return_var)
        return []; // return empty array if error

    // key names for ipmi event output
    $keys = ['ID','Date','Time','Name','Type','State','Event'];
    $events = [];

    foreach($output as $line){

        $event_raw = explode(",", $line);
        $size_raw = sizeof($event_raw);

        // add event keys as keys to ipmi event output
        $event = ($size_raw < 7) ? []: array_combine($keys, array_slice($event_raw,0,7,true));

        // put time in sortable format and add unix timestamp
        $timestamp = $event['Date']." ".$event['Time'];
        if(strtotime($timestamp)) {
            if($date = Datetime::createFromFormat('M-d-Y H:i:s', $timestamp)) {
                $event['Date'] = $date->format('Y-m-d H:i:s');
                $event['Time'] = $date->format('U');
            }
        }

        if (empty($netopts)){

            if($archive)
                $events[$event['Time']."-".$event['ID']] = $event;
            else
                $events[$event['ID']] = $event;

        }else{

            //split id into host and id
            $id = explode(':',$event['ID']);
            $event['IP'] = trim($id[0]);
            if($archive)
                $event['ID'] = $event['Time'];
            else
                $event['ID'] = trim($id[1]);
            if ($event['IP'] == 'localhost')
                $event['IP'] = '127.0.0.1';

            // add event to array of events
            $events[ip2long($event['IP']).'_'.$event['ID']] = $event;
        }
    }
    return $events;
}

/* get reading for a given sensor by name */
function ipmi_get_readings() {
    global $netopts, $hdd_temp;
    $cmd = "/usr/sbin/ipmi-sensors --comma-separated-output --no-header-output --interpret-oem-data $netopts 2>/dev/null";
    exec($cmd, $output, $return_var=null);

    if($return_var)
        return []; // return empty array if error

    // add highest hard drive temp sensor
    $hdd = "99,HDD Temperature,Temperature,$hdd_temp,C,Ok";
    if(!empty($netopts))
        $hdd = '127.0.0.1:'.$hdd;
    $output[] = $hdd;

    // key names for ipmi sensors output
    $keys = ['ID', 'Name', 'Type', 'Reading', 'Units', 'Event'];
    $sensors = [];

    foreach($output as $line){

        // add sensor keys as keys to ipmi sensor output
        $sensor_raw = explode(",", $line);
        $size_raw = sizeof($sensor_raw);
        $sensor = ($size_raw < 6) ? []: array_combine($keys, array_slice($sensor_raw,0,6,true));

        if (empty($netopts)){
            $sensors[$sensor['ID']] = $sensor;
        }else{
            //split id into host and id
            $id = explode(':',$sensor['ID']);
            $sensor['IP'] = trim($id[0]);
            $sensor['ID'] = trim($id[1]);
            if ($sensor['IP'] == 'localhost')
                $sensor['IP'] = '127.0.0.1';

            // add sensor to array of sensors
            $sensors[ip2long($sensor['IP']).'_'.$sensor['ID']] = $sensor;
        }
    }
    return $sensors; // sensor readings
}

/* get select options for a given sensor type */
function ipmi_get_options($selected=null){
    global $sensors;
    $options = "";
    foreach($sensors as $id => $sensor){
        if (($sensor['Type'] == 'Temperature') || ($sensor['Type'] == 'Fan')){
            $name = $sensor['Name'];
            $reading  = $sensor['Reading'];
            $ip = (empty($sensor['IP'])) ? '' : " (${sensor['IP']})";
            $units    = ($reading == 'N/A')    ? '' : $sensor['Units'];
            $options .= "<option value='$id'";

            // set saved option as selected
            if ($selected == $id)
                $options .= " selected";

        $options .= ">$name$ip - $reading $units</option>";
        }
    }
    return $options;
}

/* get select options for available sensors */
function ipmi_get_selected(){
    global $sensors, $netopts, $ignore;
    $ignored = explode(',', $ignore); // create array of ignored sensors
    $options = (!empty($sensors)) ? '<option value="">Toggle All</option>' : '<option value="" disabled>No Sensors Available</option>';
    foreach($sensors as $id => $sensor){
        $name     = $sensor['Name'];
        $reading  = $sensor['Reading'];
        $ip       = ''; 
        $units    = ($reading == 'N/A')    ? '' : $sensor['Units'];
        if(!empty($netopts)){
            $ip = " (${sensor['IP']})";
            $id = $sensor['IP'].':'.explode('_', $id)[1];
        }
        $pattern  = '/^'.$id.'.*/'; // id to search for
        $options .= "<option value='$id'";

        // only select sensors that are not saved
        $options .= preg_grep($pattern, $ignored) ?  '' : " selected"; // search for id in array

        $options .= ">$name$ip - $reading $units</option>";

    }
    return $options;
}

// get a json array of the contents of gihub repo
function get_content_from_github($repo, $file) {
    $ch = curl_init();
    $ch_vers = curl_version();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/'.$ch_vers['version']);
    curl_setopt($ch, CURLOPT_URL, $repo);
    $content = curl_exec($ch);
    curl_close($ch);
    if (!empty($content) && (!is_file($file) || $content != file_get_contents($file)))
        file_put_contents($file, $content);
}
?>