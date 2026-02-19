<?php
if (!file_exists('img')) {
    mkdir('img', 0777, true);
}
$img = imagecreate(400, 300);
$bg = imagecolorallocate($img, 243, 244, 246); // #F3F4F6
$text_color = imagecolorallocate($img, 107, 114, 128); // #6B7280

imagestring($img, 5, 150, 140, 'No Image', $text_color);

imagejpeg($img, 'img/parking-placeholder.jpg', 90);
imagedestroy($img);

echo "Placeholder image created successfully!";
?>