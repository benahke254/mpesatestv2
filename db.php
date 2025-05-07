<?php
// Database credentials
$host = 'sql5.freesqldatabase.com';
$user = 'sql5777359';
$password = 'YQ8SA8yu2p';
$dbname = 'sql5777359';

// Create a connection
$conn = new mysqli($host, $user, $password, $dbname);

// Check the connection
if ($conn->connect_error) {
    // If connection fails
    echo "<div style='text-align:center; margin-top:50px; padding:20px; background-color:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:5px;'>";
    echo "<strong>Error: </strong> Database connection failed: " . $conn->connect_error;
    echo "</div>";
} else {
    // If connection is successful
    echo "<div style='text-align:center; margin-top:50px; padding:20px; background-color:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:5px;'>";
    echo "<strong>Success:</strong> Database connection successful!";
    echo "</div>";
}

$conn->close();
?>
