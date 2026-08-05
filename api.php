<?php
// Enforces strict typing for the file#).
declare(strict_types=1);

// Sets the HTTP response header so the client application knows to parse the incoming data as JSON.
header('Content-Type: application/json; charset=utf-8');

// We append the filename to create an absolute path to the data store.
$dataFile = __DIR__ . '/tasks.json';

// Then resolves if falsy. If true fallback to left. if false go right. Elvis operator.
$dataFile = realpath($dataFile) ?: $dataFile;

// Retrieves the HTTP method (GET, POST, etc.). See routes below.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----Helper Functions----- //

// A helper function to format and send the HTTP response to the client, then terminate the script.
function sendJson(int $statusCode, array $payload): void
{
    // built in php function to set the HTTP response code for the current request.
    http_response_code($statusCode);
    
    // json_encode converts the PHP array into a JSON string. 
    // structure into a clean, human-readable JSON text string and outputs as the final response payload.
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    exit;
}

// Function to read the file and parse it into a PHP associative array.
function loadTasks(string $dataFile): array
{
    // If the file doesn't exist yet, return an empty array to prevent read errors.
    if (!file_exists($dataFile)) {
        return [];
    }

    // Reads the entire file into a string.
    $contents = file_get_contents($dataFile);
    
    // Check if the read failed (returns false) or if the file is completely empty.
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    // Decodes the JSON string into an associative array (the 'true' parameter ensures it becomes an array, not a standard object).
    $decoded = json_decode($contents, true);
    
    // Ensures the decoded result is actually an array before returning it. If it's malformed, return an empty array.
    return is_array($decoded) ? $decoded : [];
}

// Function to format array data into a JSON string.
function serializeTasks(array $tasks): string|false
{
    // array_values() resets the array keys to be strictly numeric to ensure it encodes as a JSON array. Response = tasks = [0 => [...], 1 => [...]] instead of { "0": {...}, "1": {...} }.
    // JSON_PRETTY_PRINT formats the output with line breaks and indentation. Found this in php docs
    return json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Function to write string data to disk.
function writeToFile(string $filePath, string $content): bool
{
    // file_put_contents writes the string to the file. 
    // LOCK_EX prevents race conditions by locking the file during the write operation.
    return file_put_contents($filePath, $content . PHP_EOL, LOCK_EX) !== false;
}

// Function to convert the PHP array back to JSON string and write it to the database.
function saveTasks(string $dataFile, array $tasks): bool
{
    // convert to json string.
    $json = serializeTasks($tasks);

    if ($json === false) {
        return false;
    }
    // write to file and return the result of that operation.
    return writeToFile($dataFile, $json);
}

// Function to parse and read the input payload from request body.
function readInput(): array
{
    // php://input is a read-only stream that allows you to read raw data from the incoming request body.
    $raw = file_get_contents('php://input');
    
    // If the stream is unreadable or empty, return an empty array.
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    // Decode the raw JSON string into an associative array.
    $decoded = json_decode($raw, true);

    // Verify the payload decoded into an array, otherwise return an empty array.
    return is_array($decoded) ? $decoded : [];
}

// Protects against invalid status values.
function normalizeStatus(?string $status): ?string
{
    if ($status === null) {
        return null;
    }

    // Define the only acceptable status strings based on project requirements.
    $allowed = ['todo', 'in_progress', 'done'];
    
    // in_array checks if the $status exists in the $allowed array. 
    // The 'true' parameter enforces strict type checking (must be exactly the same string type and value).
    return in_array($status, $allowed, true) ? $status : null;
}

// A helper function to find the index of a specific task within the array, given its ID.
function findTaskIndex(array $tasks, string $id): int
{
    // Iterate over the array, extracting both the numeric $index and the $task array.
    foreach ($tasks as $index => $task) {
        // Check if the current task's ID matches the target ID. The ?? '' safely handles missing IDs.
        if (($task['id'] ?? '') === $id) {
            return $index; // Return the position in the array.
        }
    }

    // Return -1 if the ID was not found.
    return -1;
}

// --- CONTROLLER / ROUTING LOGIC --- //

// Immediately reject any HTTP methods we don't explicitly support.
if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    sendJson(405, ['error' => 'Method not allowed']);
}

// Load the current state of the JSON database into memory before handling the specific method.
$tasks = loadTasks($dataFile);

