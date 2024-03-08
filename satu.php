
// existing code

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve form data
    $username = $_POST["username"];
    $password = $_POST["password"];

    // Validate login credentials
    if ($username == "admin" && $password == "password") {
        // Successful login
        echo "Login successful!";
        // Redirect to a different page or perform other actions
    } else {
        // Invalid login
        echo "Invalid username or password";
    }
}
?>

<form method="POST" action="<?php echo $_SERVER["PHP_SELF"]; ?>">
    <label for="username">Username:</label>
    <input type="text" id="username" name="username" required><br>

    <label for="password">Password:</label>
    <input type="password" id="password" name="password" required><br>

    <input type="submit" value="Login">
</form>

<?php
// remaining code