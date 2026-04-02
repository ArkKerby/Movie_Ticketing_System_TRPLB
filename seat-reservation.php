<?php
session_start();
// --- PHP LOGIC START ---
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$timings = ["10:30 AM", "12:30 PM", "3:00 PM", "05:30 PM", "06:30 PM", "08:30 PM", "9:30 PM", "10:30 PM"];
$selectedTime = $_GET['time'] ?? '10:30 AM'; // get selected time or default
$todayDate = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$maxSelectableDate = (new DateTime($todayDate, new DateTimeZone('Asia/Manila')))->modify('+30 days')->format('Y-m-d');
$selectedDate = $_GET['date'] ?? $todayDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $todayDate;
}
if (strtotime($selectedDate) < strtotime($todayDate)) {
    $selectedDate = $todayDate;
}
if (strtotime($selectedDate) > strtotime($maxSelectableDate)) {
    $selectedDate = $maxSelectableDate;
}

// Get movie from URL parameter
$movieTitle = $_GET['movie'] ?? null;
$branchName = $_GET['branch'] ?? null;

// Fetch movie details from database
$movie = null;
$moviePoster = 'images/default.png'; // default poster
$displayMovieTitle = 'Select Movie';

if ($movieTitle) {
    $stmt = $conn->prepare("SELECT movie_show_id, title, image_poster, trailer_youtube_id FROM MOVIE WHERE title = ? LIMIT 1");
    $stmt->bind_param("s", $movieTitle);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $movie = $result->fetch_assoc();
        $displayMovieTitle = htmlspecialchars($movie['title']);
        $moviePoster = !empty($movie['image_poster']) ? htmlspecialchars($movie['image_poster']) : 'images/default.png';
        $trailerYoutubeId = !empty($movie['trailer_youtube_id']) ? $movie['trailer_youtube_id'] : null;
    }
    $stmt->close();
}

// Get branch name if provided
$displayBranchName = 'Select Branch';
$branchId = null;
if ($branchName) {
    $displayBranchName = htmlspecialchars($branchName);
    // Get branch_id from branch name
    $stmt = $conn->prepare("SELECT branch_id FROM BRANCH WHERE branch_name = ? LIMIT 1");
    $stmt->bind_param("s", $branchName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $branchData = $result->fetch_assoc();
        $branchId = $branchData['branch_id'];
    }
    $stmt->close();
} else {
    // Default to SM Mall of Asia if no branch specified
    $displayBranchName = 'SM Mall of Asia';
    $stmt = $conn->prepare("SELECT branch_id FROM BRANCH WHERE branch_name = 'SM Mall of Asia' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $branchData = $result->fetch_assoc();
        $branchId = $branchData['branch_id'];
    }
    $stmt->close();
}

// Get cinema type and price from cinema_id parameter
$cinemaId = isset($_GET['cinema_id']) ? intval($_GET['cinema_id']) : null;
$cinemaName = '';
$cinemaPrice = 350; // default

if ($cinemaId) {
    $cinemaStmt = $conn->prepare("SELECT cinema_name, price FROM CINEMA_NUMBER WHERE cinema_number_id = ? LIMIT 1");
    $cinemaStmt->bind_param('i', $cinemaId);
    $cinemaStmt->execute();
    $cinemaRow = $cinemaStmt->get_result()->fetch_assoc();
    if ($cinemaRow) {
        $cinemaName = $cinemaRow['cinema_name'];
        $cinemaPrice = floatval($cinemaRow['price']);
    }
    $cinemaStmt->close();
}

// Check if MOVIE_SCHEDULE has branch_id column
$branchIdColumnCheck = $conn->query("SHOW COLUMNS FROM MOVIE_SCHEDULE LIKE 'branch_id'");
$movieScheduleHasBranchId = $branchIdColumnCheck && $branchIdColumnCheck->num_rows > 0;