// GET /api.php: Return all tasks.
if ($method === 'GET') {
    sendJson(200, ['tasks' => $tasks]);
}

// POST /api.php: Create a new task.
if ($method === 'POST') {
    // Parse the incoming JSON payload. returns array.
    $input = readInput();
    
    // Extract and trim the title and description, casting them explicitly to strings to satisfy strict types.
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    
    // Validate that a title was actually provided.
    if ($title === '') {
        sendJson(400, ['error' => 'Title is required']);
    }

    // Construct the new task associative array.
    $task = [
        // Generate a cryptographically secure 16-character hexadecimal string for the ID (similar to Guid.NewGuid()) but better.
        'id' => bin2hex(random_bytes(8)),
        'title' => $title,
        'description' => $description,
        'status' => 'todo',
        // formatted timestamp in UTC.
        'created_at' => gmdate('c'),
    ];

    // Append the new task to the in-memory array.
    $tasks[] = $task;

    // Attempt to save the updated array to the disk.
    if (!saveTasks($dataFile, $tasks)) {
        sendJson(500, ['error' => 'Failed to save task']);
    }

    // Respond with 201 Created and the new task data.
    sendJson(201, ['task' => $task]);
}

// PUT /api.php: Update an existing task.
if ($method === 'PUT') {
    // Parse the incoming payload.
    $input = readInput();
    
    // Read the ID from the URL query string (?id=...). _GET is a superglobal array in PHP that contains query string parameters.
    $id = trim((string)($_GET['id'] ?? ''));

    // Ensure an ID was provided.
    if ($id === '') {
        sendJson(400, ['error' => 'Task id is required']);
    }

    // Locate the target task's exact index in the array.
    $index = findTaskIndex($tasks, $id);
    
    // If findTaskIndex returns -1, the task doesn't exist.
    if ($index === -1) {
        sendJson(404, ['error' => 'Task not found']);
    }

    // array_key_exists strictly checks if the key is in the payload, even if its value is null or empty.
    $titleProvided = array_key_exists('title', $input);
    $statusProvided = array_key_exists('status', $input);
    $descriptionProvided = array_key_exists('description', $input);

    // If the user sent a PUT request with no valid fields to update, reject it.
    if (!$titleProvided && !$statusProvided && !$descriptionProvided) {
        sendJson(400, ['error' => 'No fields to update']);
    }

    // If a title was provided, validate it's not empty, then update the array at the specific index.
    if ($titleProvided) {
        $title = trim((string)$input['title']);
        if ($title === '') {
            sendJson(400, ['error' => 'Title cannot be empty']);
        }
        $tasks[$index]['title'] = $title;
    }

    // Descriptions can usually be cleared out (empty strings), so we don't strictly require length here.
    if ($descriptionProvided) {
        $tasks[$index]['description'] = trim((string)$input['description']);
    }

    // If a status was provided, validate it against the allowed list, then update.
    if ($statusProvided) {
        $rawStatus = is_string($input['status']) ? $input['status'] : null;
        $status = normalizeStatus($rawStatus);
        
        if ($status === null) {
            sendJson(400, ['error' => 'Invalid status']);
        }
        $tasks[$index]['status'] = $status;
    }

    // Attempt to write the modifications to disk.
    if (!saveTasks($dataFile, $tasks)) {
        sendJson(500, ['error' => 'Failed to update task']);
    }

    // Respond with 200 OK and the newly modified task data.
    sendJson(200, ['task' => $tasks[$index]]);
}

// DELETE /api.php: Remove a task.
if ($method === 'DELETE') {
    // Read the ID from the URL query string (e.g., ?id=123).
    $id = trim((string)($_GET['id'] ?? ''));
    
    // Validate the ID is present.
    if ($id === '') {
        sendJson(400, ['error' => 'Task id is required']);
    }

    // Find the index of the task to delete.
    $index = findTaskIndex($tasks, $id);
    if ($index === -1) {
        sendJson(404, ['error' => 'Task not found']);
    }

    // array_splice removes a specific number of elements (1) starting at a specific index. 
    array_splice($tasks, $index, 1);

    // Save the updated, smaller array to disk.
    if (!saveTasks($dataFile, $tasks)) {
        sendJson(500, ['error' => 'Failed to delete task']);
    }

    // Respond with a 200 OK success message.
    sendJson(200, ['message' => 'Task deleted']);
}