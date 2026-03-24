<?php
session_start();
require_once 'config.php';

// Get search query from URL parameter
$search_query = $_GET['q'] ?? '';
$search_results = [];

if (!empty($search_query)) {
    $conn = getDBConnection();
    $today = date('Y-m-d');
    
    // Search in movies (both now showing and coming soon)
    $search_query_escaped = $conn->real_escape_string($search_query);
    
    // Check if now_showing and coming_soon columns exist
    $columns_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'now_showing'");
    $has_now_showing = $columns_check && $columns_check->num_rows > 0;
    
    // Search in MOVIE table with proper status determination
    // Use the same logic as the main page to determine movie status
    if ($has_now_showing) {
        // If columns exist, use flags and schedule dates
        $movie_sql = "
        SELECT 
            m.movie_show_id,
            m.title,
            m.genre,
            m.duration,
            m.rating,
            m.movie_descrp,
            m.image_poster,
            m.now_showing,
            m.coming_soon,
            MIN(ms.show_date) AS show_date,
            MIN(ms.show_hour) AS show_hour,
            CASE 
                WHEN (m.coming_soon = TRUE) THEN 'coming-soon'
                WHEN (m.now_showing = TRUE OR EXISTS (
                    SELECT 1 
                    FROM MOVIE_SCHEDULE ms2 
                    WHERE ms2.movie_show_id = m.movie_show_id 
                    AND ms2.show_date >= '$today'
                )) THEN 'now-showing'
                WHEN EXISTS (
                    SELECT 1 
                    FROM MOVIE_SCHEDULE ms3 
                    WHERE ms3.movie_show_id = m.movie_show_id 
                    AND ms3.show_date > '$today'
                ) THEN 'coming-soon'
                ELSE 'coming-soon'
            END AS movie_status
        FROM MOVIE m
        LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
        WHERE (m.title LIKE '%$search_query_escaped%' 
           OR m.genre LIKE '%$search_query_escaped%' 
           OR m.movie_descrp LIKE '%$search_query_escaped%')
        GROUP BY 
            m.movie_show_id, 
            m.title, 
            m.genre, 
            m.duration, 
            m.rating, 
            m.movie_descrp, 
            m.image_poster,
            m.now_showing,
            m.coming_soon
        ORDER BY m.title;
        ";
    } else {
        // If columns don't exist, use schedule dates only
        $movie_sql = "
        SELECT 
            m.movie_show_id,
            m.title,
            m.genre,
            m.duration,
            m.rating,
            m.movie_descrp,
            m.image_poster,
            MIN(ms.show_date) AS show_date,
            MIN(ms.show_hour) AS show_hour,
            CASE 
                WHEN EXISTS (
                    SELECT 1 
                    FROM MOVIE_SCHEDULE ms2 
                    WHERE ms2.movie_show_id = m.movie_show_id 
                    AND ms2.show_date >= '$today'
                ) THEN 'now-showing'
                WHEN EXISTS (
                    SELECT 1 
                    FROM MOVIE_SCHEDULE ms3 
                    WHERE ms3.movie_show_id = m.movie_show_id 
                    AND ms3.show_date > '$today'
                ) THEN 'coming-soon'
                ELSE 'coming-soon'
            END AS movie_status
        FROM MOVIE m
        LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
        WHERE m.title LIKE '%$search_query_escaped%' 
           OR m.genre LIKE '%$search_query_escaped%' 
           OR m.movie_descrp LIKE '%$search_query_escaped%'
        GROUP BY 
            m.movie_show_id, 
            m.title, 
            m.genre, 
            m.duration, 
            m.rating, 
            m.movie_descrp, 
            m.image_poster
        ORDER BY m.title;
        ";
    }

    
    $movie_result = $conn->query($movie_sql);
    
    if ($movie_result && $movie_result->num_rows > 0) {
        while ($row = $movie_result->fetch_assoc()) {
            $search_results[] = [
                'type' => 'movie',
                'title' => $row['title'],
                'genre' => $row['genre'],
                'duration' => $row['duration'],
                'rating' => $row['rating'],
                'description' => $row['movie_descrp'],
                'image' => $row['image_poster'],
                'status' => $row['movie_status'],
                'show_date' => $row['show_date'],
                'show_hour' => $row['show_hour']
            ];
        }
    }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - Ticketix</title>
    <link rel="icon" type="image/png" href="images/brand x.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/ticketix-main.css">
    <style>
        body {
            padding-top: 70px; /* Add padding to account for fixed header */
            background: url('mainbg/Main.png') center / cover no-repeat fixed;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 0;
            pointer-events: none;
        }
        
        /* Different layout for non-logged-in users */
        body.not-logged-in::before {
            background: rgba(0, 0, 0, 0.7) !important;
        }
        
        .login-prompt-banner {
            background: linear-gradient(to right, #558ace, #558acebd);
            color: white;
            padding: 15px 20px;
            text-align: center;
            margin-bottom: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(85, 138, 206, 0.3);
        }
        
        .login-prompt-banner p {
            margin: 0;
            font-size: 16px;
        }
        
        .login-prompt-banner a {
            color: white;
            font-weight: bold;
            text-decoration: underline;
            margin-left: 10px;
        }
        
        .login-prompt-banner a:hover {
            text-decoration: none;
        }
        
        body.not-logged-in .search-result-card {
            opacity: 0.9;
            border: 2px solid rgba(85, 138, 206, 0.3);
        }
        
        body.not-logged-in .search-result-card:hover {
            opacity: 1;
            border-color: rgba(85, 138, 206, 0.3);
        }
        
        .search-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }
        
        .search-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .search-header h1 {
            color: white;
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .search-header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.1em;
        }
        
        .search-form {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .search-input {
            padding: 15px 20px;
            font-size: 16px;
            border: 2px solid #7E8D93;
            border-radius: 25px 0 0 25px;
            width: 400px;
            outline: none;
            background: white;
            color: #333;
        }

        .search-input:focus {
            border-color: #558ace;
            box-shadow: 0 0 10px rgba(85, 138, 206, 0.3);
        }
        
        .search-btn {
            padding: 15px 25px;
            background: linear-gradient(to right, #558ace, #558acebd);
            color: white;
            border: none;
            border-radius: 0 25px 25px 0;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            background: linear-gradient(to right, #558acebd, #558acec0);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .search-results {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }
        
        .search-result-card {
            background: rgba(30, 30, 40, 0.85);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 0 15px rgba(85, 138, 206, 0.3), 0 8px 16px rgba(0,0,0,0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(85, 138, 206, 0.3);
            backdrop-filter: blur(10px);
        }
        
        .search-result-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0 20px rgba(85, 138, 206, 0.3), 0 12px 24px rgba(0, 0, 0, 0.4);
            border-color: rgba(255, 255, 255, 0.2);
        }
        
        .search-result-image {
            width: 100%;
            height: 250px;
            object-fit: cover;
        }
        
        .search-result-content {
            padding: 25px;
            background: linear-gradient(135deg, rgba(85, 138, 206, 0.3), rgba(85, 138, 206, 0.3));
        }
        
        .search-result-title {
            font-size: 1.4em;
            font-weight: bold;
            margin-bottom: 12px;
            color: #ffffff;
        }
        
        .search-result-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 0.95em;
        }
        
        .search-result-genre {
            color: #BDD3DB;
            font-weight: 600;
        }
        
        .search-result-duration {
            color: #ffffff;
        }
        
        .search-result-rating {
            color: #ffffffff;
            font-weight: 600;
        }
        
        .search-result-description {
            color: #e0e0e0;
            line-height: 1.6;
            margin-bottom: 20px;
            font-size: 0.95em;
        }
        
        .search-result-status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .status-now-showing {
            background: rgba(85, 138, 206, 0.3);
            color: white;
        }
        
        .status-coming-soon {
            background: rgba(85, 138, 206, 0.3);
            color: white;
        }
        
        .search-result-actions {
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 25px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .ticket-btn {
            background: linear-gradient(to right, #558acebd, #558ace);
            color: white;
            width: 100%;
        }
        
        .ticket-btn:hover {
            background: linear-gradient(to right, #558ace, #558acebd);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(85, 138, 206, 0.4);
        }
        
        .notify-btn {
            background: linear-gradient(to right, #558acebd, #558ace);
            color: white;
            width: 100%;
        }
        
        .notify-btn:hover {
            background: linear-gradient(to right, #558ace, #558acebd);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(85, 138, 206, 0.4);
        }
        
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .no-results h3 {
            margin-bottom: 15px;
            color: white;
            font-size: 2em;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .no-results p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.1em;
            margin-bottom: 10px;
        }
        
        .back-to-home {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: linear-gradient(to right, #558acebd, #558ace);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        
        .back-to-home:hover {
            transform: translateY(-2px);
            color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        h2 {
            color: white;
            font-size: 1.8em;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }

        /* Navigation search form styling */
        .nav-search-form {
          display: flex;
          align-items: center;
          gap: 8px;
          margin-left: 20px;
        }
        
        .nav-search-label {
          color: #ccc;
          font-size: 14px;
          font-weight: 500;
          white-space: nowrap;
        }
        
        .nav-search-input {
          padding: 10px 18px;
          font-size: 14px;
          border: 2px solid rgba(255, 255, 255, 0.72);
          border-radius: 25px 0 0 25px;
          background: transparent;
          color: white;
          outline: none;
          width: 220px;
          transition: all 0.3s ease;
        }
        
        .nav-search-input::placeholder {
          color: rgba(255, 255, 255, 0.5);
        }
        
        .nav-search-input:focus {
          border-color: #7E8D93;
          background: rgba(255, 255, 255, 0.05);
          width: 280px;
          box-shadow: 0 0 15px rgba(126, 141, 147, 0.4);
          color: white;
        }
        
        .nav-search-input:focus::placeholder {
          color: rgba(255, 255, 255, 0.6);
        }
        
        .nav-search-btn {
          padding: 10px 15px;
          background: transparent;
          color: white;
          border: 2px solid rgba(255, 255, 255, 0.72);
          border-left: none;
          border-radius: 0 25px 25px 0;
          cursor: pointer;
          font-size: 18px;
          font-weight: 600;
          transition: all 0.3s ease;
          min-width: 50px;
          height: 42px;
          display: flex;
          align-items: center;
          justify-content: center;
          position: relative;
        }
        
        .nav-search-btn:hover {
          background: rgba(207, 207, 207, 0);
          border-color: rgba(255, 255, 255, 0.72);
          transform: scale(1.1);
        }
        
        .nav-search-btn:active {
          transform: scale(0.95);
        }
        
        .nav-search-btn:focus {
          outline: 2px solid rgba(126, 141, 147, 0.5);
          outline-offset: 2px;
          border-radius: 0 25px 25px 0;
          border-color: #7E8D93;
        }
        
        .nav-search-input:focus ~ .nav-search-btn {
          border-color: #7E8D93;
        }
        
        /* Responsive search page content */
        @media (max-width: 768px) {
          .search-container {
            padding: 20px 10px;
            margin-top: 10px;
          }

          .search-header h1 {
            font-size: 1.8em;
          }

          .search-form {
            flex-direction: column;
            gap: 10px;
          }

          .search-input {
            width: 100%;
            border-radius: 25px;
          }

          .search-btn {
            border-radius: 25px;
            width: 100%;
          }

          .search-results {
            grid-template-columns: 1fr;
            gap: 20px;
          }

          .search-result-content {
            padding: 15px;
          }

          .search-result-title {
            font-size: 1.2em;
          }
        }

        @media (max-width: 480px) {
          .search-header h1 {
            font-size: 1.5em;
          }

          .search-result-image {
            height: 180px;
          }

          .search-result-meta {
            gap: 8px;
            font-size: 0.85em;
          }
        }
    </style>
</head>
<body <?php if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']): ?>class="not-logged-in"<?php endif; ?>>
    <header>
        <div class="left-section">
            <div class="logo">
                <img src="images/brand x.png" alt="Ticketix Logo">
            </div>

            <!-- Hamburger Menu Button -->
            <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleMobileNav()" aria-label="Toggle navigation menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav id="mainNav">
                <a href="TICKETIX NI CLAIRE.php#home">Home</a>
                <a href="TICKETIX NI CLAIRE.php#now-showing">Now Showing</a>
                <a href="TICKETIX NI CLAIRE.php#coming-soon">Coming Soon</a>
                <a href="TICKETIX NI CLAIRE.php#contact">Contact Us</a>
            </nav>
            
            <form class="nav-search-form" id="navSearchForm" method="GET" action="search.php">
                <label for="nav-search" class="nav-search-label">Search Movies:</label>
                <input type="text" id="nav-search" name="q" placeholder="Search..." class="nav-search-input" value="<?php echo htmlspecialchars($search_query); ?>" required>
                <button type="submit" class="nav-search-btn">🔍</button>
            </form>
        </div>
        <div class="right-section">
            <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
                <a href="branch-selection.php" class="ticket-btn" style="text-decoration: none; display: inline-block; color: white; padding: 10px 25px; border-radius: 25px; cursor: pointer;">Buy Tickets</a>
                <div class="user-profile">
                    <button class="profile-button" onclick="toggleProfileDropdown()" aria-label="User Profile">
                        <?php 
                            $userName = htmlspecialchars($_SESSION['user_name']);
                            $initials = '';
                            $nameParts = explode(' ', $userName);
                            if (count($nameParts) >= 2) {
                                $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
                            } else {
                                $initials = strtoupper(substr($userName, 0, 2));
                            }
                        ?>
                        <span class="profile-initials"><?php echo $initials; ?></span>
                    </button>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="dropdown-header">
                            <div class="dropdown-header-initials"><?php echo $initials; ?></div>
                            <div class="dropdown-header-name"><?php echo $userName; ?></div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="account-settings.php" class="dropdown-item">
                            <i class="dropdown-icon"></i> Account Settings
                        </a>
                        <a href="my-bookings.php" class="dropdown-item">
                            <i class="dropdown-icon"></i> My Bookings
                        </a>
                        <a href="profile.php" class="dropdown-item">
                            <i class="dropdown-icon"></i> My Profile
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item">
                            <i class="dropdown-icon">➜]</i> Logout
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php" class="ticket-btn" style="text-decoration: none; display: inline-block; color: white; padding: 10px 25px; border-radius: 25px; cursor: pointer;">Buy Tickets</a>
                <a href="login.php" class="login-link"><i class="user-icon"></i> Log In / Sign Up</a>
            <?php endif; ?>
        </div>
    </header>
    <!-- Mobile nav overlay -->
    <div class="nav-overlay" id="navOverlay" onclick="toggleMobileNav()"></div>

    <div class="search-container">
        <?php if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']): ?>
            <div class="login-prompt-banner">
                <p>Welcome! <a href="login.php">Sign in</a> to book tickets and access exclusive features.</p>
            </div>
        <?php endif; ?>
        
        <div class="search-header">
            <h1>Search Movies</h1>
            <p>Find your favorite movies and discover new ones</p>
        </div>
        
        <form class="search-form" method="GET" action="search.php">
            <input type="text" name="q" class="search-input" placeholder="Search for movies, genres, or descriptions..." value="<?php echo htmlspecialchars($search_query); ?>" required>
            <button type="submit" class="search-btn">Search</button>
        </form>
        
        <?php if (!empty($search_query)): ?>
            <h2>Search Results for "<?php echo htmlspecialchars($search_query); ?>"</h2>
            
            <?php if (!empty($search_results)): ?>
                <div class="search-results">
                    <?php foreach ($search_results as $result): ?>
                        <div class="search-result-card">
                            <?php if (!empty($result['image'])): ?>
                                <img src="<?php echo htmlspecialchars($result['image']); ?>" alt="<?php echo htmlspecialchars($result['title']); ?>" class="search-result-image">
                            <?php else: ?>
                                <div class="search-result-image" style="background: linear-gradient(135deg, #7E8D93, #373D3F); display: flex; align-items: center; justify-content: center; color: white; font-size: 3em;">🎬</div>
                            <?php endif; ?>
                            
                            <div class="search-result-content">
                                <h3 class="search-result-title"><?php echo htmlspecialchars($result['title']); ?></h3>
                                
                                <div class="search-result-meta">
                                    <span class="search-result-genre"><?php echo htmlspecialchars($result['genre']); ?></span>
                                    <?php 
                                    // Format duration: convert minutes to hours format
                                    $duration_min = intval($result['duration'] ?? 0);
                                    $hours = floor($duration_min / 60);
                                    $minutes = $duration_min % 60;
                                    $duration_formatted = $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
                                    ?>
                                    <span class="search-result-duration"><?php echo $duration_formatted; ?></span>
                                    <span class="search-result-rating"><?php echo htmlspecialchars($result['rating'] ?: 'N/A'); ?></span>
                                </div>
                                
                                <span class="search-result-status status-<?php echo $result['status']; ?>">
                                    <?php echo ucfirst(str_replace('-', ' ', $result['status'])); ?>
                                </span>
                                
                                <?php if (!empty($result['description'])): ?>
                                    <p class="search-result-description"><?php echo htmlspecialchars($result['description']); ?></p>
                                <?php else: ?>
                                    <p class="search-result-description" style="color: #b0b0b0; font-style: italic;">No description available.</p>
                                <?php endif; ?>
                                
                                <div class="search-result-actions">
                                    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
                                        <a href="branch-selection.php?source=movie&movie=<?= urlencode($result['title']) ?>" class="action-btn ticket-btn" style="text-decoration: none; display: inline-block;" onclick="event.stopPropagation(); return true;">Buy Tickets</a>
                                    <?php else: ?>
                                        <a href="login.php" class="btn ticket-btn">Login to Buy Tickets</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-results">
                    <h3>No movies found</h3>
                    <p>Sorry, we couldn't find any movies matching "<?php echo htmlspecialchars($search_query); ?>"</p>
                    <p>Try searching with different keywords or check the spelling.</p>
                    <a href="TICKETIX NI CLAIRE.php" class="back-to-home">Back to Home</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-results">
                <h3>Start Your Search</h3>
                <p>Enter a movie title, genre, or description to find what you're looking for.</p>
                <a href="TICKETIX NI CLAIRE.php" class="back-to-home">Back to Home</a>
            </div>
        <?php endif; ?>
    </div>

<script>
// Profile Dropdown Functions
function toggleProfileDropdown() {
  const dropdown = document.getElementById('profileDropdown');
  const button = document.querySelector('.profile-button');
  
  if (dropdown && button) {
    const isShowing = dropdown.classList.contains('show');
    
    if (isShowing) {
      dropdown.classList.remove('show');
      button.classList.remove('active');
    } else {
      dropdown.classList.add('show');
      button.classList.add('active');
    }
  }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
  const profile = document.querySelector('.user-profile');
  const dropdown = document.getElementById('profileDropdown');
  const button = document.querySelector('.profile-button');
  
  if (profile && dropdown && button) {
    if (!profile.contains(event.target)) {
      dropdown.classList.remove('show');
      button.classList.remove('active');
    }
  }
});

// Close dropdown when clicking on a dropdown item
document.addEventListener('DOMContentLoaded', function() {
  const dropdownItems = document.querySelectorAll('.dropdown-item');
  dropdownItems.forEach(item => {
    item.addEventListener('click', function() {
      const dropdown = document.getElementById('profileDropdown');
      const button = document.querySelector('.profile-button');
      
      if (dropdown && button) {
        // Small delay to allow navigation before closing
        setTimeout(() => {
          dropdown.classList.remove('show');
          button.classList.remove('active');
        }, 100);
      }
    });
  });
});

// ── Mobile Navigation Toggle ──────────────────────────────────
function toggleMobileNav() {
    const hamburger = document.getElementById('hamburgerBtn');
    const nav = document.getElementById('mainNav');
    const searchForm = document.getElementById('navSearchForm');
    const rightSection = document.querySelector('.right-section');
    const overlay = document.getElementById('navOverlay');
    
    const isOpen = nav.classList.contains('mobile-open');
    
    if (isOpen) {
        hamburger.classList.remove('active');
        nav.classList.remove('mobile-open');
        if (searchForm) searchForm.classList.remove('mobile-open');
        if (rightSection) rightSection.classList.remove('mobile-open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    } else {
        hamburger.classList.add('active');
        nav.classList.add('mobile-open');
        if (searchForm) searchForm.classList.add('mobile-open');
        if (rightSection) rightSection.classList.add('mobile-open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

document.querySelectorAll('#mainNav a').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) toggleMobileNav();
    });
});

window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        const nav = document.getElementById('mainNav');
        const searchForm = document.getElementById('navSearchForm');
        const rightSection = document.querySelector('.right-section');
        const hamburger = document.getElementById('hamburgerBtn');
        const overlay = document.getElementById('navOverlay');
        if (nav) nav.classList.remove('mobile-open');
        if (searchForm) searchForm.classList.remove('mobile-open');
        if (rightSection) rightSection.classList.remove('mobile-open');
        if (hamburger) hamburger.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
});
</script>
</body>
</html>