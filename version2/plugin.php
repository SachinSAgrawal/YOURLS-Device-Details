<?php
/*
Plugin Name: Device Details
Plugin URI: https://github.com/SachinSAgrawal/YOURLS-Device-Details
Description: Parses user-agent using a custom library to display information about IP and device
Version: 2.1
Author: Sachin Agrawal
Author URI: https://sachinagrawal.me
*/

// Load the user-agent parsing library WhichBrowser
require 'vendor/autoload.php';

yourls_add_action('post_yourls_info_stats', 'ip_detail_page');

function get_ip_info($ip) {
    $url = "https://ipinfo.io/{$ip}/json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function get_timezone_offset($timezone) {
    $timezone_object = new DateTimeZone($timezone);
    $datetime = new DateTime("now", $timezone_object);
    $offset = $timezone_object->getOffset($datetime);
    return $offset / 60; // Convert seconds to minutes
}

function timezone_offset_to_gmt_offset($timezone_offset) {
    $timezone_offset = intval($timezone_offset);
    $hours = floor($timezone_offset / 60);
    $offset = ($timezone_offset < 0 ? '-' : '+') . abs($hours);
    return 'GMT' . $offset;
}

function parse_referrer_details($referrer) {
    $details = [
        'Referrer' => '',
        'Orientation' => '',
        'Language' => '',
        'Touch' => '',
        'Battery' => '',
        'Incognito' => '',
        'AdBlock' => '',
        'Size' => ''
    ];

    // Split the string to get the original referrer and the additional info
    $parts = explode(' - ', $referrer, 2);
    $details['Referrer'] = $parts[0] ?? 'direct';

    if (isset($referrer)) {
        $pattern = '/Ori:([-\w]+).*Lang:([\w-]+).*Touch:(\w+).*Bat:(\d+%|undefined).*Incog:(\w+).*AdBlock:(\w+)(?:.*Size:([\w]+x[\w]+))?/';
        preg_match($pattern, $referrer, $matches);

        if ($matches) {
            $details['Orientation'] = $matches[1] ?? '';
            $details['Language'] = $matches[2] ?? '';
            $details['Touch'] = $matches[3] ?? '';
            $details['Battery'] = ($matches[4] !== 'undefined') ? $matches[4] : '';
            $details['Incognito'] = $matches[5] ?? '';
            $details['AdBlock'] = $matches[6] ?? '';
            $details['Size'] = $matches[7] ?? ''; // This is optional now
        }
    }

    return $details;
}

function ip_detail_page($shorturl) {
    $nonce = yourls_create_nonce('ip');
    global $ydb;
    $base  = YOURLS_SITE;
    $table_url = YOURLS_DB_TABLE_URL;
    $table_log = YOURLS_DB_TABLE_LOG;
    $outdata   = '';

    $query = $ydb->fetchObjects("SELECT * FROM `$table_log` WHERE shorturl='$shorturl[0]' ORDER BY click_id DESC LIMIT 1000");

    if ($query) {
        foreach ($query as $query_result) {
            $me = "";
            $me2 = "";
            if ($query_result->ip_address == $_SERVER['REMOTE_ADDR']) {
                $me = " bgcolor='#d4eeff'";
                $me2 = "<br><i>this is your ip</i>";
            }

            // Parse user agent
            $ua = $query_result->user_agent;
            $wbresult = new WhichBrowser\Parser($ua);

            // Get additional IP information from ipinfo.io
            $ip_info = get_ip_info($query_result->ip_address);

            // Calculate local time
            $click_time_utc = new DateTime($query_result->click_time, new DateTimeZone('UTC'));
            $timezone_offset = isset($ip_info['timezone']) ? get_timezone_offset($ip_info['timezone']) : 0;
            $click_time_utc->modify($timezone_offset . ' minutes');
            $local_time = $click_time_utc->format('Y-m-d H:i:s');

            // Convert timezone offset to GMT offset
            $gmt_offset = timezone_offset_to_gmt_offset($timezone_offset);
            
            // Parse the referrer that contains the additional info
            $referrer_details = parse_referrer_details($query_result->referrer);
            
            $local_time_info = $gmt_offset . '<br>' . $local_time;
            
            $location_info = $ip_info['city']. ', ' . $ip_info['region']. ', ' . $query_result->country_code;
            
            $browser_os_info = $wbresult->browser->name . ' ' . $wbresult->browser->version->value;
            $browser_os_info .= $wbresult->os->name ? '<br>' . $wbresult->os->name. ' ' . $wbresult->os->version->value : '';
                             
            $device_info = $wbresult->device->type;
            $device_info .= $wbresult->device->manufacturer ? '<br>' . $wbresult->device->manufacturer : '';
            $device_info .= $wbresult->device->model ? '<br>' . $wbresult->device->model : '';
                          
            $interaction_info = '';
            $orientation = is_numeric($referrer_details['Orientation']) ? $referrer_details['Orientation'] : '';
            $interaction_info .= isset($orientation) && $orientation !== '' ? 'Rot: ' . $orientation : '';
            $interaction_info .= $referrer_details['Touch'] ? ($orientation !== '' ? '<br>' : '') . 'Touch: ' . $referrer_details['Touch'] : '';
            $interaction_info .= $referrer_details['Size'] ? '<br>' . $referrer_details['Size'] : '';
            
            // Debugging: Print raw data to browser console
            /*
            echo '<script>';
            echo 'console.log(' . json_encode($wbresult) . ');';
            echo 'console.log(' . json_encode($ip_info)  . ');';
            echo 'console.log(' . json_encode($referrer_details) . ');';
            echo '</script>';
            */

            $outdata .= '<tr'.$me.'>
                        <td>'.$query_result->click_time.'</td>
                        <td>'.$local_time_info.'</td>
						<td>'.$location_info.'</td>
						<td><a href="https://who.is/whois-ip/ip-address/'.$query_result->ip_address.'" target="blank">'.$query_result->ip_address.'</a>'.$me2.'</td>
						<td>'.$ua.'</td>
						<td>'.$browser_os_info.'</td>
						<td>'.$device_info.'</td>
						<td>'.$wbresult->engine->name.'</td>
						<td>'.$referrer_details['Referrer'].'</td>
						<td>'.$interaction_info.'</td>
                        <td>'.$referrer_details['Language'].'</td>
                        <td>'.$referrer_details['Battery'].'</td>
                        <td>'.$referrer_details['Incognito'].'</td>
                        <td>'.$referrer_details['AdBlock'].'</td>
						</tr>';
        }

        echo '<table  border="1" cellpadding="5" style="margin-top:25px;"><tr><td>Timestamp</td><td>Local Time</td><td>Location</td>
				<td>IP Address</td><td>User Agent</td><td>Browser/OS</td><td>Device</td><td>Engine</td><td>Referrer</td>
				<td>Screen</td><td>Language</td><td>Battery</td><td>Incognito</td><td>AdBlock</td></tr>' . $outdata . "</table><br>\n\r";
    }
}
