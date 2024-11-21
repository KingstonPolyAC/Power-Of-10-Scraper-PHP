<?php

function crawlAthletePerformance($athleteID) {
    // Base URL with the placeholder for the athlete ID
    $url = "https://thepowerof10.info/athletes/profile.aspx?athleteid=" . $athleteID;

    // Check if 'allow_url_fopen' is enabled
    if (!ini_get('allow_url_fopen')) {
        return json_encode(['error' => 'allow_url_fopen is disabled.']);
    }


     $ch = curl_init();

      // Set cURL options
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
      curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for testing purposes

      // Execute cURL request
      $htmlContent = curl_exec($ch);

    if ($htmlContent === false) {
        return json_encode(['error' => 'Unable to fetch content']);
    }

    // Initialize DOMDocument and load the HTML content
    $dom = new DOMDocument();

    // Suppress errors due to malformed HTML
    libxml_use_internal_errors(true);
    @$dom->loadHTML($htmlContent); // Suppressed warnings in case of malformed HTML
    libxml_clear_errors();

    // Initialize DOMXPath
    $xpath = new DOMXPath($dom);

    // Extract athlete name
    $athleteNameNode = $xpath->query("//*[@id='cphBody_pnlMain']/table/tbody/tr/td[1]/table[1]/tbody/tr");
    $athleteName = '';
    if ($athleteNameNode->length > 0) {
        // Loop through the nodes to find the relevant text
        foreach ($athleteNameNode as $node) {
            $textContent = trim($node->textContent);
            if (!empty($textContent)) {
                // Assume the first non-empty row is the athlete's name
                $athleteName = $textContent;
                break;
            }
        }
    } else {
        $athleteName = 'Unknown Athlete';
    }

    // XPath query to find the 'Best known performances' table
    $tableRows = $xpath->query("//div[@id='cphBody_pnlBestPerformances']//tr");

    $performances = [];

    // Get the current year
    $currentYear = date("Y");

    // Loop through the rows of the table
    foreach ($tableRows as $row) {
        // Get the columns (cells) of the row
        $columns = $xpath->query('td', $row);

        // Check if there are at least three columns
        if ($columns->length >= 3) {
            // Extract the first three columns
            $performance = [
                'Event' => trim($columns->item(0)?->textContent ?? ''), // PHP 8.1 safe null coalescing
                'PB' => trim($columns->item(1)?->textContent ?? ''),
                $currentYear => trim($columns->item(2)?->textContent ?? '')
            ];

            // Add to performances array
            $performances[] = $performance;
        }
    }

    // Combine athlete name with performances
    $result = [
        'athleteName' => $athleteName,
        'performances' => $performances
    ];

    // Return the extracted data in JSON format
    return json_encode($result);
}


function lookupAthleteID($firstname, $surname, $club) {
    // URL for athlete lookup with placeholders
    $url = "https://www.thepowerof10.info/athletes/athleteslookup.aspx?surname=" . urlencode($surname) . "&firstname=" . urlencode($firstname) . "&club=" . urlencode($club);

    // Check if 'allow_url_fopen' is enabled
    if (!ini_get('allow_url_fopen')) {
        return json_encode(['error' => 'allow_url_fopen is disabled.']);
    }

    // Check if URL already contains athleteID
    if (strpos($url, 'athleteid=') !== false) {
        // Extract athleteID directly from the URL
        preg_match('/athleteid=(\d+)/', $url, $matches);
        $athleteID = $matches[1];
        return crawlAthletePerformance($athleteID);
    }

    $location = null;

    // If no athleteID, proceed with the lookup
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$location) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) { // Ignore headers without a colon
            return $len;
        }

        // If the header is 'Location', save its value
        if (strtolower(trim($header[0])) === 'location') {
            $location = trim($header[1]);
        }

        return $len;
    });

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true); // Include the headers in the output
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for testing purposes

    // Execute cURL request
    $htmlContent = curl_exec($ch);

    if ($htmlContent === false) {
        return json_encode(['error' => 'Unable to fetch content from lookup page.']);
    }

    // Check if we got a Location header
    if ($location) {
            preg_match('/athleteid=(\d+)/', $location, $matches);
         // echo json_encode($matches);
          if($matches[1]) {
          	$athleteID = $matches[1];
        	echo $athleteID;
            return crawlAthletePerformance($athleteID);
          }
    }



    // Initialize DOMDocument and load the HTML content
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML($htmlContent); // Suppress warnings
    libxml_clear_errors();

    // Initialize DOMXPath
    $xpath = new DOMXPath($dom);

    // XPath query to find athlete links
    $athleteNodes = $xpath->query("//cphBody_pnlResults//a[contains(@href, 'profile.aspx?athleteid=')]");


    if ($athleteNodes->length == 0) {
        return json_encode(['error' => 'No athletes found for the given details.']);
    } elseif ($athleteNodes->length == 1) {
        // Single athlete found, extract athleteID from the URL
        $href = $athleteNodes->item(0)->getAttribute('href');
        preg_match('/athleteid=(\d+)/', $href, $matches);
        $athleteID = $matches[1];

        // Use the existing function to fetch performance data
        return crawlAthletePerformance($athleteID);
    } else {
        // Multiple athletes found, return IDs and proceed to search all
        $athleteIDs = [];
        foreach ($athleteNodes as $node) {
            $href = $node->getAttribute('href');
            if (preg_match('/athleteid=(\d+)/', $href, $matches)) {
                $athleteIDs[] = $matches[1];
            }
        }



        $results = ['error' => 'Multiple athletes found.', 'athleteIDs' => $athleteIDs];

        // Get performance data for each athlete
        foreach ($athleteIDs as $id) {
            $results['performances'][$id] = crawlAthletePerformance($id);
        }

        return json_encode($results);
    }
}

// Example usage of the lookup function
$firstName = 'Chris';
$surname = 'Dack';
$club = 'Kingston Athletics Club';

// Call the lookup function with the given details
$response = lookupAthleteID($firstName, $surname, $club);
//$response = crawlAthletePerformance(647267);

// Output the result (either an athlete's performance or an error)
echo $response;

