<?php
session_start();
require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$contactSuccess = '';
$contactError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_form'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $messageBody = trim($_POST['message'] ?? '');

    if ($name === '' || $email === '' || $subject === '' || $messageBody === '') {
        $contactError = 'Please fill in all the required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contactError = 'Please provide a valid email address.';
    } else {
        try {
            // Use the existing mailer.php configuration
            $mail = require __DIR__ . '/mailer.php';
            
            // Set email properties
            $mail->isHTML(false); // Plain text email
            $mail->setFrom('ticketix0@gmail.com', 'Ticketix Website');
            $mail->addAddress('ticketix0@gmail.com');
            $mail->addReplyTo($email, $name);
            
            $mail->Subject = '[Ticketix Contact] ' . $subject;
            $mail->Body = "You have received a new message from the Ticketix contact form.\n\n" .
                "Name: $name\n" .
                "Email: $email\n" .
                "Subject: $subject\n\n" .
                "Message:\n$messageBody\n";
            
            // Send the email
            $mail->send();
            $contactSuccess = 'Thanks for reaching out, ' . htmlspecialchars($name) . '! We will get back to you shortly.';
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $contactError = 'Sorry, we could not send your message at this time. Please try again later.';
            // Uncomment the line below for debugging (remove in production)
            // $contactError = 'Error: ' . $mail->ErrorInfo;
        }
    }
}

// Fetch movies from database
$today = date('Y-m-d');

// Check if now_showing column exists
$columns_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'now_showing'");
$has_now_showing = $columns_check && $columns_check->num_rows > 0;

// Check if carousel_image column exists
$carousel_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'carousel_image'");
$has_carousel_image = $carousel_check && $carousel_check->num_rows > 0;

// Check if trailer_youtube_id column exists
$trailer_check = $conn->query("SHOW COLUMNS FROM MOVIE LIKE 'trailer_youtube_id'");
$has_trailer_youtube_id = $trailer_check && $trailer_check->num_rows > 0;

// Check if CINEMA_MOVIE_ASSIGNMENT table exists
$cinemaAssignCheck = $conn->query("SHOW TABLES LIKE 'CINEMA_MOVIE_ASSIGNMENT'");
$hasCinemaAssignments = $cinemaAssignCheck && $cinemaAssignCheck->num_rows > 0;

// Fetch Now Showing movies — ONLY movies assigned to a cinema number by a Mall Admin
$nowShowingMovies = [];
$trailer_select = $has_trailer_youtube_id ? ", m.trailer_youtube_id" : "";
$carousel_select = $has_carousel_image ? ", m.carousel_image" : "";

