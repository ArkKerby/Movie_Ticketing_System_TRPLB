<?php
/**
 * Demo Data for Ticketix
 * Used when no database is available (e.g., Vercel deployment without cloud DB).
 * Provides sample movie data so the homepage renders beautifully.
 */

$isDemo = true;
$contactSuccess = '';
$contactError = '';

// ── Now Showing Movies ──────────────────────────────────────────────
$nowShowingMovies = [
    [
        'movie_show_id' => 1,
        'title' => 'Thunderstrike: Reckoning',
        'genre' => 'Action / Sci-Fi',
        'duration' => 142,
        'rating' => 'PG-13',
        'movie_descrp' => 'In a world torn apart by an ancient cosmic threat, a rogue pilot and a brilliant scientist must unite to stop a chain reaction that could obliterate the galaxy. Packed with breathtaking visual effects and heart-pounding action sequences that will leave you on the edge of your seat.',
        'image_poster' => 'https://picsum.photos/seed/thunderstrike/300/450',
        'carousel_image' => 'https://picsum.photos/seed/thunderstrike-hero/1920/800',
        'trailer_youtube_id' => '',
        'delete_at' => ''
    ],
    [
        'movie_show_id' => 2,
        'title' => 'Whispers in the Dark',
        'genre' => 'Horror / Thriller',
        'duration' => 118,
        'rating' => 'R-16',
        'movie_descrp' => 'A young journalist investigating an abandoned asylum uncovers terrifying recordings that reveal the facility\'s dark past. As she digs deeper, she realizes the whispers she hears aren\'t just echoes — they\'re warnings.',
        'image_poster' => 'https://picsum.photos/seed/whispers/300/450',
        'carousel_image' => 'https://picsum.photos/seed/whispers-hero/1920/800',
        'trailer_youtube_id' => '',
        'delete_at' => ''
    ],
    [
        'movie_show_id' => 3,
        'title' => 'Love in Manila',
        'genre' => 'Romance / Drama',
        'duration' => 125,
        'rating' => 'PG',
        'movie_descrp' => 'Two strangers from completely different worlds cross paths during a monsoon season in Manila. What begins as a chance encounter in Intramuros blossoms into a love story that transcends class, culture, and time.',
        'image_poster' => 'https://picsum.photos/seed/lovemnl/300/450',
        'carousel_image' => 'https://picsum.photos/seed/lovemnl-hero/1920/800',
        'trailer_youtube_id' => '',
        'delete_at' => ''
    ],
    [
        'movie_show_id' => 4,
        'title' => 'The Last Frontier',
        'genre' => 'Adventure / Fantasy',
        'duration' => 156,
        'rating' => 'PG-13',
        'movie_descrp' => 'Beyond the edge of the known world lies a land untouched by civilization. A band of unlikely heroes must journey through mystical forests, volcanic wastelands, and ancient ruins to prevent an eternal darkness from consuming everything.',
        'image_poster' => 'https://picsum.photos/seed/frontier/300/450',
        'carousel_image' => 'https://picsum.photos/seed/frontier-hero/1920/800',
        'trailer_youtube_id' => '',
        'delete_at' => ''
    ],
    [
        'movie_show_id' => 5,
        'title' => 'Midnight Express',
        'genre' => 'Action / Crime',
        'duration' => 131,
        'rating' => 'R-13',
        'movie_descrp' => 'When a retired detective\'s daughter is kidnapped by a ruthless crime syndicate operating through Manila\'s underground railway, he has just 12 hours to navigate a web of corruption and betrayal to bring her home alive.',
        'image_poster' => 'https://picsum.photos/seed/midnight/300/450',
        'carousel_image' => 'https://picsum.photos/seed/midnight-hero/1920/800',
        'trailer_youtube_id' => '',
        'delete_at' => ''
    ],
];

// ── Coming Soon Movies ──────────────────────────────────────────────
$comingSoonMovies = [
    [
        'movie_show_id' => 101,
        'title' => 'Star Command: Origins',
        'genre' => 'Sci-Fi / Action',
        'duration' => 148,
        'rating' => 'PG-13',
        'movie_descrp' => 'The origin story of the galaxy\'s most elite squadron. Before they were legends, they were cadets — and their first mission could be their last.',
        'image_poster' => 'https://picsum.photos/seed/starcommand/300/450',
        'earliest_date' => date('Y-m-d', strtotime('+14 days'))
    ],
    [
        'movie_show_id' => 102,
        'title' => 'Ocean\'s Heart',
        'genre' => 'Drama / Romance',
        'duration' => 134,
        'rating' => 'PG',
        'movie_descrp' => 'A marine biologist and a fisherman from a remote island discover an underwater cave system that holds the key to saving their dying coral reef — and their relationship.',
        'image_poster' => 'https://picsum.photos/seed/oceansheart/300/450',
        'earliest_date' => date('Y-m-d', strtotime('+21 days'))
    ],
    [
        'movie_show_id' => 103,
        'title' => 'Shadow Warriors',
        'genre' => 'Action / Martial Arts',
        'duration' => 119,
        'rating' => 'R-13',
        'movie_descrp' => 'In feudal Philippines, a secret order of warriors trained in ancient combat arts must rise again to protect the islands from foreign invaders with supernatural powers.',
        'image_poster' => 'https://picsum.photos/seed/shadowwar/300/450',
        'earliest_date' => date('Y-m-d', strtotime('+30 days'))
    ],
];

// ── Cinema Assignments (for cinema badges) ──────────────────────────
$movieCinemaMap = [
    1 => ['IMAX', 'Regular'],
    2 => ['Regular'],
    3 => ["Director's Club", 'Regular'],
    4 => ['IMAX'],
    5 => ['Regular'],
];
