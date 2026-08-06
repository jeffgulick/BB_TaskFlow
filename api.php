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
    // Open the file in read mode. If it fails, return an empty array. the 'r' mode opens the file for reading only. If the file does not exist, fopen() will return false.
    $fp = fopen($dataFile, 'r');
    if ($fp === false) {
        return [];
    }

    // This locks the file for reading, preventing other processes from writing to it while we read. If it fails, close the file and return an empty array.
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return [];
    }

    // Read the file contents safely while holding the lock.
    $contents = stream_get_contents($fp);

    // Release the lock and close the handle.
    flock($fp, LOCK_UN);
    fclose($fp);
    // If the file is empty or unreadable, return an empty array.
    if ($contents === false || trim($contents) === '') {
        return [];
    }
    // Decode the JSON string into a PHP associative array. If decoding fails, return an empty array.
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

// Function to format array data into a JSON string.
function serializeTasks(array $tasks): string|false
{
    // json_encode converts the PHP array into a JSON string.
    return json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Function to write string data to disk.
function writeToFile(string $filePath, string $content): bool
{
    // Open the file in 'c+' mode, which allows reading and writing. If the file doesn't exist, it will be created. If it fails, return false.
    $fp = fopen($filePath, 'c+');
    if ($fp === false) {
        return false;
    }

    // This locks the file for exclusive writing, preventing other processes from reading or writing to it while we write. If it fails, close the file and return false.
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    // Truncate the file to zero length, effectively clearing its contents. If it fails, release the lock, close the file, and return false.
    if (ftruncate($fp, 0) === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // if fseek fails, it means we couldn't move the file pointer to the beginning of the file. Release the lock, close the file, and return false.
    if (fseek($fp, 0) === -1) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Write the new content to the file.
    $written = fwrite($fp, $content . PHP_EOL);
    if ($written === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Ensure data is flushed to disk. this is important for data integrity, especially in case of a crash or power loss. If it fails, release the lock, close the file, and return false.
    fflush($fp);

    // Release lock and close.
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
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
/**
 * Handle GET requests: return the full tasks list.
 */
function handleGet(string $dataFile): void
{
    $tasks = loadTasks($dataFile);
    sendJson(200, ['tasks' => $tasks]);
}

/**
 * Handle POST requests: validate input, create a new task, persist, and
 * return the created resource with HTTP 201.
 */
function handlePost(string $dataFile): void
{
    // Read the input payload from the request body.
    $input = readInput();
    // Validate required fields and trim whitespace. If missing, return a 400 error.
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));

    if ($title === '') {
        sendJson(400, ['error' => 'Title is required']);
    }

    $task = [
        'id' => bin2hex(random_bytes(8)),
        'title' => $title,
        'description' => $description,
        'status' => 'todo',
        'created_at' => gmdate('c'),
    ];
    // Load existing tasks, append the new task, and save back to the JSON file.
    $tasks = loadTasks($dataFile);
    $tasks[] = $task;
    // Save the updated tasks array back to the JSON file. If saving fails, return a 500 error.
    if (!saveTasks($dataFile, $tasks)) {
        sendJson(500, ['error' => 'Failed to save task']);
    }

    sendJson(201, ['task' => $task]);
}

/**
 * Handle PUT requests: update an existing task identified by ?id=...,
 * validating provided fields and persisting the change.
 */
function handlePut(string $dataFile): void
{
    // Read the input payload from the request body.
    $input = readInput();
    // Retrieve the task ID from the query string, defaulting to an empty string if not provided.
    $id = trim((string)($_GET['id'] ?? ''));

    if ($id === '') {
        sendJson(400, ['error' => 'Task id is required']);
    }

    $tasks = loadTasks($dataFile);
    $index = findTaskIndex($tasks, $id);
    if ($index === -1) {
        sendJson(404, ['error' => 'Task not found']);
    }
    // Check which fields are provided in the input payload. This allows partial updates.
    $titleProvided = array_key_exists('title', $input);
    $statusProvided = array_key_exists('status', $input);
    $descriptionProvided = array_key_exists('description', $input);
    
    if (!$titleProvided && !$statusProvided && !$descriptionProvided) {
        sendJson(400, ['error' => 'No fields to update']);
    }
    // Validate and update each field if provided.
    if ($titleProvided) {
        $title = trim((string)$input['title']);
        if ($title === '') {
            sendJson(400, ['error' => 'Title cannot be empty']);
        }
        $tasks[$index]['title'] = $title;
    }

    if ($descriptionProvided) {
        // No validation for description; it can be empty. just trim whitespace.
        $tasks[$index]['description'] = trim((string)$input['description']);
    }

    if ($statusProvided) {
        $rawStatus = is_string($input['status']) ? $input['status'] : null;
        $status = normalizeStatus($rawStatus);
        if ($status === null) {
            sendJson(400, ['error' => 'Invalid status']);
        }
        $tasks[$index]['status'] = $status;
    }
    // Save the updated tasks array back to the JSON file. If saving fails, return a 500 error.
    if (!saveTasks($dataFile, $tasks)) {
        sendJson(500, ['error' => 'Failed to update task']);
    }
    // Return the updated task in the response.
    sendJson(200, ['task' => $tasks[$index]]);
}

/**
 * Handle DELETE requests: remove a task identified by ?id=... and persist.
 */
function handleDelete(string $dataFile): void
{
    // gets id from query string.
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        sendJson(400, ['error' => 'Task id is required']);
    }

    $tasks = loadTasks($dataFile);
    $index = findTaskIndex($tasks, $id);
    if ($index === -1) {
        sendJson(404, ['error' => 'Task not found']);
    }
    // Remove the task from the array using array_splice, which modifies the array in place.
    array_splice($tasks, $index, 1);
    // Save the updated tasks array back to the JSON file. If saving fails, return a 500 error.
    if (!saveTasks($dataFile, $tasks)) {
        sendJson(500, ['error' => 'Failed to delete task']);
    }
    // Return a 200 OK response with a success message.
    sendJson(200, ['message' => 'Task deleted']);
}

switch ($method) {
    case 'GET':
        handleGet($dataFile);
        break;
    case 'POST':
        handlePost($dataFile);
        break;
    case 'PUT':
        handlePut($dataFile);
        break;
    case 'DELETE':
        handleDelete($dataFile);
        break;
}