if ($hasCinemaAssignments) {
    // Only show movies that have been assigned to at least one cinema number
    $nowShowingQuery = $conn->query("
        SELECT DISTINCT m.movie_show_id, m.title, m.genre, m.duration, m.rating, m.movie_descrp, m.image_poster{$carousel_select}, m.delete_at{$trailer_select}
        FROM MOVIE m
        INNER JOIN CINEMA_MOVIE_ASSIGNMENT cma ON m.movie_show_id = cma.movie_show_id
        WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
        ORDER BY m.title ASC
        LIMIT 10
    ");
    if ($nowShowingQuery) {
        while ($row = $nowShowingQuery->fetch_assoc()) {
            $nowShowingMovies[] = $row;
        }
    }
}

// Fetch Coming Soon movies
if ($has_now_showing) {
    // If coming_soon column exists, include movies marked as coming_soon OR with schedules > today
    $comingSoonQuery = $conn->query("
        SELECT DISTINCT m.*, MIN(ms.show_date) AS earliest_date
        FROM MOVIE m
        LEFT JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
        WHERE (m.coming_soon = TRUE OR (ms.show_date > '$today' AND ms.show_date IS NOT NULL))
        AND (m.now_showing = FALSE OR m.now_showing IS NULL)
        GROUP BY m.movie_show_id
        ORDER BY m.title ASC
        LIMIT 10
    ");
} else {
    // If column doesn't exist, use schedules only
    $comingSoonQuery = $conn->query("
        SELECT DISTINCT m.*, MIN(ms.show_date) AS earliest_date
        FROM MOVIE m
        INNER JOIN MOVIE_SCHEDULE ms ON m.movie_show_id = ms.movie_show_id
        WHERE ms.show_date > '$today'
        AND m.movie_show_id NOT IN (
            SELECT DISTINCT movie_show_id 
            FROM MOVIE_SCHEDULE 
            WHERE show_date = '$today'
        )
        GROUP BY m.movie_show_id
        ORDER BY m.title ASC
        LIMIT 10
    ");
}

$comingSoonMovies = [];
if ($comingSoonQuery) {
    while ($row = $comingSoonQuery->fetch_assoc()) {
        $comingSoonMovies[] = $row;
    }
}

// If no movies found and coming_soon column exists, show all movies marked as coming_soon
if (empty($comingSoonMovies) && $has_now_showing) {
    $fallbackQuery = $conn->query("SELECT * FROM MOVIE WHERE coming_soon = TRUE AND (now_showing = FALSE OR now_showing IS NULL) ORDER BY title ASC LIMIT 10");
    if ($fallbackQuery) {
        while ($row = $fallbackQuery->fetch_assoc()) {
            $comingSoonMovies[] = $row;
        }
    }
}

// Fetch cinema assignments for all movies (for showing cinema badges)
$movieCinemaMap = [];
if ($hasCinemaAssignments) {
    $cinemaMapQuery = $conn->query("
        SELECT cma.movie_show_id, cn.cinema_name
        FROM CINEMA_MOVIE_ASSIGNMENT cma
        INNER JOIN CINEMA_NUMBER cn ON cma.cinema_number_id = cn.cinema_number_id
        ORDER BY FIELD(cn.cinema_name, 'IMAX', 'Director\'s Club', 'Regular'), cn.cinema_name
    ");
    if ($cinemaMapQuery) {
        while ($row = $cinemaMapQuery->fetch_assoc()) {
            $movieCinemaMap[$row['movie_show_id']][] = $row['cinema_name'];
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ticketix</title>
  <link rel="icon" type="image/png" href="images/brand x.png" />
  <link rel="manifest" href="manifest.json" />
  <link rel="apple-touch-icon" href="icons/apple-touch-icon.png" />
  <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="css/ticketix-main.css?v=<?php echo time(); ?>">
</head>

<body>
  <?php
  // PWD notification banner logic
  $showPwdBannerHome = false;
  if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
      $bannerHomeUserId = $_SESSION['user_id'] ?? $_SESSION['acc_id'] ?? null;
      if ($bannerHomeUserId) {
          $conn = getDBConnection();
          $bannerHomeTbl = $conn->query("SHOW TABLES LIKE 'DISCOUNT_APPLICATIONS'");
          if ($bannerHomeTbl && $bannerHomeTbl->num_rows > 0) {
              $bhStmt = $conn->prepare("SELECT status FROM DISCOUNT_APPLICATIONS WHERE acc_id = ? AND discount_type = 'pwd' ORDER BY submitted_at DESC LIMIT 1");
              $bhStmt->bind_param("i", $bannerHomeUserId);
              $bhStmt->execute();
              $bhRow = $bhStmt->get_result()->fetch_assoc();
              $bhStmt->close();
              if (!$bhRow || !in_array($bhRow['status'], ['pending', 'approved'])) {
                  // Also check if already approved in USER_ACCOUNT
                  $bhApprStmt = $conn->prepare("SELECT pwd_approved FROM USER_ACCOUNT WHERE acc_id = ?");
                  $bhApprStmt->bind_param("i", $bannerHomeUserId);
                  $bhApprStmt->execute();
                  $bhApprRow = $bhApprStmt->get_result()->fetch_assoc();
                  $bhApprStmt->close();
                  if (!$bhApprRow || empty($bhApprRow['pwd_approved'])) {
                      $showPwdBannerHome = true;
                  }
              }
          } else {
              $showPwdBannerHome = true;
          }
          $conn->close();
      }
  }
  ?>
  <?php if ($showPwdBannerHome): ?>
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
  <header>
  <div class="left-section">
    <div class="logo">
      <img src="images/brand x.png" alt="Ticketix Logo">
    </div>

    <!-- Hamburger button (visible on mobile only) -->
    <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleMobileMenu()" aria-label="Toggle Menu">
      <span></span>
      <span></span>
      <span></span>
    </button>

    <nav id="mainNav">
      <a href="#home" class="active">Home</a>
      <a href="#now-showing">Now Showing</a>
      <a href="#coming-soon">Coming Soon</a>
      <a href="#contact">Contact Us</a>
    </nav>

    <form class="nav-search-form" id="navSearchForm" method="GET" action="search.php">
      <label for="nav-search" class="nav-search-label">Search Movies:</label>
      <input type="text" id="nav-search" name="q" placeholder="Search..." class="nav-search-input" required>
      <button type="submit" class="nav-search-btn">🔍</button>
    </form>
  </div>

  <!-- Mobile nav overlay -->
  <div class="nav-overlay" id="navOverlay" onclick="closeMobileMenu()"></div>

  <div class="right-section">
    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
      <a href="branch-selection.php" class="ticket-btn" style="text-decoration: none; display: inline-block; color: white; padding: 10px 25px; border-radius: 25px; cursor: pointer;">Buy Tickets</a>
    <?php else: ?>
      <a href="login.php" class="ticket-btn" style="text-decoration: none; display: inline-block; color: white; padding: 10px 25px; border-radius: 25px; cursor: pointer;">Buy Tickets</a>
    <?php endif; ?>
    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
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
            <class="dropdown-icon">Account Settings
          </a>
          <a href="my-bookings.php" class="dropdown-item">
            <class="dropdown-icon">My Bookings
          </a>
          <a href="profile.php" class="dropdown-item">
            <class="dropdown-icon">My Profile
          </a>
          <div class="dropdown-divider"></div>
          <a href="logout.php" class="dropdown-item">
            <class="dropdown-icon">➜] Logout
          </a>
        </div>
      </div>
    <?php else: ?>
      <a href="login.php" class="login-link"><i class="user-icon"></i> Log In / Sign Up</a>
    <?php endif; ?>
  </div>
  </header>

  <section id="home" class="hero">
  <?php 
  // Calculate carousel movies count
  if (!empty($nowShowingMovies)) {
    $carouselMovies = array_slice($nowShowingMovies, 0, 5);
    $carouselCount = count($carouselMovies);
  } else {
    $carouselCount = 1; // Fallback slide
  }
  $showNavigation = $carouselCount > 1; // Only show navigation if more than 1 movie
  ?>
  
  <?php if ($showNavigation): ?>
    <button class="arrow left" onclick="changeSlide(-1)">&#10094;</button>
  <?php endif; ?>

  <div class="hero-slides">
    <?php if (!empty($nowShowingMovies)): ?>
      <?php 
      $slideIndex = 0;
      foreach ($carouselMovies as $movie): 
        $slideIndex++;
        // Use carousel_image if available, otherwise fallback to image_poster, then default
        $carouselImg = isset($movie['carousel_image']) ? trim($movie['carousel_image']) : '';
        $posterImg = isset($movie['image_poster']) ? trim($movie['image_poster']) : '';
        
        if (!empty($carouselImg) && $carouselImg !== '') {
            $imageUrl = htmlspecialchars($carouselImg);
        } else if (!empty($posterImg) && $posterImg !== '') {
            $imageUrl = htmlspecialchars($posterImg);
        } else {
            $imageUrl = 'images/default-movie.jpg';
        }
        $title = htmlspecialchars($movie['title']);
        $trailerId = !empty($movie['trailer_youtube_id']) ? htmlspecialchars($movie['trailer_youtube_id']) : '';
      ?>
        <div class="hero-slide <?php echo $slideIndex === 1 ? 'active' : ''; ?>" data-trailer="<?php echo $trailerId; ?>">
          <div class="hero-background" style="background-image: url('<?php echo $imageUrl; ?>');"></div>
          <div class="hero-trailer-container" style="display: none;">
            <iframe class="hero-trailer-iframe" src="" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
          </div>
          <div class="hero-content">
            <h1><?php echo $title; ?></h1>
            <p>Now Showing</p>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <!-- Fallback: Show default slide if no movies -->
      <div class="hero-slide active" data-trailer="">
        <div class="hero-background" style="background-image: url('images/default-movie.jpg');"></div>
        <div class="hero-trailer-container" style="display: none;">
          <iframe class="hero-trailer-iframe" src="" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>
        <div class="hero-content">
          <h1>Welcome to Ticketix</h1>
          <p>Check back soon for upcoming movies!</p>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($showNavigation): ?>
    <button class="arrow right" onclick="changeSlide(1)">&#10095;</button>
  <?php endif; ?>
  
  <!-- Slide indicators -->
  <?php if ($showNavigation): ?>
    <div class="slide-indicators">
      <?php if (!empty($nowShowingMovies)): ?>
        <?php 
        $indicatorIndex = 0;
        foreach ($carouselMovies as $movie): 
          $indicatorIndex++;
        ?>
          <span class="indicator <?php echo $indicatorIndex === 1 ? 'active' : ''; ?>" onclick="currentSlide(<?php echo $indicatorIndex; ?>)"></span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="indicator active" onclick="currentSlide(1)"></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>


  <div class="main-sections-wrapper">
  <section id="now-showing">
    <h2>Now Showing</h2>
    <div class="movie-grid">
      <?php if (count($nowShowingMovies) > 0): ?>
        <?php foreach ($nowShowingMovies as $movie): 
          // Format duration: convert minutes to hours format
          $duration_min = intval($movie['duration'] ?? 0);
          $hours = floor($duration_min / 60);
          $minutes = $duration_min % 60;
          $duration_formatted = $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
          
          $title = htmlspecialchars($movie['title']);
          $genre = htmlspecialchars($movie['genre']);
          $rating = htmlspecialchars($movie['rating'] ?: 'N/A');
          $image = htmlspecialchars($movie['image_poster'] ?: 'images/default.png');
          // Get description from database and escape for HTML attribute
          $description = !empty($movie['movie_descrp']) ? htmlspecialchars($movie['movie_descrp'], ENT_QUOTES, 'UTF-8') : 'No description available.';
          $deleteOn = !empty($movie['delete_at']) ? date('M d, Y', strtotime($movie['delete_at'])) : '';
          $trailerAttr = !empty($movie['trailer_youtube_id']) ? htmlspecialchars($movie['trailer_youtube_id']) : '';
        ?>
          <div class="movie" onclick="openMovieModal(this)" data-title="<?= $title ?>" data-genre="<?= $genre ?>" data-duration="<?= $duration_formatted ?>" data-rating="<?= $rating ?>" data-poster="<?= $image ?>" data-description="<?= $description ?>" data-delete="<?= htmlspecialchars($deleteOn) ?>" data-trailer="<?= $trailerAttr ?>">
            <img src="<?= $image ?>" alt="<?= $title ?>">
            <div class="movie-overlay">
              <div class="movie-info">
                <h3><?= $title ?></h3>
                <p><?= $genre ?> • <?= $duration_formatted ?> • <?= $rating ?></p>
                <?php if (!empty($movieCinemaMap[$movie['movie_show_id']])): ?>
                  <div class="cinema-badges">
                    <?php foreach ($movieCinemaMap[$movie['movie_show_id']] as $cinemaName): ?>
                      <span class="cinema-badge cinema-badge--<?= strtolower(preg_replace('/[^a-zA-Z]/', '', $cinemaName)) ?>"><?= htmlspecialchars($cinemaName) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($deleteOn)): ?>
                  <p class="delete-on">Available Until: <?= $deleteOn ?></p>
                <?php endif; ?>
                <div class="movie-actions">
                  <button class="action-btn trailer-btn" onclick="event.stopPropagation(); openTrailer('<?= $title ?>', '<?= $trailerAttr ?>')">▶ Trailer</button>
                  <a href="branch-selection.php?source=movie&movie=<?= urlencode($title) ?>" class="action-btn ticket-btn" style="text-decoration: none; display: inline-block;" onclick="event.stopPropagation(); return true;">Buy Tickets</a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="text-align: center; color: #ccc; grid-column: 1 / -1; padding: 40px;">No movies currently showing. Check back soon!</p>
      <?php endif; ?>
    </div>
  </section>

  <section id="coming-soon">
    <h2>Coming Soon</h2>
    <div class="movie-grid">
      <?php if (count($comingSoonMovies) > 0): ?>
        <?php foreach ($comingSoonMovies as $movie): 
          // Format duration: convert minutes to hours format
          $duration_min = intval($movie['duration'] ?? 0);
          $hours = floor($duration_min / 60);
          $minutes = $duration_min % 60;
          $duration_formatted = $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
          
          $title = htmlspecialchars($movie['title']);
          $genre = htmlspecialchars($movie['genre']);
          $rating = htmlspecialchars($movie['rating'] ?: 'N/A');
          $image = htmlspecialchars($movie['image_poster'] ?: 'images/default.png');
          
          // Get release date from query result
          $releaseDate = 'Coming Soon';
          if (!empty($movie['earliest_date'])) {
            $releaseDate = date('F d, Y', strtotime($movie['earliest_date']));
          }
        ?>
          <div class="movie">
            <img src="<?= $image ?>" alt="<?= $title ?>">
            <div class="movie-info">
              <h3><?= $title ?></h3>
              <p><?= $genre ?> • <?= $duration_formatted ?> • <?= $rating ?></p>
              <p class="release-date"><?= $releaseDate ?></p>
              <button class="notify-btn" onclick="alert('We will notify you when <?= $title ?> becomes available!')">Notify Me</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="text-align: center; color: #ccc; grid-column: 1 / -1; padding: 40px;">No upcoming movies. Check back soon!</p>
      <?php endif; ?>
    </div>
  </section>

  <section id="contact">
    <h2>Contact Us</h2>
    <div class="contact-content">
      <div class="contact-info">
        <h3>Get in Touch</h3>
        <p><strong>Address:</strong>&nbsp;504 J. P. Rizal St Marikina City, 1808, Metro Manila</p>
        <p><strong>Phone:</strong>&nbsp;+63 994 931 9562</p>
        <p><strong>Email:</strong>&nbsp;ticketix0@gmail.com</p>
        <p><strong>Business Hours:</strong>&nbsp;Monday - Sunday: 9:00 AM - 11:00 PM</p>
        
        <div class="social-links">
          <h4>Follow Us:</h4>
          <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867">Facebook</a>
          <a href="https://www.instagram.com/ticketix24">Instagram</a>
          <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867">Twitter</a>
          <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867">TikTok</a>
          <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867">YouTube</a>
        </div>
      </div>

      <div class="contact-form">
        <h3>Send us a Message</h3>
        <?php if ($contactSuccess): ?>
            <div class="contact-alert contact-success"><?= $contactSuccess ?></div>
        <?php elseif ($contactError): ?>
            <div class="contact-alert contact-error"><?= htmlspecialchars($contactError) ?></div>
        <?php endif; ?>
        <form action="#contact" method="POST">
          <input type="hidden" name="contact_form" value="1">
          <input type="text" name="name" placeholder="Your Name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
          <input type="email" name="email" placeholder="Your Email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          <input type="text" name="subject" placeholder="Subject" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
          <textarea name="message" placeholder="Your Message" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
          <button type="submit">Send Message</button>
        </form>
        <?php if ($contactSuccess): ?>
            <p class="contact-followup">We'll reply from <strong>ticketix0@gmail.com</strong> as soon as we can.</p>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Search Bar Section -->
    
  </section>
  </div><!-- /.main-sections-wrapper -->

  <!-- Trailer Modal -->
  <div id="trailerModal" class="modal">
    <div class="modal-content trailer-modal">
      <span class="close" onclick="closeTrailer()">&times;</span>
      <h2 id="trailerTitle">Movie Trailer</h2>
      <div id="trailerContainer">
        <div class="trailer-placeholder" id="trailerPlaceholder">
          <div class="trailer-icon">🎬</div>
          <p>Trailer for <span id="trailerMovieName"></span> will be available soon!</p>
          <p>In the meantime, you can watch trailers on our official YouTube channel.</p>
          <button class="btn" onclick="window.open('https://youtube.com', '_blank')">Visit YouTube</button>
        </div>
        <div id="youtubePlayer" style="display: none;">
          <iframe id="trailerVideo" width="100%" height="400" src="" frameborder="0" allowfullscreen></iframe>
        </div>
      </div>
    </div>
  </div>

  <!-- Movie Detail Modal -->
<div id="movieModal" class="modal">
  <div class="modal-content movie-detail-modal">
    <span class="close" onclick="closeMovieModal()">&times;</span>
    <div class="movie-detail-content">
      <div class="movie-poster">
        <img id="modalMoviePoster" src="" alt="Movie Poster">
      </div>
      <div class="movie-details">
        <h2 id="modalMovieTitle">Movie Title</h2>
        <p id="modalMovieGenre">Genre</p>
        <p id="modalMovieDuration">Duration</p>
        <p id="modalMovieRating">Rated:</p>
        <div class="movie-description">
          <p id="modalMovieDescription">Experience the ultimate cinematic adventure with stunning visuals and an unforgettable story.</p>
        </div>
        <div class="modal-actions">
          <!-- Removed the Watch Trailer button -->
          <a href="#" class="action-btn ticket-btn" onclick="goToSeatReservation(); return false;" style="text-decoration: none; display: inline-block;">
            Buy Tickets
          </a>
        </div>
      </div>
    </div>
  </div>
</div>


  <!-- Booking Modal -->
  <div id="bookingModal" class="modal">
    <div class="modal-content booking-modal">
      <span class="close" onclick="closeBooking()">&times;</span>
      <h2>Book Tickets for <span id="bookingMovieName"></span></h2>
      
      <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
        <div class="booking-form">
          <div class="form-group">
            <label for="showtime">Select Showtime:</label>
            <select id="showtime" required>
              <option value="">Choose a showtime</option>
              <option value="10:00 AM">10:00 AM</option>
              <option value="1:00 PM">1:00 PM</option>
              <option value="4:00 PM">4:00 PM</option>
              <option value="7:00 PM">7:00 PM</option>
              <option value="10:00 PM">10:00 PM</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="tickets">Number of Tickets:</label>
            <select id="tickets" required>
              <option value="">Select quantity</option>
              <option value="1">1 Ticket</option>
              <option value="2">2 Tickets</option>
              <option value="3">3 Tickets</option>
              <option value="4">4 Tickets</option>
              <option value="5">5 Tickets</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="seatType">Seat Type:</label>
            <select id="seatType" required>
              <option value="">Choose seat type</option>
              <option value="regular">Regular - ₱250</option>
              <option value="vip">VIP - ₱350</option>
            </select>
          </div>
          
          <div class="price-display">
            <p>Total Price: <span id="totalPrice">₱0</span></p>
          </div>
          
          <button class="btn book-now-btn" onclick="processBooking()">Book Now</button>
        </div>
      <?php else: ?>
        <div class="login-required">
          <p>Please log in to book tickets.</p>
          <a href="login.php" class="btn">Login</a>
          <a href="signup.html" class="btn">Sign Up</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <footer>
  <div class="footer-left">
    <img src="images/logo sha.png" alt="images/Ticketix Logo">
  </div>

  <div class="footer-center">
    <nav>
      <a href="#">About</a>
      <a href="#">Contact</a>
      <a href="#">Privacy Policy</a>
    </nav>
    <p>© 2025 Ticketix. All Rights Reserved.</p>
  </div>

  <div class="footer-right">
    <p class="follow-title">FOLLOW US</p>
    <div class="social-icons">
      <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867"><img src="images/facebook.png" alt="Facebook"></a>
      <a href="https://www.instagram.com/ticketix24"><img src="images/instagram.png" alt="Instagram"></a>
      <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867"><img src="images/x.png" alt="X"></a>
      <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867"><img src="images/tiktok.png" alt="TikTok"></a>
      <a href="https://www.facebook.com/photo?fbid=110530527255536&set=a.110530550588867"><img src="images/youtube.png" alt="YouTube"></a>
    </div>
  </div>
</footer>

<script>
let currentSlideIndex = 0;
const slides = document.querySelectorAll('.hero-slide');
const indicators = document.querySelectorAll('.indicator');
let isTransitioning = false; // Lock to prevent rapid transitions
let trailerStartTimeout = null; // Timer to start trailer after 2s
let trailerStopTimeout = null;  // Timer to stop trailer after 15s

// ── Trailer Autoplay Helpers ──────────────────────────────────
function startTrailerForSlide(slide) {
    const trailerId = slide.getAttribute('data-trailer');
    if (!trailerId) return; // No trailer for this movie

    // After 2 seconds on this slide, start the trailer
    trailerStartTimeout = setTimeout(() => {
        const container = slide.querySelector('.hero-trailer-container');
        const iframe = slide.querySelector('.hero-trailer-iframe');
        if (!container || !iframe) return;

        // Build YouTube embed URL: autoplay, muted, no controls, start from beginning
        const embedUrl = `https://www.youtube.com/embed/${trailerId}?autoplay=1&mute=1&controls=0&showinfo=0&rel=0&modestbranding=1&iv_load_policy=3&disablekb=1&fs=0&playsinline=1`;
        iframe.src = embedUrl;
        container.style.display = 'block';

        // Small delay then fade in
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                container.classList.add('active');
            });
        });

        // After 15 seconds, fade the trailer out and show backdrop again
        trailerStopTimeout = setTimeout(() => {
            stopTrailerForSlide(slide);
        }, 15000);
    }, 2000);
}

