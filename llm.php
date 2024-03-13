<?php

// Constants
const OPENAI_API_ENDPOINT = "https://api.openai.com/v1/completions";
const OPENAI_API_KEY = "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"; //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"; //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"; // Replace with your OpenAI API key
const NL2SQL_PROMPT = "You are an assistant that helps translate natural language queries from the user to SQL.
  You interact with a database that returns you the result of the SQL.
  Next, you interpret the result from the database and explain it in natural language to the end user.
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
  User: When did each team first reach leg 7?
  Assistant: SELECT B_NAME, TIME_GRP FROM SAILGP_SGP_STRM_PIVOT WHERE BDE_LEG_NUM_UNK = 7 ORDER BY TIME_GRP ASC
  Database: The result is [{\"B_NAME\":\"GBR\",\"TIME_GRP\":703},{\"B_NAME\":\"AUS\",\"TIME_GRP\":739},{\"B_NAME\":\"ESP\",\"TIME_GRP\":764},{\"B_NAME\":\"NZL\",\"TIME_GRP\":766},{\"B_NAME\":\"FRA\",\"TIME_GRP\":768},{\"B_NAME\":\"DEN\",\"TIME_GRP\":789}]
  Assistant: Explanation - Great Britain, Australia, Spain, New Zealand, France and Denmark reached leg 7 in respectively 703, 739, 764, 766, 768 and 789 seconds.
  User: Which team achieved the highest speed?
  Assistant: SELECT B_NAME, MAX(BOAT_SPEED_KNOTS) FROM SAILGP_SGP_STRM_PIVOT GROUP BY B_NAME ORDER BY MAX(BOAT_SPEED_KNOTS) DESC LIMIT 1
  Database: The result is [{\"B_NAME\":\"GBR\",\"MAX(BOAT_SPEED_KNOTS)\":50.9363}]
  Assistant: Explanation - Team Great Britain achieved the highest boat speed of 50.9363 knots.
  User: ";

  // Function to call the OpenAI API
  function nl2sql($finalPrompt)
  {
      $ch = curl_init();
    
      //error_log("$finalPrompt" . $finalPrompt);
      $data = [
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => $finalPrompt,
        'temperature' => 0.0,
        'max_tokens' => 1000,
        'top_p' => 1,
        'frequency_penalty' => 0,
        'presence_penalty' => 0,
        'stop' => ["\r", "Database:", "User:"],
      ];
    
      curl_setopt($ch, CURLOPT_URL, OPENAI_API_ENDPOINT);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json'
      ]);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if (curl_errno($ch)) {
        error_log("error 1");
        throw new Exception('Curl error: ' . curl_error($ch));
      }
    
      error_log("httpCode" . $httpCode);
      curl_close($ch);
    
      if ($httpCode != 200) {
        throw new Exception('An error occurred while processing your request with HTTP code: ' . $httpCode);
      }
    
      $resultArray = json_decode($response, true);
      return $resultArray['choices'][0]['text'];
  }
  
  // Read the JSON payload from the request (the input from the user)
  $payload = json_decode(file_get_contents('php://input'), true);
  $prompt = $payload['prompt'] ?? ''; // Adjust this key based on the actual structure of your JSON payload
  $sql = $payload['sql'] ?? ''; // Adjust this key based on the actual structure of your JSON payload
  error_log("$prompt: " . $prompt);
  error_log("$sql: " . $sql);
  //error_log("prompt: " . prompt);
  $dataset = $payload['dataset'] ?? ''; // Adjust this key based on the actual structure of your JSON payload
  if ($sql === '') {
    //Construct the prompt to translate the natural language question to a SQL query
    $finalPrompt = NL2SQL_PROMPT . $prompt . "\r\nAssistant: SELECT";
    $response = "SELECT " . nl2sql($finalPrompt);
  }
  else
  {
    //Construct the prompt to take the output from the database and translate it into a natural language answer to the question
    $finalPrompt = NL2SQL_PROMPT . $prompt . "\r\nAssistant: " . $sql . "\r\nDatabase: " .$dataset . "\r\nAssistant: Explanation -";
    $response = nl2sql($finalPrompt);
  }
  //error_log("finalPrompt" . $finalPrompt);
  error_log("response" . $response);
  $responseJSON = json_encode(['output' => $response]);
  header('Content-Type: application/json');
  error_log("responseJSON" . $responseJSON);
  echo $responseJSON;
  //echo "<output>test</output>";
  exit;
