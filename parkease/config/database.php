<?php

define('ADMIN_EMAIL', 'admin@spacenode.com');

class Database {
    private $host = "localhost";
    private $port = "3307"; 
    private $db_name = "parkease_db";
    private $username = "root"; 
    private $password = ""; 
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $e) {
            echo "Connection error: " . $e->getMessage();
        }

        return $this->conn;
    }
}

// Only start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>