function stopTrailerForSlide(slide) {
    // Clear any pending timers
    if (trailerStartTimeout) {
        clearTimeout(trailerStartTimeout);
        trailerStartTimeout = null;
    }
    if (trailerStopTimeout) {
        clearTimeout(trailerStopTimeout);
        trailerStopTimeout = null;
    }

    const container = slide.querySelector('.hero-trailer-container');
    const iframe = slide.querySelector('.hero-trailer-iframe');
    if (!container || !iframe) return;

    // Fade out
    container.classList.remove('active');

    // After the fade transition (1s), hide and clear src to stop playback
    setTimeout(() => {
        container.style.display = 'none';
        iframe.src = '';
    }, 1000);
}

function stopAllTrailers() {
    if (trailerStartTimeout) {
        clearTimeout(trailerStartTimeout);
        trailerStartTimeout = null;
    }
    if (trailerStopTimeout) {
        clearTimeout(trailerStopTimeout);
        trailerStopTimeout = null;
    }
    slides.forEach(slide => {
        const container = slide.querySelector('.hero-trailer-container');
        const iframe = slide.querySelector('.hero-trailer-iframe');
        if (container && iframe) {
            container.classList.remove('active');
            container.style.display = 'none';
            iframe.src = '';
        }
    });
}

// ── Slide Navigation ──────────────────────────────────────────
function showSlide(index) {
    // Prevent rapid transitions
    if (isTransitioning || slides[index].classList.contains('active')) {
        return;
    }
    
    isTransitioning = true;

    // Stop any playing trailer on the current slide
    stopAllTrailers();
    
    // Find current active slide
    let currentActiveIndex = -1;
    slides.forEach((slide, i) => {
        if (slide.classList.contains('active')) {
            currentActiveIndex = i;
        }
    });
    
    // Determine if we're wrapping around (last to first or first to last)
    const isWrappingForward = currentActiveIndex === slides.length - 1 && index === 0;
    const isWrappingBackward = currentActiveIndex === 0 && index === slides.length - 1;
    
    if (isWrappingForward || isWrappingBackward) {
        // For wrap-around transitions, handle smoothly
        // Remove active from all indicators first
        indicators.forEach(indicator => indicator.classList.remove('active'));
        
        if (isWrappingForward) {
            if (currentActiveIndex >= 0) {
                slides[currentActiveIndex].classList.remove('active');
                slides[currentActiveIndex].classList.remove('prev');
            }
            
            slides[index].classList.remove('prev', 'active');
            slides[index].style.transform = 'translateX(100%)';
            slides[index].style.opacity = '0';
            
            if (currentActiveIndex >= 0) {
                void slides[currentActiveIndex].offsetHeight;
            }
            void slides[index].offsetHeight;
            
            setTimeout(() => {
                requestAnimationFrame(() => {
                    slides[index].style.opacity = '1';
                    slides[index].classList.add('active');
                    slides[index].style.transform = '';
                    indicators[index].classList.add('active');
                    
                    setTimeout(() => {
                        isTransitioning = false;
                        slides.forEach(slide => {
                            if (!slide.classList.contains('active')) {
                                slide.classList.remove('prev');
                                slide.style.transform = '';
                            }
                            slide.style.opacity = '';
                        });
                        // Start trailer for the new active slide
                        startTrailerForSlide(slides[index]);
                    }, 550);
                });
            }, 50);
        } else {
            slides[index].classList.remove('active');
            slides[index].classList.add('prev');
            
            if (currentActiveIndex >= 0) {
                slides[currentActiveIndex].classList.remove('active');
                slides[currentActiveIndex].classList.add('prev');
            }
            
            void slides[index].offsetHeight;
            if (currentActiveIndex >= 0) {
                void slides[currentActiveIndex].offsetHeight;
            }
            
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    slides[index].classList.remove('prev');
                    slides[index].classList.add('active');
                    indicators[index].classList.add('active');
                    
                    setTimeout(() => {
                        isTransitioning = false;
                        slides.forEach(slide => {
                            if (!slide.classList.contains('active')) {
                                slide.classList.remove('prev');
                                slide.style.transform = '';
                            }
                        });
                        // Start trailer for the new active slide
                        startTrailerForSlide(slides[index]);
                    }, 550);
                });
            });
        }
    } else {
        // Normal transition (not wrapping)
        slides.forEach(slide => {
            slide.classList.remove('active', 'prev');
        });
        
        indicators.forEach(indicator => indicator.classList.remove('active'));
        
        slides[index].classList.add('active');
        indicators[index].classList.add('active');
        
        const prevIndex = index === 0 ? slides.length - 1 : index - 1;
        slides[prevIndex].classList.add('prev');
        
        setTimeout(() => {
            isTransitioning = false;
            // Start trailer for the new active slide
            startTrailerForSlide(slides[index]);
        }, 500);
    }
}

