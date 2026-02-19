<?php
$upload_dir = 'uploads/parking/';

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
    echo "Uploads directory created successfully!";
} else {
    echo "Uploads directory already exists.";
}
?>