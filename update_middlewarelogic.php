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

// Read query string parameters
$season   = $_GET['season']  ?? '';    // currently not using this to hardcode it later
$mode     = $_GET['mode']    ?? '';
$keyword  = $_GET['keyword'] ?? '';    // New: keyword passed from UI ("standings", etc.)

// Basic validation for required params
if ($mode === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing required parameter: mode"]);
    exit;
}

if ($keyword === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing required parameter: keyword"]);
    exit;
}

// This mapping converts a keyword from the UI into a SportsData.io endpoint name.
// For now, your app uses only "standings" → "Standings".
$keywordToEndpoint = [
    'standings' => 'Standings',
	'player' => 'PlayersByAvailable',
	'players' => 'PlayersByAvailable',
];
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

// Normalize keyword from UI ("Standings", "standings", "STANDINGS", etc.)
$keywordLower = strtolower(trim($keyword));

if (!isset($keywordToEndpoint[$keywordLower])) {
    http_response_code(400);
    echo json_encode([
        "error"   => "Unsupported keyword",
        "keyword" => $keyword,
    ]);
    exit;
}

$endpoint = $keywordToEndpoint[$keywordLower];

//Hardcode the season value for now we only want 2024REG
//How the program works is the xml api returns only up to date data so calling every time will always be up the latest season info
$season = "2024REG";

mw_log("{$season}: starting middleware with mode={$mode}, keyword={$keyword}, endpoint={$endpoint}");

// Only support the standings mode for now
if ($mode !== 'standings') {
    http_response_code(400);
    echo json_encode(["error" => "Unsupported mode. Currently only 'standings' is implemented."]);
    exit;
}

//Call the nfl api using the key set aside from earlier
$apiKey = '3751b77068144fee9a5e5e5058ff5eb1';

//Better base url to the api where adding the season is a later on option
$baseUrl = "https://api.sportsdata.io/v3/nfl/scores/xml";

//Endpoints that require a season
$seasonRequiredEndpoints = ['Standings'];

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

// If test fails → return error to UI
if ($httpCode !== 200 || $body === false) {
    mw_log("Endpoint test FAILED for {$endpoint}: HTTP {$httpCode}, cURL: {$curlErr}");

    http_response_code(502);
    echo "ERROR: Endpoint {$endpoint} test failed (HTTP {$httpCode})";
    exit;
}

mw_log("Endpoint {$endpoint} SUCCESS.");

// ---------------------------
// Parse XML
// ---------------------------
$xml = @simplexml_load_string($body);
if ($xml === false) {
    http_response_code(500);
    mw_log("{$season}: ERROR - Failed to parse XML from API for endpoint {$endpoint}");
    echo json_encode(["error" => "Failed to parse XML from API"]);
    exit;
}

// Raw XML string for caching
$xmlResponse = $body;

