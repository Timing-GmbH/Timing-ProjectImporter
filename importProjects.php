<?php

function callTimingAPI($endpoint, $method = 'GET', $queryParams = [], $body = [], $verbose = false, $dryRun = false) {
    global $options;
    if ($verbose) {
        echo "API Call: {$method} {$endpoint}\n";
        echo "Query Params: " . json_encode($queryParams) . "\n";
        echo "Body: " . json_encode($body) . "\n";
    }
    if ($dryRun) {
        return [];
    }

    $apiKey = $options['TIMING_API_KEY']
        ?? getenv('TIMING_API_KEY');
    $host = $options['TIMING_API_HOST']
        ?? (getenv('TIMING_API_HOST') ?: 'web.timingapp.com');

    $url = 'https://' . $host . $endpoint . '?' . http_build_query($queryParams);
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($statusCode >= 400) {
        throw new Exception("API request failed with status code $statusCode and response $response");
    }

    if ($verbose) {
        echo "Response: {$response}\n";
    }

    return json_decode($response, true);
}

function generateSubpredicates($terms) {
    $subpredicates = [];
    foreach ($terms as $term) {
        if (str_starts_with($term, '#')) {
            $subpredicates[] = createKeywordsContainRule('keywords', strtolower(substr($term, 1)));
        } else {
            $subpredicates[] = createStringContainsRule('title', strtolower($term));
            $subpredicates[] = createStringContainsRule('path', strtolower($term));
        }
    }
    return $subpredicates;
}

function createStringContainsRule($keyPath, $term) {
    return [
        'comparison' => [
            'operatorType' => 'CONTAINS',
            'leftExpression' => ['keyPath' => ['keyPath' => $keyPath]],
            'rightExpression' => ['constantValue' => ['string' => $term]],
            'option' => ['CASE_INSENSITIVE', 'DIACRITIC_INSENSITIVE'],
        ]
    ];
}

function createKeywordsContainRule($keyPath, $term) {
    return [
        'comparison' => [
            'operatorType' => 'CONTAINS',
            'leftExpression' => ['keyPath' => ['keyPath' => $keyPath]],
            'rightExpression' => ['constantValue' => ['string' => $term]],
        ]
    ];
}

function findProjectByName($projects, $name) {
    foreach ($projects as $project) {
        if ($project['title'] === $name) {
            return $project;
        }
        if (!empty($project['children'])) {
            $found = findProjectByName($project['children'], $name);
            if ($found) {
                return $found;
            }
        }
    }
    return null;
}

function parseCSV($filePath, $csvDelimiter, $termDelimiter) {
    $projects = [];
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        $firstLine = true;
        while (($data = fgetcsv($handle, 0, $csvDelimiter)) !== FALSE) {
            if ($firstLine) {
                $firstLine = false;
                continue;
            }
            $projectName = $data[0];
            if (isset($projects[$projectName])) {
                throw new Exception("Duplicate project name '{$projectName}' found in CSV file.");
            }
            $terms = explode($termDelimiter, $data[1]);
            $projects[$projectName] = $terms;
        }
        fclose($handle);
    }
    return $projects;
}

function readEnvFile($envFilePath) {
    if (!file_exists($envFilePath)) {
        throw new Exception("Env file '{$envFilePath}' does not exist.");
    }
    $envContent = file_get_contents($envFilePath);
    return json_decode($envContent, true);
}

function mergeOptions($envOptions, $cmdOptions) {
    foreach ($envOptions as $key => $value) {
        if (!isset($cmdOptions[$key])) {
            $cmdOptions[$key] = $value;
        }
    }
    return $cmdOptions;
}

$cmdOptions = getopt("", ["team::", "csv-delimiter::", "term-delimiter::", "parent::", "dry-run", "update-existing", "verbose", "env::"]);
$envFilePath = $cmdOptions['env'] ?? null;
$envOptions = $envFilePath ? readEnvFile($envFilePath) : [];

$options = mergeOptions($envOptions, $cmdOptions);

$csvFilePath = $options['file'] ?? array_pop($argv);

