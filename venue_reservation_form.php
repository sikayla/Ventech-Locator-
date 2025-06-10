<?php
// error_reporting(E_ALL); // Uncomment for debugging
// ini_set('display_errors', 1); // Uncomment for debugging

// **1. Start Session (Conditionally)**
// Start session only if not already started and not included in another script that starts it.
if (session_status() == PHP_SESSION_NONE && !defined('IS_INCLUDED_DASHBOARD')) {
    session_start();
}

$date_format_error = "";
$past_date_error = ''; // Initialize error message variable
$errors = []; // Initialize errors array
$success_message = '';
$form_data = $_POST ?? []; // Use data from POST if available (for repopulating form on error)
$pdo = null; // Initialize PDO variable

// **2. Database Connection Parameters**
$host = 'localhost';
$db   = 'ventech_db'; // Your database name
$user_db = 'root'; // Your database username
$pass = ''; // Your database password - Consider using environment variables or config files
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
      PDO::ATTR_ERRMODE            =>   PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE =>   PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
];

// **3. Get Data from Previous Page (GET Request)**
$venue_id = isset($_GET['venue_id']) ? intval($_GET['venue_id']) : 0;
$venue_name_from_get = isset($_GET['venue_name']) ? trim(htmlspecialchars($_GET['venue_name'])) : 'Selected Venue'; // Get venue name for display
$event_date_from_get = isset($_GET['event_date']) ? trim($_GET['event_date']) : '';

// **4. Validate Venue ID**
if ($venue_id <= 0 && !defined('IS_INCLUDED_DASHBOARD')) {
    // If accessed directly without a valid venue_id
    $errors['general'] = "No valid venue selected. Please go back and choose a venue.";
    error_log("Venue ID missing or invalid when accessing reservation form directly.");
    // Prevent further execution if critical info is missing when run standalone
    // Note: If included, we might rely on the including script to handle this.
    // For standalone, you might want to die() or redirect here.
}

// **5. Validate Date Format (YYYY-MM-DD) from GET**
if ($event_date_from_get && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date_from_get)) {
    error_log("Invalid date format received from GET: " . $event_date_from_get);
    $event_date_from_get = ''; // Clear invalid date
    $date_format_error = "An invalid date format was received. Please select the date again.";
}

// **6. Check if Date from GET is in the Past**
$today = date('Y-m-d');
if ($event_date_from_get && $event_date_from_get < $today) {
    error_log("Attempt to pre-fill form with past date from GET: " . $event_date_from_get);
    $past_date_error = "The date selected previously (" . htmlspecialchars($event_date_from_get) . ") is in the past. Please choose a future date.";
    $event_date_from_get = ''; // Clear the date
}

// **7. Fetch Venue Details (Price, Title, Image)**
$venue_price_per_hour = 0;
$venue_title = $venue_name_from_get; // Default to name from GET
$venue_img_src = 'https://placehold.co/150x150/e2e8f0/64748b?text=No+Venue'; // Default placeholder

if ($venue_id > 0) { // Only try to fetch if we have a valid ID
    try {
        $pdo = new PDO($dsn, $user_db, $pass, $options);

        $stmt_venue = $pdo->prepare("SELECT title, price, image_path FROM venue WHERE id = :venue_id");
        $stmt_venue->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
        $stmt_venue->execute();
        $venue_details = $stmt_venue->fetch(PDO::FETCH_ASSOC);

        if ($venue_details) {
            $venue_price_per_hour = (float) $venue_details['price'];
            $venue_title = htmlspecialchars($venue_details['title']);
            $venue_image_path = $venue_details['image_path'];

            // Construct image path
            $uploadsBaseUrl = '/ventech_locator/uploads/'; // *** ADJUST THIS PATH IF NEEDED ***
            $placeholderImg = 'https://placehold.co/150x150/e2e8f0/64748b?text=No+Image';
            $venue_img_src = $placeholderImg; // Default to placeholder

            if (!empty($venue_image_path)) {
                if (filter_var($venue_image_path, FILTER_VALIDATE_URL)) {
                    $venue_img_src = htmlspecialchars($venue_image_path); // Use URL directly
                } else {
                    // Construct full path assuming relative path from uploadsBaseUrl
                    $potential_file_path = ($uploadsBaseUrl . ltrim($venue_image_path, '/'));
                    $venue_img_src = htmlspecialchars($potential_file_path);
                }
            }
        } else {
            $errors['general'] = $errors['general'] ?? "Error: Venue with ID $venue_id not found."; // Append if general error already exists
            error_log("Venue ID $venue_id provided but not found in database.");
            $venue_title = "Venue Not Found";
            $venue_price_per_hour = 0;
            $venue_img_src = 'https://placehold.co/150x150/ef4444/ffffff?text=Not+Found';
        }

    } catch (PDOException $e) {
        error_log("Database error fetching venue details (ID: $venue_id): " . $e->getMessage());
        $errors['general'] = $errors['general'] ?? "Could not load venue details due to a database error.";
        $venue_title = "Error Loading Venue";
        $venue_price_per_hour = 0;
        $venue_img_src = 'https://placehold.co/150x150/ef4444/ffffff?text=DB+Error';
    }
    // Connection will be closed later or reused for user fetch
} else {
    // If no venue_id was provided via GET
    $venue_title = "No Venue Selected";
    $venue_price_per_hour = 0;
    if (!isset($errors['general']) && !defined('IS_INCLUDED_DASHBOARD')) {
        $errors['general'] = "No venue selected. Please go back and choose a venue.";
    }
}

// **8. Get User ID from Session and Fetch User Details for Pre-filling**
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$user_details = null;
$has_pending_reservation = false; // Flag to check for existing pending reservations

