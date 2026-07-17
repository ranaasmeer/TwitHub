<?php 
include '../core/init.php';
require_once '../core/classes/validation/Validator.php';
use validation\Validator;

if (isset($_POST['login']) && !empty($_POST['login'])) {
    
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    if(!empty($email) && !empty($password)) {
        $email = User::checkInput($email);
        $password = User::checkInput($password); 
    } 
    
    $v = new Validator; 
    $v->rules('email' , $email , ['required' , 'email']);
    $v->rules('password' , $password , ['required' , 'string']);
    $errors = $v->errors;
    
    if($errors == []) {
        // First, check if user exists and is not blocked
        $conn = Connect::connect();
        $stmt = $conn->prepare("SELECT id, password, is_blocked FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // ✅ Check if user is blocked
            if ($user['is_blocked'] == 1) {
                $_SESSION['errors'] = ['Your account has been blocked. Please contact the administrator.'];
                header('location: ../index.php');
                exit();
            }
            
            // Verify password (supports both bcrypt and MD5 for compatibility)
            $passwordValid = false;
            
            // Check if password is hashed with bcrypt
            if (password_verify($password, $user['password'])) {
                $passwordValid = true;
            } 
            // Check if password is MD5 (for backward compatibility)
            else if (md5($password) == $user['password']) {
                $passwordValid = true;
                // Optional: Rehash MD5 to bcrypt for better security
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE users SET password = :new_password WHERE id = :id");
                $updateStmt->execute(['new_password' => $newHash, 'id' => $user['id']]);
            }
            
            if ($passwordValid) {
                $_SESSION['user_id'] = $user['id'];
                header('location: ../home.php');
                exit();
            } else {
                $_SESSION['errors'] = ['The email or password is not correct'];
                header('location: ../index.php');
                exit();
            }
        } else {
            $_SESSION['errors'] = ['The email or password is not correct'];
            header('location: ../index.php');
            exit();
        }
    } else {
        $_SESSION['errors'] = $errors;
        header('location: ../index.php');
        exit();
    }
} else {
    header('location: ../index.php');
    exit();
}
?>