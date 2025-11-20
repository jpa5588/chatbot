<?php
// middlewarelogic.php (simple: fetch → store raw → parse to table → return XML)

//Default xml header to send back to the ui server
header('Content-Type: application/xml; charset=utf-8');

//Prevents any extra info, like blank lines that exist outside of the xml, issue with that during testing
ob_start();

//Default db connection config to store database connection
require_once __DIR__ . '/db_config.php'; 

//Helper function to log events in error log
function mw_log(string $msg): void {
    error_log('[NFL_MW] ' . $msg);
}
//Validate the input is accurate, standings endpoint needs to see 2024REG, 2024PRE, or 2024POST in order for request to process
//Store as a variable called $season
//Convert input to upper if someone types lowercase
//If input is not any of those, then throw 400 error code and echo error
$season = isset($_GET['season']) ? strtoupper(trim($_GET['season'])) : '';
if ($season === '') {
    http_response_code(400);
    echo "<error>Missing season parameter (e.g., 2024REG)</error>";
    exit;
}

//Attempt to connect to database using the config file from earlier using the pdo fucntion, pass in the parameters from the config
//The pdo fucntion is a prebuilt php function that allows connection to a mysql database
//Attempt to connect within 5 seconds, anything longer error their is an issue with the database connection
try {
    $pdo = new PDO($cfg['db_host'], $cfg['db_user'], $cfg['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<error>Database connection failed</error>";
    exit;
}

//API key and url to store and use, standings is hardcoded to test our endpoint and passes the $season variable from earlier
$apiKey = '3751b77068144fee9a5e5e5058ff5eb1';
$apiUrl = "https://api.sportsdata.io/v3/nfl/scores/xml/Standings/{$season}";

//Context object that will allow php to process the request to the API for later, using a custom header to call it out
//This came from sportsdata documentation for testing GETs in php
$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "Ocp-Apim-Subscription-Key: {$apiKey}\r\nAccept: application/xml\r\n",
        'timeout' => 12
    ]
]);

//Test the xmlresponse using the built php function file_get_contents, passing in the apiURL, boolean value of false, and the ctx from earlier
//If the test fails and returns false, then throw 502 error code signialing and issue trying to get the xml
$xmlResponse = @file_get_contents($apiUrl, false, $ctx);
if ($xmlResponse === false) {
    http_response_code(502);
    echo "<error>Failed to fetch upstream XML</error>";
    exit;
}
//Test case to display entire xml from API
//This was primarly used to verify API connection, callings, and base xml actually paints to the screen
//echo($xmlResponse);

//First line tells php to suppress xml error codes
//Then start loadings the xml from xmlresponse to test and verify if they are good xml formats
//If not then throw a 502 error telling us an issue with the xml
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlResponse);
if ($xml === false) {
    http_response_code(502);
    echo "<error>Invalid XML from upstream</error>";
    exit;
}

mw_log("{$season}: starting DB cache write into standings_raw_data");

