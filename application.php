<?php
require_once "config.php";

// Constants
const COHERE_API_ENDPOINT = "https://api.cohere.com/generate";
const COHERE_API_KEY = "9n2XyF7AoqIyBde0JTi2xXW4hgW2Z2js1cZ35PFj"; // Replace with your Cohere API key
//const PROMPT_PREFIX = "Translate the user query into an SQL statement. You only have access to one table named SAILGP_SGP_STRM_PIVOT. Column names are B_NAME (team/country name), BOAT_SPEED_KNOTS (speed) and TIME_GRP (number of seconds into the race). Your returned string must start with SELECT. DON'T ADD ANYTHING in the response that's not SQL. E.g. don't explain the SQL or give other comments. Here's the user query to translate to SQL: ";
const PROMPT_PREFIX = "
  Translate the user query into an SQL statement. 
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
  Your returned string must start with SELECT. 
  DON'T ADD ANYTHING in the response that's not SQL. E.g. don't explain the SQL or give other comments. 

  Example
  Input: What was the highest speed achieved?
  Output: SELECT MAX(BOAT_SPEED_KNOTS) FROM SAILGP_SGP_STRM_PIVOT

  Now it's your turn: 
  Input: ";

/* Some questions:
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
    'prompt' => PROMPT_PREFIX . $prompt,
    'temperature' => 0.0,
    'max_tokens' => 250
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
  //
  //foreach ($resultArray as $key => $value) {
  //  error_log("row");
  //  error_log("Key: $key, Value: $value");
  //}
  return $resultArray['text'];
}

// Function to execute SQL on database and return the result as a HTML table
function executeSQLAndGetFormattedData($link, $sqlStatement)
{
  $result = '';

  if ($stmt = $link->prepare($sqlStatement)) {
    $stmt->execute();

    // Check if there is an error in the SQL statement
    if ($stmt->errno) {
      throw new Exception("An error occurred while executing the SQL statement: " . $stmt->error);
    }

    // Get the result metadata
    $meta = $stmt->result_metadata();
    if (!$meta) {
      throw new Exception("No result set returned by the SQL statement.");
    }

    // Dynamically bind the results
    while ($field = $meta->fetch_field()) {
      $var = $field->name;
      $$var = null; // Create a variable with the column's name
      $fields[$var] = &$$var; // Create a reference to the variable
    }

    // Bind the results
    call_user_func_array([$stmt, 'bind_result'], $fields);

    $result .= "<table>";
    $result .= "<tr class=\"theaderrow\">";

    // Display the headers
    foreach ($fields as $name => $value) {
      $result .= "<th class=\"theader\"><div class=\"theaderdiv\">" . $name . "</div></th>";
    }

    $result .= "</tr>";

    // Fetch the results
    while ($stmt->fetch()) {
      $result .= "<tr>";
      foreach ($fields as $name => $value) {
        $result .= "<td class=\"tcell\">" . $value . "</td>";
      }
      $result .= "</tr>";
    }

    $result .= "</table>";

    $stmt->close();
  } else {
    throw new Exception("Could not run SQL: " . $sqlStatement . " Error: " . $link->error);
  }
  return $result;
}

$error = null;
$sql = null;
$formattedData = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prompt'])) {
  // This code is executed when the user has submitted a text
  try {
    $sql = callCohereAPI($_POST['prompt']);
    $formattedData = executeSQLAndGetFormattedData($link, $sql);
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
      if (isset($formattedData)) {
        echo "<div>";
        echo "<p>" . $formattedData . "</p>";
        echo "</div>";
      }
    }
    if (isset($sql)) {
      echo "<div class=\"footnote\">";
      echo "SQL used: " . htmlspecialchars($sql);
      echo "</div>";
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