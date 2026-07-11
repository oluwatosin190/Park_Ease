<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

$database = new Database();
$db = $database->getConnection();

$parking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($parking_id <= 0) {
    header('Location: index.php');
    exit();
}

// Get parking space details
$query = "SELECT id, name, address, city FROM parking_spaces WHERE id = :id AND is_active = 1";
try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $parking_id, PDO::PARAM_INT);
    $stmt->execute();
    $space = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Calendar view query error: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$space) {
    header('Location: index.php');
    exit();
}

// Get all bookings for this space with proper date formatting
$bookings_query = "SELECT start_date, end_date, status, booking_reference 
                   FROM reservations 
                   WHERE parking_id = :parking_id 
                   AND status IN ('confirmed', 'active')
                   AND end_date > NOW()
                   ORDER BY start_date";

try {
    $bookings_stmt = $db->prepare($bookings_query);
    $bookings_stmt->bindParam(':parking_id', $parking_id, PDO::PARAM_INT);
    $bookings_stmt->execute();
    $bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Calendar view bookings query error: " . $e->getMessage());
    $bookings = [];
}

// Format bookings for FullCalendar with proper date handling
$events = [];
foreach ($bookings as $booking) {
    // Validate dates
    if (empty($booking['start_date']) || empty($booking['end_date'])) {
        continue;
    }
    
    $events[] = [
        'title' => 'Booked',
        'start' => $booking['start_date'],
        'end' => $booking['end_date'],
        'color' => '#4F6EF7',
        'textColor' => 'white',
        'allDay' => false,
        'display' => 'block'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Availability Calendar - <?php echo sanitize($space['name'] ?? 'SpaceNode'); ?></title>
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
            flex-wrap: wrap;
            gap: 15px;
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
            transition: all 0.2s;
        }
        .back-link:hover {
            background: #F3F4F6;
            text-decoration: none;
        }
        .calendar-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        .info-box {
            background: #F3F4F6;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-color {
            width: 24px;
            height: 24px;
            border-radius: 6px;
        }
        .booked-color { 
            background: #4F6EF7; 
            box-shadow: 0 2px 4px rgba(79,110,247,0.2);
        }
        .available-color { 
            background: #E5E7EB; 
            border: 1px solid #D1D5DB;
        }
        .info-text {
            font-size: 14px;
            color: #374151;
        }
        #calendar {
            min-height: 600px;
        }
        
        /* Loading state */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #E5E7EB;
            border-top-color: #4F6EF7;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            .header h1 {
                font-size: 20px;
            }
            .header h1 small {
                display: block;
                margin-left: 0;
                margin-top: 5px;
            }
            .calendar-container {
                padding: 10px;
            }
            .fc .fc-toolbar {
                flex-direction: column;
                gap: 10px;
            }
            .info-box {
                gap: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .info-item {
                width: 100%;
            }
        }
        
        /* FullCalendar customizations */
        .fc-event {
            cursor: pointer;
            border-radius: 4px;
        }
        .fc-daygrid-day.fc-day-today {
            background-color: #EEF2FF;
        }
        .fc-daygrid-day-number {
            font-size: 14px;
            font-weight: 500;
        }
        .fc-col-header-cell-cushion {
            font-weight: 600;
            color: #374151;
        }
        .fc-button-primary {
            background-color: #4F6EF7 !important;
            border-color: #4F6EF7 !important;
        }
        .fc-button-primary:hover {
            background-color: #3a56d4 !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                Availability Calendar
                <small><?php echo sanitize($space['name'] ?? ''); ?></small>
            </h1>
            <a href="parking-details.php?id=<?php echo $parking_id; ?>" class="back-link">← Back to Parking</a>
        </div>
        
        <div class="calendar-container">
            <div id="calendar"></div>
        </div>
        
        <div class="info-box">
            <div class="info-item">
                <div class="info-color booked-color"></div>
                <span class="info-text">Booked (Unavailable)</span>
            </div>
            <div class="info-item">
                <div class="info-color available-color"></div>
                <span class="info-text">Available (Click to book)</span>
            </div>
            <div class="info-item">
                <span class="info-text">💡 Tip: Click on any available date to book</span>
            </div>
        </div>
    </div>
    
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            // Parse events from PHP (already JSON encoded)
            var events = <?php echo json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            
            // Check if user is logged in for booking
            var isLoggedIn = <?php echo isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) ? 'true' : 'false'; ?>;
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek'
                },
                events: events,
                height: 'auto',
                selectable: true,
                selectMirror: true,
                selectHelper: true,
                dayMaxEvents: true,
                weekends: true,
                nowIndicator: true,
                
                // Handle date selection
                select: function(arg) {
                    // Check if the selected date range is available
                    var isBooked = events.some(function(event) {
                        var eventStart = new Date(event.start);
                        var eventEnd = new Date(event.end);
                        var selectStart = new Date(arg.start);
                        var selectEnd = new Date(arg.end);
                        
                        // Check if selected range overlaps with any booked slot
                        return (selectStart < eventEnd && selectEnd > eventStart);
                    });
                    
                    if (isBooked) {
                        alert('Sorry, this time slot is already booked. Please select another date.');
                        calendar.unselect();
                        return;
                    }
                    
                    // Check if start date is in the past
                    var now = new Date();
                    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                    var selectedStart = new Date(arg.start);
                    
                    if (selectedStart < today) {
                        alert('Cannot book dates in the past. Please select a future date.');
                        calendar.unselect();
                        return;
                    }
                    
                    // Check if user is logged in
                    if (!isLoggedIn) {
                        if (confirm('Please log in to book this parking space. Would you like to log in now?')) {
                            window.location.href = 'login.php?redirect=book.php?id=<?php echo $parking_id; ?>&start=' + encodeURIComponent(arg.startStr) + '&end=' + encodeURIComponent(arg.endStr);
                        }
                        calendar.unselect();
                        return;
                    }
                    
                    // Prompt to confirm booking
                    var startDate = new Date(arg.start);
                    var endDate = new Date(arg.end);
                    var startFormatted = startDate.toLocaleString();
                    var endFormatted = endDate.toLocaleString();
                    
                    if (confirm('Would you like to book this parking space from:\n\n' + startFormatted + '\nto\n' + endFormatted + '\n\nClick OK to proceed to booking page.')) {
                        window.location.href = 'book.php?id=<?php echo $parking_id; ?>&start=' + encodeURIComponent(arg.startStr) + '&end=' + encodeURIComponent(arg.endStr);
                    }
                    calendar.unselect();
                },
                
                // Handle clicking on existing events
                eventClick: function(info) {
                    alert('This time slot is already booked.\n\nBooking Reference: ' + (info.event.extendedProps.booking_reference || 'Not available'));
                },
                
                // Customize event display
                eventContent: function(arg) {
                    return {
                        html: '<div style="padding: 2px 4px; font-size: 12px;">📅 Booked</div>'
                    };
                },
                
                // Set initial date to today
                defaultDate: new Date(),
                
                // Localization
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    week: 'Week'
                },
                
                // Loading state
                loading: function(isLoading) {
                    if (isLoading) {
                        // Could add loading indicator
                        console.log('Loading calendar...');
                    }
                }
            });
            
            calendar.render();
        });
    </script>
</body>
</html>