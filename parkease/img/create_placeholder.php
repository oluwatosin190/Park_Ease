<?php
// Create a simple placeholder image, all image that has no picture will use this by defaults guys
$img = imagecreate(400, 300);
$bg = imagecolorallocate($img, 243, 244, 246);
$text_color = imagecolorallocate($img, 107, 114, 128);
imagestring($img, 5, 150, 140, 'No Image', $text_color);
imagejpeg($img, 'parking-placeholder.jpg');
imagedestroy($img);
echo "Placeholder image created!";
?>