function changeSlide(direction) {
    if (slides.length <= 1) {
        return;
    }
    
    currentSlideIndex += direction;
    
    if (currentSlideIndex >= slides.length) {
        currentSlideIndex = 0;
    } else if (currentSlideIndex < 0) {
        currentSlideIndex = slides.length - 1;
    }
    
    showSlide(currentSlideIndex);
}

function currentSlide(index) {
    if (slides.length <= 1) {
        return;
    }
    
    currentSlideIndex = index - 1;
    showSlide(currentSlideIndex);
}

// Auto-play functionality (DISABLED - manual navigation only)
let autoPlayInterval;

function startAutoPlay() {
    if (slides.length <= 1) {
        return;
    }
    autoPlayInterval = setInterval(() => {
        changeSlide(1);
    }, 5000);
}

function stopAutoPlay() {
    clearInterval(autoPlayInterval);
}

// Start trailer for the first slide on page load
document.addEventListener('DOMContentLoaded', function() {
    // startAutoPlay(); // DISABLED - Automatic transitions turned off
    
    // Start trailer for the initial active slide after page loads
    if (slides.length > 0) {
        const activeSlide = document.querySelector('.hero-slide.active');
        if (activeSlide) {
            startTrailerForSlide(activeSlide);
        }
    }
    
    // Pause auto-play when user hovers over carousel
    const hero = document.querySelector('.hero');
    hero.addEventListener('mouseenter', stopAutoPlay);
    hero.addEventListener('mouseleave', startAutoPlay);
    
    // Pause auto-play when user clicks arrows or indicators
    const arrows = document.querySelectorAll('.arrow');
    const indicatorDots = document.querySelectorAll('.indicator');
    
    arrows.forEach(arrow => {
        arrow.addEventListener('click', () => {
            stopAutoPlay();
        });
    });
    
    indicatorDots.forEach(indicator => {
        indicator.addEventListener('click', () => {
            stopAutoPlay();
        });
    });
});

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'ArrowLeft') {
        changeSlide(-1);
        stopAutoPlay();
    } else if (event.key === 'ArrowRight') {
        changeSlide(1);
        stopAutoPlay();
    }
});

