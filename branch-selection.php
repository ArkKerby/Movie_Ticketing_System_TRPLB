<?php
// Single branch mode — skip branch selection, go straight to cinema types
header("Location: select-movie-branch.php");
exit();

// Detect flow and selected movie (if any)
$source = $_GET['source'] ?? 'home'; // 'home' = from homepage, 'movie' = from movie page
$movie_id = $_GET['movie'] ?? null;  // pass movie name or id if clicked from movie page

// Define branches with images and locations
$branches = [
    'Light Residences' => [
        'image' => 'lightresidences.png',
        'location' => 'EDSA Cor Madison St., Brgy Barangka Ilaya, Mandaluyong City'
    ],
    'SM City Baguio' => [
        'image' => 'baguio.png',
        'location' => 'Luneta Hill, Upper Session Road, Baguio City'
    ],
    'SM City Marikina' => [
        'image' => 'marikina.png',
        'location' => 'Marcos Highway, Kalumpang, Marikina City'
    ],
    'SM Aura Premier' => [
        'image' => 'aura.png',
        'location' => 'McKinley Parkway, Bonifacio Global City, Taguig City'
    ],
    'SM Center Angono' => [
        'image' => 'angono.png',
        'location' => 'E. Rodriguez Jr. Avenue, Angono, Rizal'
    ],
    'SM City Sta. Mesa' => [
        'image' => 'stamesa.png',
        'location' => 'G. Araneta Ave., Sta. Mesa, Manila'
    ],
    'SM City Sto. Tomas' => [
        'image' => 'stotomas.png',
        'location' => 'Poblacion, Sto. Tomas, Batangas'
    ],
    'SM Mall of Asia' => [
        'image' => 'moa.png',
        'location' => 'Seaside Blvd., Pasay City'
    ],
    'SM Megacenter Cabanatuan' => [
        'image' => 'cabanatuan.png',
        'location' => 'Brgy. Balintawak, Cabanatuan City, Nueva Ecija'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Cinema Locations - Ticketix</title>
  <link rel="icon" type="image/png" href="images/brand x.png" />
  <link rel="stylesheet" href="css/branch.css" />
</head>
<body>
<header>
    <div class="left-section">
        <div class="logo">
            <img src="images/brand x.png" alt="Ticketix Logo" class="ticketix-logo">
        </div>
    </div>
</header>

<main>
<button class="back-btn" onclick="window.location.href='TICKETIX NI CLAIRE.php'">
    Back to Website
</button>

<h1><?php 
    if ($source === 'movie' && $movie_id) {
        echo "Select Branch for: " . htmlspecialchars($movie_id);
    } else {
        echo "ALL CINEMA LOCATIONS";
    }
?></h1>
<section class="cinema-list">

<?php foreach ($branches as $branchName => $data): 
    // Decide link depending on source
    if ($source === 'home') {
        // From homepage "Buy Tickets" button → go to movie selection first
        $link = "select-movie-branch.php?branch=" . urlencode($branchName);
    } else if ($source === 'movie' && $movie_id) {
        // From movie card "Buy Tickets" → movie already selected, go directly to seat selection
        $link = "seat-reservation.php?branch=" . urlencode($branchName) . "&movie=" . urlencode($movie_id);
    } else {
        // Fallback: go to movie selection
        $link = "select-movie-branch.php?branch=" . urlencode($branchName);
    }
?>
    <article class="cinema-item">
        <img src="branches/<?php echo $data['image']; ?>" alt="<?php echo $branchName; ?>">
        <div class="cinema-info">
            <h2><?php echo $branchName; ?></h2>
            <p class="branch-location"><?php echo $data['location']; ?></p>
            <a href="<?php echo $link; ?>"><?php echo ($source === 'movie' && $movie_id) ? 'Select This Branch' : 'See What\'s Playing'; ?></a>
        </div>
    </article>
<?php endforeach; ?>

</section>
</main>
</body>
</html>