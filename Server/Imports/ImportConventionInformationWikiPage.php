<?php
define('ROOT_PATH', getcwd() . '/');
define('SHARED_PATH', ROOT_PATH . '../Shared/');
define('VENDOR_PATH', SHARED_PATH . './vendor/');

require SHARED_PATH . 'config.inc.php';
require SHARED_PATH . 'helpers.php';
require VENDOR_PATH . 'autoload.php';
require VENDOR_PATH . 'sergeytsalkov/meekrodb/db.class.php';
require VENDOR_PATH . 'parsecsv/php-parsecsv/parsecsv.lib.php';

use \YaLinqo\Enumerable;

set_error_handler("exceptionErrorHandler", E_ALL);


$fileName = isset($argv[1])? $argv[1] : "/temp/wiki.txt";
Log::info("Trying to import from file: " . $fileName);
if (!file_exists($fileName)) { Log::error("File dies not exist: " . $fileName); die(); };

$database = new MeekroDB(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT, DB_CHARSET);
$database->throw_exception_on_error = true;
$database->error_handler = false;

// Full page source of http://wiki.eurofurence.org/doku.php?id=ef22:it:mobileapp:coninfo
$wikiText = file_get_contents($fileName);

$regexParseContent = "/<WRAP[^>]*>PARSE_START<\/WRAP>(.*)<WRAP[^>]*>PARSE_END<\/WRAP>/si";
$regexGroup = "/====([^=]+)====(.+?)((?=====)|$)/siu";
$regexEntry = "/===([^=]+)===.+?<WRAP box[^>]*>(.+?)<\/WRAP>([^\<]*<WRAP lo[^>]*>([^\<]+)<\/WRAP>){0,1}/si";
$regexLinks = "/  \* \[\[([^\|]+)\|([^\]]+)\]\]/si";

preg_match($regexParseContent, $wikiText, $matches);
$wikiTextToParse = trim($matches[1]);

preg_match_all($regexGroup, $wikiTextToParse, $groupMatches);

$position = 0;
$groupIds = array();

try {
    
    $database->startTransaction();
    $database->query("DELETE FROM Info");
    $database->query("DELETE FROM InfoGroup");
    $database->query("UPDATE EndpointEntity SET DeltaStartDateTimeUtc = utc_timestamp() WHERE TableName = 'Info';");
    $database->query("UPDATE EndpointEntity SET DeltaStartDateTimeUtc = utc_timestamp() WHERE TableName = 'InfoGroup';");
    
    foreach($groupMatches[1] as $id => $group)  {

        $groupId = GUID();
        $parts = explode("|", trim($group),2);
        
        Log::info(sprintf("Importing Group %s", trim($parts[0])));
        
        $database->insert("InfoGroup", array(
                "Id" => $groupId,
                "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                "IsDeleted" => "0",
                "Name" => trim($parts[0]),
                "Description" => trim($parts[1]),
                "Position" => $position 
        ));
        
        $position++;
        preg_match_all($regexEntry, $groupMatches[2][$id], $entryMatches);



        $epos = 0;
        foreach($entryMatches[1] as $entryId => $entry)  {
            Log::info(sprintf("  Importing Entry %s", trim($entry)));
	   
            $links = [];
	    $images = [];
	    
	    // Images / Links
	    if ($entryMatches[4][$entryId] != "") {
	        preg_match_all($regexLinks, $entryMatches[4][$entryId], $linkMatches);
		foreach($linkMatches[1] as $linkId => $linkTarget) {
		    $linkText = $linkMatches[2][$linkId];

		    if (strtolower($linkText) == "image") {
			$imageTag = "info:import[" . strtolower($linkTarget) . "]";

			$existingImageRow = $database->queryFirstRow("SELECT * FROM image WHERE Title=%s", $imageTag);
			if ($existingImageRow) {
			    Log::info(sprintf("  Skipping import %s (exists)", $imageTag));
			    $images[] = $existingImageRow["Id"];
			} else {
			    Log::info(sprintf("  Fetching %s (new)", $imageTag));
		            $imageData = file_get_contents($linkTarget);
			    $newImageId = insertOrUpdateImageByTitle($database, $imageData, $imageTag);
		            $images[] = $newImageId;
			}
		    } else {
		        $links[] = array("Target" => $linkTarget, "Text" => $linkText);
		    }
		}
            }
            
            $database->insert("Info", array(
                    "Id" => GUID(),
                    "InfoGroupId" => $groupId,
                    "LastChangeDateTimeUtc" => $database->sqleval("utc_timestamp()"),
                    "IsDeleted" => "0",
                    "Title" => trim($entry),
                    "Text" => trim($entryMatches[2][$entryId]),
                    "Position" => $epos,
		    "ImageIds" => json_encode($images),
		    "Urls" => json_encode($links)
            ));

            
            $epos++;
        }
    }

    Log::info("Commiting changes to database");

    $database->commit();
    
} catch (Exception $e) {
    
    var_dump($e->getMessage());
    
    Log::error("Rolling back changes to database");
    $database->rollback();
    
}

?>
