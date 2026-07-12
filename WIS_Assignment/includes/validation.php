<?php
/**
 * Sanitize data for input fields.
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate presence of required fields.
 */
function validate_required($data, $fields) {
    $errors = [];
    foreach ($fields as $field => $label) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $errors[$field] = "$label is required.";
        }
    }
    return $errors;
}

/**
 * Validate email format.
 */
function validate_email($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Please enter a valid email address.";
    }
    return null;
}

/**
 * Validate username length and characters.
 */
function validate_username($username) {
    if (strlen($username) < 4 || strlen($username) > 20) {
        return "Username must be between 4 and 20 characters.";
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return "Username can only contain letters, numbers, and underscores.";
    }
    return null;
}

/**
 * Validate phone number format.
 */
function validate_phone($phone) {
    if (!preg_match('/^[0-9\-\+\s]{7,15}$/', $phone)) {
        return "Please enter a valid phone number (7 to 15 digits).";
    }
    return null;
}

/**
 * Validate price is positive decimal.
 */
function validate_price($price) {
    if (!is_numeric($price) || floatval($price) <= 0) {
        return "Price must be a positive number.";
    }
    return null;
}

/**
 * Validate stock/quantity is non-negative integer.
 */
function validate_stock($stock) {
    if (filter_var($stock, FILTER_VALIDATE_INT) === false || intval($stock) < 0) {
        return "Stock must be a non-negative integer.";
    }
    return null;
}

/**
 * Validate file upload for profile/product photo.
 */
function validate_image($file, $is_required = false) {
    // If no file was uploaded
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        if ($is_required) {
            return "Please upload an image file.";
        }
        return null; // Optional upload
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return "Error occurred during file upload. Error Code: " . $file['error'];
    }

    // Check size (limit to 2MB)
    $max_size = 2 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return "File is too large. Maximum size allowed is 2MB.";
    }

    // Check mime type / extension
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $file_info = pathinfo($file['name']);
    $extension = isset($file_info['extension']) ? strtolower($file_info['extension']) : '';

    if (!in_array($extension, $allowed_extensions)) {
        return "Invalid file format. Allowed extensions: " . implode(', ', $allowed_extensions);
    }

    // Check image dimensions (ensure it is actually a valid image)
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return "Uploaded file is not a valid image.";
    }

    return null;
}
?>
