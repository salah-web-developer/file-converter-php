<?php

require 'vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as ResponseInterface;

$app = AppFactory::create();
$uploadsDir = __DIR__ . '/uploads';

// Ensure the uploads directory exists
if (!file_exists($uploadsDir)) {
    mkdir($uploadsDir, 0777, true);
}

// Enable CORS
$app->add(function (Request $request, ResponseInterface $response, $next) {
    $response = $response->withHeader('Access-Control-Allow-Origin', '*')
                         ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                         ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    
    if ($request->getMethod() === 'OPTIONS') {
        return $response;
    }

    return $next($request, $response);
});

// Set up storage for file uploads
$adapter = new LocalFilesystemAdapter(
    $uploadsDir,
    PortableVisibilityConverter::fromArray(
        [
            'file' => ['public' => 0644, 'private' => 0600],
            'dir' => ['public' => 0755, 'private' => 0700],
        ],
        0700
    )
);
$filesystem = new Filesystem($adapter);

// Route to handle file upload
$app->post('/upload', function (Request $request, ResponseInterface $response) use ($filesystem) {
    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'];

    if ($file->getError() === UPLOAD_ERR_OK) {
        $filename = $file->getClientFilename();
        $hash = hash('sha256', $filename . time());
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $newFilename = $hash . '.' . $extension;

        $file->moveTo($GLOBALS['uploadsDir'] . DIRECTORY_SEPARATOR . $newFilename);

        $data = ['fileCode' => $hash];
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    return $response->withStatus(500)->write('Error uploading file');
});

// Route to handle file retrieval
$app->get('/retrieve', function (Request $request, ResponseInterface $response) use ($filesystem) {
    $fileCode = $request->getQueryParams()['fileCode'] ?? '';
    $directory = new DirectoryIterator($GLOBALS['uploadsDir']);

    foreach ($directory as $file) {
        if ($file->isFile() && strpos($file->getFilename(), $fileCode) === 0) {
            $filePath = $file->getPathname();
            $stream = fopen($filePath, 'rb');

            return $response->withHeader('Content-Type', mime_content_type($filePath))
                            ->withHeader('Content-Disposition', 'attachment; filename="' . $file->getFilename() . '"')
                            ->withBody(new \Slim\Psr7\Stream($stream));
        }
    }

    return $response->withStatus(404)->write('File not found');
});

// Serve the React frontend
$app->get('/{routes:.*}', function (Request $request, ResponseInterface $response, array $args) {
    $file = __DIR__ . '/client/build/index.html';

    if (file_exists($file)) {
        $stream = fopen($file, 'rb');
        return $response->withHeader('Content-Type', 'text/html')
                        ->withBody(new \Slim\Psr7\Stream($stream));
    }

    return $response->withStatus(404)->write('File not found');
});

// Start the server
$app->run();
