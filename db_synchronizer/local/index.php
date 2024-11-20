<?php
// Specify the path to the JSON config file
$jsonFile = 'conf.json'; // Replace with the actual path if necessary

// Inline CSS for styling
echo '<style>
    .left-align {
        text-align: left;
        margin-left: 0px;
    }
    .response {
        margin-top: 10px;
        padding: 10px;
        border: 1px solid #ddd;
        background-color: #f9f9f9;
    }
</style>';

// Check if the file exists
if (file_exists($jsonFile)) {
    // Read the JSON file
    $jsonContent = file_get_contents($jsonFile);

    // Decode the JSON content into a PHP array
    $config = json_decode($jsonContent, true);

    // Check if the JSON content was decoded successfully
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<h1>Ma Configuration Details</h1>";

        // Display the database configuration
        echo "<h2>Database Configuration</h2>";
        echo "Server: " . htmlspecialchars($config['database']['server']) . "<br>";
        echo "Database: " . htmlspecialchars($config['database']['db']) . "<br>";
        echo "Port: " . htmlspecialchars($config['database']['port']) . "<br>";
        echo "Username: " . htmlspecialchars($config['database']['username']) . "<br>";
        echo "Password: " . htmlspecialchars($config['database']['password']) . "<br>";
        echo "Table: " . htmlspecialchars($config['database']['table']) . "<br>";
        echo "Snapshot Table: " . htmlspecialchars($config['database']['snapshot_table']) . "<br>";
        echo "Primary Key: " . htmlspecialchars($config['database']['pk']) . "<br>";
        echo "Timestamp Column: " . htmlspecialchars($config['database']['timestamp_col']) . "<br>";

        // Other configuration
        echo "<h2>Other Configuration</h2>";
        echo "Time Zone: " . htmlspecialchars($config['database']['time_zone']) . "<br>";

        // First form for testing the database connection
        echo '<h2>Test Database Connection</h2>';
        echo '<form method="post">
                <input type="submit" name="test_connection" value="Test Database Connection">
              </form>';

        // Response field for database connection test
        if (isset($_POST['test_connection'])) {
            echo '<div class="response">';
            // Database connection settings
            $servername = $config['database']['server'];
            $username = $config['database']['username'];
            $password = $config['database']['password'];
            $dbname = $config['database']['db'];
            $port = $config['database']['port'];

            // Attempt to connect to the database
            $mysqli = @new mysqli($servername, $username, $password, $dbname, $port);

            // Check connection and display detailed error messages
            if ($mysqli->connect_errno) {
                switch ($mysqli->connect_errno) {
                    case 1045:
                        echo "<p style='color:red;'>Connection failed: Wrong credentials (username/password).</p>";
                        break;
                    case 2002:
                        echo "<p style='color:red;'>Connection failed: Server is unreachable or not responding.</p>";
                        break;
                    case 1049:
                        echo "<p style='color:red;'>Connection failed: Database '{$dbname}' not found.</p>";
                        break;
                    default:
                        echo "<p style='color:red;'>Connection failed: " . $mysqli->connect_error . " (Error Code: " . $mysqli->connect_errno . ")</p>";
                        break;
                }
            } else {
                echo "<p style='color:green;'>Successfully connected to the database!</p>";
            }

            // Close the connection
            $mysqli->close();
            echo '</div>';
        }

// Second form for testing the table, primary/unique key, and timestamp column
echo '<h2>Test Table, Primary/Unique Key, and Timestamp Column</h2>';
echo '<form method="post">
        <input type="submit" name="test_table" value="Test Table, Primary/Unique Key, and Timestamp Column">
      </form>';

    // Response field for table and primary/unique key test
    if (isset($_POST['test_table'])) {
        echo '<div class="response">';
        // Database connection settings
        $servername = $config['database']['server'];
        $username = $config['database']['username'];
        $password = $config['database']['password'];
        $dbname = $config['database']['db'];
        $port = $config['database']['port'];
        $table = $config['database']['table']; // The table to check
        $pk = $config['database']['pk']; // The primary key to check
        $timestamp_col = $config['database']['timestamp_col']; // Timestamp column to check

        // Attempt to connect to the database
        $mysqli = @new mysqli($servername, $username, $password, $dbname, $port);

        // Check connection
        if ($mysqli->connect_errno) {
            echo "<p style='color:red;'>Connection failed: " . $mysqli->connect_error . "</p>";
        } else {
            // Check if the table exists
            $result = $mysqli->query("SHOW TABLES LIKE '$table'");
            if ($result && $result->num_rows == 1) {
                echo "<p style='color:green;'>Table '$table' exists.</p>";

                // Check for primary or unique key
                $result_pk = $mysqli->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY' OR Non_unique = 0");
                if ($result_pk && $result_pk->num_rows > 0) {
                    $found = false;
                    while ($row = $result_pk->fetch_assoc()) {
                        if ($row['Column_name'] == $pk) {
                            echo "<p style='color:green;'>Table '$table' has the primary or unique key '$pk'.</p>";
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        echo "<p style='color:red;'>Table '$table' does not have the primary or unique key '$pk'.</p>";
                    }
                } else {
                    echo "<p style='color:red;'>No primary or unique key found for table '$table'.</p>";
                }

                // Check for the timestamp column
                $result_ts = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$timestamp_col'");
                if ($result_ts && $result_ts->num_rows > 0) {
                    $col_info = $result_ts->fetch_assoc();
                    $col_type = $col_info['Type'];
                    $col_default = strtolower($col_info['Default']);
                    $col_extra = strtolower($col_info['Extra']);

                    // Check if the column is of timestamp type, default to current_timestamp, and has ON UPDATE attribute
                    if (stripos($col_type, 'timestamp') !== false && 
                        ($col_default == 'current_timestamp' || $col_default == 'current_timestamp()') &&
                        (stripos($col_extra, 'on update current_timestamp') !== false || stripos($col_extra, 'on update current_timestamp()') !== false)) {
                        echo "<p style='color:green;'>Column '$timestamp_col' is of type TIMESTAMP, defaults to CURRENT_TIMESTAMP, and has ON UPDATE CURRENT_TIMESTAMP.</p>";
                    } else {
                        echo "<p style='color:red;'>Column '$timestamp_col' exists but does not match the required properties.</p>";
                        echo "<p>Type: $col_type, Default: $col_default, Extra: $col_extra</p>";
                    }
                } else {
                    echo "<p style='color:red;'>Column '$timestamp_col' does not exist in table '$table'.</p>";
                }

            } else {
                echo "<p style='color:red;'>Table '$table' does not exist.</p>";
            }
        }

        // Close the connection
        $mysqli->close();
        echo '</div>';
    }
    } else {
        echo "Error decoding JSON: " . json_last_error_msg();
    }
} else {
    echo "Configuration file not found.";
}
?>
