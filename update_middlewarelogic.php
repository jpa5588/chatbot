<?php
// middlewarelogic.php (simple: fetch → store raw → parse to table → return XML)

//Default xml header to send back to the ui server
header('Content-Type: application/json; charset=UTF-8');

//Prevents any extra info, like blank lines that exist outside of the xml, issue with that during testing
ob_start();

//Default db connection config to store database connection
require_once __DIR__ . '/db_config.php'; 

//Helper function to log events in error log
function mw_log(string $msg): void {
    error_log('[NFL_MW] ' . $msg);
}

$mode   = $_GET['mode'] ?? '';
$season = $_GET['season'] ?? '';
$keyword = $_GET['keyword'] ?? 'Standings';

// Hardcode season for standings mode. UI no longer controls this.
if ($mode === 'standings') {
    $season = '2024REG';   // The key you want to hit every single time
}

if ($mode !== 'standings') {
    http_response_code(400);
    echo "ERROR: Unsupported mode";
    exit;
}

$endpoint = ucfirst(strtolower($keyword));

// Only allow known-safe endpoints for now
$allowedEndpoints = ['Standings']; // later: 'Scores', 'Games', etc.
if (!in_array($endpoint, $allowedEndpoints, true)) {
    http_response_code(400);
    echo "ERROR: Unsupported keyword/endpoint.";
    exit;
}

//Attempt to connect to database using the config file from earlier using the pdo fucntion, pass in the parameters from the config
//The pdo fucntion is a prebuilt php function that allows connection to a mysql database
//Attempt to connect within 5 seconds, anything longer error their is an issue with the database connection
try {
	//$cfg = get_db_config();
	
    $pdo = new PDO($cfg['db_host'], $cfg['db_user'], $cfg['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: Database connection failed";
    exit;
}

//API key and url to store and use passing in the $endpoint value to call
$apiKey = '3751b77068144fee9a5e5e5058ff5eb1';
$baseUrl = "https://api.sportsdata.io/v3/nfl/scores/xml";

// Endpoints that require a season
$seasonRequiredEndpoints = ['Standings'];

//Test if Endpoint needs season or not
if (in_array($endpoint, $seasonRequiredEndpoints, true)) {

    if (empty($season)) {
        http_response_code(400);
        echo "Season is required for endpoint '{$endpoint}'.";
        exit;
    }

    // Append season ONLY for endpoints that need it
    $apiUrl = "{$baseUrl}/{$endpoint}/{$season}";

} else {

    // NO season for general endpoints
    $apiUrl = "{$baseUrl}/{$endpoint}";
}

mw_log("Testing endpoint {$endpoint} using URL: {$apiUrl}");

// Call external API via cURL
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Ocp-Apim-Subscription-Key: ' . $apiKey
    ],
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// If test fails → return error JSON to UI
if ($httpCode !== 200 || $body === false) {
    mw_log("Endpoint test FAILED for {$endpoint}: HTTP {$httpCode}, cURL: {$curlErr}");
    
    http_response_code(502);
    echo json_encode([
        "ok"       => false,
        "error"    => "Endpoint test failed",
        "endpoint" => $endpoint,
        "http"     => $httpCode,
        "curl"     => $curlErr,
    ]);
    exit;
}

// If test succeeds → continue into your real standings logic
mw_log("Endpoint test SUCCESS for {$endpoint}.");
// e.g. parse XML in $body, insert into DB, build JSON rows for UI, etc.

$xmlResponse = curl_exec($ch);

if ($xmlResponse === false) {
    http_response_code(502);
    echo "ERROR: Failed to call upstream API";
    exit;
}

//First line tells php to suppress xml error codes
//Then start loadings the xml from xmlresponse to test and verify if they are good xml formats
//If not then throw a 502 error telling us an issue with the xml
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlResponse);
if ($xml === false) {
    http_response_code(502);
    echo "ERROR: Invalid XML from upstream API";
    exit;
}

mw_log("{$season}: starting DB cache write into standings_raw_data");

//Once all the xml tests pass start storing the xml into the database
try {
	//$pdo->beginTransaction();
	
    // Unique per endpoint_key; update if it already exists
    $stmt = $pdo->prepare("
        INSERT INTO standings_raw_data (endpoint_key, xml_data, last_updated)
        VALUES (:key, :xml, NOW())
        ON DUPLICATE KEY UPDATE xml_data = VALUES(xml_data), last_updated = NOW();
    ");
	mw_log("{$season}: executing DB cache write query");
	
    $stmt->execute([
        ':key' => $season,
        ':xml'  => $xmlResponse,
    ]);
	
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
     mw_log("{$season}: DB error while inserting parsed standings - " . $e->getMessage());
    http_response_code(500);
    echo "ERROR: Failed to store standings in database";
    exit;
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

	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	
	$count = count($rows);
	
	mw_log("{$season}: SELECT query completed, fetched {$count} rows sending to UI");
	
    header('Content-Type: application/json; charset=UTF-8');
	echo json_encode([
        "count" => $count,
        "rows"  => $rows
    ]);
	
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    mw_log("{$season}: ERROR - Failed to fetch standings: " . $e->getMessage());
    echo json_encode(["error" => "Failed to fetch standings"]);
    exit;
}

exit;
