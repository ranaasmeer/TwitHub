<?php 

class User extends Connect {
    
    protected static $pdo;

    public static function checkInput($input) {
        $input = htmlspecialchars($input);
        $input = trim($input);
        $input = stripslashes($input);
        return $input;
    }  

    public static function login($email, $password) {
        $stmt = self::connect()->prepare("SELECT `id`, `password` FROM `users` WHERE `email` = :email");
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        
        if ($stmt->rowCount() > 0) {
            // First try bcrypt verification (new passwords)
            if(password_verify($password, $user->password)) {
                $_SESSION['user_id'] = $user->id;
                header('location: ../home.php');
                return true;
            }
            
            // If bcrypt fails, try MD5 (for old users)
            $md5_password = md5($password);
            if($user->password == $md5_password) {
                // Old MD5 user - upgrade their password to bcrypt
                $new_hashed = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = self::connect()->prepare("UPDATE users SET password = :new_password WHERE id = :id");
                $update_stmt->execute([
                    'new_password' => $new_hashed,
                    'id' => $user->id
                ]);
                
                $_SESSION['user_id'] = $user->id;
                header('location: ../home.php');
                return true;
            }
            
            return false;
        } else {
            return false;
        }
    }

    public static function create($table, $fields = array()) {
        $colms = implode(',' , array_keys($fields));
        $values = ':' . implode(', :' , array_keys($fields));
        $sql = "INSERT INTO {$table} ({$colms}) VALUES ({$values})";
        $pdo = self::connect();
        $pdo->beginTransaction(); 
        if($stmt = $pdo->prepare($sql)) {
            foreach($fields as $key => $data) {
                $stmt->bindValue(':'. $key, $data);
            }
            if ($stmt->execute() === FALSE) {
                $pdo->rollback();
            } else {
                $user_id = $pdo->lastInsertId();
                $pdo->commit();
            }
            return $user_id;
        }
    }
    
    public static function register($email, $password, $name, $username) {
        $pdo = self::connect();
        $pdo->beginTransaction();      
        $stmt = $pdo->prepare("INSERT INTO `users` (`email`, `password`, `name`, `username`) VALUES (:email, :password, :name, :username)");

        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        // Use password_hash instead of md5 for new users
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bindParam(":password", $hashed_password, PDO::PARAM_STR); 
        $stmt->bindParam(":name", $name, PDO::PARAM_STR);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
    
        if ($stmt->execute() === FALSE) {
            $pdo->rollback();
            echo 'Unable to insert data';
            return false;
        } else {
            $user_id = $pdo->lastInsertId();
            $pdo->commit();
        }
        
        $_SESSION['user_id'] = $user_id;
        
        // make user follow the owner by default and notifications also
        date_default_timezone_set("Africa/Cairo");
        $data = [
            'follower_id' => $user_id, 
            'following_id' => 61, 
            'time' => date("Y-m-d H:i:s") 
        ];
        User::create('follow', $data);
        
        $data_notify = [
            'notify_for' => 61,
            'notify_from' => $user_id,
            'target' => 0, 
            'type' => 'follow',
            'time' => date("Y-m-d H:i:s"),
            'count' => '0', 
            'status' => '0'
        ];
        Tweet::create('notifications', $data_notify);

        $_SESSION['welcome'] = 'welcome';
        header('location: ../home.php');
        return true;
    } 
    
    public static function update($table, $user_id, $fields = array()) {
        $colms = '';
        $loopCount = 1;
        foreach ($fields as $name => $value) {
            $colms .= "`{$name}` = :{$name}";
            if($loopCount < count($fields)) {
                $colms .= ', ';
            }
            $loopCount++;  
        }
        $sql = "UPDATE {$table} SET {$colms} WHERE id = {$user_id}";
        $pdo = self::connect(); 
        if($stmt = $pdo->prepare($sql)) {
            foreach($fields as $key => $data) {
                $stmt->bindValue(':'. $key, $data);
            }
            $stmt->execute();
            return true;
        }
        return false;
    } 

    public static function delete($table, $array) {
        $sql = "DELETE FROM " . $table;
        $where = " WHERE ";
    
        foreach($array as $key => $value) {
            $sql .= $where . $key . " = " . $value . "";
            $where = " AND ";
        }
        $sql .= ";";
       
        $stmt = self::connect()->prepare($sql);
        $stmt->execute();
    }

    public static function getData($id) {
        $stmt = self::connect()->prepare("SELECT * from `users` WHERE `id` = :id");
        $stmt->bindParam(":id", $id, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
    public static function logout() {
        $_SESSION = array();
        session_destroy();
        header('location: ../index.php');
    }

    public static function checkEmail($email) {
        $stmt = self::connect()->prepare("SELECT `email` from `users` WHERE `email` = :email");
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return true;
        } else {
            return false;
        }
    } 
    
    public static function checkUserName($username) {
        $stmt = self::connect()->prepare("SELECT `username` from `users` WHERE `username` = :username");
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return true;
        } else {
            return false;
        }
    } 

    public static function checkLogIn() {
        if (isset($_SESSION['user_id'])) {
            return true;
        } else {
            return false;
        }
    }

    public static function getIdByUsername($username) {
        $stmt = self::connect()->prepare("SELECT `id` from `users` where `username` = :username");
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        return $user->id;
    }

    public static function getUserNameById($id) {
        $stmt = self::connect()->prepare("SELECT `username` from `users` where `id` = :id");
        $stmt->bindParam(":id", $id, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        return $user->username;
    }

    public static function search($search) {
        $stmt = self::connect()->prepare("SELECT `id`,`username`,`name`,`img`,`imgCover` FROM `users`
        WHERE `username` LIKE ? OR `name` LIKE ?");
        $stmt->bindValue(1, $search.'%', PDO::PARAM_STR);
        $stmt->bindValue(2, $search.'%', PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    public static function CountNotification($user_id) {
        $stmt = self::connect()->prepare("SELECT COUNT(notify_for) as count FROM `notifications`
        WHERE notify_for = :user_id AND count = 0");
        $stmt->bindParam(":user_id", $user_id, PDO::PARAM_STR);
        $stmt->execute();
        $u = $stmt->fetch(PDO::FETCH_OBJ);
        return $u->count;
    }
    
    public static function notification($user_id) {
        $stmt = self::connect()->prepare("SELECT * FROM `notifications`
        WHERE notify_for = :user_id ORDER BY time DESC");
        $stmt->bindParam(":user_id", $user_id, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    public static function updateNotifications($user_id) {
        $stmt = self::connect()->prepare("UPDATE `notifications` SET count = 1
        WHERE notify_for = :user_id AND count = 0");
        $stmt->bindParam(":user_id", $user_id, PDO::PARAM_STR);
        $s = $stmt->execute();
        if($s) {
            return true;
        } else {
            return false;
        }
    }
}