// Get booked seats for the selected schedule
$bookedSeats = [];
if ($movie && $selectedTime) {
    // Convert time format from "10:30 AM" to "10:30:00" for database comparison
    $timeParts = date_parse($selectedTime);
    $timeFormatted = sprintf("%02d:%02d:00", $timeParts['hour'], $timeParts['minute'] ?? 0);
    
    // Get schedule_id for this movie, branch, date, and time
    if ($movieScheduleHasBranchId && $branchId) {
        $stmt = $conn->prepare("
            SELECT schedule_id 
            FROM MOVIE_SCHEDULE 
            WHERE movie_show_id = ? 
            AND branch_id = ? 
            AND show_date = ? 
            AND TIME(show_hour) = TIME(?)
            LIMIT 1
        ");
        $stmt->bind_param("iiss", $movie['movie_show_id'], $branchId, $selectedDate, $timeFormatted);
    } else {
        $stmt = $conn->prepare("
            SELECT schedule_id 
            FROM MOVIE_SCHEDULE 
            WHERE movie_show_id = ? 
            AND show_date = ? 
            AND TIME(show_hour) = TIME(?)
            LIMIT 1
        ");
        $stmt->bind_param("iss", $movie['movie_show_id'], $selectedDate, $timeFormatted);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $schedule = null;
    if ($result && $result->num_rows > 0) {
        $schedule = $result->fetch_assoc();
        $scheduleId = $schedule['schedule_id'];
        
        // Get all booked seats for this schedule from approved/pending bookings
        $column_check = $conn->query("SHOW COLUMNS FROM RESERVE LIKE 'booking_status'");
        $has_booking_status = $column_check && $column_check->num_rows > 0;
        
        if ($has_booking_status) {
            // Show seats from ALL bookings (pending, approved) - exclude only declined
            // This ensures seats are blocked immediately after booking, not just after approval
            $seatStmt = $conn->prepare("
                SELECT DISTINCT rs.seat_number
                FROM RESERVE r
                JOIN RESERVE_SEAT rs ON r.reservation_id = rs.reservation_id
                -- seat_number now in RESERVE_SEAT
                WHERE r.schedule_id = ? 
                AND (r.booking_status IS NULL OR r.booking_status = 'pending' OR r.booking_status = 'approved')
            ");
        } else {
            // If booking_status column doesn't exist, show all booked seats
            $seatStmt = $conn->prepare("
                SELECT DISTINCT rs.seat_number
                FROM RESERVE r
                JOIN RESERVE_SEAT rs ON r.reservation_id = rs.reservation_id
                -- seat_number now in RESERVE_SEAT
                WHERE r.schedule_id = ?
            ");
        }
        $seatStmt->bind_param("i", $scheduleId);
        $seatStmt->execute();
        $seatResult = $seatStmt->get_result();
        while ($row = $seatResult->fetch_assoc()) {
            $bookedSeats[] = $row['seat_number'];
        }
        $seatStmt->close();
    }
    $stmt->close();
}
// --- PHP LOGIC END ---

// PWD banner check
$showPwdBanner = false;
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    $bannerUserId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;
    if ($bannerUserId) {
        $bannerCheck = $conn->query("SHOW TABLES LIKE 'DISCOUNT_APPLICATIONS'");
        if ($bannerCheck && $bannerCheck->num_rows > 0) {
            $bannerStmt = $conn->prepare("SELECT status FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'pwd' ORDER BY submitted_at DESC LIMIT 1");
            $bannerStmt->bind_param("i", $bannerUserId);
            $bannerStmt->execute();
            $bannerRow = $bannerStmt->get_result()->fetch_assoc();
            $bannerStmt->close();
            if (!$bannerRow || !in_array($bannerRow['status'], ['pending', 'approved'])) {
                $showPwdBanner = true;
            }
        } else {
            $showPwdBanner = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title><?= $displayMovieTitle !== 'Select Movie' ? $displayMovieTitle . ' - ' : '' ?>Select Your Seat - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/seats.css" />
    <link rel="stylesheet" href="css/seat-reservation-food.css" />
    <style>
        .date-selector {
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .date-selector label {
            color: #fff;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .date-selector input[type="date"] {
            padding: 10px 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 0.95rem;
        }
        .date-selector input[type="date"]:focus {
            outline: none;
            border-color: #00BFFF;
            box-shadow: 0 0 0 2px rgba(0, 191, 255, 0.25);
        }
        .selected-date-info {
            margin-top: 10px;
            color: #cbd5e1;
            font-size: 0.9rem;
        }
        .selected-date-info strong {
            color: #fff;
        }
        /* Philippine time clock */
        .ph-clock {
            background: rgba(0,191,255,0.07);
            border: 1px solid rgba(0,191,255,0.2);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            text-align: center;
        }
        .ph-clock-label {
            font-size: 0.68rem;
            color: #f7f7f7ff;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }
        .ph-clock-time {
            font-size: 1.35rem;
            font-weight: 700;
            color: #00BFFF;
            letter-spacing: 1px;
            font-family: 'Courier New', monospace;
        }
        .ph-clock-date {
            font-size: 0.75rem;
            color: #ffffffff;
            margin-top: 2px;
        }
        /* Closed timing slots (within 20-minute cutoff) */
        #timing-list li.timing-closed {
            opacity: 0.4;
            cursor: not-allowed;
            text-decoration: line-through;
            pointer-events: auto; /* keep pointer events so click alert works */
            filter: grayscale(100%);
            position: relative;
        }
        #timing-list li.timing-closed::after {
            content: ' ✕ Closed';
            font-size: 0.7em;
            color: #ff6b6b;
            margin-left: 4px;
            text-decoration: none;
            display: inline;
        }
    </style>
</head>
<body>
    <?php if ($showPwdBanner): ?>
    <div id="pwdToast" style="
      position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-120px);
      background:linear-gradient(135deg,#1a2a4a,#0f1e38);
      border:1px solid rgba(85,138,206,0.45);
      border-radius:12px;
      padding:14px 22px;
      display:flex;align-items:center;justify-content:space-between;gap:16px;
      font-family:'Montserrat',sans-serif;font-size:0.88rem;
      color:rgba(255,255,255,0.9);
      box-shadow:0 8px 32px rgba(0,0,0,0.55),0 0 0 1px rgba(85,138,206,0.15);
      z-index:99999;
      min-width:320px;max-width:600px;width:90%;
      transition:transform 0.5s cubic-bezier(0.34,1.56,0.64,1),opacity 0.5s ease;
      opacity:0;
    ">
      <span>Have you submitted your PWD ID to have a 20% discount? <a href="profile.php#pwd" style="color:#7ab5ff;font-weight:600;text-decoration:underline;">Apply on your Profile.</a></span>
      <button onclick="dismissPwdToast()" style="background:none;border:none;color:rgba(255,255,255,0.45);cursor:pointer;font-size:1rem;padding:0 2px;line-height:1;flex-shrink:0;">x</button>
    </div>
    <script>
    (function(){
      var toast = document.getElementById('pwdToast');
      var dismissed = false;
      function showToast(){
        if(dismissed) return;
        toast.style.transform = 'translateX(-50%) translateY(0)';
        toast.style.opacity   = '1';
        setTimeout(hideToast, 4000);
      }
      function hideToast(){
        if(dismissed) return;
        toast.style.transform = 'translateX(-50%) translateY(-120px)';
        toast.style.opacity   = '0';
        setTimeout(showToast, 12000);
      }
      window.dismissPwdToast = function(){
        dismissed = true;
        toast.style.transform = 'translateX(-50%) translateY(-120px)';
        toast.style.opacity   = '0';
      };
      setTimeout(showToast, 800);
    })();
    </script>
    <?php endif; ?>
    <div class="container">
        <!-- Left Sidebar -->
        <aside class="timings">
            <button class="back-btn" onclick="window.location.href='TICKETIX NI CLAIRE.php'">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M15 8a.5.5 0 0 1-.5.5H3.707l3.147 3.146a.5.5 0 0 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L3.707 7.5H14.5A.5.5 0 0 1 15 8z"/>
                </svg>
                Back to Website
            </button>
            <div class="date-selector">
                <label for="datePicker">Select Date</label>
                <input type="date" id="datePicker" value="<?= htmlspecialchars($selectedDate) ?>" min="<?= htmlspecialchars($todayDate) ?>" max="<?= htmlspecialchars($maxSelectableDate) ?>">
            </div>
            <!-- Philippine Standard Time Clock -->
            <div class="ph-clock">
                <div class="ph-clock-label">Philippine Standard Time</div>
                <div class="ph-clock-time" id="ph-time">--:-- --</div>
                <div class="ph-clock-date" id="ph-date">---</div>
            </div>
            <h3>Available Timings</h3>
            <ul id="timing-list">
                <?php
                foreach ($timings as $time) {
                    $activeClass = ($time === $selectedTime) ? "active" : "";
                    echo "<li tabindex='0' role='button' class='$activeClass' data-time='$time'><span class='icon'></span> $time</li>";
                }
                ?>
            </ul>
        </aside>

        <!-- Seat Selection -->
        <main class="seat-selection">
            <h1>Select Your Seat</h1>
            <?php if ($cinemaName): ?>
            <div style="text-align:center; margin-bottom:10px; color:#00BFFF; font-size:0.95rem; font-weight:600;">
                <?= htmlspecialchars($cinemaName) ?> — ₱<?= number_format($cinemaPrice, 0) ?>/seat
            </div>
            <?php endif; ?>
            <div class="screen"></div>

            <div class="seats-wrapper">
                <?php
                // --- DYNAMIC SEAT LAYOUT PER CINEMA TYPE ---
                // Define seat layouts based on cinema type
                $cinemaCapacity = 100; // default
                $seatsPerRow = 10;
                $numRows = 10;

                if ($cinemaName) {
                    switch ($cinemaName) {
                        case "Director's Club":
                            $cinemaCapacity = 50;
                            $seatsPerRow = 10;
                            $numRows = 5;  // 5 × 10 = 50
                            break;
                        case 'IMAX':
                            $cinemaCapacity = 150;
                            $seatsPerRow = 15;
                            $numRows = 10; // 10 × 15 = 150
                            break;
                        case 'Regular':
                        default:
                            $cinemaCapacity = 120;
                            $seatsPerRow = 15;
                            $numRows = 8;  // 8 × 15 = 120
                            break;
                    }
                } else {
                    // Fallback when no cinema selected (use Regular layout)
                    $seatsPerRow = 15;
                    $numRows = 8;
                }

                $rowLetters = range('A', 'Z');
                $aisleAfter = intval($seatsPerRow / 2); // center aisle

                for ($r = 0; $r < $numRows; $r++):
                    $rowLetter = $rowLetters[$r];
                    echo '<div class="row"><div class="row-label">' . $rowLetter . '</div>';

                    for ($i = 1; $i <= $seatsPerRow; $i++):
                        // Add center aisle gap
                        if ($i == $aisleAfter + 1) {
                            echo '<div class="seat-gap"></div>';
                        }

                        $seatNumber = $rowLetter . '-' . $i;
                        $seatNumberAlt = $rowLetter . $i;
                        $isBooked = in_array($seatNumber, $bookedSeats) || in_array($seatNumberAlt, $bookedSeats);

                        $classes = "seat";
                        if ($isBooked) $classes .= " booked";

                        $disabled = $isBooked ? "aria-disabled='true' tabindex='-1'" : "tabindex='0' role='checkbox' aria-checked='false'";
                        $dataAttr = "data-seat='$rowLetter-$i'";

                        echo "<div class='seat-container'>";
                        echo "<div class='$classes' $disabled $dataAttr></div>";
                        echo "<span class='seat-label'>$seatNumber</span>";
                        echo "</div>";
                    endfor;
                    echo '</div>';
                endfor;
                // --- END DYNAMIC SEAT LAYOUT ---
                ?>
            </div>

            <button id="proceed-btn" class="proceed-btn" disabled>
                Proceed to checkout <span>→</span>
            </button>
        </main>

        <!-- Right Movie Info Panel -->
        <aside class="movie-info">
            <h3><?= $cinemaName ? htmlspecialchars($cinemaName) . ' — ' : '' ?><?= $displayBranchName ?></h3>
            <div class="movie-poster">
                <img src="<?= $moviePoster ?>" alt="<?= $displayMovieTitle ?> Poster" onerror="this.src='images/default.png'">
            </div>
            <?php if ($movieTitle): ?>
            <div class="movie-title-center">
                <p><?= $displayMovieTitle ?></p>
            </div>
            <?php endif; ?>
            <div class="selected-date-info">
                <span>Selected Date:</span>
                <strong><?= date('M d, Y', strtotime($selectedDate)) ?></strong>
            </div>

            <div class="food-section">
                <h4>Food Selection</h4>

                <!-- === DATABASE-DRIVEN FOOD GRID === -->
                <div class="food-grid">
                    <?php
                    $foodsQuery = $conn->query("SELECT * FROM FOOD");
                    if ($foodsQuery && $foodsQuery->num_rows > 0) {
                        while ($food = $foodsQuery->fetch_assoc()) {
                            echo "
                            <div class='food-item' data-item='{$food['food_name']}' data-food-id='{$food['food_id']}' data-food-price='{$food['food_price']}'>
                                <img src='{$food['image_path']}' alt='{$food['food_name']}'>
                                <div class='food-name'>{$food['food_name']}</div>
                                <div class='food-price'>₱{$food['food_price']}</div>
                                <div class='food-controls'>
                                    <button class='decrease'>−</button>
                                    <span class='count'>0</span>
                                    <button class='increase'>+</button>
                                </div>
                            </div>";
                        }
                    } else {
                        echo "<p>No foods available.</p>";
                    }
                    ?>
                </div>
                <!-- === END FOOD GRID === -->

            </div>
        </aside>
    </div>

    <script>
        // --- Philippine Standard Time Live Clock ---
        (function () {
            const timeEl = document.getElementById('ph-time');
            const dateEl = document.getElementById('ph-date');
            const timeFmt = new Intl.DateTimeFormat('en-PH', {
                timeZone: 'Asia/Manila',
                hour:     '2-digit',
                minute:   '2-digit',
                second:   '2-digit',
                hour12:   true
            });
            const dateFmt = new Intl.DateTimeFormat('en-PH', {
                timeZone:  'Asia/Manila',
                weekday:   'short',
                month:     'long',
                day:       'numeric',
                year:      'numeric'
            });
            function tick() {
                const now = new Date();
                if (timeEl) timeEl.textContent = timeFmt.format(now);
                if (dateEl) dateEl.textContent = dateFmt.format(now);
            }
            tick();
            setInterval(tick, 1000);
        })();

        // --- Timing Selection ---
        const selectedDate = '<?= htmlspecialchars($selectedDate, ENT_QUOTES) ?>';
        const CUTOFF_MINUTES = 20; // cannot book within this many minutes of showtime


        // Returns true if the given time string (e.g. "10:30 AM") is within 20 min
        // of the current moment, OR already in the past, on today's date.
        function isTimingClosed(timeStr) {
            // Derive today's date in Philippine Standard Time (not UTC)
            const manilaDate = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
            }).format(new Date()); // returns YYYY-MM-DD in PH time
            if (selectedDate !== manilaDate) return false; // future dates are always open

            // Parse the show time
            const match = timeStr.match(/(\d+):(\d+)\s*(AM|PM)/i);
            if (!match) return false;
            let h = parseInt(match[1], 10);
            const m = parseInt(match[2], 10);
            const period = match[3].toUpperCase();
            if (period === 'PM' && h !== 12) h += 12;
            if (period === 'AM' && h === 12) h = 0;

            const now = new Date();
            const showTime = new Date(now);
            showTime.setHours(h, m, 0, 0);

            const diffMs = showTime - now;
            const diffMinutes = diffMs / 60000;
            return diffMinutes <= CUTOFF_MINUTES; // closed if ≤ 20 min away (or past)
        }

        // Mark closed timings as disabled on page load
        const timingItems = document.querySelectorAll('#timing-list li');
        timingItems.forEach(item => {
            if (isTimingClosed(item.dataset.time || '')) {
                item.classList.add('timing-closed');
                item.setAttribute('aria-disabled', 'true');
                item.title = 'Booking closed — less than 20 minutes to showtime';
            }
        });

        timingItems.forEach(item => {
            item.addEventListener('click', () => {
                if (item.classList.contains('timing-closed')) {
                    alert('Booking for this time slot is closed. Bookings must be made at least 20 minutes before the show begins.');
                    return;
                }
                const selectedTimeValue = item.dataset.time;
                if (!selectedTimeValue) return;
                const url = new URL(window.location.href);
                url.searchParams.set('time', selectedTimeValue);
                const dateInput = document.getElementById('datePicker');
                if (dateInput && dateInput.value) {
                    url.searchParams.set('date', dateInput.value);
                }
                window.location.href = url.toString();
            });
        });

        // If the already-active timing is now closed (page was left open), warn the user
        const activeTimingOnLoad = document.querySelector('#timing-list li.active');
        if (activeTimingOnLoad && isTimingClosed(activeTimingOnLoad.dataset.time || '')) {
            activeTimingOnLoad.classList.add('timing-closed');
        }


        const datePicker = document.getElementById('datePicker');
        if (datePicker) {
            datePicker.addEventListener('change', function() {
                if (!this.value) return;
                const url = new URL(window.location.href);
                url.searchParams.set('date', this.value);
                const activeTime = document.querySelector('#timing-list li.active');
                if (activeTime && activeTime.dataset.time) {
                    url.searchParams.set('time', activeTime.dataset.time);
                }
                window.location.href = url.toString();
            });
        }

        // --- Seat Selection ---
        const seats = document.querySelectorAll('.seat:not(.booked)');
        const proceedBtn = document.getElementById('proceed-btn');
        let selectedSeats = new Set();

        // --- Click-and-Drag seat selection ---
        let isDragging = false;
        let dragAction = null; // 'select' or 'deselect'

        function updateProceedBtn() {
            proceedBtn.disabled = selectedSeats.size === 0;
            // Keep "View from Seat" button in sync (injected by seat-pov.php)
            var povBtn = document.getElementById('pov-trigger-btn');
            if (povBtn) {
                povBtn.disabled = selectedSeats.size === 0;
            }
        }

        function applySeatAction(seat) {
            const seatId = seat.getAttribute('data-seat');
            if (dragAction === 'select' && !seat.classList.contains('selected')) {
                seat.classList.add('selected');
                seat.setAttribute('aria-checked', 'true');
                selectedSeats.add(seatId);
            } else if (dragAction === 'deselect' && seat.classList.contains('selected')) {
                seat.classList.remove('selected');
                seat.setAttribute('aria-checked', 'false');
                selectedSeats.delete(seatId);
            }
            updateProceedBtn();
        }

        seats.forEach(seat => {
            // Start drag on mousedown
            seat.addEventListener('mousedown', (e) => {
                if (e.button !== 0) return; // only left-click
                e.preventDefault();
                isDragging = true;
                // First seat determines action: if it was selected, we deselect; otherwise select
                dragAction = seat.classList.contains('selected') ? 'deselect' : 'select';
                applySeatAction(seat);
            });

            // Continue drag on mouseover
            seat.addEventListener('mouseover', () => {
                if (isDragging) applySeatAction(seat);
            });

            // Touch support
            seat.addEventListener('touchstart', (e) => {
                isDragging = true;
                dragAction = seat.classList.contains('selected') ? 'deselect' : 'select';
                applySeatAction(seat);
            }, { passive: true });
        });

        // End drag on mouseup anywhere
        document.addEventListener('mouseup', () => { isDragging = false; dragAction = null; });

        // Touch drag: detect element under finger as it moves
        document.querySelector('.seats-wrapper').addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            const touch = e.touches[0];
            const el = document.elementFromPoint(touch.clientX, touch.clientY);
            if (el && el.classList.contains('seat') && !el.classList.contains('booked')) {
                applySeatAction(el);
            }
        }, { passive: true });

        document.addEventListener('touchend', () => { isDragging = false; dragAction = null; });

        // --- Food Quantity Controls ---
        const foodItems = document.querySelectorAll('.food-item');
        let foodSelections = {};
        const foodPrices = {};

        // Store food prices and IDs from data attributes
        foodItems.forEach(item => {
            const itemName = item.dataset.item;
            const foodId = item.dataset.foodId;
            const price = parseFloat(item.dataset.foodPrice || 0);
            foodPrices[itemName] = {price: price, id: foodId};
        });

        foodItems.forEach(item => {
            const increaseBtn = item.querySelector('.increase');
            const decreaseBtn = item.querySelector('.decrease');
            const countDisplay = item.querySelector('.count');
            const itemName = item.dataset.item;
            let count = 0;

            increaseBtn.addEventListener('click', () => {
                count++;
                countDisplay.textContent = count;
                foodSelections[itemName] = count;
            });

            decreaseBtn.addEventListener('click', () => {
                if (count > 0) count--;
                countDisplay.textContent = count;
                if (count === 0) delete foodSelections[itemName];
                else foodSelections[itemName] = count;
            });
        });

        // --- Proceed Button ---
        proceedBtn.addEventListener('click', () => {
            if (selectedSeats.size === 0) {
                alert('Please select at least one seat.');
                return;
            }

            const activeTimingElement = document.querySelector('#timing-list li.active');
            if (!activeTimingElement || !activeTimingElement.dataset.time) {
                alert('Please select a show time.');
                return;
            }

            // 🚫 20-minute cutoff check
            if (isTimingClosed(activeTimingElement.dataset.time)) {
                alert('Booking is now closed for this show. Bookings must be made at least 20 minutes before the show begins.');
                return;
            }

            const selectedTiming = activeTimingElement.dataset.time;

            // Calculate dynamic seat prices using the POV logic
            const selectedSeatNumbers = [...selectedSeats];
            const seatsData = selectedSeatNumbers.map(seatId => {
                return {
                    id: seatId,
                    tier: '<?= addslashes($cinemaName ?: "Standard") ?>',
                    price: <?= $cinemaPrice ?>
                };
            });

            // Calculate food total
            let foodTotal = 0;
            const foodData = [];
            Object.entries(foodSelections).forEach(([item, qty]) => {
                const foodInfo = foodPrices[item] || {price: 0, id: 0};
                const price = foodInfo.price;
                const subtotal = price * qty;
                foodTotal += subtotal;
                foodData.push({id: foodInfo.id, name: item, quantity: qty, price: price, subtotal: subtotal});
            });

            // Prepare data for checkout
            const checkoutData = {
                movie: '<?= $movieTitle ? urlencode($movieTitle) : "" ?>',
                branch: '<?= $branchName ? urlencode($branchName) : "" ?>',
                date: selectedDate,
                time: selectedTiming,
                seats: selectedSeatNumbers,
                seatsData: seatsData,
                cinemaId: <?= $cinemaId ? $cinemaId : 'null' ?>,
                cinemaName: '<?= addslashes($cinemaName) ?>',
                food: foodData,
                foodTotal: foodTotal
            };

            // Redirect to checkout page
            // Submit to procees_booking.php which handles login check
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'proceed-booking.php';
            
            const dataInput = document.createElement('input');
            dataInput.type = 'hidden';
            dataInput.name = 'booking_data';
            dataInput.value = JSON.stringify(checkoutData);
            form.appendChild(dataInput);
            
            document.body.appendChild(form);
            form.submit();
        });

        // Auto-forward to checkout if returned here after login

    </script>
    <?php include 'seat-pov.php'; ?>
</body>
</html>