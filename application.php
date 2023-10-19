<?php
require_once "config.php";

// Constants
const COHERE_API_ENDPOINT = "https://api.cohere.com/generate";
const COHERE_API_KEY = "9n2XyF7AoqIyBde0JTi2xXW4hgW2Z2js1cZ35PFj"; // Replace with your Cohere API key
const NL2SQL_PROMPT = "
  System: You are an assistant that helps translate natural language queries from the user to SQL. You also give interpret the returned data and explain it in natural language to the end user.
  You have access to the following table in the database:
  { table name: 'SAILGP_SGP_STRM_PIVOT',
    table description: 'Each record holds the sensor values of one team/country for one particular moment (second) in the race'
    columns: [ 
      { column name: 'TIME_GRP', description: 'the time in the race (in seconds).' },
      { column name: 'B_NAME', description: 'team/country name, possible values are DEN, AUS, FRA, GBR, NZL, USA, ESP, JPN' },
      { column name: 'LENGTH_RH_BOW_MM', description: 'height of bow above the water' },
      { column name: 'LENGTH_RH_P_MM', description: 'height of port side above water' },
      { column name: 'LENGTH_RH_S_MM', description: 'height of starboard side above water' },
      { column name: 'TWA_SGP_DEG', description: 'angle of boat to the wind' },
      { column name: 'TWS_MHU_TM_KM_H_1', description: 'windspeed' },
      { column name: 'BOAT_SPEED_KNOTS', description: 'speed of boat in knots' },
      { column name: 'TIME_FOILING', description: '1=boat is foiling in this second of the race, 0=boat is not foiling in this second' },
      { column name: 'MANEUVER', description: 'whether boat is currently in a maneuver, values Y or N' },
      { column name: 'BDE_LEG_NUM_UNK', description: 'the number of the leg/track the boat is currently in. Value 7 means the race has ended (crossed the finish line).' }
  ] }
  DON'T ADD ANYTHING in the response that's not SQL. E.g. don't explain the SQL or give other comments. 
  User: When did each team first reach leg 7?
  Assistant: SELECT B_NAME, TIME_GRP FROM SAILGP_SGP_STRM_PIVOT WHERE BDE_LEG_NUM_UNK = 7 ORDER BY TIME_GRP ASC
  Database: The result is [{\"B_NAME\":\"GBR\",\"TIME_GRP\":703},{\"B_NAME\":\"AUS\",\"TIME_GRP\":739},{\"B_NAME\":\"ESP\",\"TIME_GRP\":764},{\"B_NAME\":\"NZL\",\"TIME_GRP\":766},{\"B_NAME\":\"FRA\",\"TIME_GRP\":768},{\"B_NAME\":\"DEN\",\"TIME_GRP\":789}]
  Assistant: Explanation - Great Britain, Australia, Spain, New Zealand, France and Denmark reached leg 7 in respectively 703, 739, 764, 766, 768 and 789 seconds.
  User: Which team achieved the highest speed?
  Assistant: SELECT B_NAME, MAX(BOAT_SPEED_KNOTS) FROM SAILGP_SGP_STRM_PIVOT GROUP BY B_NAME ORDER BY MAX(BOAT_SPEED_KNOTS) DESC LIMIT 1
  Database: The result is [{\"B_NAME\":\"GBR\",\"MAX(BOAT_SPEED_KNOTS)\":50.9363}]
  Assistant: Explanation - Team Great Britain achieved the highest boat speed of 50.9363 knots.
  User: ";

/* Some questions:
WORKS: WHAT WAS THE AVERAGE SPEEDS OF ALL TEAMS? INCLUDE REAL COUNTRY NAMES.
WORKS: WHICH TEAM HAD THE HIGHEST AVERAGE SPEED?
WORKS: WHAT WAS THE TIME (MINIMUM TIME) THAT EACH TEAM REACHED LEG 6? INCLUDE THE TEAM NAMES.

WORKS: How much time did each team foil?
SELECT SUM(TIME_FOILING) , B_NAME FROM SAILGP_SGP_STRM_PIVOT GROUP BY B_NAME
WORKS: Which team achieved the highest speed?
SELECT B_NAME FROM SAILGP_SGP_STRM_PIVOT WHERE BOAT_SPEED_KNOTS = (SELECT MAX(BOAT_SPEED_KNOTS) FROM SAILGP_SGP_STRM_PIVOT)

DOESN'T WORK: Which team won?
SELECT "B_NAME" FROM SAILGP_SGP_STRM_PIVOT WHERE "BDE_LEG_NUM_UNK" = 7
DOESN'T WORK: Which team reached leg 7 first?
SELECT "BDE_LEG_NUM_UNK" FROM SAILGP_SGP_STRM_PIVOT WHERE "BDE_LEG_NUM_UNK" = 7
WORKS: When did each team first reach leg 7? Include the team names.
SELECT TIME_GRP, B_NAME FROM SAILGP_SGP_STRM_PIVOT WHERE BDE_LEG_NUM_UNK = 7

NOTE: It makes a difference what casing you use. E.g. all letters in capitals give bad answers!
*/

// Function to call the Cohere API

const SUGGESTED_TEXTS = [
  "How much time did each team foil?",
  "Which team achieved the highest speed?",
  "When did each team first reach leg 7? Include the team names."
];

function callCohereAPI($prompt)
{

  $ch = curl_init();

  $data = [
    'model' => 'command',
    'prompt' => $prompt,
    'temperature' => 0.0,
    'max_tokens' => 250,
    'stop_sequences' => ["Database:"],
  ];

  curl_setopt($ch, CURLOPT_URL, COHERE_API_ENDPOINT);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . COHERE_API_KEY,
    'Content-Type: application/json'
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  error_log('httpCode' . $httpCode);
  if (curl_errno($ch)) {
    error_log('Curl error: ' . curl_error($ch));
    throw new Exception('An error occurred while processing your request.');
  }

  curl_close($ch);

  if ($httpCode != 200) {
    error_log('Cohere API returned HTTP code ' . $httpCode . ' with response: ' . $response);
    throw new Exception('An error occurred while processing your request.');
  }

  $resultArray = json_decode($response, true);

  // Check if the text ends with "Database:" and remove it if it does
  if (substr($resultArray['text'], -9) === "Database:") {
    error_log('Removed stop sequence Database: from language model response');
    $resultArray['text'] = substr($resultArray['text'], 0, -9);
  }
  return $resultArray['text'];
}

function formatJSONasHTML($jsonData)
{
  $data = json_decode($jsonData, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    throw new Exception("Invalid JSON data provided.");
  }

  $result = "<table>";

  // Display headers
  if (!empty($data)) {
    $result .= "<tr class=\"theaderrow\">";
    foreach ($data[0] as $name => $value) {
      $result .= "<th class=\"theader\"><div class=\"theaderdiv\">" . htmlspecialchars($name) . "</div></th>";
    }
    $result .= "</tr>";

    // Display data
    foreach ($data as $row) {
      $result .= "<tr>";
      foreach ($row as $value) {
        $result .= "<td class=\"tcell\">" . htmlspecialchars($value) . "</td>";
      }
      $result .= "</tr>";
    }
  }

  $result .= "</table>";
  return $result;
}

function executeSQLAndGetJSON($link, $sqlStatement)
{
  $data = [];

  if ($stmt = $link->prepare($sqlStatement)) {
    $stmt->execute();

    // Check for SQL errors
    if ($stmt->errno) {
      throw new Exception("An error occurred while executing the SQL statement: " . $stmt->error);
    }

    // Get result metadata
    $meta = $stmt->result_metadata();
    if (!$meta) {
      throw new Exception("No result set returned by the SQL statement.");
    }

    // Dynamically bind the results
    while ($field = $meta->fetch_field()) {
      $var = $field->name;
      $$var = null;
      $fields[$var] = &$$var;
    }

    call_user_func_array([$stmt, 'bind_result'], $fields);

    // Fetch the results and store them in the data array
    while ($stmt->fetch()) {
      $row = [];
      foreach ($fields as $name => $value) {
        $row[$name] = $value;
      }
      $data[] = $row;
    }

    $stmt->close();
  } else {
    throw new Exception("Could not run SQL: " . $sqlStatement . " Error: " . $link->error);
  }

  return json_encode($data);
}

$error = null;
$sql = null;
$formattedData = '';
$jsonData = ''; // This will store the JSON data we get from the SQL query.
$naturalLanguageAnswer = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prompt'])) {
  // This code is executed when the user has submitted a text
  try {
    $prompt = NL2SQL_PROMPT . $_POST['prompt'] . "\nAssistant: SELECT ";
    error_log('$prompt' . $prompt);
    $sql = "SELECT " . callCohereAPI($prompt);
    error_log('$sql' . $sql);
    $jsonData = executeSQLAndGetJSON($link, $sql);
    error_log('$jsonData' . $jsonData);
    $formattedData = formatJSONasHTML($jsonData);
    $followUpPrompt = $prompt . "\nDatabase: The result is" . $jsonData . "\nAssistant: Explanation - ";
    error_log('$followUpPrompt' . $followUpPrompt);
    $naturalLanguageAnswer = callCohereAPI($followUpPrompt);
    error_log('$naturalLanguageAnswer' . $naturalLanguageAnswer);
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}
?>

<!DOCTYPE html>
<html>

<head>
  <link rel="stylesheet" type="text/css" href="styles.css">
  <script>
    function populateInput(text) {
      document.getElementById('natural-language-question').value = text;
    }
  </script>
</head>

<body class="bodystyles">
  <div class="header">
    <div class="headertop">
      <img src="logo.png" alt="Logo" />
      <div class="spacer"></div>
      <div class="buttons">
        <div class="action-button red">TICKETS</div>
        <div class="action-button striped">THE DOCK</div>
        <div class="action-button white">
          MENU&nbsp;&nbsp;<svg stroke="currentColor" fill="currentColor" stroke-width="0" class="c-button-menu__icon"
            height="1em" width="1em" viewBox="0 0 24 24">
            <path
              d="M 0 0 L 24 0 L 24 2.18182 L 0 2.18182 L 0 0 Z M 0 10.9091 L 24 10.9091 L 24 13.0909 L 0 13.0909 L 0 10.9091 Z M 0 21.8182 L 24 21.8182 L 24 24 L 0 24 L 0 21.8182 Z">
            </path>
          </svg>
        </div>
      </div>
    </div>
    <div class="headerbottom">
      <div class="breadcrumb">FAN SECTION</div>
      <div class="slash"></div>
      <div class="breadcrumb selected">ASK US</div>
    </div>
  </div>
  <div class="content">
    <div class="title">What is your question?</div>
    <form action="" method="post">
      <input class="forminput" type="textarea" name="prompt" id="natural-language-question"
        placeholder="<Type your question here>" required
        value="<?php echo isset($_POST['prompt']) ? $_POST['prompt'] : ''; ?>" />
      <div class="suggested-texts">
        <?php foreach (SUGGESTED_TEXTS as $text): ?>
          <div class="suggestion" onclick="populateInput('<?php echo $text; ?>')">
            <?php echo $text; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <br />
      <input type="submit" value="Submit" class="buttonstyle" />
    </form>

    <script>
      window.addEventListener("DOMContentLoaded", (event) => {
        document.getElementById("natural-language-question").focus();
      });
    </script>

    <?php
    if (isset($error)) {
      echo "<div>";
      echo "<h2>An error occurred:</h2>";
      echo "<p>" . htmlspecialchars($error) . "</p>";
      echo "</div>";
    }
    if (!isset($error)) {
      if (isset($naturalLanguageAnswer)) {
        echo "<div>";
        echo "<p><b>" . $naturalLanguageAnswer . "</b></p>";
        echo "</div>";
      }
    }
    if (!isset($error)) {
      if (isset($formattedData)) {
        echo "<div>";
        echo "<p>FYI, I executed the following SQL: " . htmlspecialchars($sql) . "</p>";
        echo "<p>This is what the database returned:</p>";
        echo "<p>" . $formattedData . "</p>";
        echo "</div>";
      }
    }
    ?>
    

  </div>
  <div class="footer">
    <div class="footerImages">
      <img src="logo.png" alt="SailGP Logo Web" />
      &nbsp;
      <img src="world_sailing.svg" style="filter: brightness(0) invert(1)" alt="World Sailing Logo" />
    </div>
  </div>
</body>

</html>