if ($endpoint === 'Standings') {
    // ==========================================
    // Standings: cache → standings_raw_data, then upsert → standings_parsed
    // ==========================================

    // 1) Cache raw XML into standings_raw_data (key = season, e.g. 2024REG)
    try {
        mw_log("{$season}: caching raw XML into standings_raw_data with key={$season}");

        $stmt = $pdo->prepare("
        INSERT INTO standings_raw_data (endpoint_key, xml_data, last_updated)
        VALUES (:key, :xml, NOW())
        ON DUPLICATE KEY UPDATE xml_data = VALUES(xml_data), last_updated = NOW();
    ");

        $stmt->execute([
            ':key' => $season,
            ':xml' => $xmlResponse,
        ]);

        mw_log("{$season}: raw XML cache successful for standings_raw_data");
    } catch (Throwable $e) {
        mw_log("standings_raw_data insert/update failed for key={$season}: " . $e->getMessage());
        // Do not abort; continue on to parsed upsert.
    }

    // 2) Upsert into standings_parsed
    mw_log("{$season}: starting upsert into standings_parsed");

    try {
        $pdo->beginTransaction();

        $inserted = 0;
        $updated  = 0;

        // SportsData standings: root is ArrayOfStanding, children <Standing>
        foreach ($xml->Standing as $s) {

            $data = [
                ':endpoint_key'      => $season,                       // same for all standings rows
                ':SeasonType'        => (int)($s->SeasonType ?? 0),
                ':Season'            => (int)($s->Season ?? 0),
                ':Conference'        => (string)($s->Conference ?? ''),
                ':Division'          => (string)($s->Division ?? ''),
                ':Team'              => (string)($s->Team ?? ''),      // abbrev
                ':Name'              => (string)($s->Name ?? ''),

                ':Wins'              => (int)($s->Wins ?? 0),
                ':Losses'            => (int)($s->Losses ?? 0),
                ':Ties'              => (int)($s->Ties ?? 0),
                ':Percentage'        => (float)($s->Percentage ?? 0),

                ':PointsFor'         => (int)($s->PointsFor ?? 0),
                ':PointsAgainst'     => (int)($s->PointsAgainst ?? 0),
                ':NetPoints'         => (int)($s->NetPoints ?? 0),
                ':Touchdowns'        => (int)($s->Touchdowns ?? 0),

                ':DivisionWins'      => (int)($s->DivisionWins ?? 0),
                ':DivisionLosses'    => (int)($s->DivisionLosses ?? 0),
                ':ConferenceWins'    => (int)($s->ConferenceWins ?? 0),
                ':ConferenceLosses'  => (int)($s->ConferenceLosses ?? 0),

                ':TeamID'            => (int)($s->TeamID ?? 0),
                ':DivisionTies'      => (int)($s->DivisionTies ?? 0),
                ':ConferenceTies'    => (int)($s->ConferenceTies ?? 0),

                ':GlobalTeamID'      => (int)($s->GlobalTeamID ?? 0),
                ':DivisionRank'      => (int)($s->DivisionRank ?? 0),
                ':ConferenceRank'    => (int)($s->ConferenceRank ?? 0),

                ':HomeWins'          => (int)($s->HomeWins ?? 0),
                ':HomeLosses'        => (int)($s->HomeLosses ?? 0),
                ':HomeTies'          => (int)($s->HomeTies ?? 0),

                ':AwayWins'          => (int)($s->AwayWins ?? 0),
                ':AwayLosses'        => (int)($s->AwayLosses ?? 0),
                ':AwayTies'          => (int)($s->AwayTies ?? 0),

                ':Streak'            => (string)($s->Streak ?? '')
            ];

            // 1) Check if row already exists for this (endpoint_key, Team)
            $sqlCheck = "
                SELECT 1
                FROM standings_parsed
                WHERE endpoint_key = :endpoint_key
                  AND Team         = :Team
                LIMIT 1
            ";

            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([
                ':endpoint_key' => $data[':endpoint_key'],
                ':Team'         => $data[':Team'],
            ]);

            $rowExists = (bool)$stmtCheck->fetchColumn();

            if ($rowExists) {
                // UPDATE existing row
                $sqlUpdate = "
                    UPDATE standings_parsed SET
                        SeasonType        = :SeasonType,
                        Season            = :Season,
                        Conference        = :Conference,
                        Division          = :Division,
                        Name              = :Name,
                        Wins              = :Wins,
                        Losses            = :Losses,
                        Ties              = :Ties,
                        Percentage        = :Percentage,
                        PointsFor         = :PointsFor,
                        PointsAgainst     = :PointsAgainst,
                        NetPoints         = :NetPoints,
                        Touchdowns        = :Touchdowns,
                        DivisionWins      = :DivisionWins,
                        DivisionLosses    = :DivisionLosses,
                        ConferenceWins    = :ConferenceWins,
                        ConferenceLosses  = :ConferenceLosses,
                        TeamID            = :TeamID,
                        DivisionTies      = :DivisionTies,
                        ConferenceTies    = :ConferenceTies,
                        GlobalTeamID      = :GlobalTeamID,
                        DivisionRank      = :DivisionRank,
                        ConferenceRank    = :ConferenceRank,
                        HomeWins          = :HomeWins,
                        HomeLosses        = :HomeLosses,
                        HomeTies          = :HomeTies,
                        AwayWins          = :AwayWins,
                        AwayLosses        = :AwayLosses,
                        AwayTies          = :AwayTies,
                        Streak            = :Streak
                    WHERE endpoint_key = :endpoint_key
                      AND Team         = :Team
                ";

                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute($data);
                $updated++;
            } else {
                // INSERT new row
                $sqlInsert = "
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
                ";

                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute($data);
                $inserted++;
            }
        }

        $pdo->commit();
        mw_log("{$season}: standings_parsed upsert complete; inserted={$inserted}, updated={$updated}");

        // Build response for UI from standings_parsed
        $stmt = $pdo->prepare("
            SELECT *
            FROM standings_parsed
            WHERE endpoint_key = :endpoint_key
            ORDER BY Conference, Division, DivisionRank
        ");
        $stmt->execute([':endpoint_key' => $season]);
        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);

        mw_log("{$season}: UI response built with {$count} standings rows");

        echo json_encode([
            "count" => $count,
            "rows"  => $rows
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mw_log("{$season}: ERROR - standings_parsed upsert failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Failed to process standings"]);
        exit;
    }

} elseif ($endpoint === 'PlayersByAvailable') {
    // ==========================================
    // PlayersByAvailable: cache → players_available_raw, then upsert → players_free_agents
    // ==========================================

    // 1) Cache raw XML into players_available_raw (key = 'PlayersByAvailable')
    $playersCacheKey = 'PlayersByAvailable';

    try {
        mw_log("{$season}: caching raw XML into players_available_raw with key={$playersCacheKey}");

        $stmt = $pdo->prepare("
            INSERT INTO players_available_raw (cache_key, xml_data)
            VALUES (:key, :xml)
            ON DUPLICATE KEY UPDATE
                xml_data    = VALUES(xml_data),
                last_updated = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            ':key' => $playersCacheKey,
            ':xml' => $xmlResponse,
        ]);

        mw_log("{$season}: raw XML cache successful for players_available_raw");
    } catch (Throwable $e) {
        mw_log("players_available_raw insert/update failed for key={$playersCacheKey}: " . $e->getMessage());
        // Do not abort; continue on to parsed upsert.
    }

    // 2) Upsert into players_available_raw using PlayerID as UNIQUE key (id is auto-inc PK)
    mw_log("{$season}: starting upsert into players_available_parsed");

    try {
        $pdo->beginTransaction();

        $inserted = 0;
        $updated  = 0;
		
		// Each child node is <PlayerBasic>
        foreach ($xml->PlayerBasic as $p) {

            $data = [
                ':PlayerID'                          => (int)($p->PlayerID ?? 0),
                ':Team'                              => (string)($p->Team ?? ''),
                ':Number'                            => (int)($p->Number ?? 0),
                ':FirstName'                         => (string)($p->FirstName ?? ''),
                ':LastName'                          => (string)($p->LastName ?? ''),
                ':Position'                          => (string)($p->Position ?? ''),
                ':Status'                            => (string)($p->Status ?? ''),
                ':Height'                            => (string)($p->Height ?? ''),
                ':Weight'                            => (int)($p->Weight ?? 0),
                ':BirthDate'                         => (string)($p->BirthDate ?? ''),
                ':College'                           => (string)($p->College ?? ''),
                ':Experience'                        => (string)($p->Experience ?? ''),
                ':FantasyPosition'                   => (string)($p->FantasyPosition ?? ''),
                ':Active'                            => isset($p->Active) ? ((string)$p->Active === 'true' ? 1 : 0) : 0,
                ':PositionCategory'                  => (string)($p->PositionCategory ?? ''),
                ':Name'                              => (string)($p->Name ?? ''),
                ':Age'                               => (int)($p->Age ?? 0),
                ':ShortName'                         => (string)($p->ShortName ?? ''),
                ':HeightFeet'                        => (int)($p->HeightFeet ?? 0),
                ':HeightInches'                      => (int)($p->HeightInches ?? 0),
                ':TeamID'                            => (int)($p->TeamID ?? 0),
                ':GlobalTeamID'                      => (int)($p->GlobalTeamID ?? 0),
                ':UsaTodayPlayerID'                  => (int)($p->UsaTodayPlayerID ?? 0),
                ':UsaTodayHeadshotUrl'               => (string)($p->UsaTodayHeadshotUrl ?? ''),
                ':UsaTodayHeadshotNoBackgroundUrl'   => (string)($p->UsaTodayHeadshotNoBackgroundUrl ?? ''),
                ':UsaTodayHeadshotUpdated'           => (string)($p->UsaTodayHeadshotUpdated ?? ''),
                ':UsaTodayHeadshotNoBackgroundUpdated' => (string)($p->UsaTodayHeadshotNoBackgroundUpdated ?? ''),
            ];
			// 1) Check if row already exists for this
            $sqlCheck = "
                SELECT 1
                FROM players_available_parsed
                WHERE PlayerID = :PlayerID
                LIMIT 1
            ";
			$stmtCheck = $pdo->prepare($sqlCheck);
			$stmtCheck->execute([
                ':PlayerID' => $data[':PlayerID']
            ]);
			
			 $rowExists = (bool)$stmtCheck->fetchColumn();
        // Prepare single upsert statement (INSERT ... ON DUPLICATE KEY UPDATE)
        if ($rowExists) {
		// UPDATE existing row
            $sqlUpdate = "
            UPDATE players_available_parsed SET (
                PlayerID        = :PlayerID,
                Team            = :Team,
                Number          = :Number,
                FirstName       = :FirstName,
                LastName        = :LastName,
                Position        = :Position,
                Status          = :Status,
                Height          = :Height,
                Weight          = :Weight,
                BirthDate       = :BirthDate,
                College         = :College,
                Experience      = :Experience,
                FantasyPosition = :FantasyPosition,
                Active          = :Active,
                PositionCategory= :PositionCategory,
                Name            = :Name,
                Age             = :Age,
                ShortName       = :ShortName,
                HeightFeet      = :HeightFeet,
                HeightInches    = :HeightInches,
                TeamID          = :TeamID,
                GlobalTeamID    = :GlobalTeamID,
                UsaTodayPlayerID= :UsaTodayPlayerID,
                UsaTodayHeadshotUrl= :UsaTodayHeadshotUrl,
                UsaTodayHeadshotNoBackgroundUrl= :UsaTodayHeadshotNoBackgroundUrl,
                UsaTodayHeadshotUpdated= :UsaTodayHeadshotUpdated,
                UsaTodayHeadshotNoBackgroundUpdated= :UsaTodayHeadshotNoBackgroundUpdated
				WHERE PlayerID = :PlayerID
            ";

        } else {
                // INSERT new row
                $sqlInsert = "
                    INSERT INTO players_available_parsed
                    (`PlayerID`,`Team`,`Number`,`FirstName`,`LastName`,`Position`,`Status`,
                     `Height`,`Weight`,`BirthDate`,`College`,
                     `Experience`,`FantasyPosition`,`Active`,`PositionCategory`,
                     `Name`,`Age`,`ShortName`,`HeightFeet`,
                     `HeightInches`,`TeamID`,`GlobalTeamID`,
                     `UsaTodayPlayerID`,`UsaTodayHeadshotUrl`,`UsaTodayHeadshotNoBackgroundUrl`,
                     `UsaTodayHeadshotUpdated`,`UsaTodayHeadshotNoBackgroundUpdated`)
                    VALUES
                    (:PlayerID,:Team,:Number,:FirstName,:LastName,:Position,:Status,
                     :Height,:Weight,:BirthDate,:College,
                     :Experience,:FantasyPosition,:Active,:PositionCategory,
                     :Name,:Age,:ShortName,:HeightFeet,
                     :HeightInches,:TeamID,:GlobalTeamID,
                     :UsaTodayPlayerID,:UsaTodayHeadshotUrl,:UsaTodayHeadshotNoBackgroundUrl,
                     :UsaTodayHeadshotUpdated,:UsaTodayHeadshotNoBackgroundUpdated)
                ";

                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute($data);
                $inserted++;
            }
        }

        $pdo->commit();
        mw_log("PlayersByAvailable: upsert complete; inserted={$inserted}, updated={$updated}");

        // Build response for UI from players_free_agents
        $stmt = $pdo->query("
            SELECT *
            FROM players_available_parsed
            ORDER BY LastName, FirstName
        ");

        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);

        echo json_encode([
            "count" => $count,
            "rows"  => $rows
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mw_log("PlayersByAvailable: DB error - " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Failed to process players"]);
        exit;
    }

} else {
    http_response_code(500);
    echo json_encode(["error" => "Unhandled endpoint"]);
    exit;
}