if ($user_id && $venue_id > 0) { // Only fetch user if logged in and venue is valid
    try {
        if (!$pdo) { // Create new connection if not already established
             $pdo = new PDO($dsn, $user_db, $pass, $options);
        }

        // Fetch details needed for pre-filling form from 'users' table
        $stmt_user = $pdo->prepare("SELECT client_name, email, contact_number, client_address, location FROM users WHERE id = :user_id");
        $stmt_user->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt_user->execute();
        $user_details = $stmt_user->fetch(PDO::FETCH_ASSOC);

        // ** Check for existing pending reservations for this user and venue **
        // 'pending' status assumed. Adjust column name and status value as per your database schema.
        $stmt_pending = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE user_id = :user_id AND venue_id = :venue_id AND status = 'pending'");
        $stmt_pending->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt_pending->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
        $stmt_pending->execute();
        $pending_count = $stmt_pending->fetchColumn();

        if ($pending_count > 0) {
            $has_pending_reservation = true;
            $errors['general'] = "You already have a pending reservation for " . htmlspecialchars($venue_title) . ". Please wait for its approval or manage it from your dashboard.";
        }


    } catch (PDOException $e) {
        error_log("Database error fetching user details or checking pending reservations (ID: $user_id): " . $e->getMessage());
        // Don't show error to user, just proceed without pre-filling
        $errors['general'] = $errors['general'] ?? "A database error occurred. Cannot check for existing reservations.";
    }
}

// **9. Close PDO Connection** (Done fetching initial data)
$pdo = null;


// **10. Form Submission Handling (Logic Resides in reservation_manage.php)**
// This script only displays the form. The form action points to reservation_manage.php.
// $errors and $form_data might be populated if reservation_manage.php redirects back here on validation failure.

// **11. Determine Default Values for Form Fields**
// Prioritize submitted data (if errors occurred), then logged-in user data, then GET data (for date), then empty
function get_value($field_name, $default = '') {
    global $form_data, $user_details;
    if (!empty($form_data[$field_name])) {
        return htmlspecialchars($form_data[$field_name]);
    }
    // Map user details to form fields for pre-filling
    if ($user_details) {
        switch ($field_name) {
            case 'first_name':
                // Attempt to split client_name into first and last if it contains a space
                $client_name_parts = explode(' ', $user_details['client_name'] ?? '', 2);
                return htmlspecialchars($client_name_parts[0] ?? '');
            case 'last_name':
                 $client_name_parts = explode(' ', $user_details['client_name'] ?? '', 2);
                return htmlspecialchars($client_name_parts[1] ?? ''); // Second part is last name
            case 'email': return htmlspecialchars($user_details['email'] ?? '');
            case 'mobile_number': return htmlspecialchars($user_details['contact_number'] ?? '');
            case 'address': return htmlspecialchars($user_details['client_address'] ?? $user_details['location'] ?? ''); // Prefer client_address, fallback to location
            // case 'country': return htmlspecialchars($user_details['country'] ?? 'Philippines'); // Default to Philippines if not set
            // Add more mappings if needed
        }
    }
    return htmlspecialchars($default);
}