// Movie trailer data - hardcoded fallback for movies that predate the DB trailer column
const movieTrailers = {
    'Tron: Ares': 'YShVEXb7-ic',
    'Chainsaw Man': 'VfoZp7CmOkE',
    'Black Phone': 'DdR-gzFZoDk',
    'Good Boy': 'q4-CRkd_74g',
    'Quezon': 'vgr-ABdgy9c',
    'One in a Million': 'dQw4w9WgXcQ',
    'Shelby': 'dQw4w9WgXcQ',
    'Now You See Me 3': 'dQw4w9WgXcQ',
    'Predator: The Hunt': 'dQw4w9WgXcQ',
    'Meet Greet Bye': 'dQw4w9WgXcQ'
};

// Trailer Modal Functions
// openTrailer(movieName, dbTrailerId)
// dbTrailerId comes from data-trailer attribute (saved in DB when movie was added)
function openTrailer(movieName, dbTrailerId) {
    document.getElementById('trailerMovieName').textContent = movieName;
    document.getElementById('trailerTitle').textContent = movieName + ' - Trailer';
    
    // Priority: 1) DB trailer ID  2) hardcoded map  3) show placeholder
    let trailerId = (dbTrailerId && dbTrailerId.trim()) ? dbTrailerId.trim()
                  : (movieTrailers[movieName] || '');
    
    if (trailerId) {
        document.getElementById('trailerPlaceholder').style.display = 'none';
        document.getElementById('youtubePlayer').style.display = 'block';
        
        const videoUrl = `https://www.youtube.com/embed/${trailerId}?autoplay=1&rel=0&modestbranding=1`;
        document.getElementById('trailerVideo').src = videoUrl;
    } else {
        // No trailer available — show placeholder
        document.getElementById('youtubePlayer').style.display = 'none';
        document.getElementById('trailerVideo').src = '';
        document.getElementById('trailerPlaceholder').style.display = 'block';
    }
    
    document.getElementById('trailerModal').style.display = 'block';
    stopAutoPlay();
}

