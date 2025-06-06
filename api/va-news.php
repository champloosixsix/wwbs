<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Your NewsAPI key
$apiKey = '9405cac05d85476f9e97c3abef79bfd3';

// NewsAPI endpoint for VA-related news
$url = "https://newsapi.org/v2/everything?q=VA+veterans+affairs+benefits+disability&sortBy=publishedAt&language=en&pageSize=6&apiKey=" . $apiKey;

// Make the API request
$response = @file_get_contents($url);

if ($response === FALSE) {
    // If API fails, return error response
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch news'
    ]);
} else {
    // Return the API response
    echo $response;
}
?>