$event_date_value_for_input = get_value('event_date', $event_date_from_get);
$start_time_value = get_value('start_time');
$end_time_value = get_value('end_time');
$first_name_value = get_value('first_name');
$last_name_value = get_value('last_name');
$email_value = get_value('email');
$mobile_code_value = get_value('mobile_country_code', '+63'); // Default PH code
$mobile_num_value = get_value('mobile_number');
$address_value = get_value('address');
$country_value = get_value('country', 'Philippines'); // Default Philippines
$notes_value = get_value('notes');
$voucher_value = get_value('voucher_code');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve: <?= htmlspecialchars($venue_title); ?> - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/ventech_locator/css/venue_reservation_form.css">
    <style>
        /* General body and font styles */
        body {
            font-family: 'Inter', sans-serif; /* Using Inter as specified by default */
            background-color: #121212; /* Dark background */
            color: #e0e0e0; /* Light text for readability */
        }

        /* Main Container */
        .main-container {
            display: flex;
            min-height: 100vh;
            background-color: #121212; /* Ensure consistent background */
        }

        /* Content Container (Form + Summary) */
        .content-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 1200px; /* Max width for content */
            margin: 0 auto; /* Center content */
            background-color: #1a1a1a; /* Slightly lighter dark background for content */
            border-radius: 8px; /* Rounded corners */
            overflow: hidden; /* Ensure rounded corners are applied */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4); /* Subtle shadow */
        }

        @media (min-width: 768px) {
            .content-container {
                flex-direction: row; /* Side-by-side on larger screens */
            }
        }

        /* Form and Summary Column */
        .form-and-summary-column {
            flex-grow: 1;
            padding: 2.5rem; /* Increased padding */
            color: #e0e0e0; /* Light text */
            position: relative; /* For back link positioning */
        }

        /* Image Column (for decorative image) */
        .image-column {
            display: none; /* Hidden by default on small screens */
            flex-shrink: 0;
            width: 40%; /* 40% width on larger screens */
            position: relative;
            background-color: #2a2a2a; /* Dark placeholder for image */
            overflow: hidden;
        }

        .image-column img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-overlay-text {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8), rgba(0,0,0,0));
            color: white;
            padding: 2rem 1.5rem;
            font-size: 1.5rem;
            line-height: 1.3;
            text-align: center;
            font-weight: 300;
        }

        .image-overlay-text .font-semibold {
            font-size: 2rem;
            display: block;
        }

        @media (min-width: 768px) {
            .image-column {
                display: block; /* Show on larger screens */
            }
            .form-and-summary-column {
                width: 60%; /* 60% width for form on larger screens */
            }
        }


        /* Back Link */
        .back-link {
            position: absolute;
            top: 1.5rem;
            left: 2.5rem;
            display: inline-flex;
            align-items: center;
            color: #9ca3af; /* gray-400 */
            font-size: 0.875rem; /* text-sm */
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s ease-in-out;
        }
        .back-link:hover {
            color: #e0e0e0; /* light gray on hover */
        }
        .back-link i {
            margin-right: 0.5rem;
        }

        /* Form Section Titles */
        .form-section-title {
            font-size: 1.125rem; /* text-lg */
            font-weight: 600; /* font-semibold */
            color: #f59e0b; /* orange-500 for headings */
            border-bottom: 1px solid #4b5563; /* gray-600 */
            padding-bottom: 0.75rem;
            margin-bottom: 1.5rem;
        }

        /* Input Field Styling */
        .input-icon-container {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; /* gray-400 */
            font-size: 0.875rem; /* text-sm */
        }

        /* Adjust icon position for textarea */
        textarea.input-icon-container + .input-icon {
            top: 0.75rem;
            transform: translateY(0);
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="time"],
        textarea,
        select {
            background-color: #2a2a2a; /* Darker input background */
            color: #e0e0e0; /* Light text */
            border-color: #4b5563; /* gray-600 border */
            border-radius: 0.375rem; /* rounded-md */
            padding-left: 2.5rem; /* Space for icon */
            font-size: 0.875rem; /* text-sm */
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1); /* Make calendar icon white/visible on dark background */
        }

        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(1); /* Make clock icon white/visible on dark background */
        }


        input:focus,
        textarea:focus,
        select:focus {
            border-color: #f59e0b; /* orange-500 on focus */
            outline: none;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.5); /* orange-300 ring */
        }

        .mobile-group .input-icon-container {
            position: relative;
        }

        /* Error Messages */
        .error-message {
            color: #f87171; /* red-400 */
            font-size: 0.75rem; /* text-xs */
            margin-top: 0.25rem;
        }

        /* Button Styling */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            border: none; /* Remove default button border */
        }

        .btn-primary {
            background-color: #f59e0b; /* orange-500 */
            color: #1f2937; /* Dark text for contrast */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .btn-primary:hover {
            background-color: #ea580c; /* orange-600 */
            transform: translateY(-1px); /* Slight lift effect */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }
        .btn-primary:disabled {
            background-color: #9ca3af; /* gray-400 */
            cursor: not-allowed;
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-secondary {
            background-color: #4b5563; /* gray-600 */
            color: #e0e0e0;
        }
        .btn-secondary:hover {
            background-color: #6b7280; /* gray-500 */
            transform: translateY(-1px);
        }

        /* Summary Section */
        #reservation-summary {
            background-color: #2a2a2a; /* Darker background for summary */
            border: 1px solid #4b5563; /* gray-600 border */
            border-radius: 0.5rem;
            padding: 1.5rem;
            color: #e0e0e0;
        }
        #reservation-summary .form-section-title {
            color: #e0e0e0; /* Light text for summary title */
            border-bottom-color: #6b7280; /* Lighter border for summary title */
        }


        /* Confirmation Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7); /* Dark overlay */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000; /* Higher than other content */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out;
        }
        .modal-overlay.visible {
            opacity: 1;
            visibility: visible;
        }
        .confirmation-modal-content {
            background-color: #1a1a1a; /* Dark background for modal */
            border-radius: 0.5rem;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.5);
            width: 90%;
            max-width: 450px;
            overflow: hidden; /* Ensures rounded corners */
            color: #e0e0e0; /* Light text */
        }
        .confirmation-modal-header {
            background-color: #22c55e; /* Green header from image */
            color: white;
            padding: 1rem 1.5rem;
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-top-left-radius: 0.5rem; /* Match parent */
            border-top-right-radius: 0.5rem; /* Match parent */
        }
        .confirmation-modal-body {
            padding: 1.5rem;
            text-align: center;
        }
        .confirmation-modal-buttons {
            display: flex;
            justify-content: space-around; /* Or space-evenly */
            padding: 1rem 1.5rem;
            gap: 1rem;
            border-top: 1px solid #4b5563; /* Separator line */
        }
        .confirmation-modal-buttons button {
            flex: 1; /* Make buttons take equal width */
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            border: none;
        }
        .confirmation-modal-buttons .btn-confirm {
            background-color: #22c55e; /* Green */
            color: white;
        }
        .confirmation-modal-buttons .btn-confirm:hover {
            background-color: #16a34a; /* Darker green */
            transform: translateY(-1px);
        }
        .confirmation-modal-buttons .btn-cancel {
            background-color: #4b5563; /* Gray */
            color: white;
        }
        .confirmation-modal-buttons .btn-cancel:hover {
            background-color: #6b7280; /* Lighter gray */
            transform: translateY(-1px);
        }

        /* Success Modal Styles (similar to confirmation but with success theme) */
        .success-modal-content {
            background-color: #1a1a1a; /* Dark background */
            border-radius: 0.5rem;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.5);
            width: 90%;
            max-width: 450px;
            overflow: hidden;
            color: #e0e0e0;
            text-align: center;
        }
        .success-modal-header {
            background-color: #22c55e; /* Green header */
            color: white;
            padding: 1rem 1.5rem;
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center; /* Center header content */
            gap: 0.75rem;
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
        }
        .success-modal-body {
            padding: 1.5rem;
        }
        .success-modal-body p {
            margin-bottom: 1rem;
            font-size: 1rem;
        }
        .success-modal-buttons {
            padding: 1rem 1.5rem;
            border-top: 1px solid #4b5563;
            text-align: center;
        }
        .success-modal-buttons .btn-ok {
            background-color: #f59e0b; /* Orange "OK" button */
            color: #1f2937;
            padding: 0.75rem 2rem;
            border-radius: 0.375rem;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            border: none;
        }
        .success-modal-buttons .btn-ok:hover {
            background-color: #ea580c;
            transform: translateY(-1px);
        }

    </style>