function closeTrailer() {
    // Stop video playback when closing modal
    const video = document.getElementById('trailerVideo');
    if (video) {
        video.src = ''; // This stops the video
    }
    
    document.getElementById('trailerModal').style.display = 'none';
    // startAutoPlay(); // DISABLED - No auto-resume when modal closes
}

// Direct navigation to seat reservation
function goToSeatReservation() {
    closeMovieModal();
    closeBooking();

    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
        window.location.href = 'branch-selection.php?source=movie&movie=' + encodeURIComponent(currentModalMovie);
    <?php else: ?>
        window.location.href = 'login.php';
    <?php endif; ?>
}

// Booking Modal Functions (kept for backward compatibility, but not used anymore)
function openBooking(movieName) {
    // Redirect directly to seat reservation instead of showing modal
    goToSeatReservation(movieName);
}

function closeBooking() {
    document.getElementById('bookingModal').style.display = 'none';
    // startAutoPlay(); // DISABLED - No auto-resume when modal closes
    resetBookingForm(); // Reset form
}

// Price calculation
function updatePrice() {
    const tickets = parseInt(document.getElementById('tickets').value) || 0;
    const seatType = document.getElementById('seatType').value;
    const priceDisplay = document.getElementById('totalPrice');
    
    let pricePerTicket = 0;
    if (seatType === 'regular') {
        pricePerTicket = 250;
    } else if (seatType === 'vip') {
        pricePerTicket = 350;
    }
    
    const totalPrice = tickets * pricePerTicket;
    priceDisplay.textContent = `₱${totalPrice}`;
}

