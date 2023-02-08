<!DOCTYPE html>
<html lang="en">
    <head>
        <title>SQL Injection vulnerability demo.</title>
        <meta content="text/html; charset=utf-8" />
    </head>
<body>
<?php
    if(isset($_POST['login']))
    { // this is a comment
        $username = $_POST['username'];
        $password = $_POST['password'];
        $con = mysqli_connect('localhost','tajul','^lyVz}t5','sqlidb');
        $result = mysqli_query($con, "SELECT * FROM `users` WHERE username='$username' AND password='$password'");
        if(mysqli_num_rows($result) == 0)
            echo 'Invalid username or passwd';
        else
            echo '<h1>Logged in</h1><p>This is text that should only be displayed when logged in with valid credentials.</p>';
    }
    else
    {
?>
        <form action="" method="post">
            Username: <input type="text" name="username"/><br />
            Password: <input type="password" name="password"/><br />
            <input type="submit" name="login" value="Login"/>
        </form>
<?php
    }
?>
</body>
</html>