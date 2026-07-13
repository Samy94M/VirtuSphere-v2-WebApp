<?php
session_start();

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/../lib/errors.php';
virtusphere_error_response_mode('json');

require_once '../mysql.php';
require_once '../function.php';

// ausgabe nur noch in Json
header('Content-Type: application/json');


# Credentials go straight to generateToken(), which uses a prepared statement
# for the name lookup and password_verify() for the hash. Do not HTML-encode
# them here: this is a JSON endpoint with no HTML context, and htmlspecialchars
# would corrupt passwords containing & < > " ' and break otherwise valid logins.
if (isset($_POST['password'])) {
   $username = isset($_POST['username']) ? (string) $_POST['username'] : '';
   $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
   $token = generateToken($username, $password, $connection);
   if($token == false) {
      echo json_encode('Access Forbidden');
      exit;

   } else {
      echo json_encode($token);
   } 
}