// Process booking
function processBooking() {
    const movieName = document.getElementById('bookingMovieName').textContent;
    const showtime = document.getElementById('showtime').value;
    const tickets = document.getElementById('tickets').value;
    const seatType = document.getElementById('seatType').value;
    const totalPrice = document.getElementById('totalPrice').textContent;
    
    if (!showtime || !tickets || !seatType) {
        alert('Please fill in all fields');
        return;
    }
    
    // Redirect to seat reservation page with booking details
    const params = new URLSearchParams({
        movie: movieName,
        showtime: showtime,
        tickets: tickets,
        seatType: seatType,
        totalPrice: totalPrice.replace('₱', '')
    });
    
    window.location.href = 'seat-reservation.php?' + params.toString();
}

// Reset booking form
function resetBookingForm() {
    document.getElementById('showtime').value = '';
    document.getElementById('tickets').value = '';
    document.getElementById('seatType').value = '';
    document.getElementById('totalPrice').textContent = '₱0';
}

// Add event listeners for price calculation
document.addEventListener('DOMContentLoaded', function() {
    const ticketsSelect = document.getElementById('tickets');
    const seatTypeSelect = document.getElementById('seatType');
    
    if (ticketsSelect) {
        ticketsSelect.addEventListener('change', updatePrice);
    }
    
    if (seatTypeSelect) {
        seatTypeSelect.addEventListener('change', updatePrice);
    }
});

// Movie Detail Modal Functions
// Store current modal movie title globally
let currentModalMovie = '';

function openMovieModal(element) {
    const title = element.getAttribute('data-title');
    const genre = element.getAttribute('data-genre');
    const duration = element.getAttribute('data-duration');
    const rating = element.getAttribute('data-rating');
    const posterSrc = element.getAttribute('data-poster');
    const description = element.getAttribute('data-description') || 'No description available.';

    // *** FIX: Store title globally so Buy Tickets button can use it ***
    currentModalMovie = title;

    document.getElementById('modalMovieTitle').textContent = title;
    document.getElementById('modalMovieGenre').textContent = 'Genre: ' + genre;
    document.getElementById('modalMovieDuration').textContent = 'Duration: ' + duration;
    document.getElementById('modalMovieRating').textContent = 'Rated: ' + rating;
    document.getElementById('modalMoviePoster').src = posterSrc;
    document.getElementById('modalMoviePoster').alt = title + ' Poster';
    document.getElementById('modalMovieDescription').textContent = description;
    document.getElementById('movieModal').style.display = 'block';
    stopAutoPlay();
}