</head>
<body>

    <div class="main-container">
        <div class="content-container">
            <div class="form-and-summary-column">
                 <?php if ($venue_id > 0): ?>
                    <a href="venue_display.php?id=<?= $venue_id ?>" class="back-link">
                        <i class="fas fa-chevron-left"></i> Back to Venue Details
                    </a>
                <?php else: ?>
                     <a href="index.php" class="back-link"> <i class="fas fa-chevron-left"></i> Back to Venues List
                    </a>
                <?php endif; ?>

                <h1 class="text-lg font-semibold mb-1">
                    Reservation Request
                </h1>
                <p class="text-xs text-gray-400 mb-8 leading-tight">
                    Book your event at <span class="text-orange-400"><?= htmlspecialchars($venue_title); ?></span>.
                    <br/>
                    Fill out the details below to submit your request.
                </p>

                <?php // Display general errors, past date errors, date format errors at the top
                if (!empty($errors['general']) || $past_date_error || $date_format_error): ?>
                    <div class="bg-red-900 bg-opacity-20 border-l-4 border-red-500 text-red-300 p-4 rounded-md relative mb-6 shadow-md text-xs" role="alert">
                        <strong class="font-bold block mb-1"><i class="fas fa-exclamation-triangle mr-2"></i>Please Note:</strong>
                        <ul class="list-disc list-inside text-xs space-y-1">
                            <?php if (!empty($errors['general'])): ?>
                                <li><?= htmlspecialchars($errors['general']); ?></li>
                            <?php endif; ?>
                            <?php if ($past_date_error): ?>
                                <li><?= htmlspecialchars($past_date_error); ?></li>
                            <?php endif; ?>
                             <?php if ($date_format_error): ?>
                                <li><?= htmlspecialchars($date_format_error); ?></li>
                            <?php endif; ?>
                             <?php // Display specific field errors from $errors array if needed, though they are also shown below fields ?>
                        </ul>
                         <?php if ($has_pending_reservation && $user_id): // Show dashboard link if pending reservation exists ?>
                            <p class="mt-2 text-xs">You can manage your existing reservation from your <a href="client_dashboard.php" class="font-medium underline hover:text-red-400">dashboard</a>.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php // Display success message if reservation_manage.php redirects back with one
                // This block will now be handled by the success modal in JavaScript
                /*
                if ($success_message): ?>
                    <div class="bg-green-900 bg-opacity-20 border-l-4 border-green-500 text-green-300 p-4 rounded-md relative mb-6 shadow-md text-xs" role="alert">
                        <strong class="font-bold block mb-1"><i class="fas fa-check-circle mr-2"></i>Success!</strong>
                        <span class="block text-xs"><?= htmlspecialchars($success_message); ?></span>
                        <?php if ($user_id): // Only show dashboard link if user is logged in ?>
                        <p class="mt-2 text-xs">You can view the status of your request on your <a href="client_dashboard.php" class="font-medium underline hover:text-green-400">dashboard</a>.</p>
                        <?php endif; ?>
                    </div>
                <?php else: // Show the form only if there's no success message and no fatal error preventing form display
                */ ?>

                    <?php if ($venue_id > 0 && $venue_price_per_hour >= 0 && !$has_pending_reservation) : // Only show form sections if venue details are valid and no pending reservation ?>

                     <div id="reservation-summary" class="form-section mb-6 hidden">
                         <h2 class="form-section-title flex justify-between items-center">
                             <span><i class="fas fa-receipt mr-2 text-orange-500"></i>Reservation Summary</span>
                             <span class="text-sm font-normal text-gray-500">Estimated Cost</span>
                         </h2>
                         <div class="flex flex-col sm:flex-row items-start sm:items-center mb-4">
                             <img id="summary-venue-image" src="<?= htmlspecialchars($venue_img_src) ?>" alt="<?= htmlspecialchars($venue_title) ?>" class="w-16 h-16 object-cover rounded mr-4 mb-3 sm:mb-0 shadow flex-shrink-0" onerror="this.onerror=null; this.src='https://placehold.co/64x64/2a2a2a/d1d5db?text=Img';">
                             <div class="flex-grow text-xs">
                                 <h3 id="summary-venue-name" class="font-semibold text-sm text-gray-100"><?= htmlspecialchars($venue_title) ?></h3>
                                 <p class="text-xs text-gray-400">Price per hour: <span id="summary-venue-price" data-price="<?= $venue_price_per_hour ?>">₱ <?= number_format($venue_price_per_hour, 2) ?></span></p>
                             </div>
                         </div>
                         <div class="grid grid-cols-1 gap-y-2 text-xs">
                             <div><span class="font-semibold text-gray-400">Date:</span> <span id="summary-event-date" class="text-gray-100">--</span></div>
                             <div><span class="font-semibold text-gray-400">Start:</span> <span id="summary-start-time" class="text-gray-100">--</span></div>
                             <div><span class="font-semibold text-gray-400">End:</span> <span id="summary-end-time" class="text-gray-100">--</span></div>
                         </div>
                         <hr class="my-3 border-gray-700">
                         <p class="text-base font-semibold text-right">Estimated Total: <span id="summary-total-cost" class="text-orange-500">₱ 0.00</span></p>
                         <p id="summary-error" class="text-red-400 text-xs text-right mt-1"></p>
                     </div>

                     <form id="reservationForm" novalidate class="space-y-6 text-xs font-normal">
                     <input type="hidden" name="venue_id" value="<?php echo htmlspecialchars($venue_id); ?>">
                     <input type="hidden" name="venue_name" value="<?php echo htmlspecialchars($venue_title); ?>">
                         <?php if ($user_id): ?>
                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($user_id) ?>">
                         <?php endif; ?>

                        <div class="form-section">
                            <h2 class="form-section-title"><i class="fas fa-calendar-alt mr-2 text-indigo-400"></i>Event Details</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="md:col-span-2">
                                    <label for="event-date" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Event Date*</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-calendar-day input-icon text-gray-500 text-xs"></i>
                                        <input type="date" id="event-date" name="event_date"
                                               min="<?= $today ?>"
                                               class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['event_date']) ? 'border-red-500' : '' ?>"
                                               value="<?= $event_date_value_for_input ?>" required aria-describedby="event-date-error">
                                    </div>
                                    <?php if (isset($errors['event_date'])): ?><p id="event-date-error" class="error-message"><?= htmlspecialchars($errors['event_date']); ?></p><?php endif; ?>
                                    <p id="date-availability-msg" class="text-xs text-red-400 mt-1"></p>
                                </div>
                                <div>
                                    <label for="start-time" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Start time*</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-clock input-icon text-gray-500 text-xs"></i>
                                        <input type="time" id="start-time" name="start_time" step="1800" class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['start_time']) ? 'border-red-500' : '' ?>"
                                               value="<?= $start_time_value ?>" required aria-describedby="start-time-error">
                                    </div>
                                    <?php if (isset($errors['start_time'])): ?><p id="start-time-error" class="error-message"><?= htmlspecialchars($errors['start_time']); ?></p><?php endif; ?>
                                </div>
                                <div>
                                    <label for="end-time" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">End time*</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-hourglass-end input-icon text-gray-500 text-xs"></i>
                                        <input type="time" id="end-time" name="end_time" step="1800" class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['end_time']) ? 'border-red-500' : '' ?>"
                                               value="<?= $end_time_value ?>" required aria-describedby="end-time-error time-validation-error">
                                    </div>
                                     <?php if (isset($errors['end_time'])): ?><p id="end-time-error" class="error-message"><?= htmlspecialchars($errors['end_time']); ?></p><?php endif; ?>
                                     <p id="time-validation-error" class="error-message"></p> </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h2 class="form-section-title"><i class="fas fa-user-circle mr-2 text-indigo-400"></i>Contact Information</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="first-name" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">First name*</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-user input-icon text-gray-500 text-xs"></i>
                                        <input type="text" id="first-name" name="first_name" autocomplete="given-name"
                                               class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['first_name']) ? 'border-red-500' : '' ?>"
                                               value="<?= $first_name_value ?>" required aria-describedby="first-name-error">
                                    </div>
                                    <?php if (isset($errors['first_name'])): ?><p id="first-name-error" class="error-message"><?= htmlspecialchars($errors['first_name']); ?></p><?php endif; ?>
                                </div>
                                <div>
                                    <label for="last-name" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Last name*</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-user input-icon text-gray-500 text-xs"></i>
                                        <input type="text" id="last-name" name="last_name" autocomplete="family-name"
                                               class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['last_name']) ? 'border-red-500' : '' ?>"
                                               value="<?= $last_name_value ?>" required aria-describedby="last-name-error">
                                    </div>
                                    <?php if (isset($errors['last_name'])): ?><p id="last-name-error" class="error-message"><?= htmlspecialchars($errors['last_name']); ?></p><?php endif; ?>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="email" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Email address*</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-envelope input-icon text-gray-500 text-xs"></i>
                                        <input type="email" id="email" name="email" autocomplete="email"
                                               class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['email']) ? 'border-red-500' : '' ?>"
                                               value="<?= $email_value ?>" required aria-describedby="email-error">
                                    </div>
                                     <?php if (isset($errors['email'])): ?><p id="email-error" class="error-message"><?= htmlspecialchars($errors['email']); ?></p><?php endif; ?>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="mobile" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Mobile Number</label>
                                    <div class="flex mobile-group">
                                        <span class="rounded-l bg-[#1a1a1a] text-gray-400 text-xs border border-gray-700 px-3 py-2 flex items-center gap-1">
                                             <i class="fas fa-phone-alt"></i>
                                             <select id="mobile-country-code" name="mobile_country_code" autocomplete="tel-country-code" class="bg-transparent border-none focus:ring-0 focus:border-transparent text-gray-400 p-0 m-0" aria-label="Country code">
                                                 <option value="+63" <?= ($mobile_code_value == '+63') ? 'selected' : ''; ?>>+63</option>
                                                 <option value="+1" <?= ($mobile_code_value == '+1') ? 'selected' : ''; ?>>+1</option>
                                                 <option value="+44" <?= ($mobile_code_value == '+44') ? 'selected' : ''; ?>>+44</option>
                                                 <option value="+61" <?= ($mobile_code_value == '+61') ? 'selected' : ''; ?>>+61</option>
                                                 <option value="+65" <?= ($mobile_code_value == '+65') ? 'selected' : ''; ?>>+65</option>
                                             </select>
                                        </span>
                                        <div class="input-icon-container flex-grow">
                                            <input type="tel" id="mobile" name="mobile_number" autocomplete="tel-national"
                                                   class="block w-full rounded-r bg-[#1a1a1a] text-gray-400 text-xs border border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['mobile_number']) ? 'border-red-500' : '' ?>"
                                                   value="<?= $mobile_num_value ?>" placeholder="Enter your phone number" aria-describedby="mobile-number-error">
                                        </div>
                                    </div>
                                     <?php if (isset($errors['mobile_number'])): ?><p id="mobile-number-error" class="error-message"><?= htmlspecialchars($errors['mobile_number']); ?></p><?php endif; ?>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="address" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Address</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-map-marker-alt input-icon text-gray-500 text-xs"></i>
                                        <input type="text" id="address" name="address" autocomplete="street-address"
                                               class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['address']) ? 'border-red-500' : '' ?>"
                                               value="<?= $address_value ?>" aria-describedby="address-error">
                                    </div>
                                     <?php if (isset($errors['address'])): ?><p id="address-error" class="error-message"><?= htmlspecialchars($errors['address']); ?></p><?php endif; ?>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="country" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Country</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-globe-asia input-icon text-gray-500 text-xs"></i>
                                        <select id="country" name="country" autocomplete="country-name" class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['country']) ? 'border-red-500' : '' ?>" aria-describedby="country-error">
                                            <option value="Philippines" <?= ($country_value == 'Philippines') ? 'selected' : ''; ?>>Philippines</option>
                                            <option value="USA" <?= ($country_value == 'USA') ? 'selected' : ''; ?>>USA</option>
                                            <option value="Singapore" <?= ($country_value == 'Singapore') ? 'selected' : ''; ?>>Singapore</option>
                                            <option value="Australia" <?= ($country_value == 'Australia') ? 'selected' : ''; ?>>Australia</option>
                                            <option value="United Kingdom" <?= ($country_value == 'United Kingdom') ? 'selected' : ''; ?>>United Kingdom</option>
                                            </select>
                                    </div>
                                     <?php if (isset($errors['country'])): ?><p id="country-error" class="error-message"><?= htmlspecialchars($errors['country']); ?></p><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h2 class="form-section-title"><i class="fas fa-info-circle mr-2 text-indigo-400"></i>Additional Information</h2>
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label for="notes" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Notes / Special Requests</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-sticky-note input-icon text-gray-500 text-xs" style="top: 0.75rem; transform: translateY(0);"></i> <textarea id="notes" name="notes" rows="4"
                                                  class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['notes']) ? 'border-red-500' : '' ?>"
                                                  placeholder="Any special requirements? (e.g., setup time needed, specific equipment, dietary restrictions if applicable)" aria-describedby="notes-error"><?= $notes_value ?></textarea>
                                    </div>
                                     <?php if (isset($errors['notes'])): ?><p id="notes-error" class="error-message"><?= htmlspecialchars($errors['notes']); ?></p><?php endif; ?>
                                </div>
                                <div>
                                    <label for="voucher" class="block text-sm font-medium text-gray-400 mb-1 uppercase tracking-wide">Voucher Code (Optional)</label>
                                    <div class="input-icon-container">
                                        <i class="fas fa-tag input-icon text-gray-500 text-xs"></i>
                                        <input type="text" id="voucher" name="voucher_code"
                                               class="block w-full pl-10 rounded bg-[#1a1a1a] text-gray-400 text-xs border-gray-700 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['voucher_code']) ? 'border-red-500' : '' ?>"
                                               value="<?= $voucher_value ?>" placeholder="Enter promo code if you have one" aria-describedby="voucher-error">
                                    </div>
                                    <?php if (isset($errors['voucher_code'])): ?><p id="voucher-error" class="error-message"><?= htmlspecialchars($errors['voucher_code']); ?></p><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 text-center">
                            <button type="button" id="open-confirmation-modal-btn" class="btn btn-primary w-full md:w-auto" <?= ($venue_id <= 0 || $venue_price_per_hour < 0 || $has_pending_reservation) ? 'disabled' : '' ?>>
                                <i class="fas fa-paper-plane mr-2"></i> Submit Reservation Request
                            </button>
                            <?php if ($venue_id <= 0 || $venue_price_per_hour < 0): ?>
                                <p class="text-xs text-red-400 mt-2">Cannot submit: Invalid venue details or price.</p>
                            <?php endif; ?>
                            <?php if ($has_pending_reservation): ?>
                                <p class="text-xs text-red-400 mt-2">Cannot submit: You have a pending reservation for this venue.</p>
                            <?php endif; ?>
                        </div>

                    </form>

                    <div id="responseMessage" class="mt-2 text-green-400 font-semibold text-xs"></div>
                    <?php else: // Show message if venue details couldn't be loaded or pending reservation exists ?>
                        <div class="form-section text-center">
                            <p class="text-red-400 font-semibold text-xs">
                                 <i class="fas fa-exclamation-triangle mr-2"></i>
                                 Could not load reservation form. <?= isset($errors['general']) ? htmlspecialchars($errors['general']) : 'Please select a valid venue first.' ?>
                             </p>
                        </div>
                    <?php endif; // End check for valid venue details and pending reservation ?>
                <?php // endif; // End of hiding form on success (now handled by JS modals) ?>

            </div>
            <div class="image-column">
                 <img alt="Decorative image for the reservation form" class="w-full h-full object-cover" src="/ventech_locator/images/side-photo.jpg"/> <div class="image-overlay-text">
                      <span class="font-semibold">
                       Bookings.
                      </span>
                      <br/>
                      Make your
                      <span class="text-red-400">
                       next event
                      </span>
                      unforgettable.
                 </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal HTML -->
    <div id="confirmationModal" class="modal-overlay">
        <div class="confirmation-modal-content">
            <div class="confirmation-modal-header">
                <i class="fas fa-calendar-check text-2xl"></i>
                <span>Make Room Reservations in Minutes</span>
            </div>
            <div class="confirmation-modal-body">
                <p>Are you sure you want to confirm this booking?</p>
                <div id="confirmationSummary" class="text-xs text-gray-400 mt-4">
                    <!-- Dynamic summary will be inserted here by JS -->
                </div>
            </div>
            <div class="confirmation-modal-buttons">
                <button type="button" id="confirmBookingBtn" class="btn btn-confirm">Confirm Booking</button>
                <button type="button" id="cancelBookingBtn" class="btn btn-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Success Modal HTML -->
    <div id="successModal" class="modal-overlay">
        <div class="success-modal-content">
            <div class="success-modal-header">
                <i class="fas fa-check-circle text-2xl"></i>
                <span>Reservation Confirmed!</span>
            </div>
            <div class="success-modal-body">
                <p>Your reservation request has been successfully submitted and is now pending approval.</p>
                <?php if ($user_id): ?>
                    <p class="text-xs text-gray-400">You can view its status on your dashboard.</p>
                <?php endif; ?>
            </div>
            <div class="success-modal-buttons">
                <button type="button" id="successModalOkBtn" class="btn btn-ok">OK</button>
            </div>
        </div>
    </div>

    <!-- Error Modal HTML -->
    <div id="errorModal" class="modal-overlay">
        <div class="success-modal-content"> <!-- Reusing some success modal styles for general modal structure -->
            <div class="success-modal-header !bg-red-600"> <!-- Red header for error -->
                <i class="fas fa-exclamation-triangle text-2xl"></i>
                <span>Submission Error</span>
            </div>
            <div class="success-modal-body">
                <p id="errorModalMessage" class="text-red-400"></p>
            </div>
            <div class="success-modal-buttons">
                <button type="button" id="errorModalOkBtn" class="btn btn-ok !bg-red-500">OK</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const eventDateInput = document.getElementById('event-date');
            const startTimeInput = document.getElementById('start-time');
            const endTimeInput = document.getElementById('end-time');
            const summarySection = document.getElementById('reservation-summary');
            const openConfirmationModalBtn = document.getElementById('open-confirmation-modal-btn'); // New button
            const submitButtonInForm = document.getElementById('submit-button'); // The hidden original form submit button

            // Modals
            const confirmationModal = document.getElementById('confirmationModal');
            const confirmBookingBtn = document.getElementById('confirmBookingBtn');
            const cancelBookingBtn = document.getElementById('cancelBookingBtn');
            const successModal = document.getElementById('successModal');
            const successModalOkBtn = document.getElementById('successModalOkBtn');
            const errorModal = document.getElementById('errorModal');
            const errorModalOkBtn = document.getElementById('errorModalOkBtn');
            const errorModalMessage = document.getElementById('errorModalMessage');

            // Summary elements
            const summaryDateEl = document.getElementById('summary-event-date');
            const summaryStartEl = document.getElementById('summary-start-time');
            const summaryEndEl = document.getElementById('summary-end-time');
            const summaryTotalCostEl = document.getElementById('summary-total-cost');
            const summaryErrorEl = document.getElementById('summary-error');
            const venuePriceEl = document.getElementById('summary-venue-price');
            const venuePricePerHour = parseFloat(venuePriceEl?.dataset.price || 0); // Use actual price from PHP
            const confirmationSummaryEl = document.getElementById('confirmationSummary');

            // Time validation error message element
            const timeValidationErrorEl = document.getElementById('time-validation-error');

            // PHP variable to JS
            const hasPendingReservation = <?= json_encode($has_pending_reservation); ?>;
            const userId = <?= json_encode($user_id); ?>;


            // Function to format time (e.g., 14:30 -> 2:30 PM)
            function formatTime(timeString) {
                if (!timeString) return '--';
                const [hours, minutes] = timeString.split(':');
                const date = new Date();
                date.setHours(hours, minutes, 0);
                return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
            }

            // Function to format date (e.g., 2024-12-31 -> Dec 31, 2024)
            function formatDate(dateString) {
                if (!dateString) return '--';
                try {
                    const date = new Date(dateString + 'T00:00:00'); // Avoid timezone issues by specifying time
                    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
                } catch (e) {
                    console.error("Error formatting date:", e);
                    return dateString; // Fallback to original string
                }
            }

            // Function to calculate duration in hours
            function calculateDuration(start, end) {
                if (!start || !end) return 0;
                try {
                    const startDate = new Date(`1970-01-01T${start}:00`);
                    const endDate = new Date(`1970-01-01T${end}:00`);

                    if (isNaN(startDate) || isNaN(endDate)) {
                        return 0; // Invalid time format
                    }

                    // Handle end time being on the next day if it's earlier than start time (e.g., overnight booking)
                    if (endDate <= startDate) {
                        endDate.setDate(endDate.getDate() + 1);
                    }

                    const diffMillis = endDate - startDate;
                    return diffMillis / (1000 * 60 * 60); // Convert milliseconds to hours
                } catch (e) {
                    console.error("Error calculating duration:", e);
                    return 0;
                }
            }

            // Function to validate all form fields
            function validateForm() {
                let isValid = true;
                const form = document.getElementById('reservationForm');
                const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');

                // Clear previous errors
                form.querySelectorAll('.error-message').forEach(el => el.textContent = '');
                timeValidationErrorEl.textContent = '';
                summaryErrorEl.textContent = '';

                // Basic HTML5 validation
                inputs.forEach(input => {
                    if (!input.checkValidity()) {
                        isValid = false;
                        const errorId = input.id + '-error';
                        let errorMessage = input.validationMessage || 'This field is required.';
                        // Custom messages for specific input types if needed
                        if (input.type === 'email' && input.value && !input.validity.valid) {
                            errorMessage = 'Please enter a valid email address.';
                        } else if (input.type === 'date' && input.validity.rangeUnderflow) {
                            errorMessage = 'Date cannot be in the past.';
                        }
                        document.getElementById(errorId).textContent = errorMessage;
                    }
                });

                // Custom time validation
                const startTime = startTimeInput.value;
                const endTime = endTimeInput.value;
                if (startTime && endTime) {
                    const duration = calculateDuration(startTime, endTime);
                    if (duration <= 0) {
                        timeValidationErrorEl.textContent = 'End time must be after start time.';
                        isValid = false;
                    } else if (duration < 1) { // Minimum 1 hour booking
                         timeValidationErrorEl.textContent = 'Minimum booking duration is 1 hour.';
                        isValid = false;
                    }
                } else if (!startTime || !endTime) {
                    // This case is already covered by 'required' validation for start/end time fields.
                    // If they are required and empty, isValid will already be false.
                }

                return isValid;
            }


            // Function to update summary and total cost
            function updateSummary() {
                const eventDate = eventDateInput.value;
                const startTime = startTimeInput.value;
                const endTime = endTimeInput.value;

                summaryDateEl.textContent = formatDate(eventDate);
                summaryStartEl.textContent = formatTime(startTime);
                summaryEndEl.textContent = formatTime(endTime);

                let currentTotalCost = 0;
                summaryErrorEl.textContent = ''; // Clear previous summary errors

                const duration = calculateDuration(startTime, endTime);

                if (duration > 0 && venuePricePerHour > 0) {
                    currentTotalCost = duration * venuePricePerHour;
                } else if (duration <= 0 && startTime && endTime) {
                    summaryErrorEl.textContent = 'Invalid duration (end time must be after start time).';
                } else if (!eventDate || !startTime || !endTime) {
                    summaryErrorEl.textContent = 'Please select date and time to calculate cost.';
                } else if (venuePricePerHour <= 0) {
                     summaryErrorEl.textContent = 'Venue price is unavailable or invalid.';
                }

                summaryTotalCostEl.textContent = `₱ ${currentTotalCost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

                // Show/hide summary section
                if (eventDate && startTime && endTime && duration > 0 && currentTotalCost > 0) {
                    summarySection.classList.remove('hidden');
                } else {
                    summarySection.classList.add('hidden');
                }
            }


            // Event listeners for input changes to update summary and enable/disable button
            eventDateInput.addEventListener('change', updateSummary);
            startTimeInput.addEventListener('change', updateSummary);
            endTimeInput.addEventListener('change', updateSummary);

            // Initial summary update
            updateSummary();

            // Set up event listeners for inputs to run validation on blur
            const formInputs = document.querySelectorAll('#reservationForm input, #reservationForm select, #reservationForm textarea');
            formInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    validateForm(); // Re-validate on blur
                });
                input.addEventListener('input', function() {
                    // Clear error message on input to allow user to correct
                    const errorId = input.id + '-error';
                    const errorEl = document.getElementById(errorId);
                    if (errorEl) errorEl.textContent = '';
                });
            });


            // OPEN CONFIRMATION MODAL
            openConfirmationModalBtn.addEventListener('click', function (e) {
                e.preventDefault(); // Prevent default form submission

                if (validateForm()) {
                    // Populate confirmation modal summary
                    confirmationSummaryEl.innerHTML = `
                        <p><span class="font-semibold">Venue:</span> <?= htmlspecialchars($venue_title) ?></p>
                        <p><span class="font-semibold">Date:</span> ${formatDate(eventDateInput.value)}</p>
                        <p><span class="font-semibold">Time:</span> ${formatTime(startTimeInput.value)} - ${formatTime(endTimeInput.value)}</p>
                        <p><span class="font-semibold">Estimated Total:</span> ${summaryTotalCostEl.textContent}</p>
                    `;
                    confirmationModal.classList.add('visible');
                } else {
                    // Form is invalid, show a generic error if not already displayed
                    if (!document.querySelector('.error-message.block')) {
                         showErrorModal('Please correct the errors in the form before proceeding.');
                    }
                }
            });

            // CLOSE CONFIRMATION MODAL
            cancelBookingBtn.addEventListener('click', () => {
                confirmationModal.classList.remove('visible');
            });
            confirmationModal.addEventListener('click', function(event) {
                if (event.target === confirmationModal) {
                    confirmationModal.classList.remove('visible');
                }
            });

            // CONFIRM BOOKING (ACTUAL AJAX SUBMISSION)
            confirmBookingBtn.addEventListener('click', function () {
                confirmationModal.classList.remove('visible'); // Hide confirmation modal
                const form = document.getElementById('reservationForm');
                const formData = new FormData(form);

                // Add price_per_hour and total_cost to formData
                formData.append('price_per_hour', venuePricePerHour);
                // Extract numeric value from summaryTotalCostEl.textContent (e.g., "₱ 123.45" -> 123.45)
                const totalCost = parseFloat(summaryTotalCostEl.textContent.replace(/[₱, ]/g, ''));
                formData.append('total_cost', totalCost);


                fetch('reservation_manage.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX request
                    }
                })
                .then(response => response.text())
                .then(data => {
                    const trimmedData = data.trim();

                    if (trimmedData === 'success') {
                        showSuccessModal();
                    } else {
                        showErrorModal(trimmedData || 'An unknown error occurred during reservation.');
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showErrorModal('Network error or server unreachable. Please try again.');
                });
            });

            // SUCCESS MODAL LOGIC
            function showSuccessModal() {
                successModal.classList.add('visible');
            }

            successModalOkBtn.addEventListener('click', () => {
                successModal.classList.remove('visible');
                // Optional: Redirect to dashboard or specific page after successful booking
                <?php if ($user_id): ?>
                    window.location.href = 'users/user_dashboard.php';
                <?php else: ?>
                    // If guest, perhaps redirect to a thank you page or homepage
                    window.location.href = 'index.php';
                <?php endif; ?>
            });

            successModal.addEventListener('click', function(event) {
                if (event.target === successModal) {
                    successModal.classList.remove('visible');
                    // Optional: Redirect even if clicked outside modal
                     <?php if ($user_id): ?>
                         window.location.href = 'users/user_dashboard.php';
                     <?php else: ?>
                         window.location.href = 'index.php';
                     <?php endif; ?>
                }
            });

            // ERROR MODAL LOGIC
            function showErrorModal(message) {
                errorModalMessage.textContent = message;
                errorModal.classList.add('visible');
            }

            errorModalOkBtn.addEventListener('click', () => {
                errorModal.classList.remove('visible');
            });

            errorModal.addEventListener('click', function(event) {
                if (event.target === errorModal) {
                    errorModal.classList.remove('visible');
                }
            });

             // Ensure the "Submit Reservation Request" button's disabled state is correctly set on load
            // and whenever relevant inputs change
            function updateSubmitButtonState() {
                const isFormValid = validateForm(); // Check basic validity
                const isPriceValid = venuePricePerHour > 0;
                const noPending = !hasPendingReservation;

                if (isFormValid && isPriceValid && noPending) {
                    openConfirmationModalBtn.disabled = false;
                } else {
                    openConfirmationModalBtn.disabled = true;
                }
            }

            // Call updateSubmitButtonState on page load and whenever relevant inputs change
            eventDateInput.addEventListener('change', updateSubmitButtonState);
            startTimeInput.addEventListener('change', updateSubmitButtonState);
            endTimeInput.addEventListener('change', updateSubmitButtonState);
            // Add other critical inputs that affect validity
            document.getElementById('first-name').addEventListener('input', updateSubmitButtonState);
            document.getElementById('last-name').addEventListener('input', updateSubmitButtonState);
            document.getElementById('email').addEventListener('input', updateSubmitButtonState);

            updateSubmitButtonState(); // Initial call on DOMContentLoaded
        });
    </script>

</body>
</html>
