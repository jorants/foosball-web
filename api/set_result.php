<?php
header("Content-Type: application/json; charset=UTF-8");

// Data should be a collection with:
// "players" => ["name1", ..., "name4"]
// "results" => [5, 3]  (score of name1+name2 , name3+name4)
// "start" => time
// "end" => time
// "replays => [...]
    // time is in SECONDS since 01.01.1970 00:00:00

function getPlayerFromName($pdo, $name) {
    $q = $pdo->prepare("SELECT id FROM players WHERE name=?");
    $q->execute(array($name));
    // Return the first result
    if($row = $q->fetch(PDO::FETCH_ASSOC)) {
        return $row['id'];
    }

    // Player does not exist; create it
    $q2 = $pdo->prepare("INSERT INTO players ('name') VALUES (?)");
    $q2->execute(array($name));

    // Original query to get the newly created id
    $q->execute(array($name));
    // Return the first result
    if($row = $q->fetch(PDO::FETCH_ASSOC)) {
        return $row['id'];
    }
    // ERROR
    return 0;
}

function getRatingsFromId($pdo, $pid) {
    $q = $pdo->prepare("SELECT player_id,atk_rating,def_rating,num_matches,matches_won,atk_matches,def_matches,last_played,active
        FROM player_ratings WHERE player_id = ?");
    $q->execute(array($pid));
    if($row = $q->fetch(PDO::FETCH_ASSOC)) {
        return $row;
    }
    // Return default values
    return array('player_id' => $pid,
        'atk_rating' => 1500.0,
        'def_rating' => 1500.0,
        'num_matches' => 0,
        'matches_won' => 0,
        'atk_matches' => 0,
        'def_matches' => 0,
        'last_played' => date(DATE_RFC3339),
        'active' => true);
}

// Map scores to a number in [0,1]
function scoreToValue($scoreblue, $scorered) {
    //if ($scoreblue == $scorered)
    //    return 0.5;

    //if ($scoreblue < $scorered)
    //    return $scoreblue / 20.0;
    //else
    //    return 1.0 - $scorered / 20.0;
    return $scoreblue / ($scoreblue + $scorered);
}

function process($data) {
    if ($data == NULL) {
        return array('result' => "Invalid request. JSON format expected.");
    }

    if (!file_exists("../db/results")) {
        mkdir("../db/results");
    }
    $handle = fopen("../db/results/result_" . date('Y_m_d_H_i_s') . ".json", 'w');
    if ($handle) {
        fwrite($handle, json_encode($data));
        fclose($handle);
    }

    if ( !isset($data['type'])
        || $data['type'] != "quickmatch"
        || count($data['results']) != 2
        || count($data['players']) != 4
        || empty($data['start'])
        || empty($data['end']) ) {
        return array('result' => 'Invalid request. "type" : "quickmatch" expected.');
    }

    require 'db.php';

//                       data "format"----------------------
    $dbValues = array(
        ":bluedef" => getPlayerFromName($pdo, $data['players'][0]),
        ":blueatk" => getPlayerFromName($pdo, $data['players'][1]),
        ":redatk"  => getPlayerFromName($pdo, $data['players'][2]),
        ":reddef"  => getPlayerFromName($pdo, $data['players'][3]),
        ":scoreblue" => $data['results'][0],
        ":scorered" => $data['results'][1],
        ":time" => date(DATE_RFC3339, $data['start']),
        ":duration" => ($data['end'] - $data['start']),
        ":season_id" => 1
    );

    $stmnt = $pdo->prepare("INSERT INTO matches
        ('bluedef','blueatk','redatk','reddef',
        'scoreblue','scorered','time','duration','season_id')
        VALUES (:bluedef, :blueatk, :redatk, :reddef,
        :scoreblue, :scorered, :time, :duration, :season_id)");

    if ($stmnt == false) {
        return array('result' => "PDO ERROR.", 'PDO' => $pdo->errorInfo());
    }

    $stmnt->execute($dbValues);

    $matchid = $pdo->lastInsertId();

    if (isset($data['replays'])) {
        foreach($data['replays'] as $r) {
            $stmnt = $pdo->prepare("INSERT INTO replays ('url','time','match_id') VALUES (:url, :time, :match_id)");
            if ($stmnt == false) {
                return array('result' => "PDO ERROR.", 'PDO' => $pdo->errorInfo() );
            }
            $stmnt->execute(array(':url' => $r['url'], ':time' => date(DATE_RFC3339, $r['time']), ':match_id' => $matchid));
        }
    }

    //
    // Elo computation
    //

    // Get current ratings
    // player_id,atk_rating,def_rating,num_matches,matches_won,atk_matches,def_matches,active
    $playerratings = array(
        'bluedef' => getRatingsFromId($pdo, $dbValues[':bluedef']),
        'blueatk' => getRatingsFromId($pdo, $dbValues[':blueatk']),
        'redatk'  => getRatingsFromId($pdo, $dbValues[':redatk']),
        'reddef'  => getRatingsFromId($pdo, $dbValues[':reddef'])
    );

    $matchrating = array();

    $won = array('blue' => 0, 'red' => 0);

    if ($dbValues[':scoreblue'] > $dbValues[':scorered'] ) {
        $won['blue']++;
        $pdo->exec("UPDATE statistics SET value = value + 1 WHERE key = 'bluewins'");
    }
    if ($dbValues[':scoreblue'] < $dbValues[':scorered'] ) {
        $won['red']++;
        $pdo->exec("UPDATE statistics SET value = value + 1 WHERE key = 'redwins'");
    }

    $teams = array('blue', 'red');
    $roles = array('atk', 'def');
    foreach($teams as $team) {
        foreach($roles as $role) {
            $pr = & $playerratings[$team . $role];
            $pr['num_matches']++;
            $pr['matches_won'] += $won[$team];
            $pr['active'] = true;
            $kFactor[$team . $role] = 80.0;
            if ($pr[$role . '_matches'] < 10) {
                $kFactor[$team . $role] = 160.0;
            }
	    $pr[$role . '_matches']++;
            $matchrating[$team . $role . '_rating'] = $pr[$role . '_rating'];
        }
    }

    $blueElo = 0.565 * $matchrating['bluedef_rating'] + 0.435 * $matchrating['blueatk_rating'];
    $redElo  = 0.565 * $matchrating['reddef_rating']  + 0.435 * $matchrating['redatk_rating'];

    $blueEloValue  = 1.0 / (1.0 + pow(10, ($redElo - $blueElo) / 400.0));
    $blueRealValue = scoreToValue($dbValues[':scoreblue'], $dbValues[':scorered']);

    $gainBlue = $blueRealValue - $blueEloValue;
    $gainRed  = - $gainBlue;

    $matchrating['blueatk_delta'] = $kFactor['blueatk'] * $gainBlue;
    $matchrating['bluedef_delta'] = $kFactor['bluedef'] * $gainBlue;
    $matchrating['redatk_delta']  = $kFactor['redatk']  * $gainRed;
    $matchrating['reddef_delta']  = $kFactor['reddef']  * $gainRed;

    $playerratings['blueatk']['atk_rating'] += $kFactor['blueatk']* $gainBlue;
    $playerratings['bluedef']['def_rating'] += $kFactor['bluedef']* $gainBlue;
    $playerratings['redatk']['atk_rating']  += $kFactor['redatk'] * $gainRed;
    $playerratings['reddef']['def_rating']  += $kFactor['reddef'] * $gainRed;

    $stmnt = $pdo->prepare("INSERT INTO 'match_ratings' ('match_id',
        'bluedef_rating','bluedef_delta','blueatk_rating','blueatk_delta',
        'redatk_rating','redatk_delta','reddef_rating','reddef_delta')
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmnt->execute(array($matchid,
        $matchrating['bluedef_rating'],$matchrating['bluedef_delta'],$matchrating['blueatk_rating'],$matchrating['blueatk_delta'],
        $matchrating['redatk_rating'],$matchrating['redatk_delta'],$matchrating['reddef_rating'],$matchrating['reddef_delta']));

    $stmnt = $pdo->prepare("REPLACE INTO 'player_ratings'
        ('player_id','atk_rating','def_rating',
        'num_matches', 'matches_won',
        'atk_matches','def_matches','last_played',
        'active')
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if ($stmnt == false) {
        print_r($pdo->errorInfo());
    } else {
        foreach($playerratings as $p) {
            $stmnt->execute(array($p['player_id'], $p['atk_rating'], $p['def_rating'], $p['num_matches'], $p['matches_won'], $p['atk_matches'], $p['def_matches'], date(DATE_RFC3339, $r['time']), true));
        }
    }

    return array('result' => "Match processed successfully.");
}


echo json_encode(process(json_decode(file_get_contents('php://input'),true)));
?>