function closeMovieModal() {
    document.getElementById('movieModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const trailerModal = document.getElementById('trailerModal');
    const bookingModal = document.getElementById('bookingModal');
    const movieModal = document.getElementById('movieModal');

    if (event.target === trailerModal) closeTrailer();
    if (event.target === bookingModal) closeBooking();
    if (event.target === movieModal) closeMovieModal();
}

// Movie Grid Functions - Now Showing uses same layout as Coming Soon

// Search functionality
function performSearch() {
    const searchInput = document.querySelector('.search-input');
    const searchQuery = searchInput.value.trim();
    
    if (searchQuery.length < 2) {
        alert('Please enter at least 2 characters to search.');
        return;
    }
    
    // Redirect to search page with query
    window.location.href = `search.php?q=${encodeURIComponent(searchQuery)}`;
}

// Add search functionality to the search form
document.addEventListener('DOMContentLoaded', function() {
    const searchForm = document.querySelector('.search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            performSearch();
        });
    }
    
    // Add Enter key functionality to search input
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performSearch();
            }
        });
    }
});

// Test function to verify JavaScript is working
function testFunction() {
    alert('JavaScript is working!');
    console.log('Test function called');
}

// Add click event listeners as backup
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, adding event listeners');
    
    // Add click listeners to all trailer buttons
    const trailerButtons = document.querySelectorAll('button[onclick*="openTrailer"]');
    trailerButtons.forEach(button => {
        button.addEventListener('click', function() {
            const args = this.getAttribute('onclick').match(/'([^']*)'/g);
            const movieName = args && args[0] ? args[0].replace(/'/g, '') : '';
            const trailerId = args && args[1] ? args[1].replace(/'/g, '') : '';
            openTrailer(movieName, trailerId);
        });
    });
    
    // Add click listeners to all booking buttons
    const bookingButtons = document.querySelectorAll('button[onclick*="openBooking"]');
    bookingButtons.forEach(button => {
        button.addEventListener('click', function() {
            const movieName = this.getAttribute('onclick').match(/'([^']+)'/)[1];
            openBooking(movieName);
        });
    });
    
    console.log('Event listeners added to', trailerButtons.length, 'trailer buttons and', bookingButtons.length, 'booking buttons');
});

// Smooth scroll
document.querySelectorAll('nav a').forEach(link => {
  link.addEventListener('click', function(e) {
    if (this.hash) {
      e.preventDefault();
      const target = document.querySelector(this.hash);
      window.scrollTo({
        top: target.offsetTop - 60,
        behavior: 'smooth'
      });
    }
  });
});

// Highlight active section on scroll
const sections = document.querySelectorAll('section');
const navLinks = document.querySelectorAll('nav a');

window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(section => {
    const sectionTop = section.offsetTop - 70;
    const sectionHeight = section.clientHeight;
    if (pageYOffset >= sectionTop && pageYOffset < sectionTop + sectionHeight) {
      current = section.getAttribute('id');
    }
  });

  navLinks.forEach(link => {
    link.classList.remove('active');
    if (link.getAttribute('href') === `#${current}`) {
      link.classList.add('active');
    }
  });
});

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

// ── Hamburger Menu Toggle ──────────────────────────────────────
function toggleMobileMenu() {
  const hamburger = document.getElementById('hamburgerBtn');
  const nav = document.getElementById('mainNav');
  const searchForm = document.getElementById('navSearchForm');
  const rightSection = document.querySelector('.right-section');
  const overlay = document.getElementById('navOverlay');

  const isOpen = nav.classList.contains('mobile-open');

  if (isOpen) {
    closeMobileMenu();
  } else {
    hamburger.classList.add('active');
    nav.classList.add('mobile-open');
    searchForm.classList.add('mobile-open');
    if (rightSection) rightSection.classList.add('mobile-open');
    overlay.classList.add('active');
  }
}

function closeMobileMenu() {
  const hamburger = document.getElementById('hamburgerBtn');
  const nav = document.getElementById('mainNav');
  const searchForm = document.getElementById('navSearchForm');
  const rightSection = document.querySelector('.right-section');
  const overlay = document.getElementById('navOverlay');

  hamburger.classList.remove('active');
  nav.classList.remove('mobile-open');
  searchForm.classList.remove('mobile-open');
  if (rightSection) rightSection.classList.remove('mobile-open');
  overlay.classList.remove('active');
}

// Close mobile menu when a nav link is clicked, then scroll after menu collapses
document.querySelectorAll('#mainNav a').forEach(link => {
  link.addEventListener('click', function(e) {
    if (window.innerWidth <= 768) {
      e.preventDefault();
      const targetId = this.getAttribute('href');
      closeMobileMenu();
      // Wait for menu to collapse, then scroll to the correct section
      setTimeout(() => {
        const targetEl = document.querySelector(targetId);
        if (targetEl) {
          targetEl.scrollIntoView({ behavior: 'smooth' });
        }
      }, 300);
    }
  });
});

// Close mobile menu on window resize to desktop
window.addEventListener('resize', function() {
  if (window.innerWidth > 768) {
    closeMobileMenu();
  }
});

</script>


</body>
</html>