<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$parking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get parking space details
$query = "SELECT * FROM parking_spaces WHERE id = :id AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $parking_id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    header('Location: index.php');
    exit();
}

// Get all bookings for this space
$bookings_query = "SELECT start_date, end_date, status, booking_reference 
                   FROM reservations 
                   WHERE parking_id = :parking_id 
                   AND status IN ('confirmed', 'active')
                   ORDER BY start_date";
$bookings_stmt = $db->prepare($bookings_query);
$bookings_stmt->bindParam(':parking_id', $parking_id);
$bookings_stmt->execute();
$bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Format bookings for FullCalendar
$events = [];
foreach ($bookings as $booking) {
    $events[] = [
        'title' => 'Booked',
        'start' => $booking['start_date'],
        'end' => $booking['end_date'],
        'color' => '#4F6EF7',
        'textColor' => 'white'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability Calendar - <?php echo htmlspecialchars($space['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 24px;
            color: #111827;
        }
        .header h1 small {
            font-size: 14px;
            color: #6B7280;
            font-weight: 400;
            margin-left: 10px;
        }
        .back-link {
            color: #4F6EF7;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 8px 16px;
            background: white;
            border-radius: 8px;
        }
        .calendar-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .info-box {
            background: #F3F4F6;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            display: flex;
            gap: 20px;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        .booked-color { background: #4F6EF7; }
        .available-color { background: #E5E7EB; }
        #calendar {
            min-height: 600px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                Availability Calendar
                <small><?php echo htmlspecialchars($space['name']); ?></small>
            </h1>
            <a href="parking-details.php?id=<?php echo $parking_id; ?>" class="back-link">← Back to Parking</a>
        </div>
        
        <div class="calendar-container">
            <div id="calendar"></div>
        </div>
        
        <div class="info-box">
            <div class="info-item">
                <div class="info-color booked-color"></div>
                <span>Booked</span>
            </div>
            <div class="info-item">
                <div class="info-color available-color"></div>
                <span>Available</span>
            </div>
        </div>
    </div>
    
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?php echo json_encode($events); ?>,
                height: 600,
                selectable: true,
                selectMirror: true,
                select: function(arg) {
                    if (confirm('Would you like to book this time slot?')) {
                        window.location.href = 'book.php?id=<?php echo $parking_id; ?>&start=' + arg.startStr + '&end=' + arg.endStr;
                    }
                    calendar.unselect();
                },
                eventClick: function(arg) {
                    alert('This time slot is already booked.');
                }
            });
            calendar.render();
        });
    </script>
</body>
</html>