$teamID = $options['team'] ?? null;
if ($teamID && !str_starts_with($teamID, '/teams/')) {
    $teamID = "/teams/{$teamID}";
}
$csvDelimiter = $options['csv-delimiter'] ?? ',';
$termDelimiter = $options['term-delimiter'] ?? ',';
$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$updateExisting = isset($options['update-existing']);
$parentName = $options['parent'] ?? null;

if (!$csvFilePath) {
    throw new Exception("CSV file path is required as the main argument.");
}
if (!file_exists($csvFilePath)) {
    throw new Exception("CSV file '{$csvFilePath}' does not exist.");
}

// Step 1: Fetch user's teams
$teams = callTimingAPI('/api/v1/teams', 'GET', verbose: $verbose);
$teamIDs = array_column($teams['data'], 'name', 'id');
if ($teamID && !isset($teamIDs[$teamID])) {
    $teamIDsWithNames = array_map(function($id, $name) {
        return "{$id} ({$name})";
    }, array_keys($teamIDs), array_values($teamIDs));
    throw new Exception("Specified team ID {$teamID} does not exist. Available teams: " . implode(', ', $teamIDsWithNames));
}

// Step 2: Fetch projects
$projectParams = [
    'team_id' => $teamID,
    'include_predicate' => true
];
$projects = callTimingAPI('/api/v1/projects/hierarchy', 'GET', $projectParams, [], verbose: $verbose);
$parentProject = null;
if ($parentName && !($parentProject = findProjectByName($projects['data'], $parentName))) {
    throw new Exception("Specified parent project '{$parentName}' does not exist.");
}
$projectsArray = $parentProject ? $parentProject['children'] : $projects['data'];

$projectsByID = array_column($projectsArray, null, 'self');
$projectsByName = array_column($projectsArray, null, 'title');

// Step 3: Check for duplicate project names in API response
if (count($projectsByName) !== count($projectsArray)) {
    throw new Exception("Duplicate project names found in existing project names: " . implode(', ', array_column($projectsArray, 'title')));
}

// Step 4: Parse CSV
$csvProjects = parseCSV($csvFilePath, $csvDelimiter, $termDelimiter);

// Step 5: Process projects
foreach ($csvProjects as $projectName => $terms) {
    $subpredicates = generateSubpredicates($terms);

    if (!isset($projectsByName[$projectName])) {
        // 5a. Create new project
        if ($verbose) {
            echo "Project '{$projectName}' does not exist; creating it.\n";
        }
        $newProject = [
            'title' => $projectName,
            'predicate' => json_encode(['compound' => ['type' => 'OR', 'subpredicate' => $subpredicates]]),
            'team_id' => $teamID,
            'parent' => $parentProject['self'] ?? null
        ];
        callTimingAPI('/api/v1/projects', 'POST', [], $newProject, verbose: $verbose, dryRun: $dryRun);
    } elseif ($updateExisting) {
        // 5b. Update existing project
        if ($verbose) {
            echo "Project '{$projectName}' already exists; checking for update.\n";
        }
        $project = $projectsByName[$projectName];
        $existingPredicate = isset($project['predicate'])
            ? json_decode($project['predicate'], true)
            : ['compound' => ['type' => 'OR', 'subpredicate' => []]];
        if (!isset($existingPredicate['compound']) || $existingPredicate['compound']['type'] !== 'OR') {
            throw new Exception("Invalid predicate format for project '{$projectName}'.");
        }

        $updateMade = false;
        foreach ($subpredicates as $subpredicate) {
            if (!in_array($subpredicate, $existingPredicate['compound']['subpredicate'])) {
                $existingPredicate['compound']['subpredicate'][] = $subpredicate;
                $updateMade = true;
            }
        }
        if ($updateMade) {
            if ($verbose) {
                echo "Updating project '{$projectName}' with new predicate.\n";
            }
            $updateBody = ['predicate' => json_encode($existingPredicate)];
            callTimingAPI("/api/v1{$project['self']}", 'PATCH', [], $updateBody, verbose: $verbose, dryRun: $dryRun);
        }
    }
}
