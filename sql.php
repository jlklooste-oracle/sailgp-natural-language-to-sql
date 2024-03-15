<?php
include 'init.php'; // Include the constants file

// Database credentials
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($link === false) {
    die("ERROR: Could not connect. " . mysqli_connect_error());
}
/*echo 'Successfull Connect.';
echo 'Host info: ' . mysqli_get_host_info($link);*/
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

/*
function executeSQLAndGetJSON($link, $sqlStatement)
{
    $data = [
        ['ID' => 1, 'Name' => 'John Doe', 'Age' => 30, 'City' => 'New York', 'Occupation' => 'Software Engineer'],
        ['ID' => 2, 'Name' => 'Jane Smith', 'Age' => 25, 'City' => 'Los Angeles', 'Occupation' => 'Graphic Designer'],
        ['ID' => 3, 'Name' => 'Emily Johnson', 'Age' => 28, 'City' => 'Chicago', 'Occupation' => 'Data Analyst'],
        ['ID' => 4, 'Name' => 'Michael Brown', 'Age' => 35, 'City' => 'Houston', 'Occupation' => 'Project Manager'],
        ['ID' => 5, 'Name' => 'Sarah Davis', 'Age' => 32, 'City' => 'Phoenix', 'Occupation' => 'Web Developer']
    ];

    return json_encode($data);
}
$link = "dummy";
*/

try {
    // Read the JSON payload from the request (the input from the user)
    $payload = json_decode(file_get_contents('php://input'), true);
    $sql = $payload['sql'] ?? ''; // Adjust this key based on the actual structure of your JSON payload
    $response = executeSQLAndGetJSON($link, $sql);
    $responseJSON = json_encode(['error' => false, 'output' => $response]);
} catch (Exception $e) {
    $responseJSON = json_encode(['error' => true, 'output' => $e->getMessage()]);
}
header('Content-Type: application/json');
echo $responseJSON;
exit;
