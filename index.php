<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Obsługa żądań OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Konfiguracja
define('TASKS_FILE', __DIR__ . '/tasks.json');

// Funkcje pomocnicze
function getTasks() {
    if (!file_exists(TASKS_FILE)) {
        file_put_contents(TASKS_FILE, '[]');
        return [];
    }
    
    $content = file_get_contents(TASKS_FILE);
    if (empty($content)) {
        return [];
    }
    
    $tasks = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format in tasks file');
    }
    
    return $tasks ?: [];
}

function saveTasks($tasks) {
    if (!is_writable(dirname(TASKS_FILE)) && !is_writable(TASKS_FILE)) {
        throw new Exception('Tasks file is not writable');
    }
    
    $result = file_put_contents(TASKS_FILE, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $result !== false;
}

function generateNextId($tasks) {
    if (empty($tasks)) {
        return 1;
    }
    
    $maxId = 0;
    foreach ($tasks as $task) {
        if (isset($task['id']) && $task['id'] > $maxId) {
            $maxId = $task['id'];
        }
    }
    
    return $maxId + 1;
}

function getCurrentTimestamp() {
    return date('c');
}

function validateTaskData($input, $isUpdate = false) {
    $errors = [];
    
    if (!$isUpdate) {
        // Walidacja dla tworzenia nowego zadania
        if (!isset($input['title']) || empty(trim($input['title']))) {
            $errors[] = 'Title is required';
        }
    } else {
        // Walidacja dla aktualizacji
        if (isset($input['title']) && empty(trim($input['title']))) {
            $errors[] = 'Title cannot be empty';
        }
    }
    
    if (isset($input['title']) && strlen(trim($input['title'])) > 255) {
        $errors[] = 'Title cannot be longer than 255 characters';
    }
    
    if (isset($input['description']) && strlen(trim($input['description'])) > 1000) {
        $errors[] = 'Description cannot be longer than 1000 characters';
    }
    
    if (isset($input['completed']) && !is_bool($input['completed']) && 
        !in_array(strtolower($input['completed']), ['true', 'false', '1', '0', 1, 0])) {
        $errors[] = 'Completed must be a boolean value';
    }
    
    return $errors;
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in request body');
    }
    return $input;
}

function findTaskById($tasks, $taskId) {
    foreach ($tasks as &$task) {
        if (isset($task['id']) && $task['id'] === $taskId) {
            return $task;
        }
    }
    return null;
}

// Pobierz ścieżkę żądania
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Usuń parametr query string z URI
$path = parse_url($requestUri, PHP_URL_PATH);

// Automatyczne wykrywanie ścieżki bazowej
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptDir === '/') {
    $scriptDir = '';
}
$basePath = $scriptDir;

// Usuń podstawową ścieżkę jeśli aplikacja jest w podkatalogu
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

$path = trim($path, '/');
$pathSegments = $path ? explode('/', $path) : [];

// Główna logika routingu
try {
    if (empty($pathSegments) && $requestMethod === 'GET') {
        // Dla głównego URL pokaż informację
        echo json_encode([
            'message' => 'TODO API is running',
            'endpoints' => [
                'GET /health' => 'Check API status',
                'GET /tasks' => 'Get all tasks',
                'POST /tasks' => 'Create new task',
                'PUT /tasks/:id' => 'Update task',
                'DELETE /tasks/:id' => 'Delete task'
            ]
        ]);
        exit;
    }
    
    switch (true) {
        case $pathSegments[0] === 'health' && $requestMethod === 'GET':
            // GET /health
            echo json_encode([
                'status' => 'OK',
                'timestamp' => getCurrentTimestamp(),
                'server' => 'Apache/PHP'
            ]);
            break;

        case $pathSegments[0] === 'tasks' && $requestMethod === 'GET' && count($pathSegments) === 1:
            // GET /tasks
            $tasks = getTasks();
            echo json_encode($tasks);
            break;

        case $pathSegments[0] === 'tasks' && $requestMethod === 'POST' && count($pathSegments) === 1:
            // POST /tasks
            try {
                $input = getJsonInput();
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
                break;
            }
            
            $validationErrors = validateTaskData($input, false);
            if (!empty($validationErrors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Validation failed',
                    'details' => $validationErrors
                ]);
                break;
            }
            
            $tasks = getTasks();
            $newTask = [
                'id' => generateNextId($tasks),
                'title' => trim($input['title']),
                'description' => isset($input['description']) ? trim($input['description']) : '',
                'completed' => false,
                'createdAt' => getCurrentTimestamp()
            ];
            
            $tasks[] = $newTask;
            
            try {
                if (saveTasks($tasks)) {
                    http_response_code(201);
                    echo json_encode($newTask);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to save task to file']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case $pathSegments[0] === 'tasks' && $requestMethod === 'PUT' && count($pathSegments) === 2:
            // PUT /tasks/:id
            $taskId = intval($pathSegments[1]);
            
            if ($taskId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid task ID']);
                break;
            }
            
            try {
                $input = getJsonInput();
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
                break;
            }
            
            if (empty($input)) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body cannot be empty']);
                break;
            }
            
            $validationErrors = validateTaskData($input, true);
            if (!empty($validationErrors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Validation failed',
                    'details' => $validationErrors
                ]);
                break;
            }
            
            $tasks = getTasks();
            $taskFound = false;
            
            foreach ($tasks as &$task) {
                if (isset($task['id']) && $task['id'] === $taskId) {
                    if (isset($input['title']) && !empty(trim($input['title']))) {
                        $task['title'] = trim($input['title']);
                    }
                    
                    if (isset($input['description'])) {
                        $task['description'] = trim($input['description']);
                    }
                    
                    if (isset($input['completed'])) {
                        // Konwersja różnych formatów boolean
                        $completed = $input['completed'];
                        if (is_string($completed)) {
                            $completed = in_array(strtolower($completed), ['true', '1']);
                        }
                        $task['completed'] = (bool)$completed;
                    }
                    
                    $task['updatedAt'] = getCurrentTimestamp();
                    $taskFound = true;
                    break;
                }
            }
            
            if (!$taskFound) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Task not found',
                    'id' => $taskId
                ]);
                break;
            }
            
            try {
                if (saveTasks($tasks)) {
                    $updatedTask = findTaskById($tasks, $taskId);
                    echo json_encode($updatedTask);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update task']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case $pathSegments[0] === 'tasks' && $requestMethod === 'DELETE' && count($pathSegments) === 2:
            // DELETE /tasks/:id
            $taskId = intval($pathSegments[1]);
            
            if ($taskId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid task ID']);
                break;
            }
            
            $tasks = getTasks();
            $initialCount = count($tasks);
            
            $tasks = array_filter($tasks, function($task) use ($taskId) {
                return !(isset($task['id']) && $task['id'] === $taskId);
            });
            
            // Reindex array
            $tasks = array_values($tasks);
            
            if (count($tasks) === $initialCount) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Task not found',
                    'id' => $taskId
                ]);
                break;
            }
            
            try {
                if (saveTasks($tasks)) {
                    http_response_code(200);
                    echo json_encode([
                        'message' => 'Task deleted successfully',
                        'id' => $taskId
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to delete task']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        default:
            http_response_code(404);
            echo json_encode([
                'error' => 'Endpoint not found',
                'method' => $requestMethod,
                'path' => $path,
                'available_endpoints' => [
                    'GET /health',
                    'GET /tasks', 
                    'POST /tasks',
                    'PUT /tasks/:id',
                    'DELETE /tasks/:id'
                ]
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred'
    ]);
}
?>