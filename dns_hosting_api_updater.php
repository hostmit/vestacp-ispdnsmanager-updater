<?php
/**
* This script will list all domains and will update ISPDNSMANAGER via API (slave mode).
**/

/** 
 * Config section. Unless marked as optional, constants are REQUIRED
*/
define("LOGFILE","/var/log/dns_hosting_api_updater.log"); //optional, comment out to disable logger. Carefull, 
define("LOGFILE_MAXSIZE",1000000);
define("API_URL","");
define("API_USER","");
define("API_PASS","");
define("API_MASTERIP","");
define("VESTA_BIN_PATH","/usr/local/vesta/bin/"); //trailing backslash must be present. When used from crontab, PATH can be different/not set
define("VESTA_VAR","/usr/local/vesta");

/** 
*   Deadsimple logger. If LOGFILE is not defined will return, has simple log rotate mechanic
*  @param string $msg Message to log
**/
function logger($msg) {
    if(!defined("LOGFILE")) return;
    if (filesize(LOGFILE) > LOGFILE_MAXSIZE) {
        file_put_contents(LOGFILE, "[".date("Y/m/d H:m:s")."] " . "LOGFILE over the limit: " .LOGFILE_MAXSIZE ." bytes, will rename to *.prev and start a new one" . PHP_EOL, FILE_APPEND);    
        if (file_exists(LOGFILE.".prev")) unlink(LOGFILE.".prev");
        rename(LOGFILE,LOGFILE.".prev");
    }
    file_put_contents(LOGFILE, "[".date("Y/m/d H:i:s")."] " . $msg . PHP_EOL, FILE_APPEND);
}

logger("--- Script started ---");


putenv("VESTA=".VESTA_VAR); // this variable required by vesta scripts.

$vestaDomainsArr=array();
$ispDomainsArr=array();
$cmd = VESTA_BIN_PATH . 'v-list-users json';
$output=shell_exec($cmd);
$usersJson=json_decode($output);
foreach ($usersJson as $user=>$udata) {
    $cmd = VESTA_BIN_PATH . "v-list-dns-domains $user json";
    $output=shell_exec($cmd);
    $userDomains=json_decode($output);
    foreach ($userDomains as $domain=>$ddata) {
        array_push($vestaDomainsArr,$domain);

    }
}
logger("Parsed all vesta users, we have ".sizeof($vestaDomainsArr)." domains");

$ch = curl_init();
$url = "https://".API_URL."/dnsmgr?authinfo=".API_USER.":".API_PASS."&out=json&func=domain";
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

if(!$response) {
    logger("Unable to perform curl request, will stop executing");
    die();
}

$responseJson = json_decode($response);
if (is_null($responseJson) || !isset($responseJson->doc)) {
    logger("Unable to parse response from API as json, response was: " . PHP_EOL . $response);
    die();
}

if (isset($responseJson->doc->elem)) {
    foreach($responseJson->doc->elem as $key=>$value) {
        if (!isset($value->name->{'$'})) {
            logger("Unable to find value->name->{'$'} object form key = $key. That should not happen...");
            continue;
        }
        array_push($ispDomainsArr,$value->name->{'$'});
    }
}

logger("Parsed ISP domains, got ".sizeof($ispDomainsArr)." items");

/**
 * Remove domains from ISP which are not in Vesta.
 * The problem is we are getting same OK response from API, even if domain is never existed or already deleted. So we dont bother to parse a response, just checking for valid JSON.
 *  */ 
$counterDeleted=0;
foreach($ispDomainsArr as $domain) {
    if (!in_array($domain,$vestaDomainsArr)) {
        logger("Domain: $domain is not in vesta list, will delete it");
        $ch = curl_init();
        $url = "https://".API_URL."/dnsmgr?authinfo=".API_USER.":".API_PASS."&out=json&func=domain.delete&elid=$domain";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        if (!$response || is_null(json_decode($response))) {
            logger("Something went wrong deleting domain, reposne was: $response");
        } else {
            logger("Domain $domain deleted.");
            $counterDeleted++;
        }
    }
}

/**
 * Add all domains from VESTACP which arent in ISP manager. It will return json->doc-error if something went wrong.
 */

$counterAdded=0;
foreach($vestaDomainsArr as $domain) {
    if (!in_array($domain,$ispDomainsArr)) {
        logger("Domain: $domain is not in ISP list, will add it");
        $ch = curl_init();
        $url="https://".API_URL."/dnsmgr?authinfo=".API_USER.":".API_PASS."&out=json&func=domain.edit&dtype=slave&name=$domain&masterip=" . API_MASTERIP . "&sok=ok";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($response);
        if (!$response || is_null($json) || isset($json->doc->error)) {
            logger("Something went wrong deleting domain, resposne was: $response");
        } elseif (isset($json->doc->messages)) {
            logger("Added domain $domain to ISP");
            $counterAdded++;
        } else {
            logger("Unknown response from ISP, should not happen. Response was: $reponse");
        }
    }
}

logger("*** Script finished. Deleted: $counterDeleted, Added: $counterAdded ***");
?>