//Once all the xml tests pass start storing the xml into the database
try {
    // Unique per endpoint_key; update if it already exists
    $stmt = $pdo->prepare("
        INSERT INTO standings_raw_data (endpoint_key, xml_data)
        VALUES (:key, :xml)
        ON DUPLICATE KEY UPDATE xml_data = VALUES(xml_data)
    ");
	mw_log("{$season}: executing DB cache write query");
	
    $stmt->execute([':key' => $season, ':xml' => $xmlResponse]);
	
	mw_log("{$season}: DB cache successful insert into standings_raw_data");
} catch (Throwable $e) {
    // Don’t break the response if cache write fails; just log
    error_log("[standings_raw_data] insert/update failed for {$season}: " . $e->getMessage());
}

mw_log("{$season}: starting DB cache write into standings_parsed");
//Parse the xml data from earlier and breakout the key data we find important
try {
    $pdo->beginTransaction();

    //For testing purposes delete from the table and start over to give is a clean slate
    $pdo->prepare("DELETE FROM standings_parsed WHERE endpoint_key = ?")->execute([$season]);


	//Create the insert statement to use for later to actually insert the data into a new table
	//Note Primary key is set to auto increment for each row inserted
    $ins = $pdo->prepare("
    INSERT INTO standings_parsed
		(`endpoint_key`,`SeasonType`,`Season`,`Conference`,`Division`,`Team`,`Name`,
		 `Wins`,`Losses`,`Ties`,`Percentage`,
		 `PointsFor`,`PointsAgainst`,`NetPoints`,`Touchdowns`,
		 `DivisionWins`,`DivisionLosses`,`ConferenceWins`,`ConferenceLosses`,
		 `TeamID`,`DivisionTies`,`ConferenceTies`,
		 `GlobalTeamID`,`DivisionRank`,`ConferenceRank`,
		 `HomeWins`,`HomeLosses`,`HomeTies`,
		 `AwayWins`,`AwayLosses`,`AwayTies`,`Streak`)
		VALUES
		(:endpoint_key,:SeasonType,:Season,:Conference,:Division,:Team,:Name,
		 :Wins,:Losses,:Ties,:Percentage,
		 :PointsFor,:PointsAgainst,:NetPoints,:Touchdowns,
		 :DivisionWins,:DivisionLosses,:ConferenceWins,:ConferenceLosses,
		 :TeamID,:DivisionTies,:ConferenceTies,
		 :GlobalTeamID,:DivisionRank,:ConferenceRank,
		 :HomeWins,:HomeLosses,:HomeTies,
		 :AwayWins,:AwayLosses,:AwayTies,:Streak)
		");

	//Set insert to 0 to track number of inserts
    $inserted = 0;
	
	mw_log("{$season}: DB cache started insert to standings_parsed");
	//foreach xml value with the phrase standing in its xml base store each in an array
	//The begin inserting the values we need, matching it to the insert from earlier on the key values we created
	//I.E :endpoint_key will point to $season
	//:team will convert to a string and using the current value of $s parse the phrase Team and store it
	//Continue doing this for every value in the array
    foreach ($xml->Standing as $s) {
    $ins->execute([
		':endpoint_key'     => $season,
		':SeasonType'     => (int)($s->SeasonType ?? 0),
		':Season'         => (int)($s->Season ?? 0),
		':Conference'     => (string)($s->Conference ?? ''),
		':Division'       => (string)($s->Division ?? ''),
		':Team'           => (string)($s->Team ?? ''),
		':Name'           => (string)($s->Name ?? ''),
		':Wins'           => (int)($s->Wins ?? 0),
		':Losses'         => (int)($s->Losses ?? 0),
		':Ties'           => (int)($s->Ties ?? 0),
		':Percentage'     => (float)($s->Percentage ?? 0),
		':PointsFor'      => (int)($s->PointsFor ?? 0),
		':PointsAgainst'  => (int)($s->PointsAgainst ?? 0),
		':NetPoints'      => (int)($s->NetPoints ?? 0),
		':Touchdowns'     => (int)($s->Touchdowns ?? 0),
		':DivisionWins'   => (int)($s->DivisionWins ?? 0),
		':DivisionLosses' => (int)($s->DivisionLosses ?? 0),
		':ConferenceWins' => (int)($s->ConferenceWins ?? 0),
		':ConferenceLosses'=> (int)($s->ConferenceLosses ?? 0),
		':TeamID'         => (int)($s->TeamID ?? 0),
		':DivisionTies'   => (int)($s->DivisionTies ?? 0),
		':ConferenceTies' => (int)($s->ConferenceTies ?? 0),
		':GlobalTeamID'   => (int)($s->GlobalTeamID ?? 0),
		':DivisionRank'   => (int)($s->DivisionRank ?? 0),
		':ConferenceRank' => (int)($s->ConferenceRank ?? 0),
		':HomeWins'       => (int)($s->HomeWins ?? 0),
		':HomeLosses'     => (int)($s->HomeLosses ?? 0),
		':HomeTies'       => (int)($s->HomeTies ?? 0),
		':AwayWins'       => (int)($s->AwayWins ?? 0),
		':AwayLosses'     => (int)($s->AwayLosses ?? 0),
		':AwayTies'       => (int)($s->AwayTies ?? 0),
		':Streak'         => (int)($s->Streak ?? 0),
    ]);
        $inserted++;
    }
	mw_log("{$season}: DB parsed standings insert completed total rows inserted: $inserted");
	//run the commit function to ensure the database saves the info
    $pdo->commit();
    // Log messages the raw was ok and the parsed data was inserted
    header("X-DB-Raw: OK");
    header("X-DB-Parsed: {$inserted}");
	//If anything should fail/error then run the rollback function to prevent issues on the database and then error
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log("[standings_parsed] load failed for {$season}: " . $e->getMessage());
    header("X-DB-Parsed: FAIL");
}

// Simple select query to return parsed standings for a given endpoint_key
//The current app only focuses on the key fields we felt are important
//It does convert to an xml since that is the output the our current app wants
//This will be changed later on to pull directly from the database and offer more flexiblity in responses
//For now this is a simple proof of concept that we can grab the data and filter them
try {
	mw_log("{$season}: preparing SELECT from standings sending query to DB");
    $stmt = $pdo->prepare("
        SELECT *
        FROM standings_parsed
        WHERE endpoint_key = :endpoint_key
        ORDER BY Division, DivisionRank, Percentage DESC
    ");
	mw_log("{$season}: executing SELECT query on DB");
    $stmt->execute([':endpoint_key' => $season]);

    header('Content-Type: application/xml; charset=UTF-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<ArrayOfStanding>\n";

	$rowCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$rowCount++;
        echo "  <Standing>\n";
        echo "    <Division>"     . htmlspecialchars($row['Division'])        . "</Division>\n";
        echo "    <Name>"         . htmlspecialchars($row['Name'])            . "</Name>\n";
        echo "    <Wins>"         . (int)$row['Wins']                          . "</Wins>\n";
        echo "    <Losses>"       . (int)$row['Losses']                        . "</Losses>\n";
        echo "    <Ties>"         . (int)$row['Ties']                          . "</Ties>\n";
        echo "    <Percentage>"   . number_format((float)$row['Percentage'],3,'.','') . "</Percentage>\n";
        echo "    <DivisionRank>" . (int)$row['DivisionRank']                  . "</DivisionRank>\n";
        echo "  </Standing>\n";
    }
	
    echo "</ArrayOfStanding>";
	mw_log("{$season}: SELECT query completed, fetched {$rowCount} rows sending to UI");
    exit;
} catch (Throwable $e) {
    error_log('[standings_select] ' . $e->getMessage());
    http_response_code(500);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<error>Failed to fetch standings</error>";